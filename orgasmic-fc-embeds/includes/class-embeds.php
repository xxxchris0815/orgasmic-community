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
        $css = ORGASMIC_FC_EMBEDS_URL . 'assets/embeds.css?ver=' . rawurlencode(ORGASMIC_FC_EMBEDS_VERSION);
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
            'uploadEnabled' => is_user_logged_in() && $this->store->upload_configured(),
            'tus' => esc_url_raw(ORGASMIC_FC_EMBEDS_URL . 'assets/tus.min.js?ver=4.3.1'),
        ];
        echo '<script>window.OrgasmicFcEmbeds = ' . wp_json_encode($data) . ';</script>';
        echo '<script src="https://assets.mediadelivery.net/playerjs/playerjs-latest.min.js" defer></script>';
        echo '<script src="' . esc_url(ORGASMIC_FC_EMBEDS_URL . 'assets/embeds.js?ver=' . rawurlencode(ORGASMIC_FC_EMBEDS_VERSION)) . '" defer></script>';
        echo '<script src="' . esc_url(ORGASMIC_FC_EMBEDS_URL . 'assets/track.js?ver=' . rawurlencode(ORGASMIC_FC_EMBEDS_VERSION)) . '" defer></script>';
        if (!empty($data['uploadEnabled'])) {
            echo '<script src="' . esc_url(ORGASMIC_FC_EMBEDS_URL . 'assets/upload.js?ver=' . rawurlencode(ORGASMIC_FC_EMBEDS_VERSION)) . '" defer></script>';
            echo '<div id="orgasmic-bunny-upload" hidden><div class="obu-card"><p class="obu-title">Video zu Bunny</p><p class="obu-status" data-obu-status>Wird hochgeladen…</p><div class="obu-bar"><i data-obu-bar></i></div></div></div>';
        }
    }
}
