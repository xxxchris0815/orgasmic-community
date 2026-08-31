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
        $uid = get_current_user_id();
        $mine = $this->store->channels_for_user($uid);
        if ($mine['fcm'] < 1) {
            wp_safe_redirect(add_query_arg([
                'page' => 'orgasmic-fc-app',
                'orgasmic_fc_app_test' => 'no_token',
            ], admin_url('admin.php')));
            exit;
        }
        if (!$this->fcm->can_send()) {
            wp_safe_redirect(add_query_arg([
                'page' => 'orgasmic-fc-app',
                'orgasmic_fc_app_test' => 'no_fcm',
            ], admin_url('admin.php')));
            exit;
        }

        $start = (string) get_option(Orgasmic_Fc_App_Install::OPTION_START_URL, '/portal');
        if ($start === '' || $start === '/') {
            $start = '/portal';
        }
        $this->store->enqueue(
            [$uid],
            'system',
            'LO Community',
            'Test-Benachrichtigung',
            home_url($start),
            'test',
            []
        );
        do_action('orgasmic_fc_app_send');
        $last = $this->store->last_queue_for($uid);
        $result = '1';
        if (is_array($last) && empty($last['sent_at']) && !empty($last['last_error'])) {
            $result = 'err';
            set_transient('orgasmic_fc_app_test_err', (string) $last['last_error'], 60);
        }
        wp_safe_redirect(add_query_arg([
            'page' => 'orgasmic-fc-app',
            'orgasmic_fc_app_test' => $result,
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
        echo '<p>PWA fürs Homescreen plus Push für Chat, Beiträge, Kommentare und Events. Mitglieder steuern ihre eigenen Arten über die Glocke im Portal. Store-Apps brauchen Firebase in der APK <em>und</em> das Dienstkonto hier.</p>';

        $test = isset($_GET['orgasmic_fc_app_test']) ? sanitize_key((string) $_GET['orgasmic_fc_app_test']) : '';
        if ($test === '1') {
            echo '<div class="notice notice-success"><p>Test an dein FCM-Gerät gesendet. Handy entsperren, nicht im Flugmodus.</p></div>';
        } elseif ($test === 'no_token') {
            echo '<div class="notice notice-error"><p><strong>Kein App-Token für dein WP-Konto.</strong> Die Debug-APK hat Push absichtlich nicht registriert, solange Firebase fehlt. In Codemagic <code>GOOGLE_SERVICES_JSON</code> (Android, Paket <code>live.lo.community</code>) hinterlegen, Debug-APK neu bauen, einloggen, Benachrichtigungen erlauben. Danach muss unter den Zählern mindestens <strong>1 FCM</strong> stehen.</p></div>';
        } elseif ($test === 'no_fcm') {
            echo '<div class="notice notice-error"><p><strong>Firebase-Dienstkonto fehlt in WordPress.</strong> Firebase → Projekteinstellungen → Dienstkonten → JSON hier unter „FCM Service Account“ einfügen und speichern. Das ist eine andere Datei als <code>google-services.json</code>.</p></div>';
        } elseif ($test === 'err') {
            $err = (string) get_transient('orgasmic_fc_app_test_err');
            delete_transient('orgasmic_fc_app_test_err');
            echo '<div class="notice notice-error"><p>Firebase hat den Test abgelehnt: <code>' . esc_html($err !== '' ? $err : 'unbekannt') . '</code></p></div>';
        }

        $mine = $this->store->channels_for_user(get_current_user_id());
        echo '<p><strong>' . (int) $counts['users'] . '</strong> Mitglieder mit Gerät, '
            . '<strong>' . (int) ($counts['fcm'] ?? 0) . '</strong> FCM (App), '
            . '<strong>' . (int) ($counts['web'] ?? 0) . '</strong> Web/PWA, '
            . '<strong>' . (int) $counts['queued'] . '</strong> in der Queue, '
            . '<strong>' . (int) $counts['sent'] . '</strong> gesendet. '
            . 'Dein Konto: <strong>' . (int) $mine['fcm'] . '</strong> FCM, <strong>' . (int) $mine['web'] . '</strong> Web.</p>';

        echo '<form method="post" action="options.php">';
        settings_fields('orgasmic_fc_app');
        echo '<h2>Push</h2><table class="form-table" role="presentation">';
        $this->checkbox('Push aktiv', Orgasmic_Fc_App_Install::OPTION_ENABLED, 'Benachrichtigungen zustellen');
        $this->checkbox('Chat', Orgasmic_Fc_App_Install::OPTION_CHAT, 'Neue Nachrichten im Space-Chat');
        $this->checkbox('Beiträge', Orgasmic_Fc_App_Install::OPTION_FEED, 'Neue Posts im Space');
        $this->checkbox('Kommentare', Orgasmic_Fc_App_Install::OPTION_COMMENT, 'Antworten und Mentions (nicht der ganze Space)');
        $this->checkbox('Events', Orgasmic_Fc_App_Install::OPTION_EVENT, 'Neue Termine und Reminder an RSVP „dabei“');
        $this->checkbox('Text mitsenden', Orgasmic_Fc_App_Install::OPTION_INCLUDE_BODY, 'Autor plus Nachrichtentext / Beitragstext. Aus: nur Autor und Art (Chat, Beitrag, Kommentar).');
        echo '</table>';

        echo '<h2>PWA</h2><table class="form-table" role="presentation">';
        echo '<tr><th>Start-URL</th><td><input class="regular-text" name="'
            . esc_attr(Orgasmic_Fc_App_Install::OPTION_START_URL) . '" value="'
            . esc_attr((string) get_option(Orgasmic_Fc_App_Install::OPTION_START_URL, '/')) . '" />';
        echo '<p class="description">Für diese Community: <code>/portal</code>.</p></td></tr>';
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

        echo '<h2>Geräte mit App-Push</h2>';
        $devices = $this->store->recent_fcm(20);
        if ($devices === []) {
            echo '<p>Noch kein FCM-Token gespeichert. Die Person muss die Store-/Debug-APK mit Firebase öffnen, sich einloggen und Benachrichtigungen erlauben. Danach erscheint sie hier — nicht nur unter „Dein Konto“.</p>';
        } else {
            echo '<p>Fehlt ein Mitglied, hat das Handy das Token noch nicht an WordPress geschickt (APK ohne Firebase, Login vor der Registrierung, oder Benachrichtigungen abgelehnt).</p>';
            echo '<table class="widefat striped" style="max-width:720px"><thead><tr><th>Mitglied</th><th>E-Mail</th><th>Plattform</th><th>Token zuletzt</th></tr></thead><tbody>';
            foreach ($devices as $row) {
                echo '<tr><td>' . esc_html($row['display']) . ' <code>#' . (int) $row['user_id'] . '</code></td><td>'
                    . esc_html($row['email']) . '</td><td>' . esc_html($row['platform'] !== '' ? $row['platform'] : '—')
                    . '</td><td>' . esc_html($row['updated']) . '</td></tr>';
            }
            echo '</tbody></table>';
        }

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
