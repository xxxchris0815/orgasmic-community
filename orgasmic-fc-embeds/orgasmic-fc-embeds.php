<?php
/**
 * Plugin Name: ORGASMIC Bunny Embeds
 * Plugin URI: https://community.orgasmic.live
 * Description: Embeds Bunny Stream videos in FluentCommunity, tracks playback, and forwards watch events via webhook.
 * Version: 1.2.16
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: ORGASMIC
 * License: GPL-2.0-or-later
 * Text Domain: orgasmic-fc-embeds
 * Requires Plugins: fluent-community
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('ORGASMIC_FC_EMBEDS_VERSION', '1.2.16');
define('ORGASMIC_FC_EMBEDS_FILE', __FILE__);
define('ORGASMIC_FC_EMBEDS_PATH', plugin_dir_path(__FILE__));
define('ORGASMIC_FC_EMBEDS_URL', plugin_dir_url(__FILE__));

require_once ORGASMIC_FC_EMBEDS_PATH . 'includes/class-store.php';
require_once ORGASMIC_FC_EMBEDS_PATH . 'includes/class-webhook.php';
require_once ORGASMIC_FC_EMBEDS_PATH . 'includes/class-bunny.php';
require_once ORGASMIC_FC_EMBEDS_PATH . 'includes/class-rest.php';
require_once ORGASMIC_FC_EMBEDS_PATH . 'includes/class-admin.php';
require_once ORGASMIC_FC_EMBEDS_PATH . 'includes/class-embeds.php';

final class Orgasmic_Fc_Embeds_Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $store = new Orgasmic_Fc_Embeds_Store();
        register_activation_hook(ORGASMIC_FC_EMBEDS_FILE, [$store, 'install']);

        add_action('plugins_loaded', static function () use ($store): void {
            $store->maybe_upgrade();
            $webhook = new Orgasmic_Fc_Embeds_Webhook();
            $bunny = new Orgasmic_Fc_Embeds_Bunny($store);
            (new Orgasmic_Fc_Embeds_Rest($store, $webhook, $bunny))->register();
            (new Orgasmic_Fc_Embeds_Admin($store, $webhook))->register();
            (new Orgasmic_Fc_Bunny_Embeds($store))->register();
        });

        add_action('orgasmic_fc_embeds_cleanup', [$store, 'cleanup_old']);
        add_action('plugins_loaded', static function (): void {
            if (!wp_next_scheduled('orgasmic_fc_embeds_cleanup')) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'orgasmic_fc_embeds_cleanup');
            }
        });
    }
}

Orgasmic_Fc_Embeds_Plugin::instance();
