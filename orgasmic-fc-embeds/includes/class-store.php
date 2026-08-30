<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Orgasmic_Fc_Embeds_Store
{
    public const OPTION_AUTOPLAY = 'orgasmic_fc_embeds_autoplay';
    public const OPTION_LIBRARY_ID = 'orgasmic_fc_embeds_library_id';
    public const OPTION_API_KEY = 'orgasmic_fc_embeds_api_key';
    public const OPTION_COLLECTION_ID = 'orgasmic_fc_embeds_collection_id';
    public const OPTION_WEBHOOK_URL = 'orgasmic_fc_embeds_webhook_url';
    public const OPTION_WEBHOOK_SECRET = 'orgasmic_fc_embeds_webhook_secret';
    public const OPTION_INCLUDE_PII = 'orgasmic_fc_embeds_include_pii';
    public const OPTION_RETENTION_DAYS = 'orgasmic_fc_embeds_retention_days';
    public const OPTION_DB = 'orgasmic_fc_embeds_db';
    public const DB_VERSION = 1;

    public static function table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'orgasmic_fc_video_events';
    }

    public function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            occurred_at DATETIME NOT NULL,
            event VARCHAR(32) NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            library_id VARCHAR(32) NOT NULL DEFAULT '',
            video_id VARCHAR(64) NOT NULL DEFAULT '',
            seconds DECIMAL(10,2) NOT NULL DEFAULT 0,
            duration DECIMAL(10,2) NOT NULL DEFAULT 0,
            max_seconds DECIMAL(10,2) NOT NULL DEFAULT 0,
            percent DECIMAL(5,2) NOT NULL DEFAULT 0,
            page_url VARCHAR(500) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            KEY occurred_at (occurred_at),
            KEY user_video (user_id, video_id),
            KEY event (event)
        ) {$charset};");

        update_option(self::OPTION_DB, self::DB_VERSION);
        if (get_option(self::OPTION_AUTOPLAY, null) === null) {
            add_option(self::OPTION_AUTOPLAY, '1');
        }
        if (get_option(self::OPTION_INCLUDE_PII, null) === null) {
            add_option(self::OPTION_INCLUDE_PII, '1');
        }
        if (get_option(self::OPTION_RETENTION_DAYS, null) === null) {
            add_option(self::OPTION_RETENTION_DAYS, 90);
        }
    }

    public function maybe_upgrade(): void
    {
        if ((int) get_option(self::OPTION_DB, 0) < self::DB_VERSION) {
            $this->install();
        }
    }

    public function autoplay(): bool
    {
        return (bool) get_option(self::OPTION_AUTOPLAY, '1');
    }

    public function library_id(): string
    {
        return preg_replace('/[^0-9]/', '', (string) get_option(self::OPTION_LIBRARY_ID, '')) ?: '';
    }

    public function api_key(): string
    {
        return trim((string) get_option(self::OPTION_API_KEY, ''));
    }

    public function collection_id(): string
    {
        return sanitize_text_field((string) get_option(self::OPTION_COLLECTION_ID, ''));
    }

    public function upload_configured(): bool
    {
        return $this->library_id() !== '' && $this->api_key() !== '';
    }

    public function insert(array $row): int
    {
        global $wpdb;
        $wpdb->insert(self::table_name(), [
            'occurred_at' => $row['occurred_at'],
            'event' => $row['event'],
            'user_id' => $row['user_id'] ?: null,
            'library_id' => $row['library_id'],
            'video_id' => $row['video_id'],
            'seconds' => $row['seconds'],
            'duration' => $row['duration'],
            'max_seconds' => $row['max_seconds'],
            'percent' => $row['percent'],
            'page_url' => $row['page_url'],
        ], ['%s', '%s', '%d', '%s', '%s', '%f', '%f', '%f', '%f', '%s']);

        return (int) $wpdb->insert_id;
    }

    public function recent(int $limit = 50): array
    {
        global $wpdb;
        $limit = max(1, min(200, $limit));
        $table = self::table_name();

        return $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit),
            ARRAY_A
        ) ?: [];
    }

    public function cleanup_old(): void
    {
        global $wpdb;
        $days = (int) get_option(self::OPTION_RETENTION_DAYS, 90);
        if ($days < 1) {
            return;
        }
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . self::table_name() . ' WHERE occurred_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)',
            $days
        ));
    }
}
