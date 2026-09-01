<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Orgasmic_Fc_Embeds_Bunny
{
    public function __construct(private Orgasmic_Fc_Embeds_Store $store)
    {
    }

    /**
     * @return array{library_id: string, video_id: string, expire: int, signature: string, endpoint: string, play_url: string, embed_url: string}|WP_Error
     */
    public function create_upload(string $title): array|WP_Error
    {
        $library = $this->store->library_id();
        $key = $this->store->api_key();
        if ($library === '' || $key === '') {
            return new WP_Error('bunny', 'Video-Upload ist nicht konfiguriert.', ['status' => 400]);
        }

        $title = sanitize_text_field($title);
        if ($title === '') {
            $title = 'Community-Video ' . gmdate('Y-m-d H:i');
        }

        $body = ['title' => $title];
        $collection = $this->store->collection_id();
        if ($collection !== '') {
            $body['collectionId'] = $collection;
        }

        $response = wp_remote_post('https://video.bunnycdn.com/library/' . rawurlencode($library) . '/videos', [
            'timeout' => 20,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'AccessKey' => $key,
            ],
            'body' => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('bunny', 'Das Video konnte nicht angelegt werden.', ['status' => 502]);
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        $guid = is_array($data) ? (string) ($data['guid'] ?? '') : '';
        if ($code >= 300 || $guid === '') {
            return new WP_Error('bunny', 'Das Video konnte nicht angelegt werden.', ['status' => 502]);
        }

        $expire = time() + DAY_IN_SECONDS;
        $signature = hash('sha256', $library . $key . $expire . $guid);

        return [
            'library_id' => $library,
            'video_id' => $guid,
            'expire' => $expire,
            'expirationTime' => $expire,
            'signature' => $signature,
            'endpoint' => 'https://video.bunnycdn.com/tusupload',
            'play_url' => 'https://player.mediadelivery.net/play/' . $library . '/' . $guid,
            'embed_url' => 'https://iframe.mediadelivery.net/embed/' . $library . '/' . $guid,
        ];
    }

    /**
     * @return array{status: int, storageSize: int, length: int, title: string}|WP_Error
     */
    public function video_status(string $video_id): array|WP_Error
    {
        $library = $this->store->library_id();
        $key = $this->store->api_key();
        $video_id = strtolower(preg_replace('/[^0-9a-f-]/', '', $video_id) ?: '');
        if ($library === '' || $key === '' || strlen($video_id) < 8) {
            return new WP_Error('bunny', 'Video-Status ungültig.', ['status' => 400]);
        }

        $response = wp_remote_get(
            'https://video.bunnycdn.com/library/' . rawurlencode($library) . '/videos/' . rawurlencode($video_id),
            [
                'timeout' => 20,
                'headers' => [
                    'Accept' => 'application/json',
                    'AccessKey' => $key,
                ],
            ]
        );
        if (is_wp_error($response)) {
            return new WP_Error('bunny', 'Video-Status fehlgeschlagen.', ['status' => 502]);
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code >= 300 || !is_array($data)) {
            return new WP_Error('bunny', 'Video-Status fehlgeschlagen.', ['status' => 502]);
        }

        return [
            'status' => (int) ($data['status'] ?? 0),
            'storageSize' => (int) ($data['storageSize'] ?? $data['size'] ?? 0),
            'length' => (int) ($data['length'] ?? 0),
            'title' => (string) ($data['title'] ?? ''),
            'received' => ((int) ($data['status'] ?? 0)) >= 1 || ((int) ($data['storageSize'] ?? 0)) > 0,
        ];
    }

    public function put_file(string $video_id, string $path): true|WP_Error
    {
        $library = $this->store->library_id();
        $key = $this->store->api_key();
        $video_id = strtolower(preg_replace('/[^0-9a-f-]/', '', $video_id) ?: '');
        if ($library === '' || $key === '' || strlen($video_id) < 8) {
            return new WP_Error('bunny', 'Upload-Ziel ungültig.', ['status' => 400]);
        }
        if ($path === '' || !is_readable($path)) {
            return new WP_Error('bunny', 'Datei nicht lesbar.', ['status' => 400]);
        }

        $size = (int) filesize($path);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return new WP_Error('bunny', 'Datei konnte nicht geöffnet werden.', ['status' => 400]);
        }

        $url = 'https://video.bunnycdn.com/library/' . rawurlencode($library) . '/videos/' . rawurlencode($video_id);
        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'AccessKey: ' . $key,
                    'Content-Type: application/octet-stream',
                    'Content-Length: ' . $size,
                ],
                CURLOPT_UPLOAD => true,
                CURLOPT_INFILE => $handle,
                CURLOPT_INFILESIZE => $size,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 600,
            ]);
            $body = curl_exec($curl);
            $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $error = curl_error($curl);
            curl_close($curl);
            fclose($handle);
            if ($error !== '') {
                return new WP_Error('bunny', 'Video-Upload fehlgeschlagen.', ['status' => 502]);
            }
            if ($code >= 300) {
                return new WP_Error('bunny', 'Video-Upload fehlgeschlagen.', ['status' => 502]);
            }
            return true;
        }

        fclose($handle);
        $response = wp_remote_request($url, [
            'method' => 'PUT',
            'timeout' => 600,
            'headers' => [
                'Accept' => 'application/json',
                'AccessKey' => $key,
                'Content-Type' => 'application/octet-stream',
            ],
            'body' => file_get_contents($path),
        ]);
        if (is_wp_error($response)) {
            return new WP_Error('bunny', 'Video-Upload fehlgeschlagen.', ['status' => 502]);
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code >= 300) {
            return new WP_Error('bunny', 'Video-Upload fehlgeschlagen.', ['status' => 502]);
        }
        return true;
    }

    /**
     * Public Bunny thumbnail URL (pull zone), no player iframe.
     */
    public function public_poster_url(string $library, string $video_id): string
    {
        $urls = $this->public_poster_urls($library, $video_id);

        return $urls[0] ?? '';
    }

    /**
     * @return list<string>
     */
    public function public_poster_urls(string $library, string $video_id): array
    {
        $library = preg_replace('/[^0-9]/', '', $library) ?: '';
        $video_id = strtolower(preg_replace('/[^0-9a-f-]/', '', $video_id) ?: '');
        if ($library === '' || strlen($video_id) < 8) {
            return [];
        }

        $cache_key = 'orgasmic_bunny_purls_' . md5($library . '/' . $video_id);
        $cached = get_transient($cache_key);
        if (is_array($cached) && $cached !== []) {
            return array_values(array_filter(array_map('strval', $cached)));
        }

        $host = $this->store->cdn_hostname();
        if ($host !== '') {
            $urls = [
                'https://' . $host . '/' . $video_id . '/thumbnail.jpg',
                'https://' . $host . '/' . $video_id . '/thumbnail_1.jpg',
                'https://' . $host . '/' . $video_id . '/preview.webp',
            ];
            set_transient($cache_key, $urls, 12 * HOUR_IN_SECONDS);
            return $urls;
        }

        $urls = [];
        $oembed = $this->oembed_thumbnail($library, $video_id);
        if ($oembed !== '') {
            $urls[] = $oembed;
            $this->remember_host_from_url($oembed);
        }
        $scraped = $this->embed_thumbnail($library, $video_id);
        if ($scraped !== '') {
            $urls[] = $scraped;
            $this->remember_host_from_url($scraped);
        }

        $file = $this->thumbnail_filename($library, $video_id);
        $host = $this->store->cdn_hostname();
        if ($host === '') {
            $host = $this->cdn_hostname($library, $video_id);
        }
        if ($host !== '') {
            $this->store->remember_cdn_hostname($host);
            $urls[] = 'https://' . $host . '/' . $video_id . '/' . $file;
            $urls[] = 'https://' . $host . '/' . $video_id . '/thumbnail.jpg';
            $urls[] = 'https://' . $host . '/' . $video_id . '/thumbnail_1.jpg';
            $urls[] = 'https://' . $host . '/' . $video_id . '/preview.webp';
        }

        $urls = array_values(array_unique(array_filter($urls)));
        if ($urls !== []) {
            set_transient($cache_key, $urls, 12 * HOUR_IN_SECONDS);
        }

        return $urls;
    }

    private function remember_host_from_url(string $url): void
    {
        $host = strtolower((string) (wp_parse_url($url, PHP_URL_HOST) ?: ''));
        if ($host !== '') {
            $this->store->remember_cdn_hostname($host);
        }
    }

    private function embed_thumbnail(string $library, string $video_id): string
    {
        $response = wp_remote_get(
            'https://iframe.mediadelivery.net/embed/' . rawurlencode($library) . '/' . rawurlencode($video_id),
            [
                'timeout' => 12,
                'redirection' => 3,
                'headers' => ['Accept' => 'text/html'],
            ]
        );
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) >= 300) {
            return '';
        }
        $html = (string) wp_remote_retrieve_body($response);
        if ($html === '') {
            return '';
        }
        if (preg_match('/property=["\']og:image["\'][^>]*content=["\']([^"\']+)/i', $html, $match)
            || preg_match('/content=["\']([^"\']+)["\'][^>]*property=["\']og:image["\']/i', $html, $match)
            || preg_match('/poster=["\'](https:[^"\']+)["\']/i', $html, $match)
            || preg_match('#(https://[a-z0-9.-]+\.b-cdn\.net/' . preg_quote($video_id, '#') . '/thumbnail[^"\'\s]*)#i', $html, $match)
        ) {
            return esc_url_raw($match[1] ?? '') ?: '';
        }

        return '';
    }

    public function output_poster(string $library, string $video_id): void
    {
        $found = $this->poster_payload($library, $video_id);
        if (!$found) {
            status_header(404);
            exit;
        }

        nocache_headers();
        header('Content-Type: ' . $found['type']);
        header('Content-Length: ' . (string) strlen($found['body']));
        header('Cache-Control: public, max-age=43200');
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 43200) . ' GMT');
        echo $found['body'];
        exit;
    }

    /**
     * @return array{body: string, type: string}|null
     */
    public function poster_payload(string $library, string $video_id): ?array
    {
        $library = preg_replace('/[^0-9]/', '', $library) ?: '';
        $video_id = strtolower(preg_replace('/[^0-9a-f-]/', '', $video_id) ?: '');
        if ($library === '' || strlen($video_id) < 8) {
            return null;
        }

        $cache_key = 'orgasmic_bunny_poster_' . md5($library . '/' . $video_id);
        $cached = get_transient($cache_key);
        if (is_array($cached) && array_key_exists('body', $cached) && $cached['body'] === '') {
            return null;
        }
        if (is_array($cached) && !empty($cached['body']) && !empty($cached['type'])) {
            $cached['body'] = (string) $cached['body'];
            if (strncmp($cached['body'], 'base64:', 7) === 0) {
                $decoded = base64_decode(substr($cached['body'], 7), true);
                if (is_string($decoded) && $decoded !== '') {
                    $cached['body'] = $decoded;
                }
            }
            if ($this->image_type($cached['body'])) {
                return [
                    'body' => $cached['body'],
                    'type' => (string) $cached['type'],
                ];
            }
        }

        foreach ($this->poster_urls($library, $video_id) as $url) {
            $hit = $this->fetch_image($url);
            if ($hit) {
                set_transient($cache_key, [
                    'type' => $hit['type'],
                    'body' => 'base64:' . base64_encode($hit['body']),
                ], 12 * HOUR_IN_SECONDS);
                $host = (string) (wp_parse_url($url, PHP_URL_HOST) ?: '');
                if ($host !== '' && str_ends_with($host, '.b-cdn.net')) {
                    set_transient('orgasmic_bunny_cdn_' . $library, $host, WEEK_IN_SECONDS);
                }
                return $hit;
            }
        }

        set_transient($cache_key, ['type' => '', 'body' => ''], 10 * MINUTE_IN_SECONDS);
        return null;
    }

    /**
     * @return list<string>
     */
    private function poster_urls(string $library, string $video_id): array
    {
        $urls = [];
        $file = $this->thumbnail_filename($library, $video_id);
        $oembed = $this->oembed_thumbnail($library, $video_id);
        if ($oembed !== '') {
            $urls[] = $oembed;
        }

        $cdn = $this->cdn_hostname($library, $video_id);
        if ($cdn !== '') {
            $urls[] = 'https://' . $cdn . '/' . $video_id . '/' . $file;
            $urls[] = 'https://' . $cdn . '/' . $video_id . '/thumbnail.jpg';
            $urls[] = 'https://' . $cdn . '/' . $video_id . '/preview.webp';
        }

        $urls[] = 'https://iframe.mediadelivery.net/embed/' . $library . '/' . $video_id . '/' . $file;
        $urls[] = 'https://iframe.mediadelivery.net/embed/' . $library . '/' . $video_id . '/thumbnail.jpg';
        $urls[] = 'https://iframe.mediadelivery.net/' . $library . '/' . $video_id . '/' . $file;
        $urls[] = 'https://iframe.mediadelivery.net/' . $library . '/' . $video_id . '/thumbnail.jpg';
        $urls[] = 'https://iframe.mediadelivery.net/embed/' . $library . '/' . $video_id . '/preview.webp';
        $urls[] = 'https://iframe.mediadelivery.net/' . $library . '/' . $video_id . '/preview.webp';

        return array_values(array_unique(array_filter($urls)));
    }

    private function oembed_thumbnail(string $library, string $video_id): string
    {
        $embed = 'https://iframe.mediadelivery.net/embed/' . $library . '/' . $video_id;
        $response = wp_remote_get(
            'https://video.bunnycdn.com/OEmbed?url=' . rawurlencode($embed),
            [
                'timeout' => 12,
                'headers' => ['Accept' => 'application/json'],
            ]
        );
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) >= 300) {
            return '';
        }
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        $thumb = is_array($data) ? (string) ($data['thumbnail_url'] ?? '') : '';
        return esc_url_raw($thumb) ?: '';
    }

    private function cdn_hostname(string $library, string $video_id): string
    {
        $cached = get_transient('orgasmic_bunny_cdn_' . $library);
        if (is_string($cached) && $cached !== '' && preg_match('/^[a-z0-9.-]+\.b-cdn\.net$/i', $cached)) {
            return strtolower($cached);
        }

        $meta = $this->video_meta($library, $video_id);
        foreach (['cdnHostname', 'cdnUrl', 'pullZoneHostname', 'thumbnailUrl'] as $key) {
            $value = is_array($meta) ? (string) ($meta[$key] ?? '') : '';
            $host = (string) (wp_parse_url($value, PHP_URL_HOST) ?: (str_contains($value, '.') && !str_contains($value, '/') ? $value : ''));
            if ($host !== '' && str_ends_with(strtolower($host), '.b-cdn.net')) {
                $host = strtolower($host);
                set_transient('orgasmic_bunny_cdn_' . $library, $host, WEEK_IN_SECONDS);
                return $host;
            }
        }

        $library_meta = $this->library_meta($library);
        foreach (['cdnHostname', 'pullZoneHostname', 'playerHostname', 'cdnUrl'] as $key) {
            $value = is_array($library_meta) ? (string) ($library_meta[$key] ?? '') : '';
            $host = (string) (wp_parse_url($value, PHP_URL_HOST) ?: (str_contains($value, '.') && !str_contains($value, '/') ? $value : ''));
            if ($host !== '' && str_ends_with(strtolower($host), '.b-cdn.net')) {
                $host = strtolower($host);
                set_transient('orgasmic_bunny_cdn_' . $library, $host, WEEK_IN_SECONDS);
                return $host;
            }
        }
        $zone = is_array($library_meta) ? (string) ($library_meta['pullZoneId'] ?? $library_meta['PullZoneId'] ?? '') : '';
        if ($zone !== '' && preg_match('/^[a-z0-9-]+$/i', $zone)) {
            $host = 'vz-' . strtolower($zone) . '.b-cdn.net';
            set_transient('orgasmic_bunny_cdn_' . $library, $host, WEEK_IN_SECONDS);
            return $host;
        }

        return '';
    }

    private function thumbnail_filename(string $library, string $video_id): string
    {
        $meta = $this->video_meta($library, $video_id);
        $file = is_array($meta) ? (string) ($meta['thumbnailFileName'] ?? '') : '';
        $file = preg_replace('/[^a-zA-Z0-9._-]/', '', $file) ?: '';
        return $file !== '' ? $file : 'thumbnail.jpg';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function video_meta(string $library, string $video_id): ?array
    {
        if ($this->store->library_id() !== $library || $this->store->api_key() === '') {
            return null;
        }
        $cache_key = 'orgasmic_bunny_vmeta_' . md5($library . '/' . $video_id);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }
        $response = wp_remote_get(
            'https://video.bunnycdn.com/library/' . rawurlencode($library) . '/videos/' . rawurlencode($video_id),
            [
                'timeout' => 12,
                'headers' => [
                    'Accept' => 'application/json',
                    'AccessKey' => $this->store->api_key(),
                ],
            ]
        );
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) >= 300) {
            return null;
        }
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            return null;
        }
        set_transient($cache_key, $data, HOUR_IN_SECONDS);
        return $data;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function library_meta(string $library): ?array
    {
        if ($this->store->library_id() !== $library || $this->store->api_key() === '') {
            return null;
        }
        $cache_key = 'orgasmic_bunny_lmeta_' . $library;
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }
        $response = wp_remote_get(
            'https://video.bunnycdn.com/library/' . rawurlencode($library),
            [
                'timeout' => 12,
                'headers' => [
                    'Accept' => 'application/json',
                    'AccessKey' => $this->store->api_key(),
                ],
            ]
        );
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) >= 300) {
            return null;
        }
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            return null;
        }
        set_transient($cache_key, $data, DAY_IN_SECONDS);
        return $data;
    }

    /**
     * @return array{body: string, type: string}|null
     */
    private function fetch_image(string $url): ?array
    {
        if ($url === '' || !preg_match('#^https://#i', $url)) {
            return null;
        }
        $response = wp_remote_get($url, [
            'timeout' => 12,
            'redirection' => 3,
            'headers' => [
                'Accept' => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
                'Referer' => 'https://iframe.mediadelivery.net/',
            ],
        ]);
        if (is_wp_error($response)) {
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300 || strlen($body) < 32 || strlen($body) > 2500000) {
            return null;
        }
        $type = $this->image_type($body);
        if ($type === null) {
            return null;
        }

        return ['body' => $body, 'type' => $type];
    }

    private function image_type(string $body): ?string
    {
        if (strncmp($body, "\xFF\xD8\xFF", 3) === 0) {
            return 'image/jpeg';
        }
        if (strncmp($body, "\x89PNG\r\n\x1A\n", 8) === 0) {
            return 'image/png';
        }
        if (strncmp($body, 'GIF87a', 6) === 0 || strncmp($body, 'GIF89a', 6) === 0) {
            return 'image/gif';
        }
        if (strncmp($body, 'RIFF', 4) === 0 && substr($body, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        return null;
    }
}
