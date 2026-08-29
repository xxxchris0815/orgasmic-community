<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Orgasmic_Fc_App_Portal
{
    public function register(): void
    {
        add_action('fluent_community/portal_head', [$this, 'head']);
        add_action('fluent_community/headless/head', [$this, 'head']);
        add_action('fluent_community/portal_footer', [$this, 'boot']);
        add_action('fluent_community/headless/footer', [$this, 'boot']);
        add_action('fluent_community/before_header_menu_items', [$this, 'header_item'], 9, 2);
        add_filter('fluent_community/main_menu_items', [$this, 'menu_items'], 19, 2);
        add_filter('fluent_community/mobile_menu', [$this, 'mobile_menu'], 19, 1);
    }

    public function head(): void
    {
        $theme = sanitize_hex_color((string) get_option(Orgasmic_Fc_App_Install::OPTION_THEME, '#121c30')) ?: '#121c30';
        echo '<link rel="manifest" href="' . esc_url(home_url('/orgasmic-manifest.json')) . '" />';
        echo '<meta name="theme-color" content="' . esc_attr($theme) . '" />';
        echo '<meta name="apple-mobile-web-app-capable" content="yes" />';
        echo '<meta name="apple-mobile-web-app-title" content="ORGASMIC" />';
        echo '<link rel="apple-touch-icon" href="' . esc_url(ORGASMIC_FC_APP_URL . 'assets/icon-192.png') . '" />';
        if (is_user_logged_in()) {
            $css = ORGASMIC_FC_APP_URL . 'assets/app.css?ver=' . rawurlencode(ORGASMIC_FC_APP_VERSION);
            echo '<link rel="stylesheet" href="' . esc_url($css) . '" />';
        }
    }

    public function boot(): void
    {
        static $booted = false;
        if ($booted || !is_user_logged_in()) {
            return;
        }
        $booted = true;
        $data = [
            'root' => esc_url_raw(rest_url('orgasmic-app/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'sw' => esc_url_raw(home_url('/orgasmic-sw.js')),
            'prefs' => Orgasmic_Fc_App_Install::prefs_for(get_current_user_id()),
        ];
        echo '<script>window.OrgasmicFcApp = ' . wp_json_encode($data) . ';</script>';
        echo '<script src="' . esc_url(ORGASMIC_FC_APP_URL . 'assets/app.js?ver=' . rawurlencode(ORGASMIC_FC_APP_VERSION)) . '" defer></script>';
        echo '<div id="orgasmic-app-prompt" hidden></div>';
        echo '<div id="orgasmic-app-prefs" hidden>';
        echo '<div class="orgasmic-app-prefs-overlay"><div class="orgasmic-app-prefs-card" role="dialog" aria-labelledby="orgasmic-app-prefs-title">';
        echo '<header><div><p class="oa-sub">ORGASMIC</p><h2 id="orgasmic-app-prefs-title">Benachrichtigungen</h2></div>';
        echo '<button type="button" class="oa-ghost" data-oa-prefs-close>Schließen</button></header>';
        echo '<p class="oa-prefs-status" data-oa-prefs-status hidden></p>';
        echo '<form data-oa-prefs>';
        echo '<label><input type="checkbox" name="chat" /> Chat</label>';
        echo '<label><input type="checkbox" name="feed" /> Neue Beiträge</label>';
        echo '<label><input type="checkbox" name="comment" /> Kommentare &amp; Mentions</label>';
        echo '<label><input type="checkbox" name="event" /> Events &amp; Erinnerungen</label>';
        echo '<p class="oa-help">Gilt für Push auf diesem Gerät. Ausgeschaltete Arten werden nicht zugestellt.</p>';
        echo '<button type="submit">Speichern</button>';
        echo '</form></div></div></div>';
    }

    public function header_item($auth, $context = null): void
    {
        if (!$auth && !is_user_logged_in()) {
            return;
        }
        echo '<li class="top_menu_item fcom_icon_link orgasmic-app-nav">';
        echo '<a href="#orgasmic-notify" data-orgasmic-notify="1" aria-label="Benachrichtigungen" title="Benachrichtigungen">';
        echo '<svg class="oa-nav-icon" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">';
        echo '<path d="M6 9a6 6 0 1 1 12 0c0 7 3 7 3 9H3c0-2 3-2 3-9"></path>';
        echo '<path d="M10 20a2 2 0 0 0 4 0"></path>';
        echo '</svg>';
        echo '<span class="oa-nav-label">Benachrichtigungen</span>';
        echo '</a></li>';
    }

    public function menu_items($items, $scope = null)
    {
        if (!is_array($items)) {
            return $items;
        }
        $items[] = [
            'title' => 'Benachrichtigungen',
            'permalink' => '#orgasmic-notify',
            'slug' => 'orgasmic-notify',
            'icon' => 'el-icon-bell',
            'is_custom' => true,
        ];
        return $items;
    }

    public function mobile_menu($items)
    {
        return $this->menu_items($items);
    }
}
