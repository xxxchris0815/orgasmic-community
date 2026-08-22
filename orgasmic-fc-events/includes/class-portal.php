<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Orgasmic_Fc_Events_Portal
{
    public function __construct(private Orgasmic_Fc_Events_Access $access)
    {
    }

    public function register(): void
    {
        add_action('fluent_community/portal_head', [$this, 'assets']);
        add_action('fluent_community/headless/head', [$this, 'assets']);
        add_action('fluent_community/portal_footer', [$this, 'boot']);
        add_action('fluent_community/headless/footer', [$this, 'boot']);
        add_action('fluent_community/before_header_menu_items', [$this, 'header_item'], 10, 2);
        add_filter('fluent_community/main_menu_items', [$this, 'menu_items'], 20, 2);
        add_filter('fluent_community/mobile_menu', [$this, 'mobile_menu'], 20, 1);
    }

    public function assets(): void
    {
        if (!is_user_logged_in()) {
            return;
        }
        $css = ORGAMSIC_FC_EVENTS_URL . 'assets/portal.css?ver=' . rawurlencode(ORGAMSIC_FC_EVENTS_VERSION);
        echo '<link rel="stylesheet" href="' . esc_url($css) . '" />';
    }

    public function boot(): void
    {
        static $booted = false;
        if ($booted || !is_user_logged_in()) {
            return;
        }
        $booted = true;
        $data = [
            'root' => esc_url_raw(rest_url('orgasmic-events/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'canManage' => $this->access->can_manage(),
        ];
        echo '<script>window.OrgasmicFcEvents = ' . wp_json_encode($data) . ';</script>';
        echo '<script src="' . esc_url(ORGAMSIC_FC_EVENTS_URL . 'assets/portal.js?ver=' . rawurlencode(ORGAMSIC_FC_EVENTS_VERSION)) . '" defer></script>';
        echo '<div id="orgamsic-cal-root" hidden></div>';
    }

    public function header_item($auth, $context = null): void
    {
        if (!$auth && !is_user_logged_in()) {
            return;
        }
        echo '<li class="top_menu_item fcom_icon_link orgasmic-cal-nav">';
        echo '<a href="#orgasmic-calendar" data-orgasmic-calendar="1"><span>Kalender</span></a>';
        echo '</li>';
    }

    public function menu_items($items, $scope = null)
    {
        if (!is_array($items)) {
            return $items;
        }
        $items[] = [
            'title' => 'Kalender',
            'permalink' => '#orgasmic-calendar',
            'slug' => 'orgasmic-calendar',
            'icon' => 'el-icon-date',
            'is_custom' => true,
        ];
        return $items;
    }

    public function mobile_menu($items)
    {
        return $this->menu_items($items);
    }
}
