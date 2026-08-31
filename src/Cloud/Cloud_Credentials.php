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
 * The phase A plaintext options (wpmcp_cloud_url / wpmcp_cloud_key) are
 * removed by EVERY successful vault write, not only by the read-path
 * migration: cloud-connect writes the vault before anything reads it, so a
 * migration that only fires on an empty vault would leave the plaintext key
 * in wp_options forever on the reconnect path. write() therefore reads the
 * sealed blob back, and deletes the plaintext copies only once it decrypts to
 * the values just written: a write that silently does not land must never be
 * the moment the site loses its only copy of the credentials. That read-back
 * is also what makes write() a truthful bool, so a refresh whose persist
 * failed is reported as a failure instead of handing back a token nobody
 * stored.
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

    private const FIELDS = [
        'base_url',
        'api_key',
        'access_token',
        'refresh_token',
        'access_expires_at',
        'client_id',
        'client_secret',
    ];

    /** Raw sealed blob the memo below was decoded from; null when unpopulated. */
    private static ?string $memo_blob = null;

    /** @var array<string,mixed> */
    private static array $memo_fields = [];

    /**
     * Set once the legacy import has tried and failed to seal the vault. The
     * legacy options are still returned (they are the site's only working
     * credentials), but the write is not retried for the rest of the request:
     * a single cloud call reads the vault several times, and the read-only
     * cloud-status tool must not hammer the options table.
     */
    private static bool $migration_failed = false;

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

    /**
     * Merge $fields onto the freshest stored set and re-seal.
     *
     * @return bool true only when the sealed blob read back with the merged
     *              values. Token_Refresher depends on that being honest: a
     *              rotated refresh token the cloud has already burned but this
     *              site did not store is a dead connection.
     */
    public static function merge(array $fields): bool
    {
        return self::write(array_merge(self::all(true), array_intersect_key($fields, array_flip(self::FIELDS))));
    }

    /**
     * Replace the stored set entirely; every field not supplied is dropped.
     *
     * This is the credential-set primitive (cloud-connect today, the phase 2
     * PKCE connect flow tomorrow), so it is also where the refresh health
     * state is reset: a brand-new bundle must not inherit the previous one's
     * rejection backoff, and "new credentials" and "clear health" must not be
     * able to drift apart in a caller that forgets one of them.
     */
    public static function replace(array $fields): bool
    {
        $written = self::write(array_intersect_key($fields, array_flip(self::FIELDS)));
        Token_Refresher::clear_health();
        return $written;
    }

    /** Disconnect: drop the vault, the legacy plaintext copies and the health state. */
    public static function clear(): void
    {
        self::forget_memos();
        delete_option(self::OPTION);
        delete_option(self::LEGACY_URL_OPTION);
        delete_option(self::LEGACY_KEY_OPTION);
        Token_Refresher::clear_health();
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
            // The option is absent until the first connect, so an earlier
            // get_option() in this request may have cached it as a
            // "notoption"; without dropping that entry too the forced re-read
            // returns the default and the refresher's post-lock double-check
            // silently stops seeing the winner's write.
            $notoptions = wp_cache_get('notoptions', 'options');
            if (is_array($notoptions) && isset($notoptions[ self::OPTION ])) {
                unset($notoptions[ self::OPTION ]);
                wp_cache_set('notoptions', $notoptions, 'options');
            }
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

    /**
     * Seal and persist $fields, then confirm the blob reads back. Returns
     * false when anything in that chain failed, which is what lets callers
     * (and the legacy cleanup below) distinguish "stored" from "attempted".
     */
    private static function write(array $fields): bool
    {
        $json = wp_json_encode($fields);
        if (! is_string($json)) {
            return false;
        }
        self::forget_memos();
        update_option(self::OPTION, self::encrypt($json), false);

        if (self::read_vault(true) != $fields) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison -- a JSON round trip may reorder keys; the values, not their order, are what must match.
            return false;
        }

        self::forget_legacy_plaintext();
        return true;
    }

    /**
     * Delete the phase A plaintext options once the vault demonstrably holds
     * the credentials. Guarded on a read (which the options cache answers)
     * so the common already-migrated case costs no queries.
     */
    private static function forget_legacy_plaintext(): void
    {
        foreach ([self::LEGACY_URL_OPTION, self::LEGACY_KEY_OPTION] as $legacy) {
            if (false !== get_option($legacy, false)) {
                delete_option($legacy);
            }
        }
    }

    private static function forget_memos(): void
    {
        self::$memo_blob         = null;
        self::$memo_fields       = [];
        self::$migration_failed  = false;
    }

    /**
     * Import of the phase A plaintext options for a site that has not written
     * the vault yet. write() deletes the plaintext copies, and only once the
     * sealed blob reads back with the same values, so a write that fails (or
     * an encrypt that produced nothing) leaves the site connected on the
     * legacy options instead of destroying them.
     *
     * Migration also runs from the plugin's upgrade routine, so on a normally
     * loading site this read path finds nothing left to do. It stays here as
     * the backstop for the site whose upgrade hook never fired (a manual file
     * copy, a must-use bootstrap), and its result is memoized per request so a
     * vault that cannot be written costs one attempt, not one per read.
     *
     * @return array<string,mixed>
     */
    public static function migrate_plaintext(): array
    {
        $url = (string) get_option(self::LEGACY_URL_OPTION, '');
        $key = (string) get_option(self::LEGACY_KEY_OPTION, '');
        if ('' === $url && '' === $key) {
            return [];
        }

        $fields = ['base_url' => rtrim($url, '/'), 'api_key' => $key];
        if (self::$migration_failed) {
            return $fields;
        }
        // write() keeps the plaintext when the seal did not land: it is then
        // the only copy of the credentials. Either way the caller gets a
        // working credential set back.
        if (! self::write($fields)) {
            self::$migration_failed = true;
        }
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
