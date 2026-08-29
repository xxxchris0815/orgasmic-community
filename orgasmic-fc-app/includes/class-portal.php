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
        ];
        echo '<script>window.OrgasmicFcApp = ' . wp_json_encode($data) . ';</script>';
        echo '<script src="' . esc_url(ORGASMIC_FC_APP_URL . 'assets/app.js?ver=' . rawurlencode(ORGASMIC_FC_APP_VERSION)) . '" defer></script>';
        echo '<div id="orgasmic-app-prompt" hidden></div>';
    }
}
