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
            return new WP_Error('bunny', 'Bunny Stream ist nicht konfiguriert (Library-ID und API-Key).', ['status' => 400]);
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
            return new WP_Error('bunny', $response->get_error_message(), ['status' => 502]);
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        $guid = is_array($data) ? (string) ($data['guid'] ?? '') : '';
        if ($code >= 300 || $guid === '') {
            $message = is_array($data) ? (string) ($data['message'] ?? $data['ErrorMessage'] ?? '') : '';
            return new WP_Error(
                'bunny',
                $message !== '' ? $message : 'Bunny konnte das Video nicht anlegen (HTTP ' . $code . ').',
                ['status' => 502]
            );
        }

        $expire = time() + 6 * HOUR_IN_SECONDS;
        $signature = hash('sha256', $library . $key . $expire . $guid);

        return [
            'library_id' => $library,
            'video_id' => $guid,
            'expire' => $expire,
            'signature' => $signature,
            'endpoint' => 'https://video.bunnycdn.com/tusupload',
            'play_url' => 'https://player.mediadelivery.net/play/' . $library . '/' . $guid,
            'embed_url' => 'https://iframe.mediadelivery.net/embed/' . $library . '/' . $guid,
        ];
    }
}
