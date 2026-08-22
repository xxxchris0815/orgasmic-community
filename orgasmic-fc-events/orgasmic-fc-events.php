<?php
/**
 * Plugin Name: ORGAMSIC Community Kalender
 * Description: Event calendar inside FluentCommunity with space-based visibility, RSVP, Zoom Server-to-Server meetings, activity-stream sharing, reminders, and REST API.
 * Version: 1.0.2
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: ORGAMSIC
 * License: GPL-2.0-or-later
 * Text Domain: orgasmic-fc-events
 * Requires Plugins: fluent-community
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('ORGAMSIC_FC_EVENTS_VERSION', '1.0.2');
define('ORGAMSIC_FC_EVENTS_FILE', __FILE__);
define('ORGAMSIC_FC_EVENTS_PATH', plugin_dir_path(__FILE__));
define('ORGAMSIC_FC_EVENTS_URL', plugin_dir_url(__FILE__));

require_once ORGAMSIC_FC_EVENTS_PATH . 'includes/class-install.php';
require_once ORGAMSIC_FC_EVENTS_PATH . 'includes/class-access.php';
require_once ORGAMSIC_FC_EVENTS_PATH . 'includes/class-repository.php';
require_once ORGAMSIC_FC_EVENTS_PATH . 'includes/class-zoom.php';
require_once ORGAMSIC_FC_EVENTS_PATH . 'includes/class-feed.php';
require_once ORGAMSIC_FC_EVENTS_PATH . 'includes/class-reminders.php';
require_once ORGAMSIC_FC_EVENTS_PATH . 'includes/class-rest.php';
require_once ORGAMSIC_FC_EVENTS_PATH . 'includes/class-admin.php';
require_once ORGAMSIC_FC_EVENTS_PATH . 'includes/class-portal.php';

final class Orgasmic_Fc_Events
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
        $install = new Orgasmic_Fc_Events_Install();
        register_activation_hook(ORGAMSIC_FC_EVENTS_FILE, [$install, 'activate']);

        add_action('plugins_loaded', static function (): void {
            $install = new Orgasmic_Fc_Events_Install();
            $install->maybe_upgrade();

            $access = new Orgasmic_Fc_Events_Access();
            $repo = new Orgasmic_Fc_Events_Repository();
            $zoom = new Orgasmic_Fc_Events_Zoom();
            $feed = new Orgasmic_Fc_Events_Feed();
            $reminders = new Orgasmic_Fc_Events_Reminders($repo);

            (new Orgasmic_Fc_Events_Rest($access, $repo, $zoom, $feed))->register();
            (new Orgasmic_Fc_Events_Admin($access, $zoom))->register();
            (new Orgasmic_Fc_Events_Portal($access))->register();
            $reminders->register();
        });
    }
}

Orgasmic_Fc_Events::instance();
