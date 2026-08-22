<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Orgasmic_Fc_Events_Install
{
    public const DB_VERSION = '1.0.0';
    public const OPTION_DB = 'orgasmic_fc_events_db';
    public const OPTION_ZOOM_ACCOUNT = 'orgasmic_fc_zoom_account_id';
    public const OPTION_ZOOM_CLIENT = 'orgasmic_fc_zoom_client_id';
    public const OPTION_ZOOM_SECRET = 'orgasmic_fc_zoom_client_secret';
    public const OPTION_API_KEY = 'orgasmic_fc_events_api_key';
    public const OPTION_DEFAULT_REMINDERS = 'orgasmic_fc_events_default_reminders';
    public const OPTION_DEFAULT_TZ = 'orgasmic_fc_events_timezone';
    public const OPTION_SUBTITLE = 'orgasmic_fc_events_subtitle';
    public const OPTION_APPEARANCE = 'orgasmic_fc_events_appearance';
    public const OPTION_ACCENT = 'orgasmic_fc_events_accent';
    public const DEFAULT_SUBTITLE = 'Termine für deine Kreise — RSVP, Zoom, wer dabei ist.';

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

        return [
            'subtitle' => $subtitle,
            'appearance' => $appearance,
            'accent' => sanitize_hex_color((string) get_option(self::OPTION_ACCENT, '')) ?: '',
        ];
    }

    public static function events_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'orgasmic_fc_cal_events';
    }

    public static function rsvp_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'orgasmic_fc_cal_rsvps';
    }

    public static function reminder_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'orgasmic_fc_cal_reminders';
    }

    public function activate(): void
    {
        $this->create_tables();
        update_option(self::OPTION_DB, self::DB_VERSION);

        if (get_option(self::OPTION_DEFAULT_REMINDERS, null) === null) {
            update_option(self::OPTION_DEFAULT_REMINDERS, [1440, 60]);
        }

        if (get_option(self::OPTION_DEFAULT_TZ, null) === null) {
            update_option(self::OPTION_DEFAULT_TZ, wp_timezone_string() ?: 'Europe/Berlin');
        }

        if (get_option(self::OPTION_SUBTITLE, null) === null) {
            update_option(self::OPTION_SUBTITLE, self::DEFAULT_SUBTITLE);
        }

        if (get_option(self::OPTION_APPEARANCE, null) === null) {
            update_option(self::OPTION_APPEARANCE, 'auto');
        }

        if (!wp_next_scheduled('orgasmic_fc_events_reminders')) {
            wp_schedule_event(time() + 60, 'orgasmic_five_minutes', 'orgasmic_fc_events_reminders');
        }
    }

    public function maybe_upgrade(): void
    {
        if (get_option(self::OPTION_DB) !== self::DB_VERSION) {
            $this->create_tables();
            update_option(self::OPTION_DB, self::DB_VERSION);
        }

        add_filter('cron_schedules', static function (array $schedules): array {
            $schedules['orgasmic_five_minutes'] = [
                'interval' => 300,
                'display' => 'Every 5 minutes',
            ];
            return $schedules;
        });

        if (!wp_next_scheduled('orgasmic_fc_events_reminders')) {
            wp_schedule_event(time() + 60, 'orgasmic_five_minutes', 'orgasmic_fc_events_reminders');
        }
    }

    private function create_tables(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        $events = self::events_table();
        dbDelta("CREATE TABLE {$events} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(190) NOT NULL,
            slug VARCHAR(190) NULL,
            description LONGTEXT NULL,
            image_id BIGINT UNSIGNED NULL,
            starts_at DATETIME NOT NULL,
            ends_at DATETIME NULL,
            timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/Berlin',
            status VARCHAR(20) NOT NULL DEFAULT 'published',
            visibility VARCHAR(20) NOT NULL DEFAULT 'spaces',
            space_ids LONGTEXT NULL,
            rsvp_enabled TINYINT(1) NOT NULL DEFAULT 1,
            rsvp_capacity INT UNSIGNED NULL,
            location_type VARCHAR(20) NOT NULL DEFAULT 'zoom',
            zoom_user_email VARCHAR(190) NULL,
            zoom_meeting_id VARCHAR(64) NULL,
            zoom_join_url TEXT NULL,
            zoom_start_url TEXT NULL,
            external_url TEXT NULL,
            share_to_feed TINYINT(1) NOT NULL DEFAULT 0,
            feed_ids LONGTEXT NULL,
            reminder_minutes LONGTEXT NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY starts_at (starts_at),
            KEY status_vis (status, visibility)
        ) {$charset};");

        $rsvp = self::rsvp_table();
        dbDelta("CREATE TABLE {$rsvp} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'going',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY event_user (event_id, user_id),
            KEY event_status (event_id, status)
        ) {$charset};");

        $reminders = self::reminder_table();
        dbDelta("CREATE TABLE {$reminders} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id BIGINT UNSIGNED NOT NULL,
            minutes_before INT UNSIGNED NOT NULL,
            fired_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY event_offset (event_id, minutes_before)
        ) {$charset};");
    }
}
