<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Orgasmic_Fc_Events_Admin
{
    public function __construct(
        private Orgasmic_Fc_Events_Access $access,
        private Orgasmic_Fc_Events_Zoom $zoom
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'settings']);
        add_action('admin_post_orgasmic_fc_zoom_test', [$this, 'test_zoom']);
    }

    public function menu(): void
    {
        add_submenu_page(
            'orgasmic-fc-tracker',
            'Kalender / Zoom',
            'Kalender / Zoom',
            'manage_options',
            'orgasmic-fc-calendar',
            [$this, 'render']
        );

        if (!class_exists('Orgasmic_Fc_Tracker')) {
            add_menu_page(
                'ORGAMSIC Kalender',
                'ORGAMSIC Kalender',
                'manage_options',
                'orgasmic-fc-calendar',
                [$this, 'render'],
                'dashicons-calendar-alt',
                59
            );
        }
    }

    public function settings(): void
    {
        foreach ([
            Orgasmic_Fc_Events_Install::OPTION_ZOOM_ACCOUNT => 'sanitize_text_field',
            Orgasmic_Fc_Events_Install::OPTION_ZOOM_CLIENT => 'sanitize_text_field',
            Orgasmic_Fc_Events_Install::OPTION_ZOOM_SECRET => 'sanitize_text_field',
            Orgasmic_Fc_Events_Install::OPTION_API_KEY => 'sanitize_text_field',
            Orgasmic_Fc_Events_Install::OPTION_DEFAULT_TZ => 'sanitize_text_field',
        ] as $option => $cb) {
            register_setting('orgasmic_fc_events', $option, ['sanitize_callback' => $cb]);
        }

        register_setting('orgasmic_fc_events', Orgasmic_Fc_Events_Install::OPTION_DEFAULT_REMINDERS, [
            'sanitize_callback' => static function ($value) {
                if (is_string($value)) {
                    $value = preg_split('/[,\s]+/', $value) ?: [];
                }
                return array_values(array_filter(array_map('intval', (array) $value)));
            },
        ]);
    }

    public function test_zoom(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Keine Berechtigung.');
        }
        check_admin_referer('orgasmic_fc_zoom_test');
        delete_transient('orgasmic_fc_zoom_token');
        delete_transient('orgasmic_fc_zoom_users');
        $users = $this->zoom->list_users();
        $ok = is_array($users) && !isset($users['error']);
        wp_safe_redirect(add_query_arg([
            'page' => 'orgasmic-fc-calendar',
            'zoom_test' => $ok ? '1' : '0',
        ], admin_url('admin.php')));
        exit;
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $reminders = (array) get_option(Orgasmic_Fc_Events_Install::OPTION_DEFAULT_REMINDERS, [1440, 60]);
        echo '<div class="wrap"><h1>ORGAMSIC Kalender</h1>';
        echo '<p>Mitglieder sehen Events nur für ihre Spaces (z. B. Outer Circle, Live Community, Inner Circle). Zoom-Meetings werden per Server-to-Server OAuth auf dem gewählten Sub-Account angelegt.</p>';

        if (isset($_GET['zoom_test'])) {
            echo '<div class="notice notice-' . ($_GET['zoom_test'] === '1' ? 'success' : 'error') . '"><p>';
            echo $_GET['zoom_test'] === '1' ? 'Zoom-Verbindung ok. User-Liste geladen.' : 'Zoom-Test fehlgeschlagen. Credentials und Scopes prüfen.';
            echo '</p></div>';
        }

        echo '<form method="post" action="options.php">';
        settings_fields('orgasmic_fc_events');
        echo '<h2>Zoom Server-to-Server</h2>';
        echo '<p>In Zoom: Marketplace → Develop → Server-to-Server OAuth App. Scopes: <code>user:read:admin</code>, <code>meeting:write:admin</code>, <code>meeting:read:admin</code>, <code>meeting:update:admin</code>, <code>meeting:delete:admin</code>.</p>';
        echo '<table class="form-table">';
        $this->field('Account ID', Orgasmic_Fc_Events_Install::OPTION_ZOOM_ACCOUNT);
        $this->field('Client ID', Orgasmic_Fc_Events_Install::OPTION_ZOOM_CLIENT);
        $this->field('Client Secret', Orgasmic_Fc_Events_Install::OPTION_ZOOM_SECRET, 'password');
        echo '</table>';

        echo '<h2>Allgemein</h2><table class="form-table">';
        $this->field('Standard-Zeitzone', Orgasmic_Fc_Events_Install::OPTION_DEFAULT_TZ, 'text', 'Europe/Berlin');
        echo '<tr><th>Standard-Reminder</th><td><input class="regular-text" name="' . esc_attr(Orgasmic_Fc_Events_Install::OPTION_DEFAULT_REMINDERS) . '" value="' . esc_attr(implode(', ', $reminders)) . '" />';
        echo '<p class="description">Minuten vor Start, kommagetrennt. Beispiel: <code>10080, 1440, 60</code> (1 Woche, 1 Tag, 1 Stunde). Werden als Webhook <code>event.reminder</code> über den Tracker gefeuert.</p></td></tr>';
        $this->field('REST API Key', Orgasmic_Fc_Events_Install::OPTION_API_KEY, 'text', '', 'Header <code>X-Orgasmic-Key</code> für Create/Update/Delete ohne WP-Session (n8n).');
        echo '</table>';
        submit_button('Speichern');
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('orgasmic_fc_zoom_test');
        echo '<input type="hidden" name="action" value="orgasmic_fc_zoom_test" />';
        submit_button('Zoom-Verbindung testen', 'secondary');
        echo '</form>';

        echo '<h2>REST</h2><pre style="background:#fff;padding:12px;border:1px solid #ccd0d4">GET    /wp-json/orgasmic-events/v1/events
POST   /wp-json/orgasmic-events/v1/events
GET    /wp-json/orgasmic-events/v1/events/{id}
PUT    /wp-json/orgasmic-events/v1/events/{id}
DELETE /wp-json/orgasmic-events/v1/events/{id}
POST   /wp-json/orgasmic-events/v1/events/{id}/rsvp
GET    /wp-json/orgasmic-events/v1/zoom/users</pre>';
        echo '<p>Auth: eingeloggtes Mitglied (lesen/RSVP) · Admin oder API-Key (schreiben).</p>';
        echo '</div>';
    }

    private function field(string $label, string $option, string $type = 'text', string $placeholder = '', string $help = ''): void
    {
        $value = (string) get_option($option, '');
        echo '<tr><th>' . esc_html($label) . '</th><td>';
        echo '<input type="' . esc_attr($type) . '" class="regular-text" name="' . esc_attr($option) . '" value="' . esc_attr($value) . '" placeholder="' . esc_attr($placeholder) . '" />';
        if ($help !== '') {
            echo '<p class="description">' . $help . '</p>';
        }
        echo '</td></tr>';
    }
}
