<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Orgasmic_Fc_Events_Access
{
    public function is_logged_in(): bool
    {
        return is_user_logged_in();
    }

    public function can_manage(?int $user_id = null): bool
    {
        $user_id = $user_id ?: get_current_user_id();
        if (!$user_id) {
            return false;
        }

        if (user_can($user_id, 'manage_options')) {
            return true;
        }

        if (class_exists('FluentCommunity\\App\\Services\\Helper')) {
            $helper = 'FluentCommunity\\App\\Services\\Helper';
            if (method_exists($helper, 'isSiteAdmin') && $helper::isSiteAdmin($user_id)) {
                return true;
            }
            if (method_exists($helper, 'isSuperAdmin') && $helper::isSuperAdmin($user_id)) {
                return true;
            }
        }

        return false;
    }

    public function valid_api_key(?string $key): bool
    {
        $stored = (string) get_option(Orgasmic_Fc_Events_Install::OPTION_API_KEY, '');
        return $stored !== '' && is_string($key) && hash_equals($stored, $key);
    }

    public function user_space_ids(int $user_id): array
    {
        if (class_exists('FluentCommunity\\App\\Services\\Helper')) {
            $helper = 'FluentCommunity\\App\\Services\\Helper';
            if (method_exists($helper, 'getUserSpaceIds')) {
                $ids = $helper::getUserSpaceIds($user_id);
                if (is_array($ids)) {
                    return array_values(array_map('intval', $ids));
                }
            }
        }

        global $wpdb;
        $pivot = $this->table_if_exists($wpdb->prefix . 'fcom_space_user');
        if (!$pivot) {
            $pivot = $this->table_if_exists($wpdb->prefix . 'fcom_space_users');
        }
        if (!$pivot) {
            return [];
        }

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT space_id FROM {$pivot} WHERE user_id = %d AND (status IS NULL OR status = %s OR status = %s)",
                $user_id,
                'active',
                'accepted'
            )
        );

        return array_values(array_map('intval', $ids ?: []));
    }

    public function can_view_event(array $event, int $user_id): bool
    {
        if ($this->can_manage($user_id)) {
            return true;
        }

        if (($event['status'] ?? '') !== 'published') {
            return false;
        }

        if (($event['visibility'] ?? 'spaces') === 'all') {
            return $user_id > 0;
        }

        $required = $this->decode_ids($event['space_ids'] ?? '[]');
        if ($required === []) {
            return $user_id > 0;
        }

        $owned = $this->user_space_ids($user_id);
        return (bool) array_intersect($required, $owned);
    }

    public function list_spaces(bool $rooms_only = true): array
    {
        global $wpdb;
        $table = $this->table_if_exists($wpdb->prefix . 'fcom_spaces');
        if (!$table) {
            return [];
        }

        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
        $columns = is_array($columns) ? $columns : [];
        $select = ['id', 'title', 'slug'];
        foreach (['privacy', 'type', 'status', 'parent_id'] as $optional) {
            if (in_array($optional, $columns, true)) {
                $select[] = $optional;
            }
        }

        $rows = $wpdb->get_results(
            'SELECT ' . implode(', ', $select) . " FROM {$table} ORDER BY title ASC",
            ARRAY_A
        ) ?: [];

        $out = [];
        foreach ($rows as $row) {
            if ($rooms_only && !$this->is_room($row)) {
                continue;
            }
            $out[] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'slug' => (string) ($row['slug'] ?? ''),
                'privacy' => (string) ($row['privacy'] ?? ''),
                'type' => (string) ($row['type'] ?? ''),
            ];
        }

        return $out;
    }

    public function space_titles(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $spaces = $this->list_spaces(false);
        $map = [];
        foreach ($spaces as $space) {
            $map[$space['id']] = $space;
        }

        $out = [];
        foreach ($ids as $id) {
            if (isset($map[$id])) {
                $out[] = $map[$id];
            } else {
                $out[] = ['id' => (int) $id, 'title' => 'Space #' . $id, 'slug' => '', 'privacy' => '', 'type' => ''];
            }
        }

        return $out;
    }

    public function decode_ids($value): array
    {
        if (is_array($value)) {
            return array_values(array_unique(array_map('intval', $value)));
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? array_values(array_unique(array_map('intval', $decoded))) : [];
    }

    private function is_room(array $row): bool
    {
        $type = strtolower((string) ($row['type'] ?? ''));
        if (in_array($type, [
            'course', 'courses', 'content', 'content_space',
            'space_group', 'space-group', 'group',
            'sidebar_link', 'sidebar-link', 'link',
        ], true)) {
            return false;
        }

        $status = strtolower((string) ($row['status'] ?? ''));
        if (in_array($status, ['draft', 'archived', 'deleted', 'trashed'], true)) {
            return false;
        }

        if ((int) ($row['parent_id'] ?? 0) > 0) {
            return false;
        }

        $title = trim((string) ($row['title'] ?? ''));
        if (preg_match('/\b(community|training|kurs)\s*$/iu', $title)) {
            return false;
        }
        if (preg_match('/\s+-\s+(community|training|kurs)\b/iu', $title)) {
            return false;
        }

        return true;
    }

    private function table_if_exists(string $table): ?string
    {
        global $wpdb;
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        return $found === $table ? $table : null;
    }
}
