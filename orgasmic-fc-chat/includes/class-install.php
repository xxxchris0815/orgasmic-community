<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Orgasmic_Fc_Chat_Install
{
    public const DB_VERSION = '1.0.0';
    public const OPTION_DB = 'orgasmic_fc_chat_db';
    public const OPTION_WEBHOOK_URL = 'orgasmic_fc_chat_webhook_url';
    public const OPTION_WEBHOOK_SECRET = 'orgasmic_fc_chat_webhook_secret';
    public const OPTION_INCLUDE_BODY = 'orgasmic_fc_chat_include_body';
    public const OPTION_INCLUDE_PII = 'orgasmic_fc_chat_include_pii';
    public const OPTION_POLL_SECONDS = 'orgasmic_fc_chat_poll_seconds';
    public const OPTION_MAX_LENGTH = 'orgasmic_fc_chat_max_length';
    public const OPTION_SUBTITLE = 'orgasmic_fc_chat_subtitle';
    public const OPTION_APPEARANCE = 'orgasmic_fc_chat_appearance';
    public const OPTION_ACCENT = 'orgasmic_fc_chat_accent';
    public const DEFAULT_SUBTITLE = 'Ein Chat pro Kreis — nur für Mitglieder.';

    public static function portal_settings(): array
    {
        $subtitle = (string) get_option(self::OPTION_SUBTITLE, self::DEFAULT_SUBTITLE);
        if (trim($subtitle) === '') {
            $subtitle = self::DEFAULT_SUBTITLE;
        }
        $appearance = (string) get_option(self::OPTION_APPEARANCE, 'auto');
        if (!in_array($appearance, ['auto', 'light', 'dark'], true)) {
            $appearance = 'auto';
        }
        $poll = (int) get_option(self::OPTION_POLL_SECONDS, 6);
        if ($poll < 3) {
            $poll = 3;
        }
        if ($poll > 30) {
            $poll = 30;
        }
        $max = (int) get_option(self::OPTION_MAX_LENGTH, 2000);
        if ($max < 200) {
            $max = 200;
        }
        if ($max > 8000) {
            $max = 8000;
        }

        return [
            'subtitle' => $subtitle,
            'appearance' => $appearance,
            'accent' => sanitize_hex_color((string) get_option(self::OPTION_ACCENT, '')) ?: '',
            'poll_seconds' => $poll,
            'max_length' => $max,
        ];
    }

    public static function messages_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'orgasmic_fc_chat_messages';
    }

    public static function reads_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'orgasmic_fc_chat_reads';
    }

    public function activate(): void
    {
        $this->create_tables();
        update_option(self::OPTION_DB, self::DB_VERSION);
        $this->ensure_defaults();
    }

    public function maybe_upgrade(): void
    {
        if (get_option(self::OPTION_DB) !== self::DB_VERSION) {
            $this->create_tables();
            update_option(self::OPTION_DB, self::DB_VERSION);
        }
        $this->ensure_defaults();
    }

    private function ensure_defaults(): void
    {
        if (get_option(self::OPTION_INCLUDE_BODY, null) === null) {
            update_option(self::OPTION_INCLUDE_BODY, 0);
        }
        if (get_option(self::OPTION_INCLUDE_PII, null) === null) {
            update_option(self::OPTION_INCLUDE_PII, 1);
        }
        if (get_option(self::OPTION_POLL_SECONDS, null) === null) {
            update_option(self::OPTION_POLL_SECONDS, 6);
        }
        if (get_option(self::OPTION_MAX_LENGTH, null) === null) {
            update_option(self::OPTION_MAX_LENGTH, 2000);
        }
        if (get_option(self::OPTION_SUBTITLE, null) === null) {
            update_option(self::OPTION_SUBTITLE, self::DEFAULT_SUBTITLE);
        }
        if (get_option(self::OPTION_APPEARANCE, null) === null) {
            update_option(self::OPTION_APPEARANCE, 'auto');
        }
    }

    private function create_tables(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        $messages = self::messages_table();
        dbDelta("CREATE TABLE {$messages} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            space_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            body TEXT NOT NULL,
            attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            deleted_at DATETIME NULL,
            PRIMARY KEY  (id),
            KEY space_id_id (space_id, id),
            KEY space_created (space_id, created_at)
        ) {$charset};");

        $reads = self::reads_table();
        dbDelta("CREATE TABLE {$reads} (
            user_id BIGINT UNSIGNED NOT NULL,
            space_id BIGINT UNSIGNED NOT NULL,
            last_read_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            last_read_at DATETIME NOT NULL,
            PRIMARY KEY  (user_id, space_id),
            KEY space_read (space_id, last_read_id)
        ) {$charset};");
    }
}
