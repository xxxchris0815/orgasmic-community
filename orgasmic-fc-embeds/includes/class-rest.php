<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Orgasmic_Fc_Embeds_Rest
{
    private const EVENTS = ['play', 'pause', 'progress', 'ended', 'seeked'];

    public function __construct(
        private Orgasmic_Fc_Embeds_Store $store,
        private Orgasmic_Fc_Embeds_Webhook $webhook,
        private Orgasmic_Fc_Embeds_Bunny $bunny
    ) {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        register_rest_route('orgasmic-embeds/v1', '/watch', [
            'methods' => 'POST',
            'permission_callback' => static fn() => is_user_logged_in(),
            'callback' => [$this, 'watch'],
        ]);

        register_rest_route('orgasmic-embeds/v1', '/upload/create', [
            'methods' => 'POST',
            'permission_callback' => static fn() => is_user_logged_in(),
            'callback' => [$this, 'create_upload'],
        ]);
    }

    public function create_upload(WP_REST_Request $request)
    {
        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = $request->get_params();
        }
        $title = sanitize_text_field((string) ($params['title'] ?? ''));
        $created = $this->bunny->create_upload($title);
        if (is_wp_error($created)) {
            return $created;
        }
        return rest_ensure_response($created);
    }

    public function watch(WP_REST_Request $request)
    {
        $user_id = get_current_user_id();
        $event = sanitize_key((string) $request->get_param('event'));
        if (!in_array($event, self::EVENTS, true)) {
            return new WP_Error('invalid_event', 'Unbekanntes Event.', ['status' => 400]);
        }

        $library = preg_replace('/[^0-9]/', '', (string) $request->get_param('library_id')) ?? '';
        $video = strtolower((string) preg_replace('/[^0-9a-f-]/', '', (string) $request->get_param('video_id')));
        if ($library === '' || strlen($video) < 8) {
            return new WP_Error('invalid_video', 'Video-ID fehlt.', ['status' => 400]);
        }

        $seconds = max(0, (float) $request->get_param('seconds'));
        $duration = max(0, (float) $request->get_param('duration'));
        $max = max($seconds, (float) $request->get_param('max_seconds'));
        $percent = $duration > 0 ? min(100, round(($max / $duration) * 100, 2)) : 0;
        $page = esc_url_raw((string) $request->get_param('page'));
        $occurred_at = gmdate('Y-m-d H:i:s');

        $this->store->insert([
            'occurred_at' => $occurred_at,
            'event' => $event,
            'user_id' => $user_id,
            'library_id' => $library,
            'video_id' => $video,
            'seconds' => $seconds,
            'duration' => $duration,
            'max_seconds' => $max,
            'percent' => $percent,
            'page_url' => $page,
        ]);

        $this->webhook->send($this->payload($event, $user_id, [
            'library_id' => $library,
            'video_id' => $video,
            'seconds' => round($seconds, 2),
            'duration' => round($duration, 2),
            'max_seconds' => round($max, 2),
            'percent' => $percent,
            'page' => $page,
        ], $occurred_at));

        return rest_ensure_response(['ok' => true]);
    }

    private function payload(string $event, int $user_id, array $data, string $occurred_at): array
    {
        $include_pii = (bool) get_option(Orgasmic_Fc_Embeds_Store::OPTION_INCLUDE_PII, 1);
        $user = $user_id ? get_userdata($user_id) : null;
        $payload = [
            'source' => 'orgasmic-fc-embeds',
            'event' => 'video.' . $event,
            'category' => 'video',
            'user_id' => $user_id ?: null,
            'object_type' => 'bunny_video',
            'object_id' => $data['video_id'],
            'data' => $data,
            'site' => home_url(),
            'occurred_at' => gmdate('c', strtotime($occurred_at . ' UTC') ?: time()),
        ];

        if ($user && $include_pii) {
            $payload['user'] = [
                'id' => $user->ID,
                'email' => $user->user_email,
                'display_name' => $user->display_name,
                'login' => $user->user_login,
            ];
        } elseif ($user) {
            $payload['user'] = ['id' => $user->ID];
        }

        return $payload;
    }
}
