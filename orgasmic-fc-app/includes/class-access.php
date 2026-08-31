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

    public function can_announce(?int $user_id = null): bool
    {
        if ($this->can_manage($user_id)) {
            return true;
        }
        $user_id = $user_id ?: get_current_user_id();
        if (!$user_id) {
            return false;
        }
        if (!class_exists('FluentCommunity\\App\\Services\\Helper')) {
            return false;
        }
        $helper = 'FluentCommunity\\App\\Services\\Helper';
        if (method_exists($helper, 'isModerator')) {
            try {
                if ($user_id === get_current_user_id() && $helper::isModerator()) {
                    return true;
                }
            } catch (Throwable $e) {
            }
        }

        $user = null;
        if (method_exists($helper, 'getCurrentUser') && $user_id === get_current_user_id()) {
            try {
                $user = $helper::getCurrentUser();
            } catch (Throwable $e) {
                $user = null;
            }
        }
        if (!$user && class_exists('FluentCommunity\\App\\Models\\User')) {
            try {
                $user = FluentCommunity\App\Models\User::find($user_id);
            } catch (Throwable $e) {
                $user = null;
            }
        }
        if (!is_object($user)) {
            return false;
        }

        foreach ([
            'hasCommunityAdminAccess',
            'hasCommunityModeratorAccess',
            'isCommunityAdmin',
            'isCommunityModerator',
            'hasSpaceManageAccess',
            'isSpaceModerator',
        ] as $method) {
            if (!method_exists($user, $method)) {
                continue;
            }
            try {
                if ($user->{$method}()) {
                    return true;
                }
            } catch (Throwable $e) {
            }
        }
        if (method_exists($user, 'hasCommunityPermission')) {
            foreach (['community_admin', 'community_moderator'] as $perm) {
                try {
                    if ($user->hasCommunityPermission($perm)) {
                        return true;
                    }
                } catch (Throwable $e) {
                }
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
            return $this->space_notify_ids($space_id);
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

    public function site_admin_ids(): array
    {
        $query = new WP_User_Query([
            'role' => 'administrator',
            'fields' => 'ID',
            'number' => 80,
        ]);

        return $this->normalize_ids($query->get_results() ?: []);
    }

    /**
     * Push audience: room members plus site admins (admins can open every chat,
     * but were missing from FCM unless they had also joined the space).
     */
    public function space_notify_ids(int $space_id): array
    {
        return array_values(array_unique(array_merge(
            $this->space_member_ids($space_id),
            $this->site_admin_ids()
        )));
    }

    public function user_space_ids(int $user_id): array
    {
        if ($user_id < 1) {
            return [];
        }

        global $wpdb;
        $pivot = $this->pivot_table();
        if ($pivot) {
            $ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT space_id FROM {$pivot} WHERE user_id = %d AND (status IS NULL OR status = '' OR status NOT IN ('left','banned','pending','rejected','removed','declined'))",
                    $user_id
                )
            );
            $found = $this->normalize_ids($ids ?: []);
            if ($found !== []) {
                return $found;
            }
        }

        foreach ([
            ['FluentCommunity\\App\\Services\\Helper', 'getUserSpaceIds'],
            ['FluentCommunity\\App\\Functions\\Utility', 'getUserSpaceIds'],
        ] as [$class, $method]) {
            if (class_exists($class) && method_exists($class, $method)) {
                $ids = $this->normalize_ids($class::$method($user_id));
                if ($ids !== []) {
                    return $ids;
                }
            }
        }

        return [];
    }

    /**
     * Groups, rooms, courses, and other FC spaces for admin assignment.
     *
     * @return list<array{id:int,title:string,type:string,kind:string,parent_id:int}>
     */
    public function all_spaces(): array
    {
        global $wpdb;
        $table = $this->table_if_exists($wpdb->prefix . 'fcom_spaces');
        if (!$table) {
            return [];
        }
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
        $columns = is_array($columns) ? $columns : [];
        $select = ['id', 'title'];
        foreach (['type', 'space_type', 'status', 'privacy', 'parent_id', 'parent_space_id'] as $optional) {
            if (in_array($optional, $columns, true)) {
                $select[] = $optional;
            }
        }
        $rows = $wpdb->get_results('SELECT ' . implode(', ', $select) . " FROM {$table} ORDER BY title ASC", ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $status = strtolower((string) ($row['status'] ?? ''));
            if (in_array($status, ['draft', 'archived', 'deleted', 'trashed'], true)) {
                continue;
            }
            $type = strtolower((string) ($row['type'] ?? $row['space_type'] ?? ''));
            $kind = 'room';
            if (in_array($type, ['course', 'courses'], true)) {
                $kind = 'course';
            } elseif (in_array($type, ['space_group', 'space-group', 'group'], true)) {
                $kind = 'group';
            } elseif (in_array($type, ['sidebar_link', 'sidebar-link', 'link'], true)) {
                $kind = 'other';
            }
            $out[] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'type' => $type,
                'kind' => $kind,
                'parent_id' => (int) ($row['parent_id'] ?? $row['parent_space_id'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @return list<array{ID:int,display_name:string,user_email:string,user_login:string}>
     */
    public function list_directory(string $q = '', int $limit = 40): array
    {
        $limit = max(1, min(80, $limit));
        $q = trim($q);
        $args = [
            'number' => $limit,
            'fields' => ['ID', 'display_name', 'user_email', 'user_login'],
            'orderby' => 'display_name',
            'order' => 'ASC',
        ];
        if ($q !== '') {
            if (ctype_digit($q)) {
                $user = get_userdata((int) $q);
                return $user ? [[
                    'ID' => (int) $user->ID,
                    'display_name' => (string) $user->display_name,
                    'user_email' => (string) $user->user_email,
                    'user_login' => (string) $user->user_login,
                ]] : [];
            }
            $args['search'] = '*' . $q . '*';
            $args['search_columns'] = ['user_login', 'user_email', 'display_name', 'user_nicename'];
        } else {
            $ids = $this->community_member_ids();
            if ($ids !== []) {
                $args['include'] = array_slice($ids, 0, 400);
            }
        }

        $out = [];
        foreach ((new WP_User_Query($args))->get_results() as $user) {
            $out[] = [
                'ID' => (int) $user->ID,
                'display_name' => (string) $user->display_name,
                'user_email' => (string) $user->user_email,
                'user_login' => (string) $user->user_login,
            ];
        }

        return $out;
    }

    /**
     * @param list<int> $space_ids
     * @return list<int>
     */
    public function enroll(int $user_id, array $space_ids, string $mode = 'set'): array
    {
        if ($user_id < 1) {
            return [];
        }
        $space_ids = array_values(array_unique(array_filter(array_map('intval', $space_ids))));
        $mode = $mode === 'add' ? 'add' : 'set';
        global $wpdb;
        $pivot = $this->pivot_table();
        if (!$pivot) {
            return $this->user_space_ids($user_id);
        }
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$pivot}");
        $columns = is_array($columns) ? $columns : [];
        $has_status = in_array('status', $columns, true);
        $has_role = in_array('role', $columns, true);
        $now = current_time('mysql');

        if ($mode === 'set') {
            $current = $this->user_space_ids($user_id);
            foreach (array_diff($current, $space_ids) as $sid) {
                if ($has_status) {
                    $wpdb->update($pivot, ['status' => 'left'], ['user_id' => $user_id, 'space_id' => (int) $sid]);
                } else {
                    $wpdb->delete($pivot, ['user_id' => $user_id, 'space_id' => (int) $sid]);
                }
            }
        }

        foreach ($space_ids as $sid) {
            if ($sid < 1) {
                continue;
            }
            $exists = (int) $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM {$pivot} WHERE user_id = %d AND space_id = %d", $user_id, $sid)
            );
            $row = ['user_id' => $user_id, 'space_id' => $sid];
            if ($has_status) {
                $row['status'] = 'active';
            }
            if ($has_role) {
                $row['role'] = 'member';
            }
            if (in_array('updated_at', $columns, true)) {
                $row['updated_at'] = $now;
            }
            if ($exists > 0) {
                $wpdb->update($pivot, $row, ['user_id' => $user_id, 'space_id' => $sid]);
            } else {
                if (in_array('created_at', $columns, true)) {
                    $row['created_at'] = $now;
                }
                $wpdb->insert($pivot, $row);
            }
        }

        return $this->user_space_ids($user_id);
    }

    public function valid_api_key(?string $key): bool
    {
        $stored = '';
        if (class_exists('Orgasmic_Fc_Events_Install')) {
            $stored = (string) get_option(Orgasmic_Fc_Events_Install::OPTION_API_KEY, '');
        }
        return $stored !== '' && is_string($key) && $key !== '' && hash_equals($stored, $key);
    }

    private function pivot_table(): ?string
    {
        global $wpdb;
        $pivot = $this->table_if_exists($wpdb->prefix . 'fcom_space_user');
        if (!$pivot) {
            $pivot = $this->table_if_exists($wpdb->prefix . 'fcom_space_users');
        }

        return $pivot;
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
