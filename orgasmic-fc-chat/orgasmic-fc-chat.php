<?php
/**
 * Plugin Name: ORGASMIC Chat
 * Description: Space chat inside FluentCommunity. One room per space, members only, header icon with unread badge, REST API for portal and future PWA.
 * Version: 1.1.14
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: ORGASMIC
 * License: GPL-2.0-or-later
 * Text Domain: orgasmic-fc-chat
 * Requires Plugins: fluent-community
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('ORGASMIC_FC_CHAT_VERSION', '1.1.14');
define('ORGASMIC_FC_CHAT_FILE', __FILE__);
define('ORGASMIC_FC_CHAT_PATH', plugin_dir_path(__FILE__));
define('ORGASMIC_FC_CHAT_URL', plugin_dir_url(__FILE__));

require_once ORGASMIC_FC_CHAT_PATH . 'includes/class-install.php';
require_once ORGASMIC_FC_CHAT_PATH . 'includes/class-access.php';
require_once ORGASMIC_FC_CHAT_PATH . 'includes/class-repository.php';
require_once ORGASMIC_FC_CHAT_PATH . 'includes/class-webhook.php';
require_once ORGASMIC_FC_CHAT_PATH . 'includes/class-rest.php';
require_once ORGASMIC_FC_CHAT_PATH . 'includes/class-admin.php';
require_once ORGASMIC_FC_CHAT_PATH . 'includes/class-portal.php';

final class Orgasmic_Fc_Chat
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
        $install = new Orgasmic_Fc_Chat_Install();
        register_activation_hook(ORGASMIC_FC_CHAT_FILE, [$install, 'activate']);

        add_action('plugins_loaded', static function (): void {
            $install = new Orgasmic_Fc_Chat_Install();
            $install->maybe_upgrade();

            $access = new Orgasmic_Fc_Chat_Access();
            $repo = new Orgasmic_Fc_Chat_Repository();
            $webhook = new Orgasmic_Fc_Chat_Webhook();

            (new Orgasmic_Fc_Chat_Rest($access, $repo, $webhook))->register();
            (new Orgasmic_Fc_Chat_Admin($access, $repo, $webhook))->register();
            (new Orgasmic_Fc_Chat_Portal($access, $repo))->register();
        });
    }
}

Orgasmic_Fc_Chat::instance();
