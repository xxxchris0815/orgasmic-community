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
        add_filter('pre_oembed_result', [$this, 'oembed_result'], 10, 3);
        add_filter('embed_oembed_html', [$this, 'oembed_html'], 10, 4);
        add_filter('wp_kses_allowed_html', [$this, 'allow_iframe'], 20, 2);
    }

    /**
     * @param array<string, mixed> $tags
     * @return array<string, mixed>
     */
    public function allow_iframe(array $tags, $context): array
    {
        unset($context);
        $tags['iframe'] = array_merge($tags['iframe'] ?? [], [
            'src' => true,
            'allow' => true,
            'allowfullscreen' => true,
            'frameborder' => true,
            'width' => true,
            'height' => true,
            'title' => true,
            'loading' => true,
            'referrerpolicy' => true,
            'class' => true,
            'style' => true,
        ]);
        $tags['img'] = array_merge($tags['img'] ?? [], [
            'src' => true,
            'alt' => true,
            'class' => true,
            'loading' => true,
            'decoding' => true,
        ]);
        foreach (['div', 'a', 'span'] as $tag) {
            $tags[$tag] = array_merge($tags[$tag] ?? [], [
                'class' => true,
                'href' => true,
                'contenteditable' => true,
                'role' => true,
                'tabindex' => true,
                'aria-label' => true,
                'aria-hidden' => true,
                'data-orgasmic-bunny' => true,
                'data-orgasmic-bunny-object' => true,
                'data-bunny-play' => true,
                'data-bunny-poster' => true,
            ]);
        }

        return $tags;
    }

    /**
     * @param string|false $result
     * @param mixed        $args
     */
    public function oembed_result($result, string $url, $args)
    {
        $html = $this->iframe_html($url);
        return $html !== null ? $html : $result;
    }

    /**
     * @param mixed $cached
     * @param mixed $attr
     */
    public function oembed_html($cached, string $url, $attr, int $post_id)
    {
        $html = $this->iframe_html($url);
        return $html !== null ? $html : $cached;
    }

    private function iframe_html(string $url): ?string
    {
        if (!preg_match('#https?://(?:iframe|player)\.mediadelivery\.net/(?:embed|play)/(\d+)/([0-9a-f-]+)#i', $url, $match)) {
            return null;
        }

        $library = $match[1];
        $video = strtolower($match[2]);
        $key = esc_attr($library . '/' . $video);

        if ($this->store->click_to_play()) {
            $poster = admin_url('admin-ajax.php?action=orgasmic_fc_poster&library=' . rawurlencode($library) . '&video=' . rawurlencode($video));

            return '<div class="orgasmic-bunny-embed orgasmic-bunny-embed--poster" data-orgasmic-bunny="' . $key
                . '" data-bunny-poster="1" role="button" tabindex="0" aria-label="Video abspielen">'
                . '<img class="orgasmic-bunny-poster-img" src="' . esc_url($poster) . '" alt="" loading="lazy" decoding="async" />'
                . '<span class="orgasmic-bunny-poster-play" aria-hidden="true"></span>'
                . '</div>';
        }

        $autoplay = $this->store->autoplay() ? 'true' : 'false';
        $src = 'https://iframe.mediadelivery.net/embed/' . rawurlencode($library) . '/' . rawurlencode($video)
            . '?autoplay=' . $autoplay . '&preload=true&responsive=true&playerjs=true';

        return '<div class="orgasmic-bunny-embed" data-orgasmic-bunny="' . $key . '">'
            . '<iframe src="' . esc_url($src) . '" allow="accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen loading="lazy" title="Video"></iframe>'
            . '</div>';
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
            'ajax' => esc_url_raw(admin_url('admin-ajax.php')),
            'nonce' => wp_create_nonce('wp_rest'),
            'ajaxNonce' => wp_create_nonce('orgasmic_fc_upload'),
            'autoplay' => $this->store->autoplay(),
            'clickToPlay' => $this->store->click_to_play(),
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
            echo '<div id="orgasmic-bunny-upload" hidden><div class="obu-card"><button type="button" class="obu-close" data-obu-close aria-label="Schließen">×</button><p class="obu-title">Video hochladen</p><p class="obu-status" data-obu-status>Wird hochgeladen…</p><div class="obu-bar"><i data-obu-bar></i></div></div></div>';
        }
    }
}
