<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Orgasmic_Fc_Chat_Portal
{
    public function __construct(
        private Orgasmic_Fc_Chat_Access $access,
        private Orgasmic_Fc_Chat_Repository $repo
    ) {
    }

    public function register(): void
    {
        add_action('fluent_community/portal_head', [$this, 'assets']);
        add_action('fluent_community/headless/head', [$this, 'assets']);
        add_action('fluent_community/portal_footer', [$this, 'boot']);
        add_action('fluent_community/headless/footer', [$this, 'boot']);
        add_action('fluent_community/before_header_menu_items', [$this, 'header_item'], 8, 2);
        add_filter('fluent_community/main_menu_items', [$this, 'menu_items'], 18, 2);
        add_filter('fluent_community/mobile_menu', [$this, 'mobile_menu'], 18, 1);
    }

    public function assets(): void
    {
        if (!is_user_logged_in()) {
            return;
        }
        $css = ORGASMIC_FC_CHAT_URL . 'assets/portal.css?ver=' . rawurlencode(ORGASMIC_FC_CHAT_VERSION);
        echo '<link rel="stylesheet" href="' . esc_url($css) . '" />';
    }

    public function boot(): void
    {
        static $booted = false;
        if ($booted || !is_user_logged_in()) {
            return;
        }
        $booted = true;

        $user_id = get_current_user_id();
        $space_ids = $this->access->visible_space_ids($user_id);
        $unread = $this->repo->unread_total($space_ids, $user_id);
        $settings = Orgasmic_Fc_Chat_Install::portal_settings();

        $data = [
            'root' => esc_url_raw(rest_url('orgasmic-chat/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'canManage' => $this->access->can_manage($user_id),
            'subtitle' => $settings['subtitle'],
            'appearance' => $settings['appearance'],
            'accent' => $settings['accent'],
            'bg' => $settings['bg'],
            'text' => $settings['text'],
            'card' => $settings['card'],
            'mine' => $settings['mine'],
            'theirs' => $settings['theirs'],
            'pollSeconds' => $settings['poll_seconds'],
            'maxLength' => $settings['max_length'],
            'unread' => $unread,
            'me' => $this->access->user_payload($user_id),
        ];

        echo '<script>window.OrgasmicFcChat = ' . wp_json_encode($data) . ';</script>';
        echo '<script src="' . esc_url(ORGASMIC_FC_CHAT_URL . 'assets/portal.js?ver=' . rawurlencode(ORGASMIC_FC_CHAT_VERSION)) . '" defer></script>';
        echo '<div id="orgasmic-chat-root" hidden></div>';
    }

    public function header_item($auth, $context = null): void
    {
        if (!$auth && !is_user_logged_in()) {
            return;
        }

        $unread = $this->current_unread();
        $label = $unread > 0 ? 'Chat, ' . $unread . ' ungelesen' : 'Chat';
        echo '<li class="top_menu_item fcom_icon_link orgasmic-chat-nav' . ($unread > 0 ? ' has-unread' : '') . '">';
        echo '<a href="#orgasmic-chat" data-orgasmic-chat="1" aria-label="' . esc_attr($label) . '" title="Chat">';
        echo '<svg class="och-nav-icon" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">';
        echo '<path d="M21 12c0 3.866-3.806 7-8.5 7-.86 0-1.69-.1-2.47-.29L4.5 20.5l1.2-3.37C4.64 16.1 4 14.62 4 13c0-3.866 3.806-7 8.5-7S21 8.134 21 12z"></path>';
        echo '</svg>';
        echo '<span class="och-nav-label">Chat</span>';
        echo $this->badge_html($unread);
        echo '</a></li>';
    }

    public function menu_items($items, $scope = null)
    {
        if (!is_array($items)) {
            return $items;
        }
        $unread = $this->current_unread();
        $items[] = [
            'title' => $unread > 0 ? 'Chat · ' . $unread : 'Chat',
            'permalink' => '#orgasmic-chat',
            'slug' => 'orgasmic-chat',
            'icon' => 'el-icon-chat-dot-round',
            'is_custom' => true,
        ];
        return $items;
    }

    public function mobile_menu($items)
    {
        return $this->menu_items($items);
    }

    private function current_unread(): int
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $user_id = get_current_user_id();
        if (!$user_id) {
            $cached = 0;
            return 0;
        }
        $cached = $this->repo->unread_total($this->access->visible_space_ids($user_id), $user_id);
        return $cached;
    }

    private function badge_html(int $unread): string
    {
        if ($unread < 1) {
            return '';
        }
        $text = $unread > 99 ? '99+' : (string) $unread;
        return '<span class="och-nav-badge" data-orgasmic-chat-badge="1">' . esc_html($text) . '</span>';
    }
}
