<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Orgasmic_Fc_App_Fcm
{
    public function can_send(): bool
    {
        $cred = $this->credentials();
        return $cred !== null && function_exists('openssl_sign');
    }

    public function send(array $sub, array $payload): array
    {
        if (!$this->can_send()) {
            return ['ok' => false, 'status' => 0, 'error' => 'Firebase ist noch nicht hinterlegt (Capacitor).'];
        }

        $token = (string) ($sub['endpoint'] ?? '');
        if (str_starts_with($token, 'fcm:')) {
            $token = substr($token, 4);
        }
        if ($token === '') {
            return ['ok' => false, 'status' => 0, 'error' => 'Token fehlt.'];
        }

        try {
            $access = $this->access_token();
            $project = (string) ($this->credentials()['project_id'] ?? '');
            $url = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode($project) . '/messages:send';
            $body = [
                'message' => [
                    'token' => $token,
                    'notification' => [
                        'title' => (string) ($payload['title'] ?? 'ORGASMIC'),
                        'body' => (string) ($payload['body'] ?? ''),
                    ],
                    'data' => [
                        'url' => (string) ($payload['url'] ?? '/'),
                        'tag' => (string) ($payload['tag'] ?? ''),
                        'kind' => (string) ($payload['kind'] ?? ''),
                    ],
                    'android' => ['priority' => 'HIGH'],
                    'apns' => [
                        'payload' => [
                            'aps' => [
                                'sound' => 'default',
                                'badge' => 1,
                            ],
                        ],
                    ],
                ],
            ];
            $response = wp_remote_post($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access,
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'timeout' => 10,
            ]);
        } catch (Throwable $e) {
            return ['ok' => false, 'status' => 0, 'error' => $e->getMessage()];
        }

        if (is_wp_error($response)) {
            return ['ok' => false, 'status' => 0, 'error' => $response->get_error_message()];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $error = '';
        if ($status >= 400) {
            $raw = json_decode((string) wp_remote_retrieve_body($response), true);
            $error = is_array($raw) ? (string) ($raw['error']['message'] ?? wp_remote_retrieve_body($response)) : (string) wp_remote_retrieve_body($response);
            $error = substr($error, 0, 300);
        }

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'error' => $error,
        ];
    }

    private function credentials(): ?array
    {
        $raw = (string) get_option(Orgasmic_Fc_App_Install::OPTION_FCM_JSON, '');
        if ($raw === '') {
            return null;
        }
        $json = json_decode($raw, true);
        if (!is_array($json) || empty($json['private_key']) || empty($json['client_email']) || empty($json['project_id'])) {
            return null;
        }

        return $json;
    }

    private function access_token(): string
    {
        $cached = get_transient('orgasmic_fc_app_fcm_token');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $cred = $this->credentials();
        if ($cred === null) {
            throw new RuntimeException('Firebase-Zugang fehlt.');
        }

        $now = time();
        $header = Orgasmic_Fc_App_Vapid::b64url(wp_json_encode(['alg' => 'RS256', 'typ' => 'JWT']) ?: '');
        $claim = Orgasmic_Fc_App_Vapid::b64url(wp_json_encode([
            'iss' => $cred['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]) ?: '');
        $unsigned = $header . '.' . $claim;
        $signature = '';
        $ok = openssl_sign($unsigned, $signature, $cred['private_key'], OPENSSL_ALGO_SHA256);
        if (!$ok) {
            throw new RuntimeException('Firebase-JWT fehlgeschlagen.');
        }
        $jwt = $unsigned . '.' . Orgasmic_Fc_App_Vapid::b64url($signature);

        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'body' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ],
            'timeout' => 10,
        ]);
        if (is_wp_error($response)) {
            throw new RuntimeException($response->get_error_message());
        }
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        $token = is_array($data) ? (string) ($data['access_token'] ?? '') : '';
        if ($token === '') {
            throw new RuntimeException('Firebase-Token leer.');
        }
        set_transient('orgasmic_fc_app_fcm_token', $token, 3000);

        return $token;
    }
}
