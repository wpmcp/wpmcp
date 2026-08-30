<?php

namespace WPMCP\Cloud;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Encrypted single-option credential vault for WP MCP Cloud (issue #141,
 * phase 1 of #135). The full credential set (base_url, api_key, and the token
 * bundle the phase 2 OAuth connect flow will populate: access_token,
 * refresh_token, access_expires_at, client_id) is sealed as one blob with
 * libsodium's secretbox (XSalsa20-Poly1305, authenticated) under a key derived
 * from this site's auth salt, following the shipped Stock_Key_Store pattern.
 * The persisted option never contains plaintext, and a copied database without
 * the site's wp-config salts cannot recover the secrets.
 *
 * Decryption failures (tampered blob, rotated salts) return an empty set: the
 * caller treats that as "not connected" rather than using corrupt credentials.
 *
 * On first read the vault transparently imports the phase A plaintext options
 * (wpmcp_cloud_url / wpmcp_cloud_key) and deletes them, so existing connected
 * sites keep working with no re-connect.
 */
class Cloud_Credentials
{
    public const OPTION = 'wpmcp_cloud_credentials';

    private const LEGACY_URL_OPTION = 'wpmcp_cloud_url';
    private const LEGACY_KEY_OPTION = 'wpmcp_cloud_key';

    private const FIELDS = ['base_url', 'api_key', 'access_token', 'refresh_token', 'access_expires_at', 'client_id'];

    /** @return array<string,mixed> the full credential set; empty when not connected or undecryptable. */
    public static function all(): array
    {
        $blob = get_option(self::OPTION, '');
        if (! is_string($blob) || '' === $blob) {
            return self::migrate_plaintext();
        }
        $plain = self::decrypt($blob);
        if (null === $plain) {
            return [];
        }
        $data = json_decode($plain, true);
        return is_array($data) ? array_intersect_key($data, array_flip(self::FIELDS)) : [];
    }

    /** @return mixed */
    public static function get(string $field)
    {
        return self::all()[ $field ] ?? null;
    }

    /** Merge $fields onto the stored set and re-seal. */
    public static function merge(array $fields): void
    {
        self::write(array_merge(self::all(), array_intersect_key($fields, array_flip(self::FIELDS))));
    }

    /** Replace the stored set entirely. */
    public static function replace(array $fields): void
    {
        self::write(array_intersect_key($fields, array_flip(self::FIELDS)));
    }

    public static function clear(): void
    {
        delete_option(self::OPTION);
        delete_option(self::LEGACY_URL_OPTION);
        delete_option(self::LEGACY_KEY_OPTION);
    }

    private static function write(array $fields): void
    {
        update_option(self::OPTION, self::encrypt((string) wp_json_encode($fields)), false);
    }

    /**
     * One-time import of the phase A plaintext options. Deletes the plaintext
     * copies once sealed so no cloud secret remains unencrypted.
     *
     * @return array<string,mixed>
     */
    private static function migrate_plaintext(): array
    {
        $url = (string) get_option(self::LEGACY_URL_OPTION, '');
        $key = (string) get_option(self::LEGACY_KEY_OPTION, '');
        if ('' === $url && '' === $key) {
            return [];
        }
        $fields = ['base_url' => rtrim($url, '/'), 'api_key' => $key];
        self::write($fields);
        delete_option(self::LEGACY_URL_OPTION);
        delete_option(self::LEGACY_KEY_OPTION);
        return $fields;
    }

    private static function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, self::key()));
    }

    private static function decrypt(string $blob): ?string
    {
        $raw = base64_decode($blob, true);
        if (false === $raw || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $nonce  = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain  = sodium_crypto_secretbox_open($cipher, $nonce, self::key());
        return false === $plain ? null : $plain;
    }

    private static function key(): string
    {
        return sodium_crypto_generichash('wpmcp-cloud-credentials|' . wp_salt('auth'), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
