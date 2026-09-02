<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Orgasmic_Fc_Chat_Rest
{
    public function __construct(
        private Orgasmic_Fc_Chat_Access $access,
        private Orgasmic_Fc_Chat_Repository $repo,
        private Orgasmic_Fc_Chat_Webhook $webhook
    ) {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        $ns = 'orgasmic-chat/v1';

        register_rest_route($ns, '/rooms', [
            'methods' => 'GET',
            'permission_callback' => [$this, 'logged_in'],
            'callback' => [$this, 'rooms'],
        ]);

        register_rest_route($ns, '/unread', [
            'methods' => 'GET',
            'permission_callback' => [$this, 'logged_in'],
            'callback' => [$this, 'unread'],
        ]);

        register_rest_route($ns, '/rooms/(?P<space>\d+)/messages', [
            [
                'methods' => 'GET',
                'permission_callback' => [$this, 'logged_in'],
                'callback' => [$this, 'list_messages'],
            ],
            [
                'methods' => 'POST',
                'permission_callback' => [$this, 'logged_in'],
                'callback' => [$this, 'create_message'],
            ],
        ]);

        register_rest_route($ns, '/rooms/(?P<space>\d+)/read', [
            'methods' => 'POST',
            'permission_callback' => [$this, 'logged_in'],
            'callback' => [$this, 'mark_read'],
        ]);

        register_rest_route($ns, '/messages/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'permission_callback' => [$this, 'logged_in'],
            'callback' => [$this, 'delete_message'],
        ]);

        register_rest_route($ns, '/upload', [
            'methods' => 'POST',
            'permission_callback' => [$this, 'logged_in'],
            'callback' => [$this, 'upload'],
        ]);
    }

    public function logged_in(): bool
    {
        return is_user_logged_in();
    }

    public function rooms(): WP_REST_Response
    {
        $user_id = get_current_user_id();
        $space_ids = $this->access->visible_space_ids($user_id);
        $rooms = $this->repo->list_rooms($space_ids, $user_id, $this->access->space_map());
        $this->attach_authors($rooms);

        return rest_ensure_response([
            'rooms' => $rooms,
            'unread' => (int) array_sum(array_column($rooms, 'unread')),
            'me' => $this->access->user_payload($user_id),
            'can_manage' => $this->access->can_manage($user_id),
            'portal' => Orgasmic_Fc_Chat_Install::portal_settings(),
        ]);
    }

    public function unread(): WP_REST_Response
    {
        $user_id = get_current_user_id();
        $space_ids = $this->access->visible_space_ids($user_id);
        $map = $this->repo->unread_map($space_ids, $user_id);

        return rest_ensure_response([
            'total' => (int) array_sum($map),
            'rooms' => (object) $map,
        ]);
    }

    public function list_messages(WP_REST_Request $request)
    {
        $space_id = (int) $request['space'];
        $denied = $this->deny_space($space_id);
        if ($denied) {
            return $denied;
        }

        $after = (int) $request->get_param('after');
        $before = (int) $request->get_param('before');
        $limit = (int) ($request->get_param('limit') ?: 40);
        $items = $this->repo->messages($space_id, $after, $before, $limit);
        $this->attach_authors($items);

        $has_older = false;
        if ($after < 1 && $items !== []) {
            $has_older = $this->repo->exists_before($space_id, (int) $items[0]['id']);
        }

        return rest_ensure_response([
            'items' => $items,
            'latest_id' => $this->repo->latest_id($space_id),
            'has_older' => $has_older,
        ]);
    }

    public function create_message(WP_REST_Request $request)
    {
        $space_id = (int) $request['space'];
        $denied = $this->deny_space($space_id);
        if ($denied) {
            return $denied;
        }

        $user_id = get_current_user_id();
        if ($this->rate_limited($user_id)) {
            return new WP_Error('too_many', 'Bitte kurz warten.', ['status' => 429]);
        }

        $settings = Orgasmic_Fc_Chat_Install::portal_settings();
        $body = $this->sanitize_body((string) $request->get_param('body'), $settings['max_length']);
        $attachment_id = (int) $request->get_param('attachment_id');
        $reply_to = (int) $request->get_param('reply_to');
        if ($attachment_id > 0 && !$this->owned_attachment($attachment_id, $user_id)) {
            $attachment_id = 0;
        }

        if ($body === '' && $attachment_id < 1) {
            return new WP_Error('empty', 'Nachricht ist leer.', ['status' => 400]);
        }

        $message = $this->repo->insert_message($space_id, $user_id, $body, $attachment_id, $reply_to);
        $message['author'] = $this->access->user_payload($user_id);
        if (!empty($message['reply']['user_id'])) {
            $message['reply']['author'] = $this->access->user_payload((int) $message['reply']['user_id']);
        }

        $this->webhook->message('chat.message', $user_id, [
            'message_id' => $message['id'],
            'space_id' => $space_id,
            'body' => $body,
            'preview' => $message['body'] !== '' ? $message['body'] : ($message['attachment'] ? Orgasmic_Fc_Chat_Repository::attachment_label($message['attachment']) : ''),
            'attachment_id' => $attachment_id ?: null,
        ]);
        do_action('orgasmic_fc/chat/message', $message, $space_id, $user_id);

        return rest_ensure_response($message);
    }

    public function mark_read(WP_REST_Request $request)
    {
        $space_id = (int) $request['space'];
        $denied = $this->deny_space($space_id);
        if ($denied) {
            return $denied;
        }

        $user_id = get_current_user_id();
        $previous = $this->repo->last_read_id($space_id, $user_id);
        $last_id = (int) $request->get_param('last_id');
        if ($last_id < 1) {
            $last_id = $this->repo->latest_id($space_id);
        }
        $this->repo->mark_read($space_id, $user_id, $last_id);

        if ($last_id > $previous) {
            $this->webhook->message('chat.read', $user_id, [
                'space_id' => $space_id,
                'last_id' => $last_id,
            ]);
        }

        return rest_ensure_response([
            'ok' => true,
            'last_id' => $last_id,
            'unread' => $this->repo->unread_total($this->access->visible_space_ids($user_id), $user_id),
        ]);
    }

    public function delete_message(WP_REST_Request $request)
    {
        $id = (int) $request['id'];
        $message = $this->repo->get_message($id);
        if (!$message || !empty($message['deleted'])) {
            return new WP_Error('not_found', 'Nachricht nicht gefunden.', ['status' => 404]);
        }

        $user_id = get_current_user_id();
        $denied = $this->deny_space((int) $message['space_id']);
        if ($denied) {
            return $denied;
        }

        if ((int) $message['user_id'] !== $user_id && !$this->access->can_manage($user_id)) {
            return new WP_Error('forbidden', 'Nur eigene Nachrichten oder Admins.', ['status' => 403]);
        }

        $this->repo->soft_delete($id);

        return rest_ensure_response(['ok' => true]);
    }

    public function upload(WP_REST_Request $request)
    {
        $user_id = get_current_user_id();
        if ($this->rate_limited($user_id, 'orgasmic_chat_up_', 3)) {
            return new WP_Error('too_many', 'Bitte kurz warten.', ['status' => 429]);
        }

        $files = $request->get_file_params();
        if (empty($files['file']) || !is_array($files['file'])) {
            return new WP_Error('no_file', 'Datei fehlt.', ['status' => 400]);
        }

        $file = $files['file'];
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            return new WP_Error('upload_error', 'Upload fehlgeschlagen.', ['status' => 400]);
        }

        $parsed = $this->parse_upload($file);
        if (is_wp_error($parsed)) {
            return $parsed;
        }

        $max = $parsed['kind'] === 'audio' ? 8 * 1024 * 1024 : 4 * 1024 * 1024;
        if ($parsed['size'] < 1 || $parsed['size'] > $max) {
            return new WP_Error(
                'too_large',
                $parsed['kind'] === 'audio' ? 'Sprachnachricht maximal 8 MB.' : 'Bild maximal 4 MB.',
                ['status' => 400]
            );
        }

        $_FILES['file']['name'] = $parsed['filename'];
        $_FILES['file']['type'] = $parsed['mime'];

        $mime_filter = static function (array $mimes) use ($parsed): array {
            $mimes[$parsed['ext']] = $parsed['mime'];
            return $mimes;
        };
        $type_filter = static function ($data, $file, $filename, $mimes) use ($parsed) {
            if (is_array($data) && empty($data['ext'])) {
                $data['ext'] = $parsed['ext'];
                $data['type'] = $parsed['mime'];
                $data['proper_filename'] = $parsed['filename'];
            }
            return $data;
        };
        add_filter('upload_mimes', $mime_filter);
        add_filter('wp_check_filetype_and_ext', $type_filter, 10, 4);

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $id = media_handle_upload('file', 0);
        remove_filter('upload_mimes', $mime_filter);
        remove_filter('wp_check_filetype_and_ext', $type_filter, 10);

        if (is_wp_error($id)) {
            return new WP_Error('upload_error', $id->get_error_message(), ['status' => 400]);
        }

        wp_update_post([
            'ID' => (int) $id,
            'post_author' => $user_id,
        ]);

        $duration = (int) $request->get_param('duration');
        if ($parsed['kind'] === 'audio' && $duration > 0) {
            update_post_meta((int) $id, '_orgasmic_audio_duration', min(180, $duration));
        }

        $payload = Orgasmic_Fc_Chat_Repository::attachment_payload_for((int) $id);
        if ($payload === null) {
            return new WP_Error('upload_error', 'Datei konnte nicht gelesen werden.', ['status' => 400]);
        }

        return rest_ensure_response($payload);
    }

    /**
     * @return array{kind:string,ext:string,mime:string,filename:string,size:int}|WP_Error
     */
    private function parse_upload(array $file)
    {
        $tmp = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return new WP_Error('upload_error', 'Upload ungültig.', ['status' => 400]);
        }

        $detected = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detected = (string) finfo_file($finfo, $tmp);
                finfo_close($finfo);
            }
        }
        if ($detected === '' && function_exists('mime_content_type')) {
            $detected = (string) mime_content_type($tmp);
        }

        $map = [
            'image/jpeg' => ['jpg', 'image/jpeg', 'image'],
            'image/png' => ['png', 'image/png', 'image'],
            'image/gif' => ['gif', 'image/gif', 'image'],
            'image/webp' => ['webp', 'image/webp', 'image'],
            'audio/webm' => ['webm', 'audio/webm', 'audio'],
            'video/webm' => ['webm', 'audio/webm', 'audio'],
            'audio/ogg' => ['ogg', 'audio/ogg', 'audio'],
            'application/ogg' => ['ogg', 'audio/ogg', 'audio'],
            'audio/mpeg' => ['mp3', 'audio/mpeg', 'audio'],
            'audio/mp4' => ['m4a', 'audio/mp4', 'audio'],
            'audio/x-m4a' => ['m4a', 'audio/mp4', 'audio'],
            'audio/aac' => ['aac', 'audio/aac', 'audio'],
            'audio/wav' => ['wav', 'audio/wav', 'audio'],
            'audio/x-wav' => ['wav', 'audio/wav', 'audio'],
        ];
        $client = strtolower((string) ($file['type'] ?? ''));
        $client = explode(';', $client)[0];
        if (!isset($map[$detected]) && $client !== '' && isset($map[$client])) {
            $detected = $client;
        }
        if (!isset($map[$detected])) {
            return new WP_Error('invalid_type', 'Nur Bilder oder Sprachnachrichten.', ['status' => 400]);
        }

        [$ext, $mime, $kind] = $map[$detected];

        return [
            'kind' => $kind,
            'ext' => $ext,
            'mime' => $mime,
            'filename' => ($kind === 'audio' ? 'voice' : 'image') . '-' . time() . '.' . $ext,
            'size' => $size,
        ];
    }

    private function deny_space(int $space_id)
    {
        if (!$this->access->can_access_space($space_id)) {
            return new WP_Error('forbidden', 'Kein Zugriff auf diesen Space.', ['status' => 403]);
        }

        return null;
    }

    private function sanitize_body(string $body, int $max): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = trim($body);
        $body = wp_strip_all_tags($body);
        if (function_exists('mb_substr')) {
            $body = mb_substr($body, 0, $max);
        } else {
            $body = substr($body, 0, $max);
        }

        return trim($body);
    }

    private function rate_limited(int $user_id, string $prefix = 'orgasmic_chat_rl_', int $seconds = 1): bool
    {
        $key = $prefix . $user_id;
        if (get_transient($key)) {
            return true;
        }
        set_transient($key, 1, $seconds);

        return false;
    }

    private function owned_attachment(int $attachment_id, int $user_id): bool
    {
        $post = get_post($attachment_id);
        if (!$post || $post->post_type !== 'attachment') {
            return false;
        }
        if ($this->access->can_manage($user_id)) {
            return true;
        }

        return (int) $post->post_author === $user_id;
    }

    private function attach_authors(array &$items): void
    {
        $cache = [];
        foreach ($items as &$item) {
            if (isset($item['last_message']) && is_array($item['last_message'])) {
                $uid = (int) ($item['last_message']['user_id'] ?? 0);
                if ($uid && !isset($cache[$uid])) {
                    $cache[$uid] = $this->access->user_payload($uid);
                }
                if ($uid) {
                    $item['last_message']['author'] = $cache[$uid];
                }
                continue;
            }
            $uid = (int) ($item['user_id'] ?? 0);
            if ($uid && !isset($cache[$uid])) {
                $cache[$uid] = $this->access->user_payload($uid);
            }
            if ($uid) {
                $item['author'] = $cache[$uid];
            }
            $reply_uid = (int) ($item['reply']['user_id'] ?? 0);
            if ($reply_uid) {
                if (!isset($cache[$reply_uid])) {
                    $cache[$reply_uid] = $this->access->user_payload($reply_uid);
                }
                $item['reply']['author'] = $cache[$reply_uid];
            }
        }
        unset($item);
    }
}
