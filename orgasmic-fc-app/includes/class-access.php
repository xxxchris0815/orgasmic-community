<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Orgasmic_Fc_App_Access
{
    public function space_member_ids(int $space_id): array
    {
        if ($space_id < 1) {
            return [];
        }

        foreach ([
            ['FluentCommunity\\App\\Functions\\Utility', 'getSpaceUserIds'],
            ['FluentCommunity\\App\\Services\\Helper', 'getSpaceUserIds'],
        ] as [$class, $method]) {
            if (class_exists($class) && method_exists($class, $method)) {
                $ids = $this->normalize_ids($class::$method($space_id));
                if ($ids !== []) {
                    return $ids;
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

        // FC labels membership differently across versions (active / accepted / joined / member).
        // Only drop people who left, were banned, or are still waiting for approval.
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT user_id FROM {$pivot} WHERE space_id = %d AND (status IS NULL OR status = '' OR status NOT IN ('left','banned','pending','rejected','removed','declined'))",
                $space_id
            )
        );

        return $this->normalize_ids($ids ?: []);
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

    /**
     * People allowed to see this post. Space posts stay inside the room (no secret-circle leak).
     * Community feed (no space) goes to every FluentCommunity profile.
     */
    public function audience_ids(int $space_id): array
    {
        if ($space_id > 0) {
            return $this->space_member_ids($space_id);
        }

        return $this->community_member_ids();
    }

    public function community_member_ids(): array
    {
        global $wpdb;
        $table = $this->table_if_exists($wpdb->prefix . 'fcom_xprofile');
        if ($table) {
            $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
            $sql = "SELECT user_id FROM {$table} WHERE user_id > 0";
            if (is_array($columns) && in_array('status', $columns, true)) {
                $sql .= " AND (status IS NULL OR status = '' OR status NOT IN ('inactive','deactivated','banned','blocked','deleted'))";
            }
            return $this->normalize_ids($wpdb->get_col($sql) ?: []);
        }

        return $this->normalize_ids(get_users([
            'fields' => 'ID',
            'number' => 8000,
        ]));
    }

    public function space_title(int $space_id): string
    {
        global $wpdb;
        $table = $this->table_if_exists($wpdb->prefix . 'fcom_spaces');
        if (!$table || $space_id < 1) {
            return 'Kreis';
        }
        $title = $wpdb->get_var($wpdb->prepare("SELECT title FROM {$table} WHERE id = %d", $space_id));
        return $title ? (string) $title : 'Kreis';
    }

    public function prop($model, string $key)
    {
        if (is_array($model) && array_key_exists($key, $model)) {
            return $model[$key];
        }
        if (!is_object($model)) {
            return null;
        }
        if (isset($model->{$key})) {
            return $model->{$key};
        }
        if (method_exists($model, 'getAttribute')) {
            try {
                return $model->getAttribute($key);
            } catch (Throwable $e) {
                return null;
            }
        }

        return null;
    }

    public function model_id($model): int
    {
        $id = $this->prop($model, 'id');
        if ($id === null || $id === '') {
            return is_numeric($model) ? (int) $model : 0;
        }

        return (int) $id;
    }

    public function load_feed(int $feed_id)
    {
        if ($feed_id < 1) {
            return null;
        }
        if (class_exists('FluentCommunity\\App\\Models\\Feed') && method_exists('FluentCommunity\\App\\Models\\Feed', 'find')) {
            try {
                $feed = FluentCommunity\App\Models\Feed::find($feed_id);
                return $feed ?: null;
            } catch (Throwable $e) {
                return null;
            }
        }

        return null;
    }

    public function decode_ids($value): array
    {
        if (is_array($value)) {
            return array_values(array_unique(array_filter(array_map('intval', $value))));
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $this->decode_ids($decoded) : [];
    }

    private function normalize_ids($ids): array
    {
        if ($ids instanceof Traversable) {
            $ids = iterator_to_array($ids);
        }
        if (!is_array($ids)) {
            return [];
        }
        $out = [];
        foreach ($ids as $item) {
            if (is_numeric($item)) {
                $out[] = (int) $item;
                continue;
            }
            if (is_object($item)) {
                $out[] = (int) ($item->user_id ?? $item->ID ?? $item->id ?? 0);
                continue;
            }
            if (is_array($item)) {
                $out[] = (int) ($item['user_id'] ?? $item['ID'] ?? $item['id'] ?? 0);
            }
        }

        return array_values(array_unique(array_filter($out)));
    }

    private function table_if_exists(string $table): ?string
    {
        global $wpdb;
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        return $found === $table ? $table : null;
    }
}
