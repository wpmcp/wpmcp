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
 * sites keep working with no re-connect. The plaintext copies are deleted only
 * after the sealed blob has been read back and confirmed to decrypt to the
 * same values: a write that silently does not land must never be the moment
 * the site loses its only copy of the credentials.
 *
 * Reads pass through a per-request memo keyed on the raw sealed blob, so the
 * several reads a single cloud request performs cost one decrypt rather than
 * four; because the key is the stored ciphertext itself, any write (this
 * request's or another process's, once the options cache is invalidated) is
 * picked up automatically. Token_Refresher needs the opposite guarantee for
 * its post-lock re-read, so all() takes a $force flag that drops the WordPress
 * options cache entry and re-reads from the database.
 */
class Cloud_Credentials
{
    public const OPTION = 'wpmcp_cloud_credentials';

    private const LEGACY_URL_OPTION = 'wpmcp_cloud_url';
    private const LEGACY_KEY_OPTION = 'wpmcp_cloud_key';

    private const FIELDS = ['base_url', 'api_key', 'access_token', 'refresh_token', 'access_expires_at', 'client_id'];

    /** Raw sealed blob the memo below was decoded from; null when unpopulated. */
    private static ?string $memo_blob = null;

    /** @var array<string,mixed> */
    private static array $memo_fields = [];

    /**
     * @param bool $force re-read from the database, bypassing the options
     *                    object cache and the per-request memo. Required
     *                    whenever the answer must reflect a write another
     *                    process may have made since this request started.
     * @return array<string,mixed> the full credential set; empty when not connected or undecryptable.
     */
    public static function all(bool $force = false): array
    {
        $fields = self::read_vault($force);
        if ([] !== $fields) {
            return $fields;
        }
        return self::migrate_plaintext();
    }

    /** @return mixed */
    public static function get(string $field)
    {
        return self::all()[ $field ] ?? null;
    }

    /** Merge $fields onto the freshest stored set and re-seal. */
    public static function merge(array $fields): void
    {
        self::write(array_merge(self::all(true), array_intersect_key($fields, array_flip(self::FIELDS))));
    }

    /** Replace the stored set entirely; every field not supplied is dropped. */
    public static function replace(array $fields): void
    {
        self::write(array_intersect_key($fields, array_flip(self::FIELDS)));
    }

    public static function clear(): void
    {
        self::$memo_blob = null;
        self::$memo_fields = [];
        delete_option(self::OPTION);
        delete_option(self::LEGACY_URL_OPTION);
        delete_option(self::LEGACY_KEY_OPTION);
        delete_option(Token_Refresher::HEALTH_OPTION);
    }

    /**
     * Decode the sealed option. Separate from all() so migrate_plaintext() can
     * verify its own write without recursing back through the migration path.
     *
     * @return array<string,mixed>
     */
    private static function read_vault(bool $force = false): array
    {
        if ($force) {
            wp_cache_delete(self::OPTION, 'options');
            self::$memo_blob = null;
        }

        $blob = get_option(self::OPTION, '');
        if (! is_string($blob) || '' === $blob) {
            return [];
        }
        if (null !== self::$memo_blob && $blob === self::$memo_blob) {
            return self::$memo_fields;
        }

        $plain = self::decrypt($blob);
        $data  = null === $plain ? null : json_decode($plain, true);
        $fields = is_array($data) ? array_intersect_key($data, array_flip(self::FIELDS)) : [];

        self::$memo_blob   = $blob;
        self::$memo_fields = $fields;
        return $fields;
    }

    private static function write(array $fields): bool
    {
        $json = wp_json_encode($fields);
        if (! is_string($json)) {
            return false;
        }
        self::$memo_blob   = null;
        self::$memo_fields = [];
        update_option(self::OPTION, self::encrypt($json), false);
        return true;
    }

    /**
     * One-time import of the phase A plaintext options. The plaintext copies
     * are deleted only once the sealed blob reads back with the same values,
     * so a write that fails (or an encrypt that produced nothing) leaves the
     * site connected on the legacy options instead of destroying them.
     *
     * This is a write on a read path, including the read-only cloud-status
     * tool. It happens at most once per site, and doing it lazily is what lets
     * an already-connected site keep working without a re-connect; moving it
     * into an upgrade routine is a phase 2 cleanup.
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
        if (! self::write($fields)) {
            return $fields;
        }

        $stored = self::read_vault(true);
        if (($stored['base_url'] ?? null) !== $fields['base_url'] || ($stored['api_key'] ?? null) !== $fields['api_key']) {
            // The seal did not land. Keep the plaintext: it is the only copy.
            return $fields;
        }

        delete_option(self::LEGACY_URL_OPTION);
        delete_option(self::LEGACY_KEY_OPTION);
        return $fields;
    }

    private static function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- makes the binary sodium nonce+ciphertext safe to store in the options table; not obfuscation.
        return base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, self::key()));
    }

    private static function decrypt(string $blob): ?string
    {
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decodes the storage encoding written by encrypt(); not obfuscation.
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
