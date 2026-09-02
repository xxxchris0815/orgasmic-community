<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'orgasmic_fc_push_subs');
$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'orgasmic_fc_push_queue');
$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'orgasmic_fc_mail_queue');

delete_option('orgasmic_fc_app_db');
delete_option('orgasmic_fc_app_vapid_public');
delete_option('orgasmic_fc_app_vapid_private');
delete_option('orgasmic_fc_app_vapid_subject');
delete_option('orgasmic_fc_app_enabled');
delete_option('orgasmic_fc_app_notify_chat');
delete_option('orgasmic_fc_app_notify_feed');
delete_option('orgasmic_fc_app_notify_comment');
delete_option('orgasmic_fc_app_notify_event');
delete_option('orgasmic_fc_app_include_body');
delete_option('orgasmic_fc_app_start_url');
delete_option('orgasmic_fc_app_theme');
delete_option('orgasmic_fc_app_fcm_json');
delete_option('orgasmic_fc_app_device_logs');

$wpdb->delete($wpdb->usermeta, ['meta_key' => 'orgasmic_fc_app_prefs']);
$wpdb->delete($wpdb->usermeta, ['meta_key' => '_orgasmic_fc_announce_intent']);

wp_clear_scheduled_hook('orgasmic_fc_app_send');
