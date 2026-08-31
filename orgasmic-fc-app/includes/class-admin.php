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
        add_action('admin_post_orgasmic_fc_app_enroll', [$this, 'handle_enroll']);
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
        add_submenu_page(
            'orgasmic-fc-app',
            'Einstellungen',
            'Einstellungen',
            'manage_options',
            'orgasmic-fc-app',
            [$this, 'render']
        );
        add_submenu_page(
            'orgasmic-fc-app',
            'Mitglieder',
            'Mitglieder',
            'manage_options',
            'orgasmic-fc-app-members',
            [$this, 'render_members']
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
        $uid = (int) ($_POST['orgasmic_fc_app_user_id'] ?? 0);
        if ($uid < 1) {
            $uid = get_current_user_id();
        }
        $q = [
            'page' => 'orgasmic-fc-app',
            'orgasmic_user' => $uid,
        ];
        if (!get_userdata($uid)) {
            wp_safe_redirect(add_query_arg($q + ['orgasmic_fc_app_test' => 'no_user'], admin_url('admin.php')));
            exit;
        }
        $mine = $this->store->channels_for_user($uid);
        if ($mine['fcm'] < 1 && $mine['web'] < 1) {
            wp_safe_redirect(add_query_arg($q + ['orgasmic_fc_app_test' => 'no_token'], admin_url('admin.php')));
            exit;
        }
        if ($mine['fcm'] > 0 && !$this->fcm->can_send()) {
            wp_safe_redirect(add_query_arg($q + ['orgasmic_fc_app_test' => 'no_fcm'], admin_url('admin.php')));
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
        wp_safe_redirect(add_query_arg($q + ['orgasmic_fc_app_test' => $result], admin_url('admin.php')));
        exit;
    }

    public function handle_enroll(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Keine Berechtigung.');
        }
        check_admin_referer('orgasmic_fc_app_enroll');
        $uid = (int) ($_POST['orgasmic_fc_app_user_id'] ?? 0);
        $ids = array_map('intval', (array) ($_POST['space_ids'] ?? []));
        if ($uid > 0 && get_userdata($uid)) {
            $this->access_for_enroll()->enroll($uid, $ids, 'set');
        }
        wp_safe_redirect(add_query_arg([
            'page' => 'orgasmic-fc-app-members',
            'orgasmic_user' => $uid,
            'orgasmic_fc_app_enroll' => '1',
        ], admin_url('admin.php')));
        exit;
    }

    private function access_for_enroll(): Orgasmic_Fc_App_Access
    {
        return new Orgasmic_Fc_App_Access();
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $counts = $this->store->counts();
        echo '<div class="wrap"><h1>ORGASMIC App</h1>';
        echo '<p>PWA fürs Homescreen plus Push für Chat, Beiträge, Kommentare und Events. Mitglieder steuern ihre eigenen Arten über die Glocke im Portal. Gruppen, Räume und Kurse zuordnen: <a href="'
            . esc_url(admin_url('admin.php?page=orgasmic-fc-app-members')) . '">ORGASMIC App → Mitglieder</a>. Store-Apps brauchen Firebase in der APK <em>und</em> das Dienstkonto hier.</p>';

        $test = isset($_GET['orgasmic_fc_app_test']) ? sanitize_key((string) $_GET['orgasmic_fc_app_test']) : '';
        if ($test === '1') {
            echo '<div class="notice notice-success"><p>Test-Push in die Queue gelegt und sofort zugestellt. Handy entsperren, nicht im Flugmodus. Eigenen Chat/Beitrag bekommt niemand selbst.</p></div>';
        } elseif ($test === 'no_token') {
            echo '<div class="notice notice-error"><p><strong>Kein Gerät für dieses Konto.</strong> Die Person muss die App mit Firebase öffnen, eingeloggt sein und Benachrichtigungen erlauben. Danach erscheint sie unter „Push prüfen“ mit mindestens 1 FCM.</p></div>';
        } elseif ($test === 'no_fcm') {
            echo '<div class="notice notice-error"><p><strong>Firebase-Dienstkonto fehlt in WordPress.</strong> Firebase → Projekteinstellungen → Dienstkonten → JSON hier unter „FCM Service Account“ einfügen und speichern. Das ist eine andere Datei als <code>google-services.json</code>.</p></div>';
        } elseif ($test === 'no_user') {
            echo '<div class="notice notice-error"><p>Mitglied nicht gefunden.</p></div>';
        } elseif ($test === 'err') {
            $err = (string) get_transient('orgasmic_fc_app_test_err');
            delete_transient('orgasmic_fc_app_test_err');
            echo '<div class="notice notice-error"><p>Firebase hat den Test abgelehnt: <code>' . esc_html($err !== '' ? $err : 'unbekannt') . '</code></p></div>';
        }

        $mine = $this->store->channels_for_user(get_current_user_id());
        echo '<p><strong>' . (int) $counts['users'] . '</strong> Mitglieder mit Gerät, '
            . '<strong>' . (int) ($counts['fcm'] ?? 0) . '</strong> FCM (App), '
            . '<strong>' . (int) ($counts['web'] ?? 0) . '</strong> Web/PWA, '
            . '<strong>' . (int) $counts['queued'] . '</strong> Push in der Queue, '
            . '<strong>' . (int) ($counts['mail_queued'] ?? 0) . '</strong> E-Mails in der Queue, '
            . '<strong>' . (int) $counts['sent'] . '</strong> Push gesendet. '
            . 'Dein Konto: <strong>' . (int) $mine['fcm'] . '</strong> FCM, <strong>' . (int) $mine['web'] . '</strong> Web.</p>';

        echo '<form method="post" action="options.php">';
        settings_fields('orgasmic_fc_app');
        echo '<h2>Push</h2><table class="form-table" role="presentation">';
        $this->checkbox('Push aktiv', Orgasmic_Fc_App_Install::OPTION_ENABLED, 'Benachrichtigungen zustellen');
        $this->checkbox('Chat', Orgasmic_Fc_App_Install::OPTION_CHAT, 'Neue Nachrichten im Space-Chat');
        $this->checkbox('Beiträge', Orgasmic_Fc_App_Install::OPTION_FEED, 'Neue Posts im Space');
        $this->checkbox('Kommentare', Orgasmic_Fc_App_Install::OPTION_COMMENT, 'Antworten und Mentions (nicht der ganze Space)');
        $this->checkbox('Events', Orgasmic_Fc_App_Install::OPTION_EVENT, 'Neue Events und Erinnerungen an RSVP „dabei“');
        $this->checkbox('Text mitsenden', Orgasmic_Fc_App_Install::OPTION_INCLUDE_BODY, 'Autor plus Nachrichtentext / Beitragstext. Aus: nur Autor und Art (Chat, Beitrag, Kommentar).');
        echo '</table>';
        echo '<p class="description">Admins sehen im Beitrags-Composer zwei Häkchen: <strong>Push</strong> und <strong>E-Mail an alle Mitglieder</strong>. Empfänger sind nur Leute, die den Beitrag sehen dürfen (Raum bzw. Community — keine geheimen Kreise nach außen). E-Mails gehen über <code>wp_mail</code> in der Minute-Queue.</p>';

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
        echo '<input type="hidden" name="orgasmic_fc_app_user_id" value="' . (int) get_current_user_id() . '" />';
        submit_button('Test-Push an mich senden', 'secondary');
        echo '</form>';

        $this->render_debug();

        echo '<h2>Geräte mit App-Push</h2>';
        $devices = $this->store->recent_fcm(50);
        if ($devices === []) {
            echo '<p>Noch kein FCM-Token gespeichert. Die Person muss die Store-/Debug-APK mit Firebase öffnen, sich einloggen und Benachrichtigungen erlauben. Danach erscheint sie hier — nicht nur unter „Dein Konto“.</p>';
        } else {
            echo '<p>Fehlt ein Mitglied, hat das Handy das Token noch nicht an WordPress geschickt (APK ohne Firebase, Login vor der Registrierung, oder Benachrichtigungen abgelehnt).</p>';
            echo '<table class="widefat striped" style="max-width:720px"><thead><tr><th>Mitglied</th><th>E-Mail</th><th>Plattform</th><th>Token zuletzt</th></tr></thead><tbody>';
            foreach ($devices as $row) {
                $inspect = add_query_arg([
                    'page' => 'orgasmic-fc-app',
                    'orgasmic_user' => (int) $row['user_id'],
                ], admin_url('admin.php'));
                echo '<tr><td><a href="' . esc_url($inspect) . '">' . esc_html($row['display']) . '</a> <code>#' . (int) $row['user_id'] . '</code></td><td>'
                    . esc_html($row['email']) . '</td><td>' . esc_html($row['platform'] !== '' ? $row['platform'] : '—')
                    . '</td><td>' . esc_html($row['updated']) . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        echo '<h2>Capacitor (Store-Apps)</h2>';
        echo '<p>Nicht die Community neu bauen. Ein Capacitor-Projekt lädt <code>community.orgasmic.live/portal</code>. Plugins: <code>@capacitor/push-notifications</code>, <code>@capacitor/camera</code>, <code>capacitor-voice-recorder</code>. Die Website schickt das FCM-Token an <code>/wp-json/orgasmic-app/v1/push/token</code>. Chat nutzt Kamera und Mikro der App, falls vorhanden — sonst den Browser. Ohne <code>google-services.json</code> in der APK wird Push nicht registriert (sonst stürzt Android nach dem Login ab).</p>';
        echo '</div>';
    }

    private function render_debug(): void
    {
        $q = isset($_GET['s']) ? sanitize_text_field(wp_unslash((string) $_GET['s'])) : '';
        $picked = (int) ($_GET['orgasmic_user'] ?? 0);
        echo '<h2>Push prüfen</h2>';
        echo '<p>Mitglied suchen (Name oder E-Mail). Danach siehst du: Gerätetoken, welche Arten sie erlaubt hat, und ob Chat/Beitrag überhaupt in der Queue landete — plus Firebase-Fehler. Zuordnung von Gruppen/Räumen/Kursen liegt unter <a href="'
            . esc_url(admin_url('admin.php?page=orgasmic-fc-app-members')) . '">Mitglieder</a>.</p>';
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '">';
        echo '<input type="hidden" name="page" value="orgasmic-fc-app" />';
        echo '<p><input type="search" class="regular-text" name="s" value="' . esc_attr($q) . '" placeholder="z. B. Alexandra" /> ';
        submit_button('Suchen', 'secondary', '', false);
        echo '</p></form>';

        $matches = [];
        if ($picked > 0 && $q === '') {
            $user = get_userdata($picked);
            if ($user) {
                $matches[] = [
                    'ID' => (int) $user->ID,
                    'display_name' => (string) $user->display_name,
                    'user_email' => (string) $user->user_email,
                    'user_login' => (string) $user->user_login,
                ];
            }
        } elseif ($q !== '') {
            $matches = $this->store->search_members($q, 8);
        }
        if ($q !== '' && $matches === []) {
            echo '<p>Kein Mitglied zu <code>' . esc_html($q) . '</code>.</p>';
            return;
        }
        if ($matches === []) {
            return;
        }

        if (count($matches) > 1 && $picked < 1) {
            echo '<ul>';
            foreach ($matches as $row) {
                $url = add_query_arg([
                    'page' => 'orgasmic-fc-app',
                    'orgasmic_user' => (int) $row['ID'],
                    's' => $q,
                ], admin_url('admin.php'));
                echo '<li><a href="' . esc_url($url) . '">' . esc_html($row['display_name']) . '</a> '
                    . esc_html($row['user_email']) . ' <code>#' . (int) $row['ID'] . '</code></li>';
            }
            echo '</ul>';
            return;
        }

        $member = $matches[0];
        foreach ($matches as $row) {
            if ($picked > 0 && (int) $row['ID'] === $picked) {
                $member = $row;
                break;
            }
        }
        $uid = (int) $member['ID'];
        $channels = $this->store->channels_for_user($uid);
        $prefs = Orgasmic_Fc_App_Install::prefs_for($uid);
        $subs = $this->store->subscriptions_for_users([$uid]);
        $queue = $this->store->queue_for_user($uid, 12);
        $labels = ['chat' => 'Chat', 'feed' => 'Beiträge', 'comment' => 'Kommentare', 'event' => 'Events'];

        echo '<div class="card" style="max-width:760px;padding:12px 16px">';
        echo '<p><strong>' . esc_html($member['display_name']) . '</strong> · '
            . esc_html($member['user_email']) . ' · <code>#' . $uid . '</code></p>';
        echo '<p>Geräte: <strong>' . (int) $channels['fcm'] . '</strong> FCM (App), <strong>'
            . (int) $channels['web'] . '</strong> Web/PWA.</p>';
        if ($channels['fcm'] < 1 && $channels['web'] < 1) {
            echo '<p><strong>Ursache:</strong> WordPress hat kein Token. App öffnen, einloggen, Benachrichtigungen erlauben, App einmal in den Hintergrund und wieder nach vorn. Danach diese Seite neu laden.</p>';
        } elseif ($channels['fcm'] > 0 && !$this->fcm->can_send()) {
            echo '<p><strong>Ursache:</strong> Token ist da, aber das Firebase-Dienstkonto fehlt oben auf dieser Seite.</p>';
        }

        echo '<p>Erlaubte Arten: ';
        $bits = [];
        foreach ($labels as $key => $label) {
            $bits[] = $label . ': ' . (!empty($prefs[$key]) ? 'an' : 'aus');
        }
        echo esc_html(implode(' · ', $bits)) . '. Aus = sie filtert diese Art selbst raus (Profil → Benachrichtigungen).</p>';

        if ($subs !== []) {
            echo '<table class="widefat striped"><thead><tr><th>Kanal</th><th>Plattform</th><th>Token zuletzt</th></tr></thead><tbody>';
            foreach ($subs as $sub) {
                echo '<tr><td>' . esc_html((string) ($sub['channel'] ?? '')) . '</td><td>'
                    . esc_html((string) ($sub['platform'] ?? '—')) . '</td><td>'
                    . esc_html((string) ($sub['updated_at'] ?? '')) . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:12px 0">';
        wp_nonce_field('orgasmic_fc_app_test_push');
        echo '<input type="hidden" name="action" value="orgasmic_fc_app_test_push" />';
        echo '<input type="hidden" name="orgasmic_fc_app_user_id" value="' . $uid . '" />';
        submit_button('Test-Push an ' . $member['display_name'] . ' senden', 'primary', 'submit', false);
        echo '</form>';

        echo '<h3>Letzte Zustellungen</h3>';
        if ($queue === []) {
            echo '<p>Noch nichts in der Queue für dieses Konto. Dann war sie keine Empfängerin: eigene Nachricht, nicht im Raum, oder Art abgeschaltet. Zum Testen muss <em>jemand anderes</em> in denselben Raum schreiben — oder du nutzt den Test-Button oben.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>Art</th><th>Titel</th><th>Versuche</th><th>Gesendet</th><th>Fehler</th></tr></thead><tbody>';
            foreach ($queue as $row) {
                $sent = (string) ($row['sent_at'] ?? '');
                echo '<tr><td>' . esc_html((string) ($row['kind'] ?? '')) . '</td><td>'
                    . esc_html((string) ($row['title'] ?? '')) . '</td><td>'
                    . (int) ($row['attempts'] ?? 0) . '</td><td>'
                    . esc_html($sent !== '' ? $sent : 'offen') . '</td><td>'
                    . esc_html((string) ($row['last_error'] ?? '')) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }

    public function render_members(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $access = $this->access_for_enroll();
        $q = isset($_GET['s']) ? sanitize_text_field(wp_unslash((string) $_GET['s'])) : '';
        $picked = (int) ($_GET['orgasmic_user'] ?? 0);

        echo '<div class="wrap"><h1>Mitglieder</h1>';
        echo '<p>Hier ordnest du einer Person <strong>Gruppen</strong>, <strong>Räume</strong> und <strong>Kurse</strong> zu. Chat hängt am Raum bzw. Kurs — kein extra Häkchen. Speichern ersetzt die komplette Zuordnung dieser Person.</p>';

        if (!empty($_GET['orgasmic_fc_app_enroll'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Zuordnung gespeichert.</p></div>';
        }

        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="margin:12px 0 20px">';
        echo '<input type="hidden" name="page" value="orgasmic-fc-app-members" />';
        echo '<p><input type="search" class="regular-text" name="s" value="' . esc_attr($q) . '" placeholder="Name, E-Mail oder User-ID" /> ';
        submit_button('Suchen', 'secondary', '', false);
        echo '</p></form>';

        if ($picked > 0) {
            $user = get_userdata($picked);
            if (!$user) {
                echo '<p>Mitglied nicht gefunden.</p></div>';
                return;
            }
            $back = add_query_arg(['page' => 'orgasmic-fc-app-members', 's' => $q], admin_url('admin.php'));
            echo '<p><a href="' . esc_url($back) . '">← Alle Mitglieder</a></p>';
            echo '<div class="card" style="max-width:920px;padding:16px 20px">';
            echo '<p><strong>' . esc_html((string) $user->display_name) . '</strong> · '
                . esc_html((string) $user->user_email) . ' · <code>#' . $picked . '</code></p>';
            $this->render_enroll($picked);
            echo '</div></div>';
            return;
        }

        $matches = $access->list_directory($q, $q === '' ? 40 : 20);
        if ($matches === []) {
            echo $q !== ''
                ? '<p>Kein Mitglied zu <code>' . esc_html($q) . '</code>.</p></div>'
                : '<p>Keine Community-Mitglieder gefunden.</p></div>';
            return;
        }

        echo '<table class="widefat striped" style="max-width:860px"><thead><tr><th>Mitglied</th><th>E-Mail</th><th>ID</th><th></th></tr></thead><tbody>';
        foreach ($matches as $row) {
            $url = add_query_arg([
                'page' => 'orgasmic-fc-app-members',
                'orgasmic_user' => (int) $row['ID'],
                's' => $q,
            ], admin_url('admin.php'));
            echo '<tr><td>' . esc_html($row['display_name']) . '</td><td>'
                . esc_html($row['user_email']) . '</td><td><code>#' . (int) $row['ID']
                . '</code></td><td><a class="button button-small" href="' . esc_url($url) . '">Zuordnen</a></td></tr>';
        }
        echo '</tbody></table>';
        if ($q === '') {
            echo '<p class="description">Erste 40 Mitglieder. Über die Suche kommst du an alle Konten.</p>';
        }
        echo '</div>';
    }

    private function render_enroll(int $uid): void
    {
        $access = $this->access_for_enroll();
        $spaces = $access->all_spaces();
        $owned = $access->user_space_ids($uid);
        $by_kind = [
            'group' => [],
            'room' => [],
            'course' => [],
            'other' => [],
        ];
        foreach ($spaces as $space) {
            $kind = (string) ($space['kind'] ?? 'room');
            if (!isset($by_kind[$kind])) {
                $kind = 'other';
            }
            $by_kind[$kind][] = $space;
        }

        echo '<p class="description">Gruppe = Ordner in der Sidebar. Räume und Kurse extra setzen — der Chat gehört zum jeweiligen Raum oder Kurs. API: <code>POST /wp-json/orgasmic-app/v1/members/'
            . $uid . '/spaces</code>, Header <code>X-Orgasmic-Key</code> (Kalender-Schlüssel), JSON <code>{"space_ids":[1,2],"mode":"set"}</code>.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('orgasmic_fc_app_enroll');
        echo '<input type="hidden" name="action" value="orgasmic_fc_app_enroll" />';
        echo '<input type="hidden" name="orgasmic_fc_app_user_id" value="' . $uid . '" />';

        $this->render_space_checks('Gruppen', $by_kind['group'], $owned, 'group');
        $this->render_room_checks($by_kind['room'], $by_kind['group'], $owned);
        $this->render_space_checks('Kurse', $by_kind['course'], $owned, 'course');
        $this->render_space_checks('Weitere', $by_kind['other'], $owned, 'other');

        if ($spaces === []) {
            echo '<p>Keine FluentCommunity-Spaces gefunden.</p>';
        }
        submit_button('Zuordnung speichern');
        echo '</form>';
        echo '<script>
        document.querySelectorAll("[data-oc-toggle]").forEach(function (btn) {
          btn.addEventListener("click", function () {
            var sel = btn.getAttribute("data-oc-toggle");
            var on = btn.getAttribute("data-oc-on") === "1";
            document.querySelectorAll(sel).forEach(function (el) { el.checked = on; });
          });
        });
        </script>';
    }

    /**
     * @param list<array{id:int,title:string,kind:string,parent_id?:int}> $items
     * @param list<int> $owned
     */
    private function render_space_checks(string $label, array $items, array $owned, string $kind): void
    {
        if ($items === []) {
            return;
        }
        $sel = 'input[name="space_ids[]"][data-kind="' . esc_attr($kind) . '"]';
        echo '<h2>' . esc_html($label) . ' · '
            . '<button type="button" class="button-link" data-oc-toggle="' . esc_attr($sel) . '" data-oc-on="1">alle</button> · '
            . '<button type="button" class="button-link" data-oc-toggle="' . esc_attr($sel) . '" data-oc-on="0">keine</button></h2>';
        echo '<div style="display:flex;flex-wrap:wrap;gap:8px 16px;margin:0 0 16px">';
        foreach ($items as $space) {
            $this->space_checkbox($space, $owned, 0, $kind);
        }
        echo '</div>';
    }

    /**
     * @param list<array{id:int,title:string,kind:string,parent_id?:int}> $rooms
     * @param list<array{id:int,title:string}> $groups
     * @param list<int> $owned
     */
    private function render_room_checks(array $rooms, array $groups, array $owned): void
    {
        if ($rooms === []) {
            return;
        }
        $group_ids = [];
        foreach ($groups as $group) {
            $group_ids[(int) $group['id']] = (string) $group['title'];
        }
        $nested = [];
        $loose = [];
        foreach ($rooms as $room) {
            $parent = (int) ($room['parent_id'] ?? 0);
            if ($parent > 0 && isset($group_ids[$parent])) {
                $nested[$parent][] = $room;
            } else {
                $loose[] = $room;
            }
        }

        echo '<h2>Räume <span style="font-weight:400;font-size:13px">(inkl. Chat)</span></h2>';
        foreach ($group_ids as $gid => $gtitle) {
            $chunk = $nested[$gid] ?? [];
            if ($chunk === []) {
                continue;
            }
            $sel = 'input[name="space_ids[]"][data-parent="' . (int) $gid . '"]';
            echo '<p style="margin:12px 0 6px"><strong>' . esc_html($gtitle) . '</strong> · '
                . '<button type="button" class="button-link" data-oc-toggle="' . esc_attr($sel) . '" data-oc-on="1">alle</button> · '
                . '<button type="button" class="button-link" data-oc-toggle="' . esc_attr($sel) . '" data-oc-on="0">keine</button></p>';
            echo '<div style="display:flex;flex-wrap:wrap;gap:8px 16px;margin:0 0 12px">';
            foreach ($chunk as $space) {
                $this->space_checkbox($space, $owned, $gid, 'room');
            }
            echo '</div>';
        }
        if ($loose !== []) {
            if ($nested !== []) {
                echo '<p style="margin:12px 0 6px"><strong>Ohne Gruppe</strong></p>';
            }
            echo '<div style="display:flex;flex-wrap:wrap;gap:8px 16px;margin:0 0 16px">';
            foreach ($loose as $space) {
                $this->space_checkbox($space, $owned, 0, 'room');
            }
            echo '</div>';
        }
    }

    /**
     * @param array{id:int,title:string} $space
     * @param list<int> $owned
     */
    private function space_checkbox(array $space, array $owned, int $parent = 0, string $kind = 'room'): void
    {
        $id = (int) $space['id'];
        echo '<label style="min-width:200px"><input type="checkbox" name="space_ids[]" value="' . $id . '"'
            . ' data-kind="' . esc_attr($kind) . '"'
            . ($parent > 0 ? ' data-parent="' . $parent . '"' : '')
            . ' ' . checked(in_array($id, $owned, true), true, false) . ' /> '
            . esc_html((string) $space['title']) . '</label>';
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
