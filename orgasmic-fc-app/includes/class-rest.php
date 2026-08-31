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
        private Orgasmic_Fc_App_Fcm $fcm
    ) {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
        add_action('wp_ajax_orgasmic_fc_app_boot', [$this, 'ajax_boot']);
        add_action('wp_ajax_nopriv_orgasmic_fc_app_boot', [$this, 'ajax_boot']);
        add_action('wp_ajax_orgasmic_fc_app_push_token', [$this, 'ajax_token']);
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
            'native' => [
                'capacitorReady' => true,
                'fcmConfigured' => $this->fcm->can_send(),
                'tokenPath' => 'push/token',
            ],
        ]);
    }

    public function ajax_boot(): void
    {
        $uid = get_current_user_id();
        wp_send_json([
            'ok' => true,
            'loggedIn' => $uid > 0,
            'userId' => $uid,
            'nonce' => wp_create_nonce('wp_rest'),
            'prefs' => $uid > 0
                ? Orgasmic_Fc_App_Install::prefs_for($uid)
                : Orgasmic_Fc_App_Install::default_prefs(),
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
