<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Orgasmic_Fc_App_Store
{
    public function save_subscription(int $user_id, string $endpoint, string $p256dh, string $auth, string $encoding = 'aes128gcm'): void
    {
        global $wpdb;
        $table = Orgasmic_Fc_App_Install::subs_table();
        $now = gmdate('Y-m-d H:i:s');
        $hash = hash('sha256', $endpoint);
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table} (user_id, endpoint, endpoint_hash, p256dh, auth_token, content_encoding, channel, platform, user_agent, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE
                    user_id = VALUES(user_id),
                    p256dh = VALUES(p256dh),
                    auth_token = VALUES(auth_token),
                    content_encoding = VALUES(content_encoding),
                    channel = VALUES(channel),
                    platform = VALUES(platform),
                    user_agent = VALUES(user_agent),
                    updated_at = VALUES(updated_at)",
                $user_id,
                $endpoint,
                $hash,
                $p256dh,
                $auth,
                $encoding ?: 'aes128gcm',
                'web',
                '',
                substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 190),
                $now,
                $now
            )
        );
    }

    public function save_token(int $user_id, string $token, string $platform = ''): void
    {
        $token = trim($token);
        $platform = sanitize_key($platform);
        if ($token === '') {
            return;
        }
        $endpoint = 'fcm:' . $token;
        global $wpdb;
        $table = Orgasmic_Fc_App_Install::subs_table();
        $now = gmdate('Y-m-d H:i:s');
        $hash = hash('sha256', $endpoint);
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table} (user_id, endpoint, endpoint_hash, p256dh, auth_token, content_encoding, channel, platform, user_agent, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE
                    user_id = VALUES(user_id),
                    channel = VALUES(channel),
                    platform = VALUES(platform),
                    user_agent = VALUES(user_agent),
                    updated_at = VALUES(updated_at)",
                $user_id,
                $endpoint,
                $hash,
                '-',
                '-',
                'fcm',
                'fcm',
                $platform,
                substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 190),
                $now,
                $now
            )
        );
    }

    public function delete_endpoint(string $endpoint, ?int $user_id = null): void
    {
        global $wpdb;
        $table = Orgasmic_Fc_App_Install::subs_table();
        $hash = hash('sha256', $endpoint);
        if ($user_id) {
            $wpdb->delete($table, ['endpoint_hash' => $hash, 'user_id' => $user_id], ['%s', '%d']);
            return;
        }
        $wpdb->delete($table, ['endpoint_hash' => $hash], ['%s']);
    }

    public function delete_for_user(int $user_id): void
    {
        if ($user_id < 1) {
            return;
        }
        global $wpdb;
        $wpdb->delete(Orgasmic_Fc_App_Install::subs_table(), ['user_id' => $user_id], ['%d']);
        $wpdb->delete(Orgasmic_Fc_App_Install::queue_table(), ['user_id' => $user_id], ['%d']);
        $mail = Orgasmic_Fc_App_Install::mail_table();
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $mail));
        if ($found === $mail) {
            $wpdb->delete($mail, ['user_id' => $user_id], ['%d']);
        }
        delete_user_meta($user_id, Orgasmic_Fc_App_Install::META_PREFS);
        delete_user_meta($user_id, Orgasmic_Fc_App_Install::META_ANNOUNCE);
        foreach ([$wpdb->prefix . 'fcom_space_user', $wpdb->prefix . 'fcom_space_users'] as $pivot) {
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $pivot));
            if ($exists === $pivot) {
                $wpdb->delete($pivot, ['user_id' => $user_id], ['%d']);
            }
        }
    }

    public function subscriptions_for_users(array $user_ids): array
    {
        $user_ids = array_values(array_unique(array_filter(array_map('intval', $user_ids))));
        if ($user_ids === []) {
            return [];
        }

        global $wpdb;
        $table = Orgasmic_Fc_App_Install::subs_table();
        $in = implode(',', $user_ids);
        return $wpdb->get_results(
            "SELECT * FROM {$table} WHERE user_id IN ({$in})",
            ARRAY_A
        ) ?: [];
    }

    public function enqueue(array $user_ids, string $kind, string $title, string $body, string $url, string $tag, array $extra = []): int
    {
        $user_ids = array_values(array_unique(array_filter(array_map('intval', $user_ids))));
        if ($user_ids === []) {
            return 0;
        }

        global $wpdb;
        $table = Orgasmic_Fc_App_Install::queue_table();
        $now = gmdate('Y-m-d H:i:s');
        $payload = wp_json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $count = 0;
        foreach ($user_ids as $user_id) {
            $ok = $wpdb->insert($table, [
                'user_id' => $user_id,
                'kind' => $kind,
                'title' => $this->clip($title, 190),
                'body' => $this->clip($body, 255),
                'url' => $url,
                'tag' => $this->clip($tag, 64),
                'payload' => $payload,
                'attempts' => 0,
                'available_at' => $now,
                'sent_at' => null,
                'last_error' => null,
            ], ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s']);
            if ($ok) {
                $count++;
            }
        }

        return $count;
    }

    public function pending(int $limit = 40): array
    {
        global $wpdb;
        $table = Orgasmic_Fc_App_Install::queue_table();
        $limit = max(1, min(100, $limit));
        $now = gmdate('Y-m-d H:i:s');
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE sent_at IS NULL AND available_at <= %s AND attempts < 8 ORDER BY id ASC LIMIT {$limit}",
                $now
            ),
            ARRAY_A
        ) ?: [];
    }

    public function mark_sent(int $id): void
    {
        global $wpdb;
        $wpdb->update(
            Orgasmic_Fc_App_Install::queue_table(),
            ['sent_at' => gmdate('Y-m-d H:i:s'), 'last_error' => null],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        );
    }

    public function mark_retry(int $id, string $error, int $status = 0): void
    {
        global $wpdb;
        $table = Orgasmic_Fc_App_Install::queue_table();
        $delay = $status === 429 || $status >= 500 ? 300 : 60;
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table}
                 SET attempts = attempts + 1,
                     available_at = %s,
                     last_error = %s
                 WHERE id = %d",
                gmdate('Y-m-d H:i:s', time() + $delay),
                $this->clip($error, 500),
                $id
            )
        );
    }

    public function counts(): array
    {
        global $wpdb;
        $subs = Orgasmic_Fc_App_Install::subs_table();
        $queue = Orgasmic_Fc_App_Install::queue_table();
        return [
            'devices' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$subs}"),
            'fcm' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$subs} WHERE channel IN ('fcm','apns')"),
            'web' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$subs} WHERE channel = 'web' OR channel = ''"),
            'users' => (int) $wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM {$subs}"),
            'queued' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$queue} WHERE sent_at IS NULL"),
            'sent' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$queue} WHERE sent_at IS NOT NULL"),
            'mail_queued' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Orgasmic_Fc_App_Install::mail_table() . ' WHERE sent_at IS NULL'),
        ];
    }

    public function channels_for_user(int $user_id): array
    {
        $out = ['fcm' => 0, 'web' => 0];
        foreach ($this->subscriptions_for_users([$user_id]) as $sub) {
            $channel = (string) ($sub['channel'] ?? 'web');
            if ($channel === 'fcm' || $channel === 'apns') {
                $out['fcm']++;
            } else {
                $out['web']++;
            }
        }
        return $out;
    }

    /**
     * Latest FCM devices so admins can see whose phone actually registered.
     *
     * @return list<array{user_id:int,display:string,email:string,platform:string,updated:string}>
     */
    public function recent_fcm(int $limit = 50): array
    {
        global $wpdb;
        $table = Orgasmic_Fc_App_Install::subs_table();
        $n = max(1, min(50, $limit));
        $rows = $wpdb->get_results(
            "SELECT user_id, platform, updated_at FROM {$table} WHERE channel IN ('fcm','apns') ORDER BY updated_at DESC LIMIT {$n}",
            ARRAY_A
        );
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $uid = (int) ($row['user_id'] ?? 0);
            $user = $uid ? get_userdata($uid) : false;
            $out[] = [
                'user_id' => $uid,
                'display' => $user ? (string) $user->display_name : ('#' . $uid),
                'email' => $user ? (string) $user->user_email : '',
                'platform' => (string) ($row['platform'] ?? ''),
                'updated' => (string) ($row['updated_at'] ?? ''),
            ];
        }

        return $out;
    }

    public function enqueue_mail(array $user_ids, int $feed_id, string $subject, string $body, string $url): int
    {
        $user_ids = array_values(array_unique(array_filter(array_map('intval', $user_ids))));
        if ($user_ids === []) {
            return 0;
        }

        global $wpdb;
        $table = Orgasmic_Fc_App_Install::mail_table();
        $now = gmdate('Y-m-d H:i:s');
        $count = 0;
        foreach ($user_ids as $user_id) {
            $exists = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE feed_id = %d AND user_id = %d LIMIT 1",
                    $feed_id,
                    $user_id
                )
            );
            if ($exists > 0) {
                continue;
            }
            $ok = $wpdb->insert($table, [
                'user_id' => $user_id,
                'feed_id' => $feed_id,
                'subject' => $this->clip($subject, 190),
                'body' => $body,
                'url' => $url,
                'attempts' => 0,
                'available_at' => $now,
                'sent_at' => null,
                'last_error' => null,
            ], ['%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s']);
            if ($ok) {
                $count++;
            }
        }

        return $count;
    }

    public function pending_mail(int $limit = 20): array
    {
        global $wpdb;
        $table = Orgasmic_Fc_App_Install::mail_table();
        $limit = max(1, min(50, $limit));
        $now = gmdate('Y-m-d H:i:s');

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE sent_at IS NULL AND available_at <= %s AND attempts < 8 ORDER BY id ASC LIMIT {$limit}",
                $now
            ),
            ARRAY_A
        ) ?: [];
    }

    public function mark_mail_sent(int $id): void
    {
        global $wpdb;
        $wpdb->update(
            Orgasmic_Fc_App_Install::mail_table(),
            ['sent_at' => gmdate('Y-m-d H:i:s'), 'last_error' => null],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        );
    }

    public function mark_mail_retry(int $id, string $error): void
    {
        global $wpdb;
        $table = Orgasmic_Fc_App_Install::mail_table();
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table}
                 SET attempts = attempts + 1,
                     available_at = %s,
                     last_error = %s
                 WHERE id = %d",
                gmdate('Y-m-d H:i:s', time() + 120),
                $this->clip($error, 500),
                $id
            )
        );
    }

    public function last_queue_for(int $user_id): ?array
    {
        $rows = $this->queue_for_user($user_id, 1);

        return $rows[0] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function queue_for_user(int $user_id, int $limit = 12): array
    {
        if ($user_id < 1) {
            return [];
        }
        global $wpdb;
        $table = Orgasmic_Fc_App_Install::queue_table();
        $n = max(1, min(50, $limit));

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, kind, title, body, attempts, available_at, sent_at, last_error
                 FROM {$table} WHERE user_id = %d ORDER BY id DESC LIMIT {$n}",
                $user_id
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * @return list<array{ID:int,display_name:string,user_email:string,user_login:string}>
     */
    public function search_members(string $q, int $limit = 8): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        $n = max(1, min(20, $limit));
        if (ctype_digit($q)) {
            $user = get_userdata((int) $q);
            if ($user) {
                return [[
                    'ID' => (int) $user->ID,
                    'display_name' => (string) $user->display_name,
                    'user_email' => (string) $user->user_email,
                    'user_login' => (string) $user->user_login,
                ]];
            }
        }

        $query = new WP_User_Query([
            'search' => '*' . $q . '*',
            'search_columns' => ['user_login', 'user_email', 'display_name', 'user_nicename'],
            'number' => $n,
            'fields' => ['ID', 'display_name', 'user_email', 'user_login'],
            'orderby' => 'display_name',
            'order' => 'ASC',
        ]);
        $out = [];
        foreach ($query->get_results() as $user) {
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
     * @param array<string, mixed> $row
     */
    public function save_device_log(array $row): void
    {
        $logs = get_option(Orgasmic_Fc_App_Install::OPTION_DEVICE_LOGS, []);
        if (!is_array($logs)) {
            $logs = [];
        }
        array_unshift($logs, $row);
        $logs = array_slice($logs, 0, 40);
        update_option(Orgasmic_Fc_App_Install::OPTION_DEVICE_LOGS, $logs, false);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function device_logs(): array
    {
        $logs = get_option(Orgasmic_Fc_App_Install::OPTION_DEVICE_LOGS, []);

        return is_array($logs) ? $logs : [];
    }

    public function clear_device_logs(): void
    {
        delete_option(Orgasmic_Fc_App_Install::OPTION_DEVICE_LOGS);
    }

    private function clip(string $value, int $max): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max);
        }
        return substr($value, 0, $max);
    }
}
