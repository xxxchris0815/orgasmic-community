<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Orgasmic_Fc_App_WebPush
{
    public function can_send(): bool
    {
        return function_exists('openssl_pkey_derive')
            && Orgasmic_Fc_App_Vapid::public_key() !== ''
            && Orgasmic_Fc_App_Vapid::private_pem() !== '';
    }

    public function send(array $sub, array $payload): array
    {
        if (!$this->can_send()) {
            return ['ok' => false, 'status' => 0, 'error' => 'Push braucht PHP 8.2+ mit OpenSSL (openssl_pkey_derive).'];
        }

        $endpoint = (string) ($sub['endpoint'] ?? '');
        $p256dh = Orgasmic_Fc_App_Vapid::b64url_decode((string) ($sub['p256dh'] ?? ''));
        $auth = Orgasmic_Fc_App_Vapid::b64url_decode((string) ($sub['auth_token'] ?? ''));
        if ($endpoint === '' || strlen($p256dh) !== 65 || strlen($auth) !== 16) {
            return ['ok' => false, 'status' => 0, 'error' => 'Ungültiges Subscription-Format.'];
        }

        try {
            $body = $this->encrypt(wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}', $p256dh, $auth);
            $jwt = $this->vapid_header($endpoint);
        } catch (Throwable $e) {
            return ['ok' => false, 'status' => 0, 'error' => $e->getMessage()];
        }

        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Authorization' => $jwt,
                'Content-Type' => 'application/octet-stream',
                'Content-Encoding' => 'aes128gcm',
                'TTL' => '86400',
                'Urgency' => 'high',
            ],
            'body' => $body,
            'timeout' => 8,
            'blocking' => true,
        ]);

        if (is_wp_error($response)) {
            return ['ok' => false, 'status' => 0, 'error' => $response->get_error_message()];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'error' => $status >= 400 ? substr((string) wp_remote_retrieve_body($response), 0, 300) : '',
        ];
    }

    private function encrypt(string $plain, string $ua_public, string $ua_auth): string
    {
        $local = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        if ($local === false) {
            throw new RuntimeException('Lokaler ECDH-Key fehlgeschlagen.');
        }

        $peer = openssl_pkey_get_public($this->uncompressed_to_pem($ua_public));
        if ($peer === false) {
            throw new RuntimeException('Empfänger-Key ungültig.');
        }

        $shared = openssl_pkey_derive($peer, $local, 32);
        if (!is_string($shared) || $shared === '') {
            throw new RuntimeException('ECDH fehlgeschlagen.');
        }

        $details = openssl_pkey_get_details($local);
        if (!is_array($details) || empty($details['ec']['x']) || empty($details['ec']['y'])) {
            throw new RuntimeException('Lokaler ECDH-Punkt ungültig.');
        }
        $as_public = Orgasmic_Fc_App_Vapid::uncompressed_point($details);
        $salt = random_bytes(16);

        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $ua_public . $as_public, $ua_auth);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        $padded = $plain . "\x02";
        $tag = '';
        $cipher = openssl_encrypt($padded, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            throw new RuntimeException('AES-GCM fehlgeschlagen.');
        }

        $rs = pack('N', 4096);
        $idlen = chr(strlen($as_public));
        return $salt . $rs . $idlen . $as_public . $cipher . $tag;
    }

    private function vapid_header(string $endpoint): string
    {
        $parts = wp_parse_url($endpoint);
        $aud = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
        $header = Orgasmic_Fc_App_Vapid::b64url(wp_json_encode(['typ' => 'JWT', 'alg' => 'ES256']) ?: '');
        $payload = Orgasmic_Fc_App_Vapid::b64url(wp_json_encode([
            'aud' => $aud,
            'exp' => time() + 12 * HOUR_IN_SECONDS,
            'sub' => Orgasmic_Fc_App_Vapid::subject(),
        ]) ?: '');
        $unsigned = $header . '.' . $payload;

        $key = openssl_pkey_get_private(Orgasmic_Fc_App_Vapid::private_pem());
        if ($key === false) {
            throw new RuntimeException('VAPID-Private-Key ungültig.');
        }
        $signature = '';
        if (!openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('VAPID-Signatur fehlgeschlagen.');
        }

        $jwt = $unsigned . '.' . Orgasmic_Fc_App_Vapid::b64url($this->der_to_jose($signature));
        return 'vapid t=' . $jwt . ', k=' . Orgasmic_Fc_App_Vapid::public_key();
    }

    private function uncompressed_to_pem(string $uncompressed): string
    {
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $uncompressed;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private function der_to_jose(string $der): string
    {
        $offset = 0;
        if (ord($der[$offset++]) !== 0x30) {
            throw new RuntimeException('Ungültige ECDSA-Signatur.');
        }
        $len = ord($der[$offset++]);
        if ($len & 0x80) {
            $offset += $len & 0x7f;
        }
        $read_int = static function (string $der, int &$offset): string {
            if (ord($der[$offset++]) !== 0x02) {
                throw new RuntimeException('Ungültige ECDSA-Signatur.');
            }
            $len = ord($der[$offset++]);
            $value = substr($der, $offset, $len);
            $offset += $len;
            $value = ltrim($value, "\x00");
            return str_pad($value, 32, "\x00", STR_PAD_LEFT);
        };

        return $read_int($der, $offset) . $read_int($der, $offset);
    }
}
