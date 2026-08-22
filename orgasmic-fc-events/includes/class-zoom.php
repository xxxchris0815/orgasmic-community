<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Orgasmic_Fc_Events_Zoom
{
    public function configured(): bool
    {
        return $this->account_id() !== '' && $this->client_id() !== '' && $this->client_secret() !== '';
    }

    public function list_users(): array
    {
        $cached = get_transient('orgasmic_fc_zoom_users');
        if (is_array($cached)) {
            return $cached;
        }

        $users = [];
        $next = '';
        do {
            $query = ['page_size' => 300, 'status' => 'active'];
            if ($next !== '') {
                $query['next_page_token'] = $next;
            }
            $response = $this->request('GET', '/users?' . http_build_query($query));
            if (is_wp_error($response)) {
                return ['error' => $response->get_error_message()];
            }
            foreach (($response['users'] ?? []) as $user) {
                $users[] = [
                    'id' => (string) ($user['id'] ?? ''),
                    'email' => (string) ($user['email'] ?? ''),
                    'display_name' => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: (string) ($user['email'] ?? ''),
                    'type' => (int) ($user['type'] ?? 0),
                    'status' => (string) ($user['status'] ?? ''),
                ];
            }
            $next = (string) ($response['next_page_token'] ?? '');
        } while ($next !== '');

        set_transient('orgasmic_fc_zoom_users', $users, 10 * MINUTE_IN_SECONDS);
        return $users;
    }

    public function create_meeting(array $event): array|WP_Error
    {
        $host = $event['zoom_user_email'] ?? '';
        if ($host === '') {
            return new WP_Error('zoom_host', 'Bitte eine Zoom-Account-E-Mail wählen.');
        }

        $duration = $this->duration_minutes($event);
        $body = [
            'topic' => $event['title'] ?? 'ORGAMSIC Event',
            'type' => 2,
            'start_time' => gmdate('Y-m-d\TH:i:s\Z', strtotime((string) $event['starts_at']) ?: time()),
            'duration' => $duration,
            'timezone' => 'UTC',
            'agenda' => wp_strip_all_tags((string) ($event['description'] ?? '')),
            'settings' => [
                'join_before_host' => false,
                'waiting_room' => true,
                'host_video' => true,
                'participant_video' => true,
            ],
        ];

        $created = $this->request('POST', '/users/' . rawurlencode($host) . '/meetings', $body);
        if (is_wp_error($created)) {
            return $created;
        }

        return [
            'zoom_meeting_id' => (string) ($created['id'] ?? ''),
            'zoom_join_url' => (string) ($created['join_url'] ?? ''),
            'zoom_start_url' => (string) ($created['start_url'] ?? ''),
        ];
    }

    public function update_meeting(array $event): array|WP_Error
    {
        $id = (string) ($event['zoom_meeting_id'] ?? '');
        if ($id === '') {
            return $this->create_meeting($event);
        }

        $duration = $this->duration_minutes($event);
        $updated = $this->request('PATCH', '/meetings/' . rawurlencode($id), [
            'topic' => $event['title'] ?? 'ORGAMSIC Event',
            'start_time' => gmdate('Y-m-d\TH:i:s\Z', strtotime((string) $event['starts_at']) ?: time()),
            'duration' => $duration,
            'timezone' => 'UTC',
            'agenda' => wp_strip_all_tags((string) ($event['description'] ?? '')),
        ]);

        if (is_wp_error($updated)) {
            return $updated;
        }

        $fresh = $this->request('GET', '/meetings/' . rawurlencode($id));
        if (is_wp_error($fresh)) {
            return [
                'zoom_meeting_id' => $id,
                'zoom_join_url' => (string) ($event['zoom_join_url'] ?? ''),
                'zoom_start_url' => (string) ($event['zoom_start_url'] ?? ''),
            ];
        }

        return [
            'zoom_meeting_id' => (string) ($fresh['id'] ?? $id),
            'zoom_join_url' => (string) ($fresh['join_url'] ?? $event['zoom_join_url'] ?? ''),
            'zoom_start_url' => (string) ($fresh['start_url'] ?? $event['zoom_start_url'] ?? ''),
        ];
    }

    public function delete_meeting(string $meeting_id): void
    {
        if ($meeting_id === '') {
            return;
        }
        $this->request('DELETE', '/meetings/' . rawurlencode($meeting_id));
    }

    private function duration_minutes(array $event): int
    {
        $start = strtotime((string) ($event['starts_at'] ?? '')) ?: time();
        $end = !empty($event['ends_at']) ? (strtotime((string) $event['ends_at']) ?: 0) : 0;
        if ($end > $start) {
            return max(15, (int) ceil(($end - $start) / 60));
        }
        return 90;
    }

    private function request(string $method, string $path, ?array $body = null): array|WP_Error
    {
        $token = $this->token();
        if (is_wp_error($token)) {
            return $token;
        }

        $args = [
            'method' => $method,
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ],
        ];
        if ($body !== null && $method !== 'GET') {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request('https://api.zoom.us/v2' . $path, $args);
        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $json = $raw !== '' ? json_decode($raw, true) : [];

        if ($code >= 300) {
            $message = is_array($json) ? (string) ($json['message'] ?? $raw) : $raw;
            return new WP_Error('zoom_api', 'Zoom: ' . $message, ['status' => $code]);
        }

        return is_array($json) ? $json : [];
    }

    private function token(): string|WP_Error
    {
        $cached = get_transient('orgasmic_fc_zoom_token');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        if (!$this->configured()) {
            return new WP_Error('zoom_config', 'Zoom Server-to-Server ist nicht konfiguriert.');
        }

        $auth = base64_encode($this->client_id() . ':' . $this->client_secret());
        $response = wp_remote_post('https://zoom.us/oauth/token', [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Basic ' . $auth,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'grant_type' => 'account_credentials',
                'account_id' => $this->account_id(),
            ],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $json = json_decode((string) wp_remote_retrieve_body($response), true);
        $token = is_array($json) ? (string) ($json['access_token'] ?? '') : '';
        if ($token === '') {
            return new WP_Error('zoom_token', 'Zoom-Token konnte nicht geholt werden.');
        }

        $ttl = max(60, ((int) ($json['expires_in'] ?? 3600)) - 60);
        set_transient('orgasmic_fc_zoom_token', $token, $ttl);
        return $token;
    }

    private function account_id(): string
    {
        return trim((string) get_option(Orgasmic_Fc_Events_Install::OPTION_ZOOM_ACCOUNT, ''));
    }

    private function client_id(): string
    {
        return trim((string) get_option(Orgasmic_Fc_Events_Install::OPTION_ZOOM_CLIENT, ''));
    }

    private function client_secret(): string
    {
        return trim((string) get_option(Orgasmic_Fc_Events_Install::OPTION_ZOOM_SECRET, ''));
    }
}
