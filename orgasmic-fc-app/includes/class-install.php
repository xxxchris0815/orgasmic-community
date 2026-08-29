<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Orgasmic_Fc_App_Install
{
    public const DB_VERSION = '1.0.0';
    public const OPTION_DB = 'orgasmic_fc_app_db';
    public const OPTION_VAPID_PUBLIC = 'orgasmic_fc_app_vapid_public';
    public const OPTION_VAPID_PRIVATE = 'orgasmic_fc_app_vapid_private';
    public const OPTION_VAPID_SUBJECT = 'orgasmic_fc_app_vapid_subject';
    public const OPTION_ENABLED = 'orgasmic_fc_app_enabled';
    public const OPTION_CHAT = 'orgasmic_fc_app_notify_chat';
    public const OPTION_FEED = 'orgasmic_fc_app_notify_feed';
    public const OPTION_COMMENT = 'orgasmic_fc_app_notify_comment';
    public const OPTION_EVENT = 'orgasmic_fc_app_notify_event';
    public const OPTION_INCLUDE_BODY = 'orgasmic_fc_app_include_body';
    public const OPTION_START_URL = 'orgasmic_fc_app_start_url';
    public const OPTION_THEME = 'orgasmic_fc_app_theme';

    public static function subs_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'orgasmic_fc_push_subs';
    }

    public static function queue_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'orgasmic_fc_push_queue';
    }

    public function activate(): void
    {
        $this->create_tables();
        update_option(self::OPTION_DB, self::DB_VERSION);
        $this->ensure_defaults();
        Orgasmic_Fc_App_Vapid::ensure_keys();
        if (!wp_next_scheduled('orgasmic_fc_app_send')) {
            wp_schedule_event(time() + 30, 'orgasmic_one_minute', 'orgasmic_fc_app_send');
        }
    }

    public function maybe_upgrade(): void
    {
        add_filter('cron_schedules', static function (array $schedules): array {
            $schedules['orgasmic_one_minute'] = [
                'interval' => 60,
                'display' => 'Every minute',
            ];
            return $schedules;
        });

        if (get_option(self::OPTION_DB) !== self::DB_VERSION) {
            $this->create_tables();
            update_option(self::OPTION_DB, self::DB_VERSION);
        }
        $this->ensure_defaults();
        Orgasmic_Fc_App_Vapid::ensure_keys();

        if (!wp_next_scheduled('orgasmic_fc_app_send')) {
            wp_schedule_event(time() + 30, 'orgasmic_one_minute', 'orgasmic_fc_app_send');
        }
    }

    private function ensure_defaults(): void
    {
        $defaults = [
            self::OPTION_ENABLED => 1,
            self::OPTION_CHAT => 1,
            self::OPTION_FEED => 1,
            self::OPTION_COMMENT => 1,
            self::OPTION_EVENT => 1,
            self::OPTION_INCLUDE_BODY => 0,
            self::OPTION_START_URL => '/',
            self::OPTION_THEME => '#121c30',
        ];
        foreach ($defaults as $key => $value) {
            if (get_option($key, null) === null) {
                update_option($key, $value);
            }
        }
        if (get_option(self::OPTION_VAPID_SUBJECT, null) === null) {
            $admin = get_option('admin_email');
            update_option(self::OPTION_VAPID_SUBJECT, $admin ? 'mailto:' . $admin : 'mailto:hello@orgasmic.live');
        }
    }

    private function create_tables(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        $subs = self::subs_table();
        dbDelta("CREATE TABLE {$subs} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            endpoint TEXT NOT NULL,
            endpoint_hash CHAR(64) NOT NULL,
            p256dh VARCHAR(255) NOT NULL,
            auth_token VARCHAR(255) NOT NULL,
            content_encoding VARCHAR(32) NOT NULL DEFAULT 'aes128gcm',
            user_agent VARCHAR(190) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY endpoint_hash (endpoint_hash),
            KEY user_id (user_id)
        ) {$charset};");

        $queue = self::queue_table();
        dbDelta("CREATE TABLE {$queue} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            kind VARCHAR(32) NOT NULL,
            title VARCHAR(190) NOT NULL,
            body VARCHAR(255) NOT NULL,
            url TEXT NULL,
            tag VARCHAR(64) NULL,
            payload LONGTEXT NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            available_at DATETIME NOT NULL,
            sent_at DATETIME NULL,
            last_error TEXT NULL,
            PRIMARY KEY  (id),
            KEY pending (sent_at, available_at),
            KEY user_id (user_id)
        ) {$charset};");
    }
}
