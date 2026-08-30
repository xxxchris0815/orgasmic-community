<?php
/**
 * Plugin Name: ORGASMIC App
 * Description: PWA shell, offline cache, and push notifications for chat, posts, comments, and calendar events. Same APIs later wrap in Capacitor for the App Store.
 * Version: 1.1.4
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: ORGASMIC
 * License: GPL-2.0-or-later
 * Text Domain: orgasmic-fc-app
 * Requires Plugins: fluent-community
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('ORGASMIC_FC_APP_VERSION', '1.1.4');
define('ORGASMIC_FC_APP_FILE', __FILE__);
define('ORGASMIC_FC_APP_PATH', plugin_dir_path(__FILE__));
define('ORGASMIC_FC_APP_URL', plugin_dir_url(__FILE__));

require_once ORGASMIC_FC_APP_PATH . 'includes/class-install.php';
require_once ORGASMIC_FC_APP_PATH . 'includes/class-access.php';
require_once ORGASMIC_FC_APP_PATH . 'includes/class-vapid.php';
require_once ORGASMIC_FC_APP_PATH . 'includes/class-webpush.php';
require_once ORGASMIC_FC_APP_PATH . 'includes/class-fcm.php';
require_once ORGASMIC_FC_APP_PATH . 'includes/class-store.php';
require_once ORGASMIC_FC_APP_PATH . 'includes/class-notify.php';
require_once ORGASMIC_FC_APP_PATH . 'includes/class-rest.php';
require_once ORGASMIC_FC_APP_PATH . 'includes/class-admin.php';
require_once ORGASMIC_FC_APP_PATH . 'includes/class-pwa.php';
require_once ORGASMIC_FC_APP_PATH . 'includes/class-portal.php';

final class Orgasmic_Fc_App
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
        $install = new Orgasmic_Fc_App_Install();
        register_activation_hook(ORGASMIC_FC_APP_FILE, [$install, 'activate']);

        add_action('plugins_loaded', static function (): void {
            $install = new Orgasmic_Fc_App_Install();
            $install->maybe_upgrade();

            $access = new Orgasmic_Fc_App_Access();
            $store = new Orgasmic_Fc_App_Store();
            $push = new Orgasmic_Fc_App_WebPush();
            $fcm = new Orgasmic_Fc_App_Fcm();
            $notify = new Orgasmic_Fc_App_Notify($access, $store, $push, $fcm);

            (new Orgasmic_Fc_App_Rest($store, $push, $fcm))->register();
            (new Orgasmic_Fc_App_Admin($store, $push, $fcm))->register();
            (new Orgasmic_Fc_App_Pwa())->register();
            (new Orgasmic_Fc_App_Portal())->register();
            $notify->register();
        });
    }
}

Orgasmic_Fc_App::instance();
