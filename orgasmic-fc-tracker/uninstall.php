<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'orgasmic_fc_events');

delete_option('orgasmic_fc_webhook_url');
delete_option('orgasmic_fc_webhook_secret');
delete_option('orgasmic_fc_include_pii');
delete_option('orgasmic_fc_include_content');
delete_option('orgasmic_fc_retention_days');
delete_option('orgasmic_fc_enabled_groups');

wp_clear_scheduled_hook('orgasmic_fc_tracker_cleanup');
