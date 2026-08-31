<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Orgasmic_Fc_App_Admin
{
    public function __construct(
        private Orgasmic_Fc_App_Store $store,
        private Orgasmic_Fc_App_WebPush $push,
        private Orgasmic_Fc_App_Fcm $fcm
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'settings']);
        add_action('admin_post_orgasmic_fc_app_test_push', [$this, 'handle_test']);
        add_action('admin_notices', [$this, 'php_notice']);
    }

    public function menu(): void
    {
        add_menu_page(
            'ORGASMIC App',
            'ORGASMIC App',
            'manage_options',
            'orgasmic-fc-app',
            [$this, 'render'],
            'dashicons-smartphone',
            62
        );
    }

    public function settings(): void
    {
        $bool = 'rest_sanitize_boolean';
        foreach ([
            Orgasmic_Fc_App_Install::OPTION_ENABLED => $bool,
            Orgasmic_Fc_App_Install::OPTION_CHAT => $bool,
            Orgasmic_Fc_App_Install::OPTION_FEED => $bool,
            Orgasmic_Fc_App_Install::OPTION_COMMENT => $bool,
            Orgasmic_Fc_App_Install::OPTION_EVENT => $bool,
            Orgasmic_Fc_App_Install::OPTION_INCLUDE_BODY => $bool,
            Orgasmic_Fc_App_Install::OPTION_VAPID_SUBJECT => 'sanitize_text_field',
            Orgasmic_Fc_App_Install::OPTION_START_URL => 'sanitize_text_field',
            Orgasmic_Fc_App_Install::OPTION_THEME => static function ($value): string {
                return sanitize_hex_color((string) $value) ?: '#121c30';
            },
            Orgasmic_Fc_App_Install::OPTION_FCM_JSON => static function ($value): string {
                $value = is_string($value) ? trim($value) : '';
                if ($value === '') {
                    return (string) get_option(Orgasmic_Fc_App_Install::OPTION_FCM_JSON, '');
                }
                $json = json_decode($value, true);
                if (!is_array($json) || empty($json['private_key']) || empty($json['client_email']) || empty($json['project_id'])) {
                    return (string) get_option(Orgasmic_Fc_App_Install::OPTION_FCM_JSON, '');
                }
                delete_transient('orgasmic_fc_app_fcm_token');
                return $value;
            },
        ] as $option => $cb) {
            register_setting('orgasmic_fc_app', $option, ['sanitize_callback' => $cb]);
        }
    }

    public function php_notice(): void
    {
        if (!current_user_can('manage_options') || $this->push->can_send()) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'toplevel_page_orgasmic-fc-app') {
            return;
        }
        echo '<div class="notice notice-warning"><p>PWA-Cache läuft. Push-Versand braucht <strong>PHP 8.2+</strong> mit OpenSSL (<code>openssl_pkey_derive</code>). Aktuell: '
            . esc_html(PHP_VERSION) . '.</p></div>';
    }

    public function handle_test(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Keine Berechtigung.');
        }
        check_admin_referer('orgasmic_fc_app_test_push');
        $this->store->enqueue(
            [get_current_user_id()],
            'system',
            'ORGASMIC',
            'Test-Benachrichtigung',
            home_url((string) get_option(Orgasmic_Fc_App_Install::OPTION_START_URL, '/')),
            'test',
            []
        );
        do_action('orgasmic_fc_app_send');
        wp_safe_redirect(add_query_arg([
            'page' => 'orgasmic-fc-app',
            'orgasmic_fc_app_test' => '1',
        ], admin_url('admin.php')));
        exit;
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $counts = $this->store->counts();
        echo '<div class="wrap"><h1>ORGASMIC App</h1>';
        echo '<p>PWA fürs Homescreen plus Push für Chat, Beiträge, Kommentare und Events. Mitglieder steuern ihre eigenen Arten über die Glocke im Portal. Dieselbe REST-API nutzt später Capacitor für Play Store / App Store.</p>';

        if (isset($_GET['orgasmic_fc_app_test'])) {
            echo '<div class="notice notice-success"><p>Test wurde in die Queue gelegt. Am Handy/Browser muss Push erlaubt sein.</p></div>';
        }

        echo '<p><strong>' . (int) $counts['users'] . '</strong> Mitglieder mit Gerät, '
            . '<strong>' . (int) $counts['devices'] . '</strong> Subscriptions, '
            . '<strong>' . (int) $counts['queued'] . '</strong> in der Queue, '
            . '<strong>' . (int) $counts['sent'] . '</strong> gesendet.</p>';

        echo '<form method="post" action="options.php">';
        settings_fields('orgasmic_fc_app');
        echo '<h2>Push</h2><table class="form-table" role="presentation">';
        $this->checkbox('Push aktiv', Orgasmic_Fc_App_Install::OPTION_ENABLED, 'Benachrichtigungen zustellen');
        $this->checkbox('Chat', Orgasmic_Fc_App_Install::OPTION_CHAT, 'Neue Nachrichten im Space-Chat');
        $this->checkbox('Beiträge', Orgasmic_Fc_App_Install::OPTION_FEED, 'Neue Posts im Space');
        $this->checkbox('Kommentare', Orgasmic_Fc_App_Install::OPTION_COMMENT, 'Antworten und Mentions (nicht der ganze Space)');
        $this->checkbox('Events', Orgasmic_Fc_App_Install::OPTION_EVENT, 'Neue Termine und Reminder an RSVP „dabei“');
        $this->checkbox('Text mitsenden', Orgasmic_Fc_App_Install::OPTION_INCLUDE_BODY, 'Nachrichtentext / Beitragstext in der Push. Standard aus.');
        echo '</table>';

        echo '<h2>PWA</h2><table class="form-table" role="presentation">';
        echo '<tr><th>Start-URL</th><td><input class="regular-text" name="'
            . esc_attr(Orgasmic_Fc_App_Install::OPTION_START_URL) . '" value="'
            . esc_attr((string) get_option(Orgasmic_Fc_App_Install::OPTION_START_URL, '/')) . '" />';
        echo '<p class="description">Meist <code>/</code>. Wenn das Portal unter einem Pfad liegt, z. B. <code>/community/</code>.</p></td></tr>';
        $theme = (string) get_option(Orgasmic_Fc_App_Install::OPTION_THEME, '#121c30');
        echo '<tr><th>Theme-Farbe</th><td><input type="color" name="'
            . esc_attr(Orgasmic_Fc_App_Install::OPTION_THEME) . '" value="'
            . esc_attr($theme !== '' ? $theme : '#121c30') . '" /></td></tr>';
        echo '<tr><th>VAPID Subject</th><td><input class="regular-text" name="'
            . esc_attr(Orgasmic_Fc_App_Install::OPTION_VAPID_SUBJECT) . '" value="'
            . esc_attr(Orgasmic_Fc_App_Vapid::subject()) . '" />';
        echo '<p class="description"><code>mailto:…</code> für den Push-Dienst (nicht öffentlich in der Notification).</p></td></tr>';
        echo '<tr><th>VAPID Public</th><td><code>' . esc_html(Orgasmic_Fc_App_Vapid::public_key() ?: '— wird beim Aktivieren erzeugt —') . '</code></td></tr>';
        echo '</table>';

        echo '<h2>Capacitor / Firebase</h2><table class="form-table" role="presentation">';
        echo '<tr><th>FCM Service Account</th><td>';
        echo '<textarea class="large-text code" rows="6" name="' . esc_attr(Orgasmic_Fc_App_Install::OPTION_FCM_JSON) . '" placeholder=\'{ "type": "service_account", "project_id": "…" }\'></textarea>';
        echo '<p class="description">JSON aus Firebase → Projekteinstellungen → Dienstkonten. Leer lassen behält den gespeicherten Schlüssel. Aktuell: '
            . ($this->fcm->can_send() ? '<strong>hinterlegt</strong> — Store-Tokens können zugestellt werden.' : '<strong>nicht hinterlegt</strong> — Website-Push läuft weiter, Capacitor-Push wartet.')
            . '</p></td></tr>';
        echo '</table>';
        submit_button();
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('orgasmic_fc_app_test_push');
        echo '<input type="hidden" name="action" value="orgasmic_fc_app_test_push" />';
        submit_button('Test-Push an mich senden', 'secondary');
        echo '</form>';

        echo '<h2>Capacitor (Store-Apps)</h2>';
        echo '<p>Nicht die Community neu bauen. Ein Capacitor-Projekt lädt <code>community.orgasmic.live/portal</code>. Plugins: <code>@capacitor/push-notifications</code>, <code>@capacitor/camera</code>, <code>capacitor-voice-recorder</code>. Die Website schickt das FCM-Token an <code>/wp-json/orgasmic-app/v1/push/token</code>. Chat nutzt Kamera und Mikro der App, falls vorhanden — sonst den Browser. Ohne <code>google-services.json</code> in der APK wird Push nicht registriert (sonst stürzt Android nach dem Login ab).</p>';
        echo '</div>';
    }

    private function checkbox(string $label, string $option, string $help): void
    {
        echo '<tr><th>' . esc_html($label) . '</th><td>';
        echo '<input type="hidden" name="' . esc_attr($option) . '" value="0" />';
        echo '<label><input type="checkbox" name="' . esc_attr($option) . '" value="1" '
            . checked((bool) get_option($option, 1), true, false) . ' /> '
            . esc_html($help) . '</label></td></tr>';
    }
}
