<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Orgasmic_Fc_Chat_Repository
{
    public function list_rooms(array $space_ids, int $user_id, array $space_map): array
    {
        $space_ids = array_values(array_unique(array_filter(array_map('intval', $space_ids))));
        if ($space_ids === []) {
            return [];
        }

        $last = $this->last_messages($space_ids);
        $unread = $this->unread_map($space_ids, $user_id);

        $rooms = [];
        foreach ($space_ids as $space_id) {
            $space = $space_map[$space_id] ?? [
                'id' => $space_id,
                'title' => 'Space #' . $space_id,
                'slug' => '',
                'privacy' => '',
                'type' => '',
                'logo' => '',
            ];
            $message = $last[$space_id] ?? null;
            $rooms[] = [
                'space_id' => $space_id,
                'title' => (string) $space['title'],
                'slug' => (string) ($space['slug'] ?? ''),
                'privacy' => (string) ($space['privacy'] ?? ''),
                'logo' => (string) ($space['logo'] ?? ''),
                'unread' => (int) ($unread[$space_id] ?? 0),
                'last_message' => $message,
            ];
        }

        usort($rooms, static function (array $a, array $b): int {
            $a_time = $a['last_message']['created_at'] ?? '';
            $b_time = $b['last_message']['created_at'] ?? '';
            if ($a_time === $b_time) {
                return strcasecmp((string) $a['title'], (string) $b['title']);
            }
            return $a_time < $b_time ? 1 : -1;
        });

        return $rooms;
    }

    public function unread_map(array $space_ids, int $user_id): array
    {
        $space_ids = array_values(array_unique(array_filter(array_map('intval', $space_ids))));
        if ($space_ids === [] || $user_id < 1) {
            return [];
        }

        global $wpdb;
        $messages = Orgasmic_Fc_Chat_Install::messages_table();
        $reads = Orgasmic_Fc_Chat_Install::reads_table();
        $in = implode(',', $space_ids);

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT m.space_id, COUNT(*) AS unread
                 FROM {$messages} m
                 LEFT JOIN {$reads} r ON r.space_id = m.space_id AND r.user_id = %d
                 WHERE m.space_id IN ({$in})
                   AND m.deleted_at IS NULL
                   AND m.user_id <> %d
                   AND m.id > COALESCE(r.last_read_id, 0)
                 GROUP BY m.space_id",
                $user_id,
                $user_id
            ),
            ARRAY_A
        ) ?: [];

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['space_id']] = (int) $row['unread'];
        }

        return $map;
    }

    public function unread_total(array $space_ids, int $user_id): int
    {
        return (int) array_sum($this->unread_map($space_ids, $user_id));
    }

    public function messages(int $space_id, int $after = 0, int $before = 0, int $limit = 50): array
    {
        global $wpdb;
        $table = Orgasmic_Fc_Chat_Install::messages_table();
        $limit = max(1, min(100, $limit));

        $where = ['space_id = %d', 'deleted_at IS NULL'];
        $args = [$space_id];
        $order = 'ASC';

        if ($after > 0) {
            $where[] = 'id > %d';
            $args[] = $after;
        } elseif ($before > 0) {
            $where[] = 'id < %d';
            $args[] = $before;
            $order = 'DESC';
        }

        $sql = "SELECT id, space_id, user_id, body, attachment_id, created_at
                FROM {$table}
                WHERE " . implode(' AND ', $where) . "
                ORDER BY id {$order}
                LIMIT {$limit}";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A) ?: [];
        if ($order === 'DESC') {
            $rows = array_reverse($rows);
        }

        return array_map([$this, 'hydrate_message'], $rows);
    }

    public function latest_id(int $space_id): int
    {
        global $wpdb;
        $table = Orgasmic_Fc_Chat_Install::messages_table();
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT MAX(id) FROM {$table} WHERE space_id = %d AND deleted_at IS NULL",
                $space_id
            )
        );
    }

    public function get_message(int $id): ?array
    {
        global $wpdb;
        $table = Orgasmic_Fc_Chat_Install::messages_table();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id),
            ARRAY_A
        );

        return $row ? $this->hydrate_message($row) : null;
    }

    public function insert_message(int $space_id, int $user_id, string $body, int $attachment_id = 0): array
    {
        global $wpdb;
        $table = Orgasmic_Fc_Chat_Install::messages_table();
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert($table, [
            'space_id' => $space_id,
            'user_id' => $user_id,
            'body' => $body,
            'attachment_id' => $attachment_id,
            'created_at' => $now,
            'deleted_at' => null,
        ], ['%d', '%d', '%s', '%d', '%s', '%s']);

        $id = (int) $wpdb->insert_id;
        $this->mark_read($space_id, $user_id, $id);

        return $this->get_message($id) ?? [
            'id' => $id,
            'space_id' => $space_id,
            'user_id' => $user_id,
            'body' => $body,
            'attachment_id' => $attachment_id,
            'attachment' => $this->attachment_payload($attachment_id),
            'created_at' => $now,
            'deleted' => false,
        ];
    }

    public function soft_delete(int $id): bool
    {
        global $wpdb;
        $table = Orgasmic_Fc_Chat_Install::messages_table();
        return false !== $wpdb->update(
            $table,
            ['deleted_at' => gmdate('Y-m-d H:i:s')],
            ['id' => $id],
            ['%s'],
            ['%d']
        );
    }

    public function last_read_id(int $space_id, int $user_id): int
    {
        if ($user_id < 1 || $space_id < 1) {
            return 0;
        }

        global $wpdb;
        $table = Orgasmic_Fc_Chat_Install::reads_table();
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT last_read_id FROM {$table} WHERE user_id = %d AND space_id = %d",
                $user_id,
                $space_id
            )
        );
    }

    public function mark_read(int $space_id, int $user_id, int $last_id): void
    {
        if ($user_id < 1 || $space_id < 1) {
            return;
        }

        global $wpdb;
        $table = Orgasmic_Fc_Chat_Install::reads_table();
        $now = gmdate('Y-m-d H:i:s');
        $current = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT last_read_id FROM {$table} WHERE user_id = %d AND space_id = %d",
                $user_id,
                $space_id
            )
        );

        if ($last_id < $current) {
            $last_id = $current;
        }

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table} (user_id, space_id, last_read_id, last_read_at)
                 VALUES (%d, %d, %d, %s)
                 ON DUPLICATE KEY UPDATE
                    last_read_id = GREATEST(last_read_id, VALUES(last_read_id)),
                    last_read_at = VALUES(last_read_at)",
                $user_id,
                $space_id,
                $last_id,
                $now
            )
        );
    }

    public function recent(int $limit = 50): array
    {
        global $wpdb;
        $table = Orgasmic_Fc_Chat_Install::messages_table();
        $limit = max(1, min(200, $limit));
        $rows = $wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY id DESC LIMIT {$limit}",
            ARRAY_A
        ) ?: [];

        return array_map([$this, 'hydrate_message'], $rows);
    }

    private function last_messages(array $space_ids): array
    {
        global $wpdb;
        $table = Orgasmic_Fc_Chat_Install::messages_table();
        $in = implode(',', $space_ids);
        $rows = $wpdb->get_results(
            "SELECT m.id, m.space_id, m.user_id, m.body, m.attachment_id, m.created_at
             FROM {$table} m
             INNER JOIN (
                SELECT space_id, MAX(id) AS max_id
                FROM {$table}
                WHERE space_id IN ({$in}) AND deleted_at IS NULL
                GROUP BY space_id
             ) latest ON latest.max_id = m.id",
            ARRAY_A
        ) ?: [];

        $map = [];
        foreach ($rows as $row) {
            $hydrated = $this->hydrate_message($row);
            $hydrated['preview'] = $this->preview($hydrated);
            $map[(int) $row['space_id']] = $hydrated;
        }

        return $map;
    }

    private function hydrate_message(array $row): array
    {
        $attachment_id = (int) ($row['attachment_id'] ?? 0);
        return [
            'id' => (int) $row['id'],
            'space_id' => (int) $row['space_id'],
            'user_id' => (int) $row['user_id'],
            'body' => (string) ($row['body'] ?? ''),
            'attachment_id' => $attachment_id,
            'attachment' => $this->attachment_payload($attachment_id),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'deleted' => !empty($row['deleted_at']),
        ];
    }

    private function attachment_payload(int $attachment_id): ?array
    {
        return self::attachment_payload_for($attachment_id);
    }

    public static function attachment_payload_for(int $attachment_id): ?array
    {
        if ($attachment_id < 1) {
            return null;
        }
        $url = wp_get_attachment_url($attachment_id);
        if (!$url) {
            return null;
        }
        $mime = (string) get_post_mime_type($attachment_id);
        $kind = (str_starts_with($mime, 'audio/') || $mime === 'video/webm' || $mime === 'application/ogg')
            ? 'audio'
            : 'image';
        $image = $kind === 'image' ? wp_get_attachment_image_src($attachment_id, 'large') : null;

        return [
            'id' => $attachment_id,
            'url' => esc_url_raw($url),
            'thumb' => $image ? esc_url_raw((string) $image[0]) : '',
            'mime' => $mime,
            'kind' => $kind,
            'duration' => $kind === 'audio' ? (int) get_post_meta($attachment_id, '_orgasmic_audio_duration', true) : 0,
        ];
    }

    public static function attachment_label(?array $attachment): string
    {
        if (!$attachment) {
            return '';
        }
        $kind = (string) ($attachment['kind'] ?? '');
        $mime = (string) ($attachment['mime'] ?? '');
        if ($kind === 'audio' || str_starts_with($mime, 'audio/')) {
            return '🎤 Sprachnachricht';
        }

        return '📷 Bild';
    }

    private function preview(array $message): string
    {
        $body = trim((string) ($message['body'] ?? ''));
        if ($body !== '') {
            $body = preg_replace('/\s+/', ' ', $body) ?? $body;
            if (function_exists('mb_substr')) {
                return mb_substr($body, 0, 140);
            }
            return substr($body, 0, 140);
        }
        if (!empty($message['attachment'])) {
            return self::attachment_label($message['attachment']);
        }

        return '';
    }
}
