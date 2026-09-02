<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Orgasmic_Fc_App_Rest
{
    public function __construct(
        private Orgasmic_Fc_App_Store $store,
        private Orgasmic_Fc_App_WebPush $push,
        private Orgasmic_Fc_App_Fcm $fcm,
        private Orgasmic_Fc_App_Access $access,
        private Orgasmic_Fc_App_Notify $notify
    ) {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
        add_action('wp_ajax_orgasmic_fc_app_boot', [$this, 'ajax_boot']);
        add_action('wp_ajax_nopriv_orgasmic_fc_app_boot', [$this, 'ajax_boot']);
        add_action('wp_ajax_orgasmic_fc_app_push_token', [$this, 'ajax_token']);
        add_action('wp_ajax_orgasmic_fc_app_device_log', [$this, 'ajax_device_log']);
        add_action('wp_ajax_nopriv_orgasmic_fc_app_device_log', [$this, 'ajax_device_log']);
    }

    public function routes(): void
    {
        $ns = 'orgasmic-app/v1';

        register_rest_route($ns, '/bootstrap', [
            'methods' => 'GET',
            'permission_callback' => static fn() => is_user_logged_in(),
            'callback' => [$this, 'bootstrap'],
        ]);

        register_rest_route($ns, '/push/subscribe', [
            'methods' => 'POST',
            'permission_callback' => static fn() => is_user_logged_in(),
            'callback' => [$this, 'subscribe'],
        ]);

        register_rest_route($ns, '/push/unsubscribe', [
            'methods' => 'POST',
            'permission_callback' => static fn() => is_user_logged_in(),
            'callback' => [$this, 'unsubscribe'],
        ]);

        register_rest_route($ns, '/push/token', [
            'methods' => 'POST',
            'permission_callback' => static fn() => is_user_logged_in(),
            'callback' => [$this, 'token'],
        ]);

        register_rest_route($ns, '/prefs', [
            [
                'methods' => 'GET',
                'permission_callback' => static fn() => is_user_logged_in(),
                'callback' => [$this, 'get_prefs'],
            ],
            [
                'methods' => 'POST',
                'permission_callback' => static fn() => is_user_logged_in(),
                'callback' => [$this, 'save_prefs'],
            ],
        ]);

        register_rest_route($ns, '/account/delete', [
            'methods' => 'POST',
            'permission_callback' => static fn() => is_user_logged_in(),
            'callback' => [$this, 'delete_account'],
        ]);

        register_rest_route($ns, '/announce/intent', [
            'methods' => 'POST',
            'permission_callback' => [$this, 'can_announce'],
            'callback' => [$this, 'announce_intent'],
        ]);

        register_rest_route($ns, '/announce', [
            'methods' => 'POST',
            'permission_callback' => [$this, 'can_announce'],
            'callback' => [$this, 'announce'],
        ]);

        register_rest_route($ns, '/device-log', [
            'methods' => 'POST',
            'permission_callback' => '__return_true',
            'callback' => [$this, 'device_log'],
        ]);

        register_rest_route($ns, '/spaces', [
            'methods' => 'GET',
            'permission_callback' => [$this, 'can_manage_or_key'],
            'callback' => [$this, 'list_spaces'],
        ]);

        register_rest_route($ns, '/members', [
            'methods' => 'POST',
            'permission_callback' => [$this, 'can_manage_or_key'],
            'callback' => [$this, 'create_member'],
        ]);

        register_rest_route($ns, '/members/(?P<id>\d+)/spaces', [
            [
                'methods' => 'GET',
                'permission_callback' => [$this, 'can_manage_or_key'],
                'callback' => [$this, 'member_spaces'],
            ],
            [
                'methods' => 'POST',
                'permission_callback' => [$this, 'can_manage_or_key'],
                'callback' => [$this, 'save_member_spaces'],
            ],
        ]);
    }

    public function can_announce(): bool
    {
        return $this->access->can_announce();
    }

    public function can_manage_or_key(WP_REST_Request $request): bool
    {
        return $this->access->can_manage() || $this->access->valid_api_key($request->get_header('x-orgasmic-key'));
    }

    public function list_spaces(): WP_REST_Response
    {
        return rest_ensure_response(['spaces' => $this->access->all_spaces()]);
    }

    public function member_spaces(WP_REST_Request $request): WP_REST_Response
    {
        $user_id = (int) $request['id'];
        $owned = $this->access->user_space_ids($user_id);
        $roles = $this->access->user_space_roles($user_id);
        $spaces = $this->access->all_spaces();
        foreach ($spaces as &$space) {
            $sid = (int) $space['id'];
            $space['assigned'] = in_array($sid, $owned, true);
            $space['role'] = $roles[$sid] ?? null;
        }
        unset($space);

        return rest_ensure_response([
            'user_id' => $user_id,
            'space_ids' => $owned,
            'roles' => (object) $roles,
            'spaces' => $spaces,
        ]);
    }

    public function save_member_spaces(WP_REST_Request $request)
    {
        $user_id = (int) $request['id'];
        if (!get_userdata($user_id)) {
            return new WP_Error('not_found', 'Mitglied nicht gefunden.', ['status' => 404]);
        }
        $json = $request->get_json_params();
        if (!is_array($json)) {
            $json = [];
        }
        $owned = $this->apply_enroll($user_id, $json, $request);

        return rest_ensure_response([
            'ok' => true,
            'user_id' => $user_id,
            'space_ids' => $owned,
            'roles' => (object) $this->access->user_space_roles($user_id),
        ]);
    }

    public function create_member(WP_REST_Request $request)
    {
        $json = $request->get_json_params();
        if (!is_array($json)) {
            $json = [];
        }
        $result = $this->access->ensure_member($json);
        if (is_wp_error($result)) {
            return $result;
        }
        $user_id = (int) $result['user_id'];
        if (!isset($json['mode'])) {
            $json['mode'] = 'add';
        }
        $owned = $this->apply_enroll($user_id, $json, $request);
        $user = get_userdata($user_id);

        return rest_ensure_response([
            'ok' => true,
            'created' => !empty($result['created']),
            'user_id' => $user_id,
            'email' => $user ? (string) $user->user_email : '',
            'display_name' => $user ? (string) $user->display_name : '',
            'user_login' => $user ? (string) $user->user_login : '',
            'password' => $result['password'],
            'space_ids' => $owned,
            'roles' => (object) $this->access->user_space_roles($user_id),
        ]);
    }

    /**
     * @param array<string, mixed> $json
     * @return list<int>
     */
    private function apply_enroll(int $user_id, array $json, WP_REST_Request $request): array
    {
        $ids = $json['space_ids'] ?? $request->get_param('space_ids');
        $courses = $json['course_ids'] ?? $request->get_param('course_ids');
        $items = $json['spaces'] ?? $json['memberships'] ?? null;
        $mode = sanitize_key((string) ($json['mode'] ?? $request->get_param('mode') ?: 'set'));
        $role = (string) ($json['role'] ?? $request->get_param('role') ?: 'member');
        $roles = $json['roles'] ?? $request->get_param('roles');
        if (!is_array($ids)) {
            $ids = [];
        }
        if (is_array($courses)) {
            $ids = array_merge($ids, $courses);
        }
        if (!is_array($roles)) {
            $roles = [];
        }
        if (is_array($items)) {
            foreach ($items as $item) {
                if (is_numeric($item)) {
                    $ids[] = (int) $item;
                    continue;
                }
                if (!is_array($item)) {
                    continue;
                }
                $sid = (int) ($item['id'] ?? $item['space_id'] ?? 0);
                if ($sid < 1) {
                    continue;
                }
                $ids[] = $sid;
                if (!empty($item['role'])) {
                    $roles[$sid] = (string) $item['role'];
                }
            }
        }
        if ($mode !== 'add') {
            $mode = 'set';
        }
        if ($ids === [] && $mode === 'add') {
            return $this->access->user_space_ids($user_id);
        }

        return $this->access->enroll($user_id, $ids, $mode, $role, $roles);
    }

    public function announce_intent(WP_REST_Request $request)
    {
        $json = $request->get_json_params();
        if (!is_array($json)) {
            $json = [];
        }
        update_user_meta(get_current_user_id(), Orgasmic_Fc_App_Install::META_ANNOUNCE, [
            'push' => !empty($json['push']),
            'email' => !empty($json['email']),
            'at' => time(),
        ]);

        return rest_ensure_response(['ok' => true]);
    }

    public function announce(WP_REST_Request $request)
    {
        $json = $request->get_json_params();
        if (!is_array($json)) {
            $json = [];
        }
        $feed_id = (int) ($json['feed_id'] ?? $request->get_param('feed_id'));
        $feed = $this->access->load_feed($feed_id);
        if (!$feed) {
            return new WP_Error('not_found', 'Beitrag nicht gefunden.', ['status' => 404]);
        }
        $push = !empty($json['push']);
        $email = !empty($json['email']);
        if (!$push && !$email) {
            return new WP_Error('invalid', 'Push oder E-Mail wählen.', ['status' => 400]);
        }

        return rest_ensure_response($this->notify->announce_feed($feed, $push, $email));
    }

    public function bootstrap(): WP_REST_Response
    {
        $start = (string) get_option(Orgasmic_Fc_App_Install::OPTION_START_URL, '/');
        return rest_ensure_response([
            'vapidPublicKey' => Orgasmic_Fc_App_Vapid::public_key(),
            'enabled' => (bool) get_option(Orgasmic_Fc_App_Install::OPTION_ENABLED, 1),
            'canPush' => $this->push->can_send(),
            'startUrl' => $start,
            'theme' => (string) get_option(Orgasmic_Fc_App_Install::OPTION_THEME, '#121c30'),
            'nonce' => wp_create_nonce('wp_rest'),
            'loggedIn' => is_user_logged_in(),
            'userId' => get_current_user_id(),
            'kinds' => [
                'chat' => (bool) get_option(Orgasmic_Fc_App_Install::OPTION_CHAT, 1),
                'feed' => (bool) get_option(Orgasmic_Fc_App_Install::OPTION_FEED, 1),
                'comment' => (bool) get_option(Orgasmic_Fc_App_Install::OPTION_COMMENT, 1),
                'event' => (bool) get_option(Orgasmic_Fc_App_Install::OPTION_EVENT, 1),
            ],
            'prefs' => Orgasmic_Fc_App_Install::prefs_for(get_current_user_id()),
            'canAnnounce' => $this->access->can_announce(),
            'native' => [
                'capacitorReady' => true,
                'fcmConfigured' => $this->fcm->can_send(),
                'tokenPath' => 'push/token',
            ],
        ]);
    }

    public function ajax_device_log(): void
    {
        $raw = (string) ($_POST['payload'] ?? '');
        if ($raw === '') {
            $raw = (string) file_get_contents('php://input');
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            status_header(400);
            wp_send_json(['ok' => false]);
        }
        $saved = $this->ingest_device_log($json);
        if ($saved === 'rate') {
            wp_send_json(['ok' => true, 'skipped' => true]);
        }
        wp_send_json(['ok' => true]);
    }

    public function device_log(WP_REST_Request $request)
    {
        $json = $request->get_json_params();
        if (!is_array($json) || $json === []) {
            $payload = $request->get_param('payload');
            if (is_string($payload)) {
                $decoded = json_decode($payload, true);
                $json = is_array($decoded) ? $decoded : [];
            } elseif (is_array($payload)) {
                $json = $payload;
            } else {
                $json = $request->get_params();
            }
        }
        $saved = $this->ingest_device_log($json);
        if ($saved === 'rate') {
            return rest_ensure_response(['ok' => true, 'skipped' => true]);
        }

        return rest_ensure_response(['ok' => true]);
    }

    /**
     * @param array<string, mixed> $json
     */
    private function ingest_device_log(array $json): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $key = 'oa_dlog_' . md5($ip);
        $n = (int) get_transient($key);
        if ($n >= 8) {
            return 'rate';
        }
        set_transient($key, $n + 1, 120);

        $err = [];
        if (!empty($json['err']) && is_array($json['err'])) {
            foreach (array_slice($json['err'], -12) as $item) {
                $err[] = substr(sanitize_text_field((string) $item), 0, 240);
            }
        }
        $fail = [];
        if (!empty($json['fail']) && is_array($json['fail'])) {
            foreach (array_slice($json['fail'], -12) as $item) {
                $fail[] = substr(sanitize_text_field((string) $item), 0, 180);
            }
        }
        $plugins = [];
        if (!empty($json['plugins']) && is_array($json['plugins'])) {
            foreach (array_slice($json['plugins'], 0, 24) as $item) {
                $plugins[] = sanitize_key((string) $item);
            }
        }

        $this->store->save_device_log([
            'at' => gmdate('Y-m-d H:i:s'),
            'user_id' => get_current_user_id(),
            'v' => sanitize_text_field((string) ($json['v'] ?? '')),
            'href' => esc_url_raw((string) ($json['href'] ?? '')),
            'ua' => substr(sanitize_text_field((string) ($json['ua'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''))), 0, 240),
            'native' => !empty($json['native']),
            'cap' => !empty($json['cap']),
            'platform' => sanitize_key((string) ($json['platform'] ?? '')),
            'plugins' => $plugins,
            'ready' => sanitize_key((string) ($json['ready'] ?? '')),
            'ptr' => sanitize_text_field((string) ($json['ptr'] ?? '')),
            'ptrSkipped' => !empty($json['ptrSkipped']),
            'skel' => max(0, (int) ($json['skel'] ?? 0)),
            'feed' => max(0, (int) ($json['feed'] ?? 0)),
            'load' => max(0, (int) ($json['load'] ?? 0)),
            'text' => substr(sanitize_text_field((string) ($json['text'] ?? '')), 0, 280),
            'fail' => $fail,
            'cookieLen' => max(0, (int) ($json['cookieLen'] ?? 0)),
            'fetchName' => substr(sanitize_text_field((string) ($json['fetchName'] ?? '')), 0, 80),
            'online' => !isset($json['online']) || !empty($json['online']),
            'vis' => sanitize_key((string) ($json['vis'] ?? '')),
            'loggedIn' => !empty($json['loggedIn']),
            'sw' => !empty($json['sw']),
            'reason' => sanitize_key((string) ($json['reason'] ?? 'ping')),
            'ms' => max(0, (int) ($json['ms'] ?? 0)),
            'err' => $err,
        ]);

        return 'ok';
    }

    public function ajax_boot(): void
    {
        $uid = get_current_user_id();
        $user = $uid > 0 ? get_userdata($uid) : null;
        wp_send_json([
            'ok' => true,
            'loggedIn' => $uid > 0,
            'userId' => $uid,
            'displayName' => $user ? (string) $user->display_name : '',
            'nonce' => wp_create_nonce('wp_rest'),
            'prefs' => $uid > 0
                ? Orgasmic_Fc_App_Install::prefs_for($uid)
                : Orgasmic_Fc_App_Install::default_prefs(),
            'canAnnounce' => $uid > 0 && $this->access->can_announce($uid),
            'privacyUrl' => (string) get_privacy_policy_url(),
            'safetyUrl' => home_url('/kinderschutz'),
            'restRoot' => esc_url_raw(rest_url()),
            'ajax' => esc_url_raw(admin_url('admin-ajax.php')),
        ]);
    }

    public function ajax_token(): void
    {
        if (!is_user_logged_in()) {
            status_header(401);
            wp_send_json(['ok' => false, 'message' => 'auth']);
        }
        $token = sanitize_text_field((string) ($_POST['token'] ?? ''));
        $platform = sanitize_key((string) ($_POST['platform'] ?? ''));
        if ($token === '') {
            status_header(400);
            wp_send_json(['ok' => false, 'message' => 'Token fehlt.']);
        }
        $this->store->save_token(get_current_user_id(), $token, $platform);
        wp_send_json(['ok' => true]);
    }

    public function token(WP_REST_Request $request)
    {
        $token = sanitize_text_field((string) $request->get_param('token'));
        $platform = sanitize_key((string) $request->get_param('platform'));
        if ($token === '') {
            return new WP_Error('invalid', 'Token fehlt.', ['status' => 400]);
        }
        $this->store->save_token(get_current_user_id(), $token, $platform);

        return rest_ensure_response(['ok' => true]);
    }

    public function get_prefs(): WP_REST_Response
    {
        return rest_ensure_response([
            'prefs' => Orgasmic_Fc_App_Install::prefs_for(get_current_user_id()),
        ]);
    }

    public function save_prefs(WP_REST_Request $request)
    {
        $json = $request->get_json_params();
        if (!is_array($json)) {
            $json = [];
        }
        $incoming = isset($json['prefs']) && is_array($json['prefs']) ? $json['prefs'] : $json;
        $prefs = Orgasmic_Fc_App_Install::save_prefs(get_current_user_id(), $incoming);

        return rest_ensure_response(['ok' => true, 'prefs' => $prefs]);
    }

    /**
     * Apple 5.1.1(v): account deletion must be available in-app, not only on the website.
     */
    public function delete_account(WP_REST_Request $request)
    {
        $user = wp_get_current_user();
        if (!$user || !$user->ID) {
            return new WP_Error('not_logged_in', 'Nicht angemeldet.', ['status' => 401]);
        }

        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = [];
        }
        $confirm = (string) ($params['confirm'] ?? $request->get_param('confirm') ?? '');
        if ($confirm !== 'DELETE') {
            return new WP_Error(
                'confirm_required',
                'Bitte bestätige die Löschung mit confirm=DELETE.',
                ['status' => 400]
            );
        }

        if (user_can($user, 'manage_options')) {
            return new WP_Error(
                'admin_forbidden',
                'Administrator-Konten können nicht in der App gelöscht werden. Bitte einen anderen Admin kontaktieren.',
                ['status' => 403]
            );
        }

        $user_id = (int) $user->ID;
        $this->store->delete_for_user($user_id);

        if (!function_exists('wp_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }
        $deleted = wp_delete_user($user_id);
        if (!$deleted) {
            return new WP_Error('delete_failed', 'Konto konnte nicht gelöscht werden.', ['status' => 500]);
        }

        wp_logout();
        wp_set_current_user(0);

        return rest_ensure_response([
            'ok' => true,
            'deleted' => true,
        ]);
    }

    public function subscribe(WP_REST_Request $request)
    {
        $endpoint = esc_url_raw((string) $request->get_param('endpoint'));
        $keys = $request->get_param('keys');
        if (!is_array($keys)) {
            $keys = [];
        }
        $p256dh = sanitize_text_field((string) ($keys['p256dh'] ?? $request->get_param('p256dh')));
        $auth = sanitize_text_field((string) ($keys['auth'] ?? $request->get_param('auth')));
        $encoding = sanitize_key((string) $request->get_param('contentEncoding') ?: 'aes128gcm');

        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            return new WP_Error('invalid', 'Subscription unvollständig.', ['status' => 400]);
        }

        $this->store->save_subscription(get_current_user_id(), $endpoint, $p256dh, $auth, $encoding);
        return rest_ensure_response(['ok' => true]);
    }

    public function unsubscribe(WP_REST_Request $request)
    {
        $endpoint = (string) $request->get_param('endpoint');
        $token = sanitize_text_field((string) $request->get_param('token'));
        if ($token !== '') {
            $endpoint = 'fcm:' . $token;
        } else {
            $endpoint = esc_url_raw($endpoint);
        }
        if ($endpoint === '') {
            return new WP_Error('invalid', 'Endpoint fehlt.', ['status' => 400]);
        }
        $this->store->delete_endpoint($endpoint, get_current_user_id());
        return rest_ensure_response(['ok' => true]);
    }
}
