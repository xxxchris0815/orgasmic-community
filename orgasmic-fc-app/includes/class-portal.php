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
        add_filter('fluent_community/menu_groups', [$this, 'menu_groups'], 20);
        add_filter('fluent_community/menu_items_api_response', [$this, 'menu_groups'], 20);
        add_filter('fluent_community/profile_view_data', [$this, 'profile_view_data'], 20, 2);
    }

    public function head(): void
    {
        $theme = sanitize_hex_color((string) get_option(Orgasmic_Fc_App_Install::OPTION_THEME, '#121c30')) ?: '#121c30';
        echo '<link rel="manifest" href="' . esc_url(home_url('/orgasmic-manifest.json')) . '" />';
        echo '<meta name="theme-color" content="' . esc_attr($theme) . '" />';
        echo '<meta name="apple-mobile-web-app-capable" content="yes" />';
        echo '<meta name="apple-mobile-web-app-title" content="LO Community" />';
        echo '<link rel="apple-touch-icon" href="' . esc_url(ORGASMIC_FC_APP_URL . 'assets/icon-192.png') . '" />';
        $css = ORGASMIC_FC_APP_URL . 'assets/app.css?ver=' . rawurlencode(ORGASMIC_FC_APP_VERSION);
        echo '<link rel="stylesheet" href="' . esc_url($css) . '" />';
    }

    public function boot(): void
    {
        static $booted = false;
        if ($booted) {
            return;
        }
        $booted = true;
        $logged = is_user_logged_in();
        $data = [
            'root' => esc_url_raw(rest_url('orgasmic-app/v1/')),
            'ajax' => esc_url_raw(admin_url('admin-ajax.php')),
            'nonce' => wp_create_nonce('wp_rest'),
            'navIcon' => $this->icon_svg(false),
            'sw' => esc_url_raw(home_url('/orgasmic-sw.js')),
            'prefs' => $logged ? Orgasmic_Fc_App_Install::prefs_for(get_current_user_id()) : Orgasmic_Fc_App_Install::default_prefs(),
            'loggedIn' => $logged,
            'canAnnounce' => $logged && (new Orgasmic_Fc_App_Access())->can_manage(),
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

    public function menu_groups($groups)
    {
        if (!is_array($groups)) {
            return $groups;
        }
        if (empty($groups['profileDropdownItems']) || !is_array($groups['profileDropdownItems'])) {
            $groups['profileDropdownItems'] = [];
        }

        foreach ($groups['profileDropdownItems'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $slug = (string) ($item['slug'] ?? '');
            $permalink = (string) ($item['permalink'] ?? $item['url'] ?? '');
            if ($slug === 'orgasmic-notify' || str_contains($permalink, '#orgasmic-notify')) {
                return $groups;
            }
        }

        $groups['profileDropdownItems'][] = $this->profile_item();
        return $groups;
    }

    public function profile_view_data($data, $xprofile = null)
    {
        if (!is_array($data)) {
            return $data;
        }
        if (empty($data['profile_nav_actions']) || !is_array($data['profile_nav_actions'])) {
            $data['profile_nav_actions'] = [];
        }
        foreach ($data['profile_nav_actions'] as $item) {
            if (is_array($item) && (($item['css_class'] ?? '') === 'oa-profile-notify' || str_contains((string) ($item['url'] ?? ''), '#orgasmic-notify'))) {
                return $data;
            }
        }
        $data['profile_nav_actions'][] = [
            'css_class' => 'oa-profile-notify',
            'title' => __('Benachrichtigungen', 'orgasmic-fc-app'),
            'svg_icon' => $this->icon_svg(false),
            'url' => '#orgasmic-notify',
        ];
        return $data;
    }

    private function profile_item(): array
    {
        $icon = $this->icon_svg(false);
        return [
            'title' => __('Benachrichtigungen', 'orgasmic-fc-app'),
            'name' => __('Benachrichtigungen', 'orgasmic-fc-app'),
            'permalink' => '#orgasmic-notify',
            'url' => '#orgasmic-notify',
            'slug' => 'orgasmic-notify',
            'icon_svg' => $icon,
            'svg_icon' => $icon,
            'is_custom' => true,
        ];
    }

    private function icon_svg(bool $filled = true): string
    {
        if ($filled) {
            return '<svg class="oa-nav-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="22" height="22">'
                . '<path fill="currentColor" d="M12 3a6 6 0 0 0-6 6c0 7-3 7-3 9h18c0-2-3-2-3-9a6 6 0 0 0-6-6zm-2.2 17a2.2 2.2 0 0 0 4.4 0h-4.4z"></path>'
                . '</svg>';
        }
        return '<svg class="oa-nav-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M6 9a6 6 0 1 1 12 0c0 7 3 7 3 9H3c0-2 3-2 3-9"></path>'
            . '<path d="M10 20a2 2 0 0 0 4 0"></path>'
            . '</svg>';
    }
}
