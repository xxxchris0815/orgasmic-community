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
        $css = ORGASMIC_FC_EVENTS_URL . 'assets/portal.css?ver=' . rawurlencode(ORGASMIC_FC_EVENTS_VERSION);
        echo '<link rel="stylesheet" href="' . esc_url($css) . '" />';
    }

    public function boot(): void
    {
        static $booted = false;
        if ($booted || !is_user_logged_in()) {
            return;
        }
        $booted = true;
        $settings = Orgasmic_Fc_Events_Install::portal_settings();
        $data = [
            'root' => esc_url_raw(rest_url('orgasmic-events/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'canManage' => $this->access->can_manage(),
            'subtitle' => $settings['subtitle'],
            'appearance' => $settings['appearance'],
            'accent' => $settings['accent'],
            'hasToday' => $this->today_has_events(),
        ];
        echo '<script>window.OrgasmicFcEvents = ' . wp_json_encode($data) . ';</script>';
        echo '<script src="' . esc_url(ORGASMIC_FC_EVENTS_URL . 'assets/portal.js?ver=' . rawurlencode(ORGASMIC_FC_EVENTS_VERSION)) . '" defer></script>';
        echo '<div id="orgasmic-cal-root" hidden></div>';
    }

    public function header_item($auth, $context = null): void
    {
        if (!$auth && !is_user_logged_in()) {
            return;
        }
        $today = $this->today_has_events();
        echo '<li class="top_menu_item fcom_icon_link orgasmic-cal-nav' . ($today ? ' has-today' : '') . '">';
        echo '<a href="#orgasmic-calendar" data-orgasmic-calendar="1" aria-label="Kalender" title="Kalender">';
        echo '<svg class="oc-nav-icon" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">';
        echo '<rect x="3.25" y="4.5" width="17.5" height="16.25" rx="2.25"></rect>';
        echo '<path d="M8 3.25v3M16 3.25v3M3.25 9.5h17.5"></path>';
        echo '<path d="M8 13h.01M12 13h.01M16 13h.01M8 16.5h.01M12 16.5h.01M16 16.5h.01"></path>';
        echo '</svg>';
        echo '<span class="oc-nav-label">Kalender</span>';
        if ($today) {
            echo '<span class="oc-nav-dot" title="Heute findet ein Event statt"></span>';
        }
        echo '</a></li>';
    }

    public function menu_items($items, $scope = null)
    {
        if (!is_array($items)) {
            return $items;
        }
        $items[] = [
            'title' => $this->today_has_events() ? 'Kalender · heute' : 'Kalender',
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

    private function today_has_events(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $user_id = get_current_user_id();
        if (!$user_id) {
            $cached = false;
            return false;
        }

        $tz_name = (string) get_option(Orgasmic_Fc_Events_Install::OPTION_DEFAULT_TZ, 'Europe/Berlin');
        try {
            $tz = new DateTimeZone($tz_name);
        } catch (Exception $e) {
            $tz = new DateTimeZone('Europe/Berlin');
        }

        $from = (new DateTimeImmutable('today', $tz))->modify('-1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $to = (new DateTimeImmutable('tomorrow', $tz))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $repo = new Orgasmic_Fc_Events_Repository();
        $rows = $repo->query_visible(
            $this->access->user_space_ids($user_id),
            $this->access->can_manage($user_id),
            ['from' => $from, 'to' => $to, 'limit' => 80]
        );

        $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
        foreach ($rows as $row) {
            try {
                $event_tz = new DateTimeZone((string) ($row['timezone'] ?: $tz_name));
            } catch (Exception $e) {
                $event_tz = $tz;
            }
            $start = (new DateTimeImmutable((string) $row['starts_at'] . ' UTC'))->setTimezone($event_tz);
            if ($start->format('Y-m-d') === $today) {
                $cached = true;
                return true;
            }
            if (!empty($row['ends_at'])) {
                $end = (new DateTimeImmutable((string) $row['ends_at'] . ' UTC'))->setTimezone($event_tz);
                $day_start = new DateTimeImmutable($today . ' 00:00:00', $event_tz);
                $day_end = $day_start->modify('+1 day');
                if ($start < $day_end && $end >= $day_start) {
                    $cached = true;
                    return true;
                }
            }
        }

        $cached = false;
        return false;
    }
}
