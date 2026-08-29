<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Orgasmic_Fc_App_Vapid
{
    public static function ensure_keys(): void
    {
        if ((string) get_option(Orgasmic_Fc_App_Install::OPTION_VAPID_PUBLIC, '') !== '') {
            return;
        }

        $keys = self::generate();
        if ($keys === null) {
            return;
        }

        update_option(Orgasmic_Fc_App_Install::OPTION_VAPID_PUBLIC, $keys['public']);
        update_option(Orgasmic_Fc_App_Install::OPTION_VAPID_PRIVATE, $keys['private']);
    }

    public static function generate(): ?array
    {
        $key = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        if ($key === false) {
            return null;
        }

        $details = openssl_pkey_get_details($key);
        if (!is_array($details) || empty($details['ec']['x']) || empty($details['ec']['y']) || empty($details['ec']['d'])) {
            return null;
        }

        $public = "\x04" . $details['ec']['x'] . $details['ec']['y'];
        openssl_pkey_export($key, $pem);

        return [
            'public' => self::b64url($public),
            'private' => (string) $pem,
        ];
    }

    public static function public_key(): string
    {
        return (string) get_option(Orgasmic_Fc_App_Install::OPTION_VAPID_PUBLIC, '');
    }

    public static function private_pem(): string
    {
        return (string) get_option(Orgasmic_Fc_App_Install::OPTION_VAPID_PRIVATE, '');
    }

    public static function subject(): string
    {
        $sub = trim((string) get_option(Orgasmic_Fc_App_Install::OPTION_VAPID_SUBJECT, ''));
        return $sub !== '' ? $sub : 'mailto:hello@orgasmic.live';
    }

    public static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64url_decode(string $data): string
    {
        $data = strtr($data, '-_', '+/');
        $pad = strlen($data) % 4;
        if ($pad) {
            $data .= str_repeat('=', 4 - $pad);
        }
        $out = base64_decode($data, true);
        return $out === false ? '' : $out;
    }
}
