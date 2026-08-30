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
        add_action('wp_ajax_orgasmic_fc_upload_create', [$this, 'ajax_create']);
        add_action('wp_ajax_orgasmic_fc_upload_status', [$this, 'ajax_status']);
        add_action('wp_ajax_orgasmic_fc_upload_push', [$this, 'ajax_push']);
        add_action('wp_ajax_orgasmic_fc_upload_chunk', [$this, 'ajax_chunk']);
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

        register_rest_route('orgasmic-embeds/v1', '/upload/status', [
            'methods' => 'GET',
            'permission_callback' => static fn() => is_user_logged_in(),
            'callback' => [$this, 'upload_status'],
        ]);

        register_rest_route('orgasmic-embeds/v1', '/upload/push', [
            'methods' => 'POST',
            'permission_callback' => static fn() => is_user_logged_in(),
            'callback' => [$this, 'push_upload'],
        ]);

        register_rest_route('orgasmic-embeds/v1', '/upload/chunk', [
            'methods' => 'POST',
            'permission_callback' => static fn() => is_user_logged_in(),
            'callback' => [$this, 'chunk_upload'],
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

    public function upload_status(WP_REST_Request $request)
    {
        $status = $this->bunny->video_status((string) $request->get_param('video_id'));
        if (is_wp_error($status)) {
            return $status;
        }
        return rest_ensure_response($status);
    }

    public function push_upload(WP_REST_Request $request)
    {
        $video_id = (string) $request->get_param('video_id');
        $files = $request->get_file_params();
        $file = $files['file'] ?? null;
        if (!is_array($file) || empty($file['tmp_name'])) {
            return new WP_Error('bunny', 'Keine Datei erhalten.', ['status' => 400]);
        }
        if (!empty($file['error'])) {
            return new WP_Error('bunny', 'Upload-Fehler ' . (int) $file['error'] . '.', ['status' => 400]);
        }

        $max = (int) wp_max_upload_size();
        if ($max > 0 && !empty($file['size']) && (int) $file['size'] > $max) {
            return new WP_Error('bunny', 'Datei ist größer als das PHP-Upload-Limit.', ['status' => 413]);
        }

        $put = $this->bunny->put_file($video_id, (string) $file['tmp_name']);
        if (is_wp_error($put)) {
            return $put;
        }

        return $this->status_response($video_id);
    }

    public function chunk_upload(WP_REST_Request $request)
    {
        $video_id = $this->sanitize_video_id((string) $request->get_param('video_id'));
        $offset = (int) $request->get_param('offset');
        $total = (int) $request->get_param('total');
        if ($video_id === '' || $offset < 0 || $total < 1 || $offset >= $total) {
            return new WP_Error('bunny', 'Ungültiger Upload-Abschnitt.', ['status' => 400]);
        }
        if ($total > 512 * 1024 * 1024) {
            return new WP_Error('bunny', 'Video ist größer als 512 MB.', ['status' => 413]);
        }

        $chunk = $this->chunk_bytes($request);
        $length = strlen($chunk);
        if ($length < 1) {
            return new WP_Error('bunny', 'Leerer Upload-Abschnitt.', ['status' => 400]);
        }
        if ($offset + $length > $total) {
            return new WP_Error('bunny', 'Upload-Abschnitt passt nicht zur Dateigröße.', ['status' => 400]);
        }

        $path = $this->store->chunk_path($video_id, get_current_user_id());
        if ($path === '') {
            return new WP_Error('bunny', 'Temporärer Upload-Ordner fehlt.', ['status' => 500]);
        }
        if ($offset > 0 && (!is_file($path) || (int) filesize($path) < $offset)) {
            return new WP_Error('bunny', 'Upload-Abschnitt außerhalb der Reihenfolge.', ['status' => 409]);
        }

        $handle = fopen($path, $offset === 0 ? 'wb' : 'c+b');
        if ($handle === false) {
            return new WP_Error('bunny', 'Temporäre Datei konnte nicht geschrieben werden.', ['status' => 500]);
        }
        if (fseek($handle, $offset) !== 0 || fwrite($handle, $chunk) !== $length) {
            fclose($handle);
            return new WP_Error('bunny', 'Temporäre Datei konnte nicht geschrieben werden.', ['status' => 500]);
        }
        fclose($handle);

        $written = $offset + $length;
        if ($written < $total) {
            return rest_ensure_response([
                'ok' => true,
                'done' => false,
                'written' => $written,
                'total' => $total,
            ]);
        }

        if (function_exists('set_time_limit')) {
            set_time_limit(600);
        }

        $put = $this->bunny->put_file($video_id, $path);
        @unlink($path);
        if (is_wp_error($put)) {
            return $put;
        }

        return $this->status_response($video_id);
    }

    public function ajax_create(): void
    {
        if (!$this->ajax_ready()) {
            return;
        }
        $request = new WP_REST_Request('POST', '/orgasmic-embeds/v1/upload/create');
        $request->set_param('title', sanitize_text_field((string) wp_unslash($_POST['title'] ?? '')));
        $this->send_ajax($this->create_upload($request));
    }

    public function ajax_status(): void
    {
        if (!$this->ajax_ready()) {
            return;
        }
        $request = new WP_REST_Request('GET', '/orgasmic-embeds/v1/upload/status');
        $request->set_param('video_id', sanitize_text_field((string) wp_unslash($_POST['video_id'] ?? '')));
        $this->send_ajax($this->upload_status($request));
    }

    public function ajax_push(): void
    {
        if (!$this->ajax_ready()) {
            return;
        }
        $request = new WP_REST_Request('POST', '/orgasmic-embeds/v1/upload/push');
        $request->set_param('video_id', sanitize_text_field((string) wp_unslash($_POST['video_id'] ?? '')));
        if (!empty($_FILES)) {
            $request->set_file_params($_FILES);
        }
        $this->send_ajax($this->push_upload($request));
    }

    public function ajax_chunk(): void
    {
        if (!$this->ajax_ready()) {
            return;
        }
        $request = new WP_REST_Request('POST', '/orgasmic-embeds/v1/upload/chunk');
        $request->set_param('video_id', sanitize_text_field((string) wp_unslash($_POST['video_id'] ?? '')));
        $request->set_param('offset', (int) ($_POST['offset'] ?? 0));
        $request->set_param('total', (int) ($_POST['total'] ?? 0));
        if (!empty($_FILES)) {
            $request->set_file_params($_FILES);
        }
        $this->send_ajax($this->chunk_upload($request));
    }

    private function ajax_ready(): bool
    {
        if (!is_user_logged_in()) {
            status_header(403);
            wp_send_json(['message' => 'Bitte neu anmelden.']);
            return false;
        }
        $nonce = (string) ($_POST['nonce'] ?? $_REQUEST['_wpnonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'orgasmic_fc_upload')) {
            status_header(403);
            wp_send_json(['message' => 'Bitte die Seite neu laden.']);
            return false;
        }

        return true;
    }

    /**
     * @param WP_REST_Response|WP_Error|array<string, mixed> $result
     */
    private function send_ajax($result): void
    {
        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            $status = is_array($data) ? (int) ($data['status'] ?? 400) : 400;
            status_header($status > 0 ? $status : 400);
            wp_send_json([
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ]);
        }
        $payload = $result instanceof WP_REST_Response ? $result->get_data() : $result;
        wp_send_json($payload);
    }

    private function sanitize_video_id(string $video_id): string
    {
        return strtolower(preg_replace('/[^0-9a-f-]/', '', $video_id) ?: '');
    }

    private function chunk_bytes(WP_REST_Request $request): string
    {
        $files = $request->get_file_params();
        $file = $files['chunk'] ?? $files['file'] ?? null;
        if (is_array($file) && !empty($file['tmp_name']) && is_readable((string) $file['tmp_name'])) {
            $bytes = file_get_contents((string) $file['tmp_name']);
            return $bytes !== false ? $bytes : '';
        }

        $body = $request->get_body();
        if (is_string($body) && $body !== '') {
            return $body;
        }
        $raw = file_get_contents('php://input');
        return is_string($raw) ? $raw : '';
    }

    private function status_response(string $video_id): WP_REST_Response
    {
        $status = $this->bunny->video_status($video_id);
        if (is_wp_error($status)) {
            return rest_ensure_response(['ok' => true, 'received' => true]);
        }

        return rest_ensure_response(array_merge(['ok' => true, 'done' => true], $status));
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
