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
            'users' => (int) $wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM {$subs}"),
            'queued' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$queue} WHERE sent_at IS NULL"),
            'sent' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$queue} WHERE sent_at IS NOT NULL"),
        ];
    }

    private function clip(string $value, int $max): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max);
        }
        return substr($value, 0, $max);
    }
}
