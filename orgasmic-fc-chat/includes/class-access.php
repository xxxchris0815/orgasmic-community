<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Orgasmic_Fc_Chat_Access
{
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

    public function user_space_ids(int $user_id): array
    {
        if (class_exists('FluentCommunity\\App\\Services\\Helper')) {
            $helper = 'FluentCommunity\\App\\Services\\Helper';
            if (method_exists($helper, 'getUserSpaceIds')) {
                $ids = $helper::getUserSpaceIds($user_id);
                if (is_array($ids)) {
                    return array_values(array_unique(array_map('intval', $ids)));
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

        return array_values(array_unique(array_map('intval', $ids ?: [])));
    }

    public function can_access_space(int $space_id, ?int $user_id = null): bool
    {
        $user_id = $user_id ?: get_current_user_id();
        if (!$user_id || $space_id < 1) {
            return false;
        }
        if ($this->can_manage($user_id)) {
            return true;
        }

        return in_array($space_id, $this->user_space_ids($user_id), true);
    }

    public function visible_space_ids(int $user_id): array
    {
        $owned = $this->user_space_ids($user_id);
        if (!$this->can_manage($user_id)) {
            return $owned;
        }

        $all = array_map(static fn(array $space): int => $space['id'], $this->list_spaces());
        return array_values(array_unique(array_merge($all, $owned)));
    }

    public function list_spaces(): array
    {
        global $wpdb;
        $table = $this->table_if_exists($wpdb->prefix . 'fcom_spaces');
        if (!$table) {
            return [];
        }

        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
        $columns = is_array($columns) ? $columns : [];
        $select = ['id', 'title', 'slug'];
        foreach (['privacy', 'type', 'status', 'logo', 'logo_url', 'icon'] as $optional) {
            if (in_array($optional, $columns, true)) {
                $select[] = $optional;
            }
        }

        $sql = 'SELECT ' . implode(', ', $select) . " FROM {$table} ORDER BY title ASC";
        $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $type = (string) ($row['type'] ?? '');
            if (in_array($type, ['course', 'courses'], true)) {
                continue;
            }
            $status = strtolower((string) ($row['status'] ?? ''));
            if (in_array($status, ['draft', 'archived', 'deleted', 'trashed'], true)) {
                continue;
            }
            $logo = (string) ($row['logo'] ?? $row['logo_url'] ?? $row['icon'] ?? '');
            $out[] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'slug' => (string) ($row['slug'] ?? ''),
                'privacy' => (string) ($row['privacy'] ?? ''),
                'type' => $type,
                'logo' => $this->normalize_logo($logo),
            ];
        }

        return $out;
    }

    public function space_map(): array
    {
        $map = [];
        foreach ($this->list_spaces() as $space) {
            $map[$space['id']] = $space;
        }

        return $map;
    }

    public function user_payload(int $user_id): array
    {
        $user = get_userdata($user_id);
        if (!$user) {
            return [
                'id' => $user_id,
                'display_name' => 'Mitglied #' . $user_id,
                'avatar' => '',
            ];
        }

        return [
            'id' => $user_id,
            'display_name' => (string) $user->display_name,
            'avatar' => get_avatar_url($user_id, ['size' => 96]) ?: '',
        ];
    }

    private function normalize_logo(string $logo): string
    {
        $logo = trim($logo);
        if ($logo === '') {
            return '';
        }
        if (str_starts_with($logo, 'http://') || str_starts_with($logo, 'https://') || str_starts_with($logo, '//')) {
            return esc_url_raw($logo);
        }
        if (is_numeric($logo)) {
            $url = wp_get_attachment_image_url((int) $logo, 'thumbnail');
            return $url ? esc_url_raw($url) : '';
        }

        return '';
    }

    private function table_if_exists(string $table): ?string
    {
        global $wpdb;
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        return $found === $table ? $table : null;
    }
}
