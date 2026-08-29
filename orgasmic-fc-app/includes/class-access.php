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
                "SELECT user_id FROM {$pivot} WHERE space_id = %d AND (status IS NULL OR status = %s OR status = %s)",
                $space_id,
                'active',
                'accepted'
            )
        );

        return array_values(array_unique(array_filter(array_map('intval', $ids ?: []))));
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

    private function table_if_exists(string $table): ?string
    {
        global $wpdb;
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        return $found === $table ? $table : null;
    }
}
