<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'orgasmic_fc_video_events');

delete_option('orgasmic_fc_embeds_autoplay');
delete_option('orgasmic_fc_embeds_webhook_url');
delete_option('orgasmic_fc_embeds_webhook_secret');
delete_option('orgasmic_fc_embeds_include_pii');
delete_option('orgasmic_fc_embeds_retention_days');
delete_option('orgasmic_fc_embeds_db');

wp_clear_scheduled_hook('orgasmic_fc_embeds_cleanup');
