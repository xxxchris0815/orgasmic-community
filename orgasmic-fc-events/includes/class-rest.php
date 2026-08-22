<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Orgasmic_Fc_Events_Rest
{
    public function __construct(
        private Orgasmic_Fc_Events_Access $access,
        private Orgasmic_Fc_Events_Repository $repo,
        private Orgasmic_Fc_Events_Zoom $zoom,
        private Orgasmic_Fc_Events_Feed $feed
    ) {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        $ns = 'orgasmic-events/v1';

        register_rest_route($ns, '/bootstrap', [
            'methods' => 'GET',
            'permission_callback' => [$this, 'can_read'],
            'callback' => [$this, 'bootstrap'],
        ]);

        register_rest_route($ns, '/events', [
            [
                'methods' => 'GET',
                'permission_callback' => [$this, 'can_read'],
                'callback' => [$this, 'list_events'],
            ],
            [
                'methods' => 'POST',
                'permission_callback' => [$this, 'can_write'],
                'callback' => [$this, 'create_event'],
            ],
        ]);

        register_rest_route($ns, '/events/(?P<id>\d+)', [
            [
                'methods' => 'GET',
                'permission_callback' => [$this, 'can_read'],
                'callback' => [$this, 'get_event'],
            ],
            [
                'methods' => 'PUT,PATCH',
                'permission_callback' => [$this, 'can_write'],
                'callback' => [$this, 'update_event'],
            ],
            [
                'methods' => 'DELETE',
                'permission_callback' => [$this, 'can_write'],
                'callback' => [$this, 'delete_event'],
            ],
        ]);

        register_rest_route($ns, '/events/(?P<id>\d+)/rsvp', [
            'methods' => 'POST',
            'permission_callback' => [$this, 'can_read'],
            'callback' => [$this, 'rsvp'],
        ]);

        register_rest_route($ns, '/events/(?P<id>\d+)/image', [
            'methods' => 'POST',
            'permission_callback' => [$this, 'can_write'],
            'callback' => [$this, 'upload_image'],
        ]);

        register_rest_route($ns, '/zoom/users', [
            'methods' => 'GET',
            'permission_callback' => [$this, 'can_write'],
            'callback' => [$this, 'zoom_users'],
        ]);
    }

    public function can_read(WP_REST_Request $request): bool
    {
        return $this->access->valid_api_key($request->get_header('x-orgasmic-key')) || is_user_logged_in();
    }

    public function can_write(WP_REST_Request $request): bool
    {
        return $this->access->valid_api_key($request->get_header('x-orgasmic-key')) || $this->access->can_manage();
    }

    public function bootstrap(WP_REST_Request $request): WP_REST_Response
    {
        $user_id = get_current_user_id();
        return rest_ensure_response([
            'can_manage' => $this->can_write($request),
            'timezone' => (string) get_option(Orgasmic_Fc_Events_Install::OPTION_DEFAULT_TZ, wp_timezone_string()),
            'spaces' => $this->access->can_manage($user_id) || $this->can_write($request)
                ? $this->access->list_spaces()
                : $this->access->space_titles($this->access->user_space_ids($user_id)),
            'my_space_ids' => $this->access->user_space_ids($user_id),
            'zoom_configured' => $this->zoom->configured(),
            'default_reminders' => get_option(Orgasmic_Fc_Events_Install::OPTION_DEFAULT_REMINDERS, [1440, 60]),
            'portal' => Orgasmic_Fc_Events_Install::portal_settings(),
            'user' => [
                'id' => $user_id,
                'display_name' => wp_get_current_user()->display_name,
            ],
        ]);
    }

    public function list_events(WP_REST_Request $request): WP_REST_Response
    {
        $user_id = get_current_user_id();
        $is_manager = $this->can_write($request);
        $rows = $this->repo->query_visible(
            $this->access->user_space_ids($user_id),
            $is_manager,
            [
                'from' => $request->get_param('from'),
                'to' => $request->get_param('to'),
                'limit' => $request->get_param('limit'),
            ]
        );

        $items = [];
        foreach ($rows as $row) {
            if (!$is_manager && !$this->access->can_view_event($row, $user_id)) {
                continue;
            }
            $items[] = $this->present($row, $user_id, $is_manager, false);
        }

        return rest_ensure_response(['items' => $items]);
    }

    public function get_event(WP_REST_Request $request)
    {
        $event = $this->repo->find((int) $request['id']);
        if (!$event) {
            return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        }

        $user_id = get_current_user_id();
        $is_manager = $this->can_write($request);
        if (!$is_manager && !$this->access->can_view_event($event, $user_id)) {
            return new WP_Error('forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
        }

        do_action('orgasmic_fc/event/viewed', $event, $user_id);

        return rest_ensure_response($this->present($event, $user_id, $is_manager, true));
    }

    public function create_event(WP_REST_Request $request)
    {
        $payload = $this->payload_from_request($request);
        if (empty($payload['title']) || empty($payload['starts_at'])) {
            return new WP_Error('invalid', 'Titel und Startzeit sind Pflicht.', ['status' => 400]);
        }

        $payload = $this->maybe_sync_zoom($payload, true);
        if (is_wp_error($payload)) {
            return $payload;
        }

        $id = $this->repo->create($payload);
        $event = $this->repo->find($id);
        if (!$event) {
            return new WP_Error('create_failed', 'Event konnte nicht gespeichert werden.', ['status' => 500]);
        }

        if (!empty($payload['share_to_feed'])) {
            $shared = $this->feed->share($event, $this->access);
            if ($shared['ids'] !== []) {
                $this->repo->update($id, ['feed_ids' => $shared['ids'], 'share_to_feed' => 1]);
                $event = $this->repo->find($id) ?: $event;
            }
            $presented = $this->present($event, get_current_user_id(), true, true);
            $presented['feed_share_error'] = $shared['error'];
            do_action('orgasmic_fc/event/created', $event, get_current_user_id());
            return rest_ensure_response($presented);
        }

        do_action('orgasmic_fc/event/created', $event, get_current_user_id());
        return rest_ensure_response($this->present($event, get_current_user_id(), true, true));
    }

    public function update_event(WP_REST_Request $request)
    {
        $existing = $this->repo->find((int) $request['id']);
        if (!$existing) {
            return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        }

        $payload = array_merge($existing, $this->payload_from_request($request, $existing));
        $payload = $this->maybe_sync_zoom($payload, false);
        if (is_wp_error($payload)) {
            return $payload;
        }

        $this->repo->update((int) $existing['id'], $payload);
        $event = $this->repo->find((int) $existing['id']);

        $feed_error = '';
        if (!empty($payload['share_to_feed'])) {
            $existing_feeds = $this->access->decode_ids($event['feed_ids'] ?? '[]');
            if ($existing_feeds === []) {
                $shared = $this->feed->share($event, $this->access);
                $feed_error = $shared['error'];
                if ($shared['ids'] !== []) {
                    $this->repo->update((int) $event['id'], ['feed_ids' => $shared['ids'], 'share_to_feed' => 1]);
                    $event = $this->repo->find((int) $event['id']) ?: $event;
                }
            }
        }

        do_action('orgasmic_fc/event/updated', $event, get_current_user_id());
        $presented = $this->present($event, get_current_user_id(), true, true);
        $presented['feed_share_error'] = $feed_error;
        return rest_ensure_response($presented);
    }

    public function delete_event(WP_REST_Request $request)
    {
        $event = $this->repo->find((int) $request['id']);
        if (!$event) {
            return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        }

        if (!empty($event['zoom_meeting_id'])) {
            $this->zoom->delete_meeting((string) $event['zoom_meeting_id']);
        }

        $this->repo->delete((int) $event['id']);
        do_action('orgasmic_fc/event/deleted', $event, get_current_user_id());
        return rest_ensure_response(['deleted' => true, 'id' => (int) $event['id']]);
    }

    public function rsvp(WP_REST_Request $request)
    {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return new WP_Error('auth', 'Bitte einloggen.', ['status' => 401]);
        }

        $event = $this->repo->find((int) $request['id']);
        if (!$event || !$this->access->can_view_event($event, $user_id)) {
            return new WP_Error('forbidden', 'Kein Zugriff.', ['status' => 403]);
        }
        if (empty($event['rsvp_enabled'])) {
            return new WP_Error('rsvp', 'RSVP ist für dieses Event deaktiviert.', ['status' => 400]);
        }

        $status = sanitize_key((string) $request->get_param('status'));
        if (!in_array($status, ['going', 'maybe', 'declined'], true)) {
            return new WP_Error('invalid', 'status muss going, maybe oder declined sein.', ['status' => 400]);
        }

        if ($status === 'going' && !empty($event['rsvp_capacity'])) {
            $counts = $this->repo->rsvp_counts((int) $event['id']);
            $mine = $this->repo->my_rsvp((int) $event['id'], $user_id);
            if ($mine !== 'going' && $counts['going'] >= (int) $event['rsvp_capacity']) {
                return new WP_Error('capacity', 'Dieses Event ist voll.', ['status' => 409]);
            }
        }

        $result = $this->repo->set_rsvp((int) $event['id'], $user_id, $status);
        do_action('orgasmic_fc/event/rsvp', $event, $user_id, $status, $result['previous']);

        return rest_ensure_response($this->present($this->repo->find((int) $event['id']), $user_id, $this->access->can_manage($user_id), true));
    }

    public function upload_image(WP_REST_Request $request)
    {
        $event = $this->repo->find((int) $request['id']);
        if (!$event) {
            return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        if (empty($_FILES['image'])) {
            return new WP_Error('file', 'Bitte ein Bild als Feld "image" senden.', ['status' => 400]);
        }

        $id = media_handle_upload('image', 0);
        if (is_wp_error($id)) {
            return $id;
        }

        $this->repo->update((int) $event['id'], ['image_id' => (int) $id]);
        return rest_ensure_response($this->present($this->repo->find((int) $event['id']), get_current_user_id(), true, true));
    }

    public function zoom_users()
    {
        if (!$this->zoom->configured()) {
            return new WP_Error('zoom', 'Zoom ist nicht konfiguriert.', ['status' => 400]);
        }
        $users = $this->zoom->list_users();
        if (isset($users['error'])) {
            return new WP_Error('zoom', (string) $users['error'], ['status' => 502]);
        }
        return rest_ensure_response(['items' => $users]);
    }

    private function payload_from_request(WP_REST_Request $request, array $existing = []): array
    {
        $params = $request->get_json_params();
        if (!is_array($params) || $params === []) {
            $params = $request->get_params();
        }

        $keys = [
            'title', 'description', 'image_id', 'starts_at', 'ends_at', 'timezone', 'status',
            'visibility', 'space_ids', 'rsvp_enabled', 'rsvp_capacity', 'location_type',
            'zoom_user_email', 'zoom_join_url', 'external_url', 'share_to_feed',
            'create_zoom', 'reminder_minutes', 'created_by',
        ];

        $out = $existing;
        foreach ($keys as $key) {
            if (array_key_exists($key, $params)) {
                $out[$key] = $params[$key];
            }
        }

        if (empty($out['created_by'])) {
            $out['created_by'] = get_current_user_id();
        }

        return $out;
    }

    private function maybe_sync_zoom(array $payload, bool $is_create): array|WP_Error
    {
        $create_zoom = !empty($payload['create_zoom']) || (($payload['location_type'] ?? '') === 'zoom' && !empty($payload['zoom_user_email']) && $is_create);
        $update_zoom = !$is_create && ($payload['location_type'] ?? '') === 'zoom' && !empty($payload['zoom_user_email']);

        if (!$create_zoom && !$update_zoom) {
            return $payload;
        }
        if (($payload['location_type'] ?? '') !== 'zoom') {
            return $payload;
        }
        if (!$this->zoom->configured()) {
            return $payload;
        }

        $result = $is_create || empty($payload['zoom_meeting_id'])
            ? $this->zoom->create_meeting($payload)
            : $this->zoom->update_meeting($payload);

        if (is_wp_error($result)) {
            return $result;
        }

        return array_merge($payload, $result);
    }

    private function present(array $event, int $user_id, bool $is_manager, bool $detail): array
    {
        $space_ids = $this->access->decode_ids($event['space_ids'] ?? '[]');
        $counts = $this->repo->rsvp_counts((int) $event['id']);
        $mine = $user_id ? $this->repo->my_rsvp((int) $event['id'], $user_id) : null;
        $can_see_link = $is_manager || !$event['rsvp_enabled'] || $mine === 'going';

        $item = [
            'id' => (int) $event['id'],
            'title' => $event['title'],
            'slug' => $event['slug'],
            'starts_at' => $this->iso((string) $event['starts_at']),
            'ends_at' => !empty($event['ends_at']) ? $this->iso((string) $event['ends_at']) : null,
            'timezone' => $event['timezone'],
            'status' => $event['status'],
            'visibility' => $event['visibility'],
            'spaces' => $this->access->space_titles($space_ids),
            'image_url' => !empty($event['image_id']) ? wp_get_attachment_image_url((int) $event['image_id'], 'large') : null,
            'location_type' => $event['location_type'],
            'rsvp_enabled' => (bool) $event['rsvp_enabled'],
            'rsvp_capacity' => $event['rsvp_capacity'] ? (int) $event['rsvp_capacity'] : null,
            'rsvp' => [
                'mine' => $mine,
                'counts' => $counts,
            ],
            'can_manage' => $is_manager,
            'excerpt' => wp_trim_words(wp_strip_all_tags((string) $event['description']), 28),
        ];

        if ($can_see_link) {
            $item['join_url'] = $event['location_type'] === 'url' ? $event['external_url'] : $event['zoom_join_url'];
        }

        if ($detail) {
            $item['description_html'] = wp_kses_post((string) $event['description']);
            $item['attendees'] = $this->repo->attendees((int) $event['id'], $is_manager);
            $item['reminder_minutes'] = json_decode((string) $event['reminder_minutes'], true) ?: [];
            if ($is_manager) {
                $item['zoom_user_email'] = $event['zoom_user_email'];
                $item['zoom_meeting_id'] = $event['zoom_meeting_id'];
                $item['zoom_start_url'] = $event['zoom_start_url'];
                $item['external_url'] = $event['external_url'];
                $item['space_ids'] = $space_ids;
                $item['share_to_feed'] = (bool) $event['share_to_feed'];
                $item['feed_ids'] = $this->access->decode_ids($event['feed_ids'] ?? '[]');
            }
        }

        return $item;
    }

    private function iso(string $mysqlUtc): string
    {
        return gmdate('c', strtotime($mysqlUtc . ' UTC') ?: time());
    }
}
