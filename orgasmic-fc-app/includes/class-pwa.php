<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Orgasmic_Fc_App_Pwa
{
    public function register(): void
    {
        add_action('init', [$this, 'serve'], 0);
        add_filter('query_vars', static function (array $vars): array {
            $vars[] = 'orgasmic_pwa';
            return $vars;
        });
    }

    public function serve(): void
    {
        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $path = rtrim($path, '/') ?: '/';
        if (str_ends_with($path, '/orgasmic-sw.js') || $path === '/orgasmic-sw.js') {
            $this->javascript();
        }
        if (str_ends_with($path, '/orgasmic-manifest.json') || $path === '/orgasmic-manifest.json') {
            $this->manifest();
        }
    }

    private function javascript(): void
    {
        nocache_headers();
        header('Content-Type: application/javascript; charset=utf-8');
        header('Service-Worker-Allowed: /');
        header('X-Content-Type-Options: nosniff');
        $js = (string) file_get_contents(ORGASMIC_FC_APP_PATH . 'assets/sw.js');
        $js = str_replace(
            ['__ICON192__', '__BADGE__'],
            [ORGASMIC_FC_APP_URL . 'assets/icon-192.png', ORGASMIC_FC_APP_URL . 'assets/badge-72.png'],
            $js
        );
        echo $js;
        exit;
    }

    private function manifest(): void
    {
        $theme = sanitize_hex_color((string) get_option(Orgasmic_Fc_App_Install::OPTION_THEME, '#121c30')) ?: '#121c30';
        $start = (string) get_option(Orgasmic_Fc_App_Install::OPTION_START_URL, '/');
        $start_url = $start[0] === '/' ? home_url($start) : $start;
        $manifest = [
            'name' => 'ORGASMIC',
            'short_name' => 'ORGASMIC',
            'description' => 'Community, Chat und Kalender',
            'start_url' => $start_url,
            'scope' => home_url('/'),
            'display' => 'standalone',
            'background_color' => $theme,
            'theme_color' => $theme,
            'icons' => [
                [
                    'src' => ORGASMIC_FC_APP_URL . 'assets/icon-192.png',
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    'src' => ORGASMIC_FC_APP_URL . 'assets/icon-512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any maskable',
                ],
            ],
        ];
        nocache_headers();
        header('Content-Type: application/manifest+json; charset=utf-8');
        echo wp_json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
