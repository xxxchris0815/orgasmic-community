<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Orgasmic_Fc_Chat_Admin
{
    public function __construct(
        private Orgasmic_Fc_Chat_Repository $repo,
        private Orgasmic_Fc_Chat_Webhook $webhook
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'settings']);
        add_action('admin_post_orgasmic_fc_chat_test_webhook', [$this, 'handle_test']);
    }

    public function menu(): void
    {
        add_menu_page(
            'ORGASMIC Chat',
            'ORGASMIC Chat',
            'manage_options',
            'orgasmic-fc-chat',
            [$this, 'render_log'],
            'dashicons-format-chat',
            61
        );
        add_submenu_page(
            'orgasmic-fc-chat',
            'Nachrichten',
            'Nachrichten',
            'manage_options',
            'orgasmic-fc-chat',
            [$this, 'render_log']
        );
        add_submenu_page(
            'orgasmic-fc-chat',
            'Einstellungen',
            'Einstellungen',
            'manage_options',
            'orgasmic-fc-chat-settings',
            [$this, 'render_settings']
        );
    }

    public function settings(): void
    {
        register_setting('orgasmic_fc_chat', Orgasmic_Fc_Chat_Install::OPTION_WEBHOOK_URL, [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
        ]);
        register_setting('orgasmic_fc_chat', Orgasmic_Fc_Chat_Install::OPTION_WEBHOOK_SECRET, [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('orgasmic_fc_chat', Orgasmic_Fc_Chat_Install::OPTION_INCLUDE_BODY, [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
        ]);
        register_setting('orgasmic_fc_chat', Orgasmic_Fc_Chat_Install::OPTION_INCLUDE_PII, [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
        ]);
        register_setting('orgasmic_fc_chat', Orgasmic_Fc_Chat_Install::OPTION_POLL_SECONDS, [
            'type' => 'integer',
            'sanitize_callback' => static function ($value): int {
                $value = absint($value);
                return min(30, max(3, $value ?: 6));
            },
        ]);
        register_setting('orgasmic_fc_chat', Orgasmic_Fc_Chat_Install::OPTION_MAX_LENGTH, [
            'type' => 'integer',
            'sanitize_callback' => static function ($value): int {
                $value = absint($value);
                return min(8000, max(200, $value ?: 2000));
            },
        ]);
        register_setting('orgasmic_fc_chat', Orgasmic_Fc_Chat_Install::OPTION_SUBTITLE, [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
        ]);
        register_setting('orgasmic_fc_chat', Orgasmic_Fc_Chat_Install::OPTION_APPEARANCE, [
            'type' => 'string',
            'sanitize_callback' => static function ($value): string {
                $value = sanitize_text_field((string) $value);
                return in_array($value, ['auto', 'light', 'dark'], true) ? $value : 'auto';
            },
        ]);
        register_setting('orgasmic_fc_chat', Orgasmic_Fc_Chat_Install::OPTION_ACCENT, [
            'type' => 'string',
            'sanitize_callback' => static function ($value): string {
                return sanitize_hex_color((string) $value) ?: '';
            },
        ]);
    }

    public function handle_test(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Keine Berechtigung.');
        }
        check_admin_referer('orgasmic_fc_chat_test_webhook');
        $result = $this->webhook->send_test();
        wp_safe_redirect(add_query_arg([
            'page' => 'orgasmic-fc-chat-settings',
            'orgasmic_fc_chat_test' => $result['ok'] ? '1' : '0',
        ], admin_url('admin.php')));
        exit;
    }

    public function render_log(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        echo '<div class="wrap"><h1>ORGASMIC Chat — Nachrichten</h1>';
        echo '<p>Letzte Nachrichten über alle Spaces. Geheime Kreise bleiben im Portal trotzdem nur für Mitglieder sichtbar.</p>';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>Zeit (UTC)</th><th>Space</th><th>Mitglied</th><th>Nachricht</th><th>Status</th>';
        echo '</tr></thead><tbody>';

        $rows = $this->repo->recent(80);
        if ($rows === []) {
            echo '<tr><td colspan="5">Noch keine Nachrichten.</td></tr>';
        }
        foreach ($rows as $row) {
            $user = !empty($row['user_id']) ? get_userdata((int) $row['user_id']) : null;
            $preview = (string) $row['body'];
            if ($preview === '' && !empty($row['attachment'])) {
                $preview = '📷 Bild';
            }
            if (function_exists('mb_substr')) {
                $preview = mb_substr($preview, 0, 160);
            } else {
                $preview = substr($preview, 0, 160);
            }
            echo '<tr>';
            echo '<td>' . esc_html((string) $row['created_at']) . '</td>';
            echo '<td>#' . esc_html((string) $row['space_id']) . '</td>';
            echo '<td>' . esc_html($user ? $user->display_name : '—') . '</td>';
            echo '<td>' . esc_html($preview) . '</td>';
            echo '<td>' . (!empty($row['deleted']) ? 'gelöscht' : 'aktiv') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    public function render_settings(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        echo '<div class="wrap"><h1>ORGASMIC Chat — Einstellungen</h1>';
        if (isset($_GET['orgasmic_fc_chat_test'])) {
            $ok = $_GET['orgasmic_fc_chat_test'] === '1';
            echo '<div class="notice notice-' . ($ok ? 'success' : 'error') . '"><p>';
            echo $ok ? 'Test-Webhook wurde ausgelöst.' : 'Test-Webhook fehlgeschlagen. URL prüfen.';
            echo '</p></div>';
        }

        $settings = Orgasmic_Fc_Chat_Install::portal_settings();
        echo '<form method="post" action="options.php">';
        settings_fields('orgasmic_fc_chat');
        echo '<h2>Portal</h2><table class="form-table" role="presentation">';
        echo '<tr><th>Untertitel</th><td>';
        echo '<textarea class="large-text" rows="2" name="' . esc_attr(Orgasmic_Fc_Chat_Install::OPTION_SUBTITLE) . '">';
        echo esc_textarea($settings['subtitle']);
        echo '</textarea></td></tr>';

        $appearance = $settings['appearance'];
        echo '<tr><th>Erscheinungsbild</th><td><select name="' . esc_attr(Orgasmic_Fc_Chat_Install::OPTION_APPEARANCE) . '">';
        foreach ([
            'auto' => 'Wie FluentCommunity (empfohlen)',
            'light' => 'Hell',
            'dark' => 'Dunkel',
        ] as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($appearance, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></td></tr>';

        $accent = $settings['accent'] !== '' ? $settings['accent'] : '#409eff';
        echo '<tr><th>Akzentfarbe</th><td>';
        echo '<input type="color" name="' . esc_attr(Orgasmic_Fc_Chat_Install::OPTION_ACCENT) . '" value="' . esc_attr($accent) . '" /></td></tr>';

        echo '<tr><th>Polling (Sekunden)</th><td><input type="number" min="3" max="30" name="'
            . esc_attr(Orgasmic_Fc_Chat_Install::OPTION_POLL_SECONDS) . '" value="'
            . esc_attr((string) $settings['poll_seconds']) . '" />';
        echo '<p class="description">Wie oft das Portal nach neuen Nachrichten fragt. Für die App dieselbe REST-API.</p></td></tr>';

        echo '<tr><th>Max. Textlänge</th><td><input type="number" min="200" max="8000" name="'
            . esc_attr(Orgasmic_Fc_Chat_Install::OPTION_MAX_LENGTH) . '" value="'
            . esc_attr((string) $settings['max_length']) . '" /></td></tr>';
        echo '</table>';

        echo '<h2>Webhook</h2><table class="form-table" role="presentation">';
        echo '<tr><th>Webhook-URL</th><td><input type="url" class="regular-text" name="'
            . esc_attr(Orgasmic_Fc_Chat_Install::OPTION_WEBHOOK_URL) . '" value="'
            . esc_attr((string) get_option(Orgasmic_Fc_Chat_Install::OPTION_WEBHOOK_URL, '')) . '" />';
        echo '<p class="description">Events: <code>chat.message</code>, <code>chat.read</code>, <code>chat.test</code>.</p></td></tr>';

        echo '<tr><th>Webhook-Secret</th><td><input type="text" class="regular-text" name="'
            . esc_attr(Orgasmic_Fc_Chat_Install::OPTION_WEBHOOK_SECRET) . '" value="'
            . esc_attr((string) get_option(Orgasmic_Fc_Chat_Install::OPTION_WEBHOOK_SECRET, '')) . '" />';
        echo '<p class="description">Optional. HMAC-SHA256 in <code>X-Orgasmic-Signature</code>.</p></td></tr>';

        echo '<tr><th>Nachrichtentext</th><td>';
        echo '<input type="hidden" name="' . esc_attr(Orgasmic_Fc_Chat_Install::OPTION_INCLUDE_BODY) . '" value="0" />';
        echo '<label><input type="checkbox" name="' . esc_attr(Orgasmic_Fc_Chat_Install::OPTION_INCLUDE_BODY) . '" value="1" '
            . checked((bool) get_option(Orgasmic_Fc_Chat_Install::OPTION_INCLUDE_BODY, 0), true, false)
            . ' /> Nachrichtentext im Webhook mitschicken</label>';
        echo '<p class="description">Standard aus — nur Metadaten (Space, User, Message-ID).</p></td></tr>';

        echo '<tr><th>Personenbezogene Daten</th><td>';
        echo '<input type="hidden" name="' . esc_attr(Orgasmic_Fc_Chat_Install::OPTION_INCLUDE_PII) . '" value="0" />';
        echo '<label><input type="checkbox" name="' . esc_attr(Orgasmic_Fc_Chat_Install::OPTION_INCLUDE_PII) . '" value="1" '
            . checked((bool) get_option(Orgasmic_Fc_Chat_Install::OPTION_INCLUDE_PII, 1), true, false)
            . ' /> Name und E-Mail im Webhook mitschicken</label></td></tr>';
        echo '</table>';
        submit_button();
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('orgasmic_fc_chat_test_webhook');
        echo '<input type="hidden" name="action" value="orgasmic_fc_chat_test_webhook" />';
        submit_button('Test-Webhook senden', 'secondary');
        echo '</form></div>';
    }
}
