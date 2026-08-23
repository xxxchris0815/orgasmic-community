<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'orgasmic_fc_chat_messages');
$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'orgasmic_fc_chat_reads');

delete_option('orgasmic_fc_chat_db');
delete_option('orgasmic_fc_chat_webhook_url');
delete_option('orgasmic_fc_chat_webhook_secret');
delete_option('orgasmic_fc_chat_include_body');
delete_option('orgasmic_fc_chat_include_pii');
delete_option('orgasmic_fc_chat_poll_seconds');
delete_option('orgasmic_fc_chat_max_length');
delete_option('orgasmic_fc_chat_subtitle');
delete_option('orgasmic_fc_chat_appearance');
delete_option('orgasmic_fc_chat_accent');
delete_option('orgasmic_fc_chat_color_bg');
delete_option('orgasmic_fc_chat_color_text');
delete_option('orgasmic_fc_chat_color_card');
delete_option('orgasmic_fc_chat_color_mine');
delete_option('orgasmic_fc_chat_color_theirs');
delete_option('orgasmic_fc_chat_space_mode');
delete_option('orgasmic_fc_chat_space_ids');
