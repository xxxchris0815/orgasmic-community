<?php
/**
 * Plugin Name: ORGAMSIC Bunny Embeds
 * Plugin URI: https://community.orgasmic.live
 * Description: Embeds Bunny Stream play links as an inline player in FluentCommunity feeds.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: ORGAMSIC
 * License: GPL-2.0-or-later
 * Text Domain: orgasmic-fc-embeds
 * Requires Plugins: fluent-community
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('ORGAMSIC_FC_EMBEDS_VERSION', '1.0.0');
define('ORGAMSIC_FC_EMBEDS_FILE', __FILE__);
define('ORGAMSIC_FC_EMBEDS_PATH', plugin_dir_path(__FILE__));
define('ORGAMSIC_FC_EMBEDS_URL', plugin_dir_url(__FILE__));

require_once ORGAMSIC_FC_EMBEDS_PATH . 'includes/class-embeds.php';

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
        add_action('plugins_loaded', [$this, 'boot']);
    }

    public function boot(): void
    {
        (new Orgasmic_Fc_Bunny_Embeds())->register();
    }
}

Orgasmic_Fc_Embeds_Plugin::instance();
