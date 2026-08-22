<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Orgasmic_Fc_Bunny_Embeds
{
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
        echo '<script src="' . esc_url(ORGAMSIC_FC_EMBEDS_URL . 'assets/embeds.js?ver=' . rawurlencode(ORGAMSIC_FC_EMBEDS_VERSION)) . '" defer></script>';
    }
}
