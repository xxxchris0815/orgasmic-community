<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'orgasmic_fc_cal_events');
$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'orgasmic_fc_cal_rsvps');
$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'orgasmic_fc_cal_reminders');

delete_option('orgasmic_fc_events_db');
delete_option('orgasmic_fc_zoom_account_id');
delete_option('orgasmic_fc_zoom_client_id');
delete_option('orgasmic_fc_zoom_client_secret');
delete_option('orgasmic_fc_events_api_key');
delete_option('orgasmic_fc_events_default_reminders');
delete_option('orgasmic_fc_events_timezone');

wp_clear_scheduled_hook('orgasmic_fc_events_reminders');
delete_transient('orgasmic_fc_zoom_token');
delete_transient('orgasmic_fc_zoom_users');
