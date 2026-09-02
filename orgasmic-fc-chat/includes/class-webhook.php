<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Orgasmic_Fc_Chat_Webhook
{
    public function send(array $payload): array
    {
        $url = trim((string) get_option(Orgasmic_Fc_Chat_Install::OPTION_WEBHOOK_URL, ''));
        if ($url === '' || !wp_http_validate_url($url)) {
            return [
                'ok' => false,
                'message' => 'Keine gültige Webhook-URL hinterlegt.',
            ];
        }

        $body = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'ORGASMIC-FC-Chat/' . ORGASMIC_FC_CHAT_VERSION,
        ];

        $secret = (string) get_option(Orgasmic_Fc_Chat_Install::OPTION_WEBHOOK_SECRET, '');
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

    public function message(string $event, int $user_id, array $data): void
    {
        $include_pii = (bool) get_option(Orgasmic_Fc_Chat_Install::OPTION_INCLUDE_PII, 1);
        $include_body = (bool) get_option(Orgasmic_Fc_Chat_Install::OPTION_INCLUDE_BODY, 0);
        $user = $include_pii ? $this->user_fields($user_id) : ['id' => $user_id];

        if (!$include_body) {
            unset($data['body'], $data['preview']);
        }

        $this->send([
            'source' => 'orgasmic-fc-chat',
            'event' => $event,
            'category' => 'chat',
            'user_id' => $user_id ?: null,
            'user' => $user,
            'data' => $data,
            'site' => home_url(),
            'occurred_at' => gmdate('c'),
        ]);
    }

    public function send_test(): array
    {
        return $this->send([
            'source' => 'orgasmic-fc-chat',
            'event' => 'chat.test',
            'category' => 'chat',
            'user_id' => get_current_user_id() ?: null,
            'data' => [
                'message' => 'ORGASMIC Chat test ping',
            ],
            'site' => home_url(),
            'occurred_at' => gmdate('c'),
        ]);
    }

    private function user_fields(int $user_id): array
    {
        $user = get_userdata($user_id);
        if (!$user) {
            return ['id' => $user_id];
        }

        return [
            'id' => $user_id,
            'display_name' => (string) $user->display_name,
            'email' => (string) $user->user_email,
        ];
    }
}
