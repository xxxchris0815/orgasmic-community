<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Orgasmic_Fc_Admin
{
    public function __construct(
        private Orgasmic_Fc_Store $store,
        private Orgasmic_Fc_Webhook $webhook
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'settings']);
        add_action('admin_post_orgasmic_fc_test_webhook', [$this, 'handle_test_webhook']);
        add_action('admin_notices', [$this, 'notices']);
    }

    public function menu(): void
    {
        add_menu_page(
            'ORGAMSIC Tracker',
            'ORGAMSIC Tracker',
            'manage_options',
            'orgasmic-fc-tracker',
            [$this, 'render_dashboard'],
            'dashicons-chart-area',
            58
        );

        add_submenu_page(
            'orgasmic-fc-tracker',
            'Übersicht',
            'Übersicht',
            'manage_options',
            'orgasmic-fc-tracker',
            [$this, 'render_dashboard']
        );

        add_submenu_page(
            'orgasmic-fc-tracker',
            'Mitglieder',
            'Mitglieder',
            'manage_options',
            'orgasmic-fc-members',
            [$this, 'render_members']
        );

        add_submenu_page(
            'orgasmic-fc-tracker',
            'Kurse',
            'Kurse',
            'manage_options',
            'orgasmic-fc-courses',
            [$this, 'render_courses']
        );

        add_submenu_page(
            'orgasmic-fc-tracker',
            'Ereignisse',
            'Ereignisse',
            'manage_options',
            'orgasmic-fc-events',
            [$this, 'render_events']
        );

        add_submenu_page(
            'orgasmic-fc-tracker',
            'Einstellungen',
            'Einstellungen',
            'manage_options',
            'orgasmic-fc-settings',
            [$this, 'render_settings']
        );
    }

    public function settings(): void
    {
        register_setting('orgasmic_fc_tracker', Orgasmic_Fc_Store::OPTION_WEBHOOK_URL, [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
        ]);
        register_setting('orgasmic_fc_tracker', Orgasmic_Fc_Store::OPTION_WEBHOOK_SECRET, [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('orgasmic_fc_tracker', Orgasmic_Fc_Store::OPTION_INCLUDE_PII, [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
        ]);
        register_setting('orgasmic_fc_tracker', Orgasmic_Fc_Store::OPTION_INCLUDE_CONTENT, [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
        ]);
        register_setting('orgasmic_fc_tracker', Orgasmic_Fc_Store::OPTION_RETENTION_DAYS, [
            'type' => 'integer',
            'sanitize_callback' => 'absint',
        ]);
        register_setting('orgasmic_fc_tracker', Orgasmic_Fc_Store::OPTION_ENABLED_GROUPS, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_groups'],
        ]);
    }

    public function sanitize_groups($value): array
    {
        $allowed = array_keys(Orgasmic_Fc_Store::event_groups());
        $value = is_array($value) ? $value : [];

        return array_values(array_intersect($allowed, array_map('sanitize_key', $value)));
    }

    public function notices(): void
    {
        if (!isset($_GET['page']) || strpos((string) $_GET['page'], 'orgasmic-fc') !== 0) {
            return;
        }

        $fc_active = defined('FLUENT_COMMUNITY_PLUGIN_VERSION') || class_exists('FluentCommunity\\App\\App');
        if (!$fc_active) {
            echo '<div class="notice notice-warning"><p>ORGAMSIC Tracker: FluentCommunity ist nicht aktiv. Hooks greifen erst nach der Aktivierung.</p></div>';
        }

        if (isset($_GET['orgasmic_fc_test'])) {
            $ok = $_GET['orgasmic_fc_test'] === '1';
            echo '<div class="notice notice-' . ($ok ? 'success' : 'error') . '"><p>';
            echo $ok
                ? esc_html('Test-Webhook wurde ausgelöst.')
                : esc_html('Test-Webhook fehlgeschlagen. URL prüfen.');
            echo '</p></div>';
        }
    }

    public function handle_test_webhook(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Keine Berechtigung.');
        }

        check_admin_referer('orgasmic_fc_test_webhook');
        $result = $this->webhook->send_test();
        wp_safe_redirect(add_query_arg(
            [
                'page' => 'orgasmic-fc-settings',
                'orgasmic_fc_test' => $result['ok'] ? '1' : '0',
            ],
            admin_url('admin.php')
        ));
        exit;
    }

    public function render_dashboard(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $summary = $this->store->get_summary(7);
        $members = array_slice($this->store->get_member_stats(8), 0, 8);
        $map = [];
        foreach ($summary['totals'] as $row) {
            $map[$row['event']] = (int) $row['total'];
        }

        $this->header('Übersicht — letzte 7 Tage');
        echo '<div class="orgasmic-fc-cards">';
        $this->card('Aktive Mitglieder', (string) $summary['active_users']);
        $this->card('Lektionen abgeschlossen', (string) ($map['course.lesson_completed'] ?? 0));
        $this->card('Kurse abgeschlossen', (string) ($map['course.completed'] ?? 0));
        $this->card('Neue Posts', (string) ($map['feed.created'] ?? 0));
        $this->card('Kommentare', (string) ($map['comment.added'] ?? 0));
        $this->card('Reaktionen', (string) (($map['feed.react_added'] ?? 0) + ($map['comment.react_added'] ?? 0)));
        $this->card('Space-Beitritte', (string) ($map['space.joined'] ?? 0));
        $this->card('Gespeicherte Events', (string) $summary['stored_events']);
        echo '</div>';

        echo '<h2>Aktivität nach Event</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Event</th><th>Anzahl</th></tr></thead><tbody>';
        if ($summary['totals'] === []) {
            echo '<tr><td colspan="2">Noch keine Events. Sobald Mitglieder Kurse oder den Feed nutzen, erscheinen die Zahlen hier.</td></tr>';
        }
        foreach ($summary['totals'] as $row) {
            echo '<tr><td><code>' . esc_html($row['event']) . '</code></td><td>' . (int) $row['total'] . '</td></tr>';
        }
        echo '</tbody></table>';

        echo '<h2>Top Engagement</h2>';
        $this->members_table($members);
        $this->styles();
    }

    public function render_members(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $this->header('Mitglieder-Engagement');
        echo '<p>Score = Lektionen×5 + Kursabschluss×20 + Posts×4 + Kommentare×3 + Reaktionen + Space-Beitritte×2. Zählt nur Events nach Aktivierung des Plugins.</p>';
        $this->members_table($this->store->get_member_stats(200));
        $this->styles();
    }

    public function render_courses(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $this->header('Kursfortschritt');
        $courses = $this->store->get_course_progress();

        if ($courses === []) {
            echo '<p>Noch keine abgeschlossenen Lektionen erfasst. Der Tracker startet ab dem Zeitpunkt der Aktivierung.</p>';
            $this->styles();
            return;
        }

        foreach ($courses as $course) {
            echo '<h2>' . esc_html($course['title']) . '</h2>';
            echo '<p>' . (int) $course['unique_student_count'] . ' aktive Lernende · ';
            echo (int) $course['lesson_completions'] . ' Lektionsabschlüsse</p>';
            echo '<table class="widefat striped"><thead><tr>';
            echo '<th>Mitglied</th><th>Lektionen</th><th>Zuletzt</th></tr></thead><tbody>';
            foreach ($course['students'] as $student) {
                echo '<tr>';
                echo '<td>' . esc_html($student['display_name']) . ' <code>#' . (int) $student['user_id'] . '</code></td>';
                echo '<td>' . (int) $student['lessons_completed'] . '</td>';
                echo '<td>' . esc_html($student['last_completed_at']) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        $this->styles();
    }

    public function render_events(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $category = isset($_GET['category']) ? sanitize_key((string) $_GET['category']) : '';
        $user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
        $args = [
            'category' => $category,
            'user_id' => $user_id,
            'limit' => 100,
        ];

        $this->header('Ereignisprotokoll');
        echo '<form method="get" style="margin:12px 0">';
        echo '<input type="hidden" name="page" value="orgasmic-fc-events" />';
        echo '<label>Kategorie <select name="category"><option value="">alle</option>';
        foreach (Orgasmic_Fc_Store::event_groups() as $key => $label) {
            echo '<option value="' . esc_attr($key) . '"' . selected($category, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label> ';
        echo '<label>User-ID <input type="number" name="user_id" value="' . ($user_id ?: '') . '" class="small-text" /></label> ';
        submit_button('Filtern', 'secondary', '', false);
        echo '</form>';

        $events = $this->store->get_events($args);
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>Zeit (UTC)</th><th>Event</th><th>User</th><th>Objekt</th><th>Details</th>';
        echo '</tr></thead><tbody>';

        if ($events === []) {
            echo '<tr><td colspan="5">Keine Ereignisse.</td></tr>';
        }

        foreach ($events as $event) {
            $user = $event['user_id'] ? get_userdata((int) $event['user_id']) : null;
            $payload = json_decode((string) $event['payload'], true);
            echo '<tr>';
            echo '<td>' . esc_html($event['occurred_at']) . '</td>';
            echo '<td><code>' . esc_html($event['event']) . '</code><br><small>' . esc_html($event['category']) . '</small></td>';
            echo '<td>' . ($user ? esc_html($user->display_name) : '—') . '<br><small>#' . esc_html((string) $event['user_id']) . '</small></td>';
            echo '<td>' . esc_html((string) $event['object_type']) . ' #' . esc_html((string) $event['object_id']) . '</td>';
            echo '<td><code style="white-space:pre-wrap;font-size:11px">' . esc_html(wp_json_encode($payload['data'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . '</code></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        $this->styles();
    }

    public function render_settings(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $this->header('Einstellungen');
        echo '<form method="post" action="options.php">';
        settings_fields('orgasmic_fc_tracker');

        $url = (string) get_option(Orgasmic_Fc_Store::OPTION_WEBHOOK_URL, '');
        $secret = (string) get_option(Orgasmic_Fc_Store::OPTION_WEBHOOK_SECRET, '');
        $pii = (bool) get_option(Orgasmic_Fc_Store::OPTION_INCLUDE_PII, 1);
        $content = (bool) get_option(Orgasmic_Fc_Store::OPTION_INCLUDE_CONTENT, 0);
        $days = (int) get_option(Orgasmic_Fc_Store::OPTION_RETENTION_DAYS, 365);
        $enabled = (array) get_option(Orgasmic_Fc_Store::OPTION_ENABLED_GROUPS, array_keys(Orgasmic_Fc_Store::event_groups()));

        echo '<table class="form-table" role="presentation">';
        echo '<tr><th>Webhook-URL</th><td><input type="url" class="regular-text" name="' . esc_attr(Orgasmic_Fc_Store::OPTION_WEBHOOK_URL) . '" value="' . esc_attr($url) . '" placeholder="https://n8n.example.com/webhook/fc" />';
        echo '<p class="description">Optional. Jedes Event wird zusätzlich als JSON POST gesendet (nicht-blockierend). Header <code>X-Orgasmic-Signature</code> wenn Secret gesetzt.</p></td></tr>';
        echo '<tr><th>Webhook-Secret</th><td><input type="text" class="regular-text" name="' . esc_attr(Orgasmic_Fc_Store::OPTION_WEBHOOK_SECRET) . '" value="' . esc_attr($secret) . '" autocomplete="off" />';
        echo '<p class="description">HMAC-SHA256 über den JSON-Body.</p></td></tr>';
        echo '<tr><th>Personenbezogene Daten</th><td>';
        echo '<input type="hidden" name="' . esc_attr(Orgasmic_Fc_Store::OPTION_INCLUDE_PII) . '" value="0" />';
        echo '<label><input type="checkbox" name="' . esc_attr(Orgasmic_Fc_Store::OPTION_INCLUDE_PII) . '" value="1" ' . checked($pii, true, false) . ' /> E-Mail, Name und Login im Webhook mitsenden</label></td></tr>';
        echo '<tr><th>Inhalte</th><td>';
        echo '<input type="hidden" name="' . esc_attr(Orgasmic_Fc_Store::OPTION_INCLUDE_CONTENT) . '" value="0" />';
        echo '<label><input type="checkbox" name="' . esc_attr(Orgasmic_Fc_Store::OPTION_INCLUDE_CONTENT) . '" value="1" ' . checked($content, true, false) . ' /> Post- und Kommentartexte mitsenden (sonst nur Kurzauszug)</label></td></tr>';
        echo '<tr><th>Aufbewahrung</th><td><input type="number" min="1" class="small-text" name="' . esc_attr(Orgasmic_Fc_Store::OPTION_RETENTION_DAYS) . '" value="' . esc_attr((string) $days) . '" /> Tage im lokalen Protokoll</td></tr>';
        echo '<tr><th>Event-Gruppen</th><td>';
        foreach (Orgasmic_Fc_Store::event_groups() as $key => $label) {
            echo '<label style="display:block;margin:4px 0"><input type="checkbox" name="' . esc_attr(Orgasmic_Fc_Store::OPTION_ENABLED_GROUPS) . '[]" value="' . esc_attr($key) . '" ' . checked(in_array($key, $enabled, true), true, false) . ' /> ' . esc_html($label) . '</label>';
        }
        echo '</td></tr></table>';
        submit_button('Speichern');
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('orgasmic_fc_test_webhook');
        echo '<input type="hidden" name="action" value="orgasmic_fc_test_webhook" />';
        submit_button('Test-Webhook senden', 'secondary');
        echo '</form>';

        echo '<h2>Webhook-Payload</h2>';
        echo '<pre style="background:#fff;padding:12px;border:1px solid #ccd0d4;max-width:820px">{
  "source": "orgasmic-fc-tracker",
  "event": "course.lesson_completed",
  "category": "courses",
  "user_id": 12,
  "user": { "id": 12, "email": "member@example.com", "display_name": "Alex" },
  "object_type": "lesson",
  "object_id": 44,
  "parent_type": "course",
  "parent_id": 3,
  "data": { "lesson": { "id": 44, "title": "Live Call #12" } },
  "occurred_at": "2026-08-22T10:00:00+00:00"
}</pre>';

        echo '<p>REST (nur Admins): <code>/wp-json/orgasmic-fc/v1/summary</code>, <code>/members</code>, <code>/courses</code>, <code>/events</code></p>';
        $this->styles();
    }

    private function header(string $title): void
    {
        echo '<div class="wrap orgasmic-fc-wrap"><h1>' . esc_html($title) . '</h1>';
    }

    private function card(string $label, string $value): void
    {
        echo '<div class="orgasmic-fc-card"><strong>' . esc_html($value) . '</strong><span>' . esc_html($label) . '</span></div>';
    }

    private function members_table(array $members): void
    {
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>Mitglied</th><th>Score</th><th>Lektionen</th><th>Kurse</th><th>Posts</th><th>Kommentare</th><th>Reaktionen</th><th>Spaces</th><th>Zuletzt</th>';
        echo '</tr></thead><tbody>';

        if ($members === []) {
            echo '<tr><td colspan="9">Noch keine Mitglieder-Events.</td></tr>';
        }

        foreach ($members as $row) {
            $profile = admin_url('admin.php?page=orgasmic-fc-events&user_id=' . (int) $row['user_id']);
            echo '<tr>';
            echo '<td><a href="' . esc_url($profile) . '">' . esc_html($row['display_name']) . '</a><br><small>' . esc_html($row['email']) . '</small></td>';
            echo '<td><strong>' . (int) $row['engagement_score'] . '</strong></td>';
            echo '<td>' . (int) $row['lessons_completed'] . '</td>';
            echo '<td>' . (int) $row['courses_completed'] . '</td>';
            echo '<td>' . (int) $row['posts'] . '</td>';
            echo '<td>' . (int) $row['comments'] . '</td>';
            echo '<td>' . (int) $row['reactions'] . '</td>';
            echo '<td>' . (int) $row['spaces_joined'] . '</td>';
            echo '<td>' . esc_html((string) $row['last_seen']) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private function styles(): void
    {
        echo '<style>
            .orgasmic-fc-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin:16px 0 24px}
            .orgasmic-fc-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px}
            .orgasmic-fc-card strong{display:block;font-size:28px;line-height:1.2}
            .orgasmic-fc-card span{color:#646970}
        </style></div>';
    }
}
