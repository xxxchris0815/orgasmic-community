<?php
/**
 * Plugin Name: ORGAMSIC FluentCommunity Tracker
 * Plugin URI: https://community.orgasmic.live
 * Description: Tracks FluentCommunity lesson progress and community engagement, stores a local activity log, optionally forwards events via webhook, and embeds Bunny Stream videos inline.
 * Version: 1.1.1
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: ORGAMSIC
 * License: GPL-2.0-or-later
 * Text Domain: orgasmic-fc-tracker
 * Requires Plugins: fluent-community
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('ORGAMSIC_FC_TRACKER_VERSION', '1.1.1');
define('ORGAMSIC_FC_TRACKER_FILE', __FILE__);
define('ORGAMSIC_FC_TRACKER_PATH', plugin_dir_path(__FILE__));
define('ORGAMSIC_FC_TRACKER_URL', plugin_dir_url(__FILE__));

require_once ORGAMSIC_FC_TRACKER_PATH . 'includes/class-store.php';
require_once ORGAMSIC_FC_TRACKER_PATH . 'includes/class-webhook.php';
require_once ORGAMSIC_FC_TRACKER_PATH . 'includes/class-hooks.php';
require_once ORGAMSIC_FC_TRACKER_PATH . 'includes/class-admin.php';
require_once ORGAMSIC_FC_TRACKER_PATH . 'includes/class-rest.php';
require_once ORGAMSIC_FC_TRACKER_PATH . 'includes/class-embeds.php';

final class Orgasmic_Fc_Tracker
{
    private static ?self $instance = null;

    public Orgasmic_Fc_Store $store;
    public Orgasmic_Fc_Webhook $webhook;
    public Orgasmic_Fc_Hooks $hooks;
    public Orgasmic_Fc_Admin $admin;
    public Orgasmic_Fc_Rest $rest;
    public Orgasmic_Fc_Embeds $embeds;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $this->store = new Orgasmic_Fc_Store();
        $this->webhook = new Orgasmic_Fc_Webhook();
        $this->hooks = new Orgasmic_Fc_Hooks($this->store, $this->webhook);
        $this->admin = new Orgasmic_Fc_Admin($this->store, $this->webhook);
        $this->rest = new Orgasmic_Fc_Rest($this->store);
        $this->embeds = new Orgasmic_Fc_Embeds();

        register_activation_hook(ORGAMSIC_FC_TRACKER_FILE, [$this->store, 'install']);
        add_action('plugins_loaded', [$this, 'boot']);
        add_action('orgasmic_fc_tracker_cleanup', [$this->store, 'cleanup_old_events']);
    }

    public function boot(): void
    {
        if (!wp_next_scheduled('orgasmic_fc_tracker_cleanup')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'orgasmic_fc_tracker_cleanup');
        }

        $this->hooks->register();
        $this->admin->register();
        $this->rest->register();
        $this->embeds->register();
        $this->store->upgrade();
    }
}

Orgasmic_Fc_Tracker::instance();
