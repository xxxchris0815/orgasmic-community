<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Orgasmic_Fc_Events_Repository
{
    public function create(array $data): int
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $row = $this->prepare_row($data, $now, $now);
        $row = array_filter($row, static fn($value) => $value !== null);
        $wpdb->insert(Orgasmic_Fc_Events_Install::events_table(), $row);
        return (int) $wpdb->insert_id;
    }

    public function update(int $id, array $data): bool
    {
        global $wpdb;
        $existing = $this->find($id);
        if (!$existing) {
            return false;
        }

        $merged = array_merge($existing, $data);
        $row = $this->prepare_row($merged, $existing['created_at'], current_time('mysql', true));
        unset($row['created_at']);

        return false !== $wpdb->update(
            Orgasmic_Fc_Events_Install::events_table(),
            $row,
            ['id' => $id]
        );
    }

    public function delete(int $id): bool
    {
        global $wpdb;
        $wpdb->delete(Orgasmic_Fc_Events_Install::rsvp_table(), ['event_id' => $id]);
        $wpdb->delete(Orgasmic_Fc_Events_Install::reminder_table(), ['event_id' => $id]);
        return (bool) $wpdb->delete(Orgasmic_Fc_Events_Install::events_table(), ['id' => $id]);
    }

    public function find(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . Orgasmic_Fc_Events_Install::events_table() . ' WHERE id = %d', $id),
            ARRAY_A
        );
        return $row ?: null;
    }

    public function query_visible(array $space_ids, bool $is_manager, array $args = []): array
    {
        global $wpdb;

        $from = !empty($args['from']) ? (string) $args['from'] : gmdate('Y-m-d H:i:s', time() - 12 * HOUR_IN_SECONDS);
        $to = !empty($args['to']) ? (string) $args['to'] : gmdate('Y-m-d H:i:s', time() + 400 * DAY_IN_SECONDS);
        $limit = min(200, max(1, (int) ($args['limit'] ?? 80)));

        $sql = 'SELECT * FROM ' . Orgasmic_Fc_Events_Install::events_table()
            . ' WHERE starts_at >= %s AND starts_at <= %s';
        $params = [$from, $to];

        if (!$is_manager) {
            $sql .= " AND status = 'published'";
        }

        $sql .= ' ORDER BY starts_at ASC LIMIT %d';
        $params[] = $limit;

        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) ?: [];
        if ($is_manager) {
            return $rows;
        }

        return array_values(array_filter($rows, function (array $row) use ($space_ids) {
            if (($row['visibility'] ?? '') === 'all') {
                return true;
            }
            $required = json_decode((string) $row['space_ids'], true);
            $required = is_array($required) ? array_map('intval', $required) : [];
            if ($required === []) {
                return true;
            }
            return (bool) array_intersect($required, $space_ids);
        }));
    }

    public function upcoming_for_reminders(): array
    {
        global $wpdb;
        $from = gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS);
        $to = gmdate('Y-m-d H:i:s', time() + 40 * DAY_IN_SECONDS);

        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . Orgasmic_Fc_Events_Install::events_table()
                . " WHERE status = 'published' AND starts_at >= %s AND starts_at <= %s",
                $from,
                $to
            ),
            ARRAY_A
        ) ?: [];
    }

    public function reminder_fired(int $event_id, int $minutes): bool
    {
        global $wpdb;
        $id = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . Orgasmic_Fc_Events_Install::reminder_table()
                . ' WHERE event_id = %d AND minutes_before = %d',
                $event_id,
                $minutes
            )
        );
        return (bool) $id;
    }

    public function mark_reminder_fired(int $event_id, int $minutes): void
    {
        global $wpdb;
        $wpdb->replace(
            Orgasmic_Fc_Events_Install::reminder_table(),
            [
                'event_id' => $event_id,
                'minutes_before' => $minutes,
                'fired_at' => current_time('mysql', true),
            ]
        );
    }

    public function set_rsvp(int $event_id, int $user_id, string $status): array
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . Orgasmic_Fc_Events_Install::rsvp_table()
                . ' WHERE event_id = %d AND user_id = %d',
                $event_id,
                $user_id
            ),
            ARRAY_A
        );

        if ($existing) {
            $wpdb->update(
                Orgasmic_Fc_Events_Install::rsvp_table(),
                ['status' => $status, 'updated_at' => $now],
                ['id' => (int) $existing['id']]
            );
        } else {
            $wpdb->insert(Orgasmic_Fc_Events_Install::rsvp_table(), [
                'event_id' => $event_id,
                'user_id' => $user_id,
                'status' => $status,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return [
            'previous' => $existing['status'] ?? null,
            'status' => $status,
        ];
    }

    public function rsvp_counts(int $event_id): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT status, COUNT(*) AS total FROM ' . Orgasmic_Fc_Events_Install::rsvp_table()
                . ' WHERE event_id = %d GROUP BY status',
                $event_id
            ),
            ARRAY_A
        ) ?: [];

        $counts = ['going' => 0, 'maybe' => 0, 'declined' => 0];
        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['total'];
        }
        return $counts;
    }

    public function attendees(int $event_id, bool $include_email = false): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT user_id, status, updated_at FROM ' . Orgasmic_Fc_Events_Install::rsvp_table()
                . " WHERE event_id = %d AND status IN ('going','maybe') ORDER BY status ASC, updated_at ASC",
                $event_id
            ),
            ARRAY_A
        ) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $user = get_userdata((int) $row['user_id']);
            $item = [
                'user_id' => (int) $row['user_id'],
                'display_name' => $user ? $user->display_name : '(gelöscht)',
                'avatar' => get_avatar_url((int) $row['user_id'], ['size' => 64]),
                'status' => $row['status'],
            ];
            if ($include_email && $user) {
                $item['email'] = $user->user_email;
            }
            $out[] = $item;
        }
        return $out;
    }

    public function my_rsvp(int $event_id, int $user_id): ?string
    {
        global $wpdb;
        $status = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT status FROM ' . Orgasmic_Fc_Events_Install::rsvp_table()
                . ' WHERE event_id = %d AND user_id = %d',
                $event_id,
                $user_id
            )
        );
        return $status ? (string) $status : null;
    }

    public function going_user_ids(int $event_id): array
    {
        global $wpdb;
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT user_id FROM ' . Orgasmic_Fc_Events_Install::rsvp_table()
                . " WHERE event_id = %d AND status = 'going'",
                $event_id
            )
        );
        return array_map('intval', $ids ?: []);
    }

    private function prepare_row(array $data, string $created_at, string $updated_at): array
    {
        $space_ids = $data['space_ids'] ?? [];
        if (is_string($space_ids)) {
            $decoded = json_decode($space_ids, true);
            $space_ids = is_array($decoded) ? $decoded : [];
        }

        $reminders = $data['reminder_minutes'] ?? get_option(Orgasmic_Fc_Events_Install::OPTION_DEFAULT_REMINDERS, [1440, 60]);
        if (is_string($reminders)) {
            $decoded = json_decode($reminders, true);
            $reminders = is_array($decoded) ? $decoded : [];
        }
        $reminders = array_values(array_unique(array_map('intval', $reminders)));

        $feed_ids = $data['feed_ids'] ?? [];
        if (!is_string($feed_ids)) {
            $feed_ids = wp_json_encode(array_map('intval', (array) $feed_ids));
        }

        return [
            'title' => sanitize_text_field((string) ($data['title'] ?? '')),
            'slug' => sanitize_title((string) ($data['slug'] ?? $data['title'] ?? '')),
            'description' => wp_kses_post((string) ($data['description'] ?? '')),
            'image_id' => !empty($data['image_id']) ? (int) $data['image_id'] : null,
            'starts_at' => $this->datetime((string) ($data['starts_at'] ?? '')),
            'ends_at' => !empty($data['ends_at']) ? $this->datetime((string) $data['ends_at']) : null,
            'timezone' => sanitize_text_field((string) ($data['timezone'] ?? 'Europe/Berlin')),
            'status' => in_array($data['status'] ?? 'published', ['draft', 'published', 'cancelled'], true)
                ? $data['status']
                : 'published',
            'visibility' => ($data['visibility'] ?? 'spaces') === 'all' ? 'all' : 'spaces',
            'space_ids' => wp_json_encode(array_values(array_unique(array_map('intval', $space_ids)))),
            'rsvp_enabled' => empty($data['rsvp_enabled']) ? 0 : 1,
            'rsvp_capacity' => !empty($data['rsvp_capacity']) ? (int) $data['rsvp_capacity'] : null,
            'location_type' => in_array($data['location_type'] ?? 'zoom', ['zoom', 'url', 'none'], true)
                ? $data['location_type']
                : 'zoom',
            'zoom_user_email' => sanitize_email((string) ($data['zoom_user_email'] ?? '')),
            'zoom_meeting_id' => sanitize_text_field((string) ($data['zoom_meeting_id'] ?? '')),
            'zoom_join_url' => esc_url_raw((string) ($data['zoom_join_url'] ?? '')),
            'zoom_start_url' => esc_url_raw((string) ($data['zoom_start_url'] ?? '')),
            'external_url' => esc_url_raw((string) ($data['external_url'] ?? '')),
            'share_to_feed' => empty($data['share_to_feed']) ? 0 : 1,
            'feed_ids' => $feed_ids,
            'reminder_minutes' => wp_json_encode($reminders),
            'created_by' => (int) ($data['created_by'] ?? get_current_user_id()),
            'created_at' => $created_at,
            'updated_at' => $updated_at,
        ];
    }

    private function datetime(string $value): string
    {
        $ts = strtotime($value);
        return gmdate('Y-m-d H:i:s', $ts ?: time());
    }
}
