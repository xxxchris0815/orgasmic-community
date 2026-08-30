<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Orgasmic_Fc_Embeds_Admin
{
    public function __construct(
        private Orgasmic_Fc_Embeds_Store $store,
        private Orgasmic_Fc_Embeds_Webhook $webhook
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'settings']);
        add_action('admin_post_orgasmic_fc_embeds_test_webhook', [$this, 'handle_test']);
    }

    public function menu(): void
    {
        add_menu_page(
            'ORGASMIC Bunny',
            'ORGASMIC Bunny',
            'manage_options',
            'orgasmic-fc-embeds',
            [$this, 'render_log'],
            'dashicons-video-alt3',
            60
        );
        add_submenu_page(
            'orgasmic-fc-embeds',
            'Wiedergaben',
            'Wiedergaben',
            'manage_options',
            'orgasmic-fc-embeds',
            [$this, 'render_log']
        );
        add_submenu_page(
            'orgasmic-fc-embeds',
            'Einstellungen',
            'Einstellungen',
            'manage_options',
            'orgasmic-fc-embeds-settings',
            [$this, 'render_settings']
        );
    }

    public function settings(): void
    {
        register_setting('orgasmic_fc_embeds', Orgasmic_Fc_Embeds_Store::OPTION_AUTOPLAY, [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
        ]);
        register_setting('orgasmic_fc_embeds', Orgasmic_Fc_Embeds_Store::OPTION_LIBRARY_ID, [
            'type' => 'string',
            'sanitize_callback' => static fn($value) => preg_replace('/[^0-9]/', '', (string) $value) ?: '',
        ]);
        register_setting('orgasmic_fc_embeds', Orgasmic_Fc_Embeds_Store::OPTION_API_KEY, [
            'type' => 'string',
            'sanitize_callback' => static function ($value) {
                $value = trim((string) $value);
                if ($value === '') {
                    return (string) get_option(Orgasmic_Fc_Embeds_Store::OPTION_API_KEY, '');
                }
                return sanitize_text_field($value);
            },
        ]);
        register_setting('orgasmic_fc_embeds', Orgasmic_Fc_Embeds_Store::OPTION_COLLECTION_ID, [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('orgasmic_fc_embeds', Orgasmic_Fc_Embeds_Store::OPTION_WEBHOOK_URL, [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
        ]);
        register_setting('orgasmic_fc_embeds', Orgasmic_Fc_Embeds_Store::OPTION_WEBHOOK_SECRET, [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('orgasmic_fc_embeds', Orgasmic_Fc_Embeds_Store::OPTION_INCLUDE_PII, [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
        ]);
        register_setting('orgasmic_fc_embeds', Orgasmic_Fc_Embeds_Store::OPTION_RETENTION_DAYS, [
            'type' => 'integer',
            'sanitize_callback' => 'absint',
        ]);
    }

    public function handle_test(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Keine Berechtigung.');
        }
        check_admin_referer('orgasmic_fc_embeds_test_webhook');
        $result = $this->webhook->send_test();
        wp_safe_redirect(add_query_arg([
            'page' => 'orgasmic-fc-embeds-settings',
            'orgasmic_fc_embeds_test' => $result['ok'] ? '1' : '0',
        ], admin_url('admin.php')));
        exit;
    }

    public function render_log(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        echo '<div class="wrap"><h1>ORGASMIC Bunny — Wiedergaben</h1>';
        echo '<p>Wer hat welches Video wie weit gesehen. Wird zusätzlich an den Webhook geschickt.</p>';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>Zeit (UTC)</th><th>Event</th><th>Mitglied</th><th>Video</th><th>Position</th><th>Max</th><th>%</th>';
        echo '</tr></thead><tbody>';

        $rows = $this->store->recent(80);
        if ($rows === []) {
            echo '<tr><td colspan="7">Noch keine Wiedergaben.</td></tr>';
        }
        foreach ($rows as $row) {
            $user = !empty($row['user_id']) ? get_userdata((int) $row['user_id']) : null;
            echo '<tr>';
            echo '<td>' . esc_html((string) $row['occurred_at']) . '</td>';
            echo '<td><code>video.' . esc_html((string) $row['event']) . '</code></td>';
            echo '<td>' . esc_html($user ? $user->display_name : '—') . '</td>';
            echo '<td><code>' . esc_html((string) $row['video_id']) . '</code></td>';
            echo '<td>' . esc_html($this->fmt_time((float) $row['seconds'])) . '</td>';
            echo '<td>' . esc_html($this->fmt_time((float) $row['max_seconds'])) . '</td>';
            echo '<td>' . esc_html(number_format((float) $row['percent'], 1)) . '%</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    public function render_settings(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        echo '<div class="wrap"><h1>ORGASMIC Bunny — Einstellungen</h1>';
        if (isset($_GET['orgasmic_fc_embeds_test'])) {
            $ok = $_GET['orgasmic_fc_embeds_test'] === '1';
            echo '<div class="notice notice-' . ($ok ? 'success' : 'error') . '"><p>';
            echo $ok ? 'Test-Webhook wurde ausgelöst.' : 'Test-Webhook fehlgeschlagen. URL prüfen.';
            echo '</p></div>';
        }

        echo '<form method="post" action="options.php">';
        settings_fields('orgasmic_fc_embeds');
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th>Bunny Library-ID</th><td><input type="text" class="regular-text" name="'
            . esc_attr(Orgasmic_Fc_Embeds_Store::OPTION_LIBRARY_ID) . '" value="'
            . esc_attr($this->store->library_id()) . '" placeholder="z. B. 123456" />';
        echo '<p class="description">Stream → deine Library. Steht in der URL und unter API.</p></td></tr>';

        echo '<tr><th>Bunny Stream API-Key</th><td><input type="password" class="regular-text" name="'
            . esc_attr(Orgasmic_Fc_Embeds_Store::OPTION_API_KEY) . '" value="" autocomplete="new-password" placeholder="'
            . ($this->store->api_key() !== '' ? 'Gesetzt — leer lassen zum Behalten' : 'Library-API-Key') . '" />';
        echo '<p class="description">Stream → Library → API. Wird nur serverseitig genutzt (nicht im Browser).</p></td></tr>';

        echo '<tr><th>Collection (optional)</th><td><input type="text" class="regular-text" name="'
            . esc_attr(Orgasmic_Fc_Embeds_Store::OPTION_COLLECTION_ID) . '" value="'
            . esc_attr($this->store->collection_id()) . '" placeholder="Collection-GUID" />';
        echo '<p class="description">Neue Community-Videos landen in dieser Collection.</p></td></tr>';

        echo '<tr><th>Autoplay</th><td>';
        echo '<input type="hidden" name="' . esc_attr(Orgasmic_Fc_Embeds_Store::OPTION_AUTOPLAY) . '" value="0" />';
        echo '<label><input type="checkbox" name="' . esc_attr(Orgasmic_Fc_Embeds_Store::OPTION_AUTOPLAY) . '" value="1" '
            . checked($this->store->autoplay(), true, false) . ' /> Video im Feed automatisch starten</label>';
        echo '<p class="description">Browser können Autoplay mit Ton trotzdem blocken.</p></td></tr>';

        echo '<tr><th>Webhook-URL</th><td><input type="url" class="regular-text" name="'
            . esc_attr(Orgasmic_Fc_Embeds_Store::OPTION_WEBHOOK_URL) . '" value="'
            . esc_attr((string) get_option(Orgasmic_Fc_Embeds_Store::OPTION_WEBHOOK_URL, '')) . '" placeholder="https://n8n.example.com/webhook/bunny" />';
        echo '<p class="description">Events: <code>video.play</code>, <code>video.pause</code>, <code>video.progress</code>, <code>video.ended</code>, <code>video.seeked</code>.</p></td></tr>';

        echo '<tr><th>Webhook-Secret</th><td><input type="text" class="regular-text" name="'
            . esc_attr(Orgasmic_Fc_Embeds_Store::OPTION_WEBHOOK_SECRET) . '" value="'
            . esc_attr((string) get_option(Orgasmic_Fc_Embeds_Store::OPTION_WEBHOOK_SECRET, '')) . '" />';
        echo '<p class="description">Optional. Wird als HMAC-SHA256 in <code>X-Orgasmic-Signature</code> gesendet.</p></td></tr>';

        echo '<tr><th>Personenbezogene Daten</th><td>';
        echo '<input type="hidden" name="' . esc_attr(Orgasmic_Fc_Embeds_Store::OPTION_INCLUDE_PII) . '" value="0" />';
        echo '<label><input type="checkbox" name="' . esc_attr(Orgasmic_Fc_Embeds_Store::OPTION_INCLUDE_PII) . '" value="1" '
            . checked((bool) get_option(Orgasmic_Fc_Embeds_Store::OPTION_INCLUDE_PII, 1), true, false)
            . ' /> Name und E-Mail im Webhook mitschicken</label></td></tr>';

        echo '<tr><th>Aufbewahrung (Tage)</th><td><input type="number" min="1" max="3650" name="'
            . esc_attr(Orgasmic_Fc_Embeds_Store::OPTION_RETENTION_DAYS) . '" value="'
            . esc_attr((string) ((int) get_option(Orgasmic_Fc_Embeds_Store::OPTION_RETENTION_DAYS, 90))) . '" /></td></tr>';
        echo '</table>';
        submit_button();
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('orgasmic_fc_embeds_test_webhook');
        echo '<input type="hidden" name="action" value="orgasmic_fc_embeds_test_webhook" />';
        submit_button('Test-Webhook senden', 'secondary');
        echo '</form></div>';
    }

    private function fmt_time(float $seconds): string
    {
        $seconds = max(0, (int) round($seconds));
        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
