<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Orgasmic_Fc_Embeds_Webhook
{
    public function send(array $payload): array
    {
        $url = trim((string) get_option(Orgasmic_Fc_Embeds_Store::OPTION_WEBHOOK_URL, ''));
        if ($url === '' || !wp_http_validate_url($url)) {
            return [
                'ok' => false,
                'message' => 'Keine gültige Webhook-URL hinterlegt.',
            ];
        }

        $body = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'ORGASMIC-FC-Embeds/' . ORGASMIC_FC_EMBEDS_VERSION,
        ];

        $secret = (string) get_option(Orgasmic_Fc_Embeds_Store::OPTION_WEBHOOK_SECRET, '');
        if ($secret !== '') {
            $headers['X-Orgasmic-Signature'] = hash_hmac('sha256', (string) $body, $secret);
        }

        $response = wp_remote_post($url, [
            'headers' => $headers,
            'body' => $body,
            'timeout' => 8,
            'blocking' => false,
            'data_format' => 'body',
        ]);

        if (is_wp_error($response)) {
            return [
                'ok' => false,
                'message' => $response->get_error_message(),
            ];
        }

        return [
            'ok' => true,
            'message' => 'Webhook gesendet.',
        ];
    }

    public function send_test(): array
    {
        return $this->send([
            'source' => 'orgasmic-fc-embeds',
            'event' => 'video.test',
            'category' => 'video',
            'user_id' => get_current_user_id() ?: null,
            'data' => [
                'message' => 'ORGASMIC Bunny Embeds test ping',
            ],
            'site' => home_url(),
            'occurred_at' => gmdate('c'),
        ]);
    }
}
