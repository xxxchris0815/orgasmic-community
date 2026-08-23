<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Orgasmic_Fc_Bunny_Embeds
{
    public function __construct(private Orgasmic_Fc_Embeds_Store $store)
    {
    }

    public function register(): void
    {
        add_action('fluent_community/portal_head', [$this, 'assets']);
        add_action('fluent_community/headless/head', [$this, 'assets']);
        add_action('fluent_community/portal_footer', [$this, 'boot']);
        add_action('fluent_community/headless/footer', [$this, 'boot']);
    }

    public function assets(): void
    {
        $css = ORGAMSIC_FC_EMBEDS_URL . 'assets/embeds.css?ver=' . rawurlencode(ORGAMSIC_FC_EMBEDS_VERSION);
        echo '<link rel="stylesheet" href="' . esc_url($css) . '" />';
    }

    public function boot(): void
    {
        static $booted = false;
        if ($booted) {
            return;
        }
        $booted = true;

        $data = [
            'root' => esc_url_raw(rest_url('orgasmic-embeds/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'autoplay' => $this->store->autoplay(),
            'loggedIn' => is_user_logged_in(),
        ];
        echo '<script>window.OrgasmicFcEmbeds = ' . wp_json_encode($data) . ';</script>';
        echo '<script src="https://assets.mediadelivery.net/playerjs/playerjs-latest.min.js" defer></script>';
        echo '<script src="' . esc_url(ORGAMSIC_FC_EMBEDS_URL . 'assets/embeds.js?ver=' . rawurlencode(ORGAMSIC_FC_EMBEDS_VERSION)) . '" defer></script>';
        echo '<script src="' . esc_url(ORGAMSIC_FC_EMBEDS_URL . 'assets/track.js?ver=' . rawurlencode(ORGAMSIC_FC_EMBEDS_VERSION)) . '" defer></script>';
    }
}
