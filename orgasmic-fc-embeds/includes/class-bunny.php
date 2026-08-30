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
}
