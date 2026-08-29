<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Orgasmic_Fc_App_Rest
{
    public function __construct(
        private Orgasmic_Fc_App_Store $store,
        private Orgasmic_Fc_App_WebPush $push
    ) {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
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
            'kinds' => [
                'chat' => (bool) get_option(Orgasmic_Fc_App_Install::OPTION_CHAT, 1),
                'feed' => (bool) get_option(Orgasmic_Fc_App_Install::OPTION_FEED, 1),
                'comment' => (bool) get_option(Orgasmic_Fc_App_Install::OPTION_COMMENT, 1),
                'event' => (bool) get_option(Orgasmic_Fc_App_Install::OPTION_EVENT, 1),
            ],
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
        $endpoint = esc_url_raw((string) $request->get_param('endpoint'));
        if ($endpoint === '') {
            return new WP_Error('invalid', 'Endpoint fehlt.', ['status' => 400]);
        }
        $this->store->delete_endpoint($endpoint, get_current_user_id());
        return rest_ensure_response(['ok' => true]);
    }
}
