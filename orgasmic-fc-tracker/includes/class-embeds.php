<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Orgasmic_Fc_Embeds
{
    public const PATTERN = '#https?://(?:iframe|player)\.mediadelivery\.net/(?:embed|play)/([0-9]+)/([0-9a-fA-F-]{8,})#i';

    public function register(): void
    {
        add_action('init', [$this, 'register_wp_embed']);
        add_filter('pre_oembed_result', [$this, 'pre_oembed'], 10, 3);
        add_filter('oembed_dataparse', [$this, 'oembed_dataparse'], 10, 3);
        add_filter('fluent_community/feed_oembed_api_response', [$this, 'filter_oembed'], 10, 2);
        add_filter('fluent_community/feed_links_api_response', [$this, 'filter_link_preview'], 10, 2);
        add_filter('fluent_community/feeds_api_response', [$this, 'filter_feeds_response'], 10, 2);
        add_filter('fluent_community/feed_api_response', [$this, 'filter_feed_response'], 10, 2);
        add_filter('fluent_community/feed/new_feed_response', [$this, 'filter_single_feed'], 10, 2);
        add_filter('fluent_community/feed/update_feed_response', [$this, 'filter_single_feed'], 10, 2);
        add_filter('fluent_community/feed/patch_feed_response', [$this, 'filter_single_feed'], 10, 2);
        add_action('fluent_community/portal_head', [$this, 'assets']);
        add_action('fluent_community/headless/head', [$this, 'assets']);
        add_action('fluent_community/portal_footer', [$this, 'boot']);
        add_action('fluent_community/headless/footer', [$this, 'boot']);
    }

    public function register_wp_embed(): void
    {
        wp_embed_register_handler('orgasmic_bunny_stream', self::PATTERN, [$this, 'wp_embed_handler']);
    }

    public function wp_embed_handler(array $matches): string
    {
        $parsed = $this->parse($matches[0] ?? '');
        return $parsed ? $this->iframe_html($parsed['library'], $parsed['video']) : $matches[0];
    }

    public function pre_oembed($result, $url, $args)
    {
        $parsed = $this->parse((string) $url);
        return $parsed ? $this->iframe_html($parsed['library'], $parsed['video']) : $result;
    }

    public function oembed_dataparse($result, $data, $url)
    {
        $parsed = $this->parse((string) $url);
        return $parsed ? $this->iframe_html($parsed['library'], $parsed['video']) : $result;
    }

    public function filter_oembed($data, $request)
    {
        $url = $this->url_from_payload($data, $request);
        $parsed = $this->parse($url);
        if (!$parsed) {
            return $data;
        }

        $payload = $this->oembed_payload($parsed['library'], $parsed['video'], $url, $data);

        if (is_array($data) && isset($data['oembed'])) {
            if (is_array($data['oembed'])) {
                $data['oembed'] = array_merge($data['oembed'], $payload);
            } else {
                $data['oembed'] = $payload['html'];
            }
            return $data;
        }

        if (is_array($data)) {
            return array_merge($data, $payload);
        }

        return $payload;
    }

    public function filter_link_preview($data, $request)
    {
        return $this->filter_oembed($data, $request);
    }

    public function filter_feeds_response($data, $request = null)
    {
        return $this->hydrate($data);
    }

    public function filter_feed_response($data, $request = null)
    {
        return $this->hydrate($data);
    }

    public function filter_single_feed($data, $extra = null)
    {
        return $this->hydrate($data);
    }

    public function assets(): void
    {
        $css = ORGAMSIC_FC_TRACKER_URL . 'assets/embeds.css?ver=' . rawurlencode(ORGAMSIC_FC_TRACKER_VERSION);
        echo '<link rel="stylesheet" href="' . esc_url($css) . '" />';
    }

    public function boot(): void
    {
        static $booted = false;
        if ($booted) {
            return;
        }
        $booted = true;
        echo '<script src="' . esc_url(ORGAMSIC_FC_TRACKER_URL . 'assets/embeds.js?ver=' . rawurlencode(ORGAMSIC_FC_TRACKER_VERSION)) . '" defer></script>';
    }

    /**
     * @return array{library: string, video: string}|null
     */
    public function parse(string $url): ?array
    {
        if ($url === '' || !preg_match(self::PATTERN, $url, $m)) {
            return null;
        }

        return [
            'library' => $m[1],
            'video' => strtolower($m[2]),
        ];
    }

    public function iframe_html(string $library, string $video): string
    {
        $src = $this->embed_src($library, $video);

        return '<div class="orgasmic-bunny-embed" data-orgasmic-bunny="' . esc_attr($library . '/' . $video) . '">'
            . '<iframe src="' . esc_url($src) . '" '
            . 'loading="lazy" '
            . 'allow="accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture; fullscreen" '
            . 'allowfullscreen="true" '
            . 'title="Video"></iframe>'
            . '</div>';
    }

    public function embed_src(string $library, string $video): string
    {
        return 'https://player.mediadelivery.net/embed/' . rawurlencode($library) . '/' . rawurlencode($video)
            . '?autoplay=false&preload=true&responsive=true';
    }

    private function oembed_payload(string $library, string $video, string $url, $data): array
    {
        $title = 'Video';
        if (is_array($data)) {
            $title = (string) ($data['title'] ?? $data['oembed']['title'] ?? $title);
        }

        return [
            'type' => 'video',
            'version' => '1.0',
            'provider_name' => 'Bunny Stream',
            'provider_url' => 'https://bunny.net',
            'html' => $this->iframe_html($library, $video),
            'player' => $this->iframe_html($library, $video),
            'width' => 640,
            'height' => 360,
            'url' => $url,
            'title' => $title !== '' ? $title : 'Video',
        ];
    }

    private function hydrate($data)
    {
        if (is_array($data)) {
            if ($this->looks_like_feed($data)) {
                $data = $this->inject_feed($data);
            }
            foreach ($data as $key => $value) {
                if (is_array($value) || is_object($value)) {
                    $data[$key] = $this->hydrate($value);
                }
            }
            return $data;
        }

        if (is_object($data)) {
            if (isset($data->meta) || isset($data->media_preview) || isset($data->message) || isset($data->message_rendered)) {
                $as_array = json_decode(wp_json_encode($data), true);
                if (is_array($as_array) && $this->looks_like_feed($as_array)) {
                    $hydrated = $this->inject_feed($as_array);
                    foreach (['meta', 'media_preview'] as $field) {
                        if (array_key_exists($field, $hydrated)) {
                            $data->{$field} = $hydrated[$field];
                        }
                    }
                }
            }
            foreach (get_object_vars($data) as $key => $value) {
                if (is_array($value) || is_object($value)) {
                    $data->{$key} = $this->hydrate($value);
                }
            }
        }

        return $data;
    }

    private function looks_like_feed(array $data): bool
    {
        return isset($data['meta'])
            || isset($data['media_preview'])
            || isset($data['message'])
            || isset($data['message_rendered']);
    }

    private function inject_feed(array $feed): array
    {
        $preview = [];
        if (isset($feed['media_preview']) && is_array($feed['media_preview'])) {
            $preview = $feed['media_preview'];
        } elseif (isset($feed['meta']) && is_array($feed['meta']) && isset($feed['meta']['media_preview']) && is_array($feed['meta']['media_preview'])) {
            $preview = $feed['meta']['media_preview'];
        }

        $url = '';
        foreach ([$preview['url'] ?? '', $preview['og_url'] ?? '', $feed['message'] ?? '', $feed['message_rendered'] ?? ''] as $candidate) {
            if (is_string($candidate) && $this->parse($candidate)) {
                $url = $candidate;
                break;
            }
        }

        $parsed = $this->parse($url);
        if (!$parsed) {
            return $feed;
        }

        $html = $this->iframe_html($parsed['library'], $parsed['video']);
        $preview['html'] = $html;
        $preview['player'] = $html;
        $preview['type'] = 'video';
        $preview['provider'] = 'Bunny Stream';
        $preview['provider_name'] = 'Bunny Stream';
        $preview['width'] = (int) ($preview['width'] ?? 640);
        $preview['height'] = (int) ($preview['height'] ?? 360);
        if (empty($preview['url'])) {
            $preview['url'] = $this->embed_src($parsed['library'], $parsed['video']);
        }

        if (isset($feed['media_preview'])) {
            $feed['media_preview'] = $preview;
        }
        if (isset($feed['meta']) && is_array($feed['meta'])) {
            $feed['meta']['media_preview'] = $preview;
        } elseif (!isset($feed['media_preview'])) {
            $feed['media_preview'] = $preview;
        }

        return $feed;
    }

    private function url_from_payload($data, $request): string
    {
        foreach ([$request, $data] as $payload) {
            if (!is_array($payload)) {
                continue;
            }
            foreach (['url', 'link', 'href', 'og_url'] as $key) {
                if (!empty($payload[$key]) && is_string($payload[$key])) {
                    return $payload[$key];
                }
            }
            if (isset($payload['oembed']) && is_array($payload['oembed'])) {
                foreach (['url', 'link', 'href'] as $key) {
                    if (!empty($payload['oembed'][$key]) && is_string($payload['oembed'][$key])) {
                        return $payload['oembed'][$key];
                    }
                }
            }
        }

        $blob = '';
        if (is_array($data)) {
            $blob = wp_json_encode($data) ?: '';
        } elseif (is_string($data)) {
            $blob = $data;
        }

        return preg_match(self::PATTERN, $blob, $m) ? $m[0] : '';
    }
}
