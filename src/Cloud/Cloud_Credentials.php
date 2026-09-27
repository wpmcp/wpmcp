<?php

namespace WPMCP\Cloud;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Encrypted single-option credential vault for WP MCP Cloud (issue #141,
 * phase 1 of #135). The full credential set (base_url, api_key, and the token
 * bundle the phase 2 OAuth connect flow will populate: access_token,
 * refresh_token, access_expires_at, client_id, client_secret) is sealed as one
 * blob, following the shipped Stock_Key_Store pattern. The persisted option
 * never contains plaintext, and a copied database without the site's
 * wp-config salts cannot recover the secrets. That last guarantee holds only
 * when AUTH_KEY / AUTH_SALT are defined in wp-config: when they are missing
 * or left at the default phrase, wp_salt() generates them and stores them in
 * the auth_key / auth_salt options, i.e. in the same database as the blob.
 *
 * Cryptography
 *
 *  - AEAD: libsodium crypto_secretbox (XSalsa20-Poly1305). Every write draws a
 *    fresh random 24-byte nonce, stored in front of the ciphertext, and the
 *    whole thing is base64 encoded for the options table. The Poly1305 tag
 *    authenticates the blob, so a tampered or truncated value fails to open
 *    instead of decrypting to garbage.
 *  - Key: BLAKE2b-256 (crypto_generichash) over a domain-separation label and
 *    wp_salt('auth'). The label keeps this key distinct from Stock_Key_Store's
 *    and from the fingerprint key below, even though all three hang off the
 *    same salt.
 *  - Fallback: PHP's sodium extension when it is loaded, otherwise the
 *    sodium_compat polyfill WordPress core has bundled since 5.2 (same API,
 *    same wire format, pure PHP). If neither is present the vault fails
 *    CLOSED: write() returns false and nothing is stored, and it never falls
 *    back to plaintext. A failed write also means the phase A plaintext
 *    options are left where they are, because they are then the site's only
 *    copy of the credentials.
 *
 * Salt rotation (documented behaviour)
 *
 * Rotating wp_salt('auth') (new wp-config keys, a migration tool that
 * regenerates them) changes the key, so the existing blob can no longer be
 * opened. The vault then:
 *
 *  - reads as an empty set, so the site reports as not connected. It never
 *    falls back to anything unauthenticated;
 *  - is reported by cloud-status as token_status "unreadable" (is_unreadable()),
 *    which tells the operator the credentials were lost to a key change rather
 *    than never set;
 *  - keeps the sealed blob untouched. Reads never overwrite or delete it, a
 *    legacy plaintext import never seals over it, and a cloud-connect whose
 *    probe fails puts the raw blob back (snapshot() / restore()), so
 *    restoring the previous salts recovers the connection;
 *  - is replaced by the next successful cloud-connect, which seals a fresh set
 *    under the new key.
 *
 * An API key is recoverable by re-running cloud-connect; a refresh token is
 * not, which is the price of binding the vault to the site's salts rather
 * than storing a key next to the ciphertext it protects.
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
 * Reads pass through a per-request memo keyed on the raw sealed blob and the
 * key it was opened with, so the several reads a single cloud request
 * performs cost one decrypt rather than four; because the memo key is the
 * stored ciphertext itself, any write (this request's or another process's,
 * once the options cache is invalidated) is picked up automatically.
 * Token_Refresher needs the opposite guarantee for its post-lock re-read, so
 * all() takes a $force flag that drops the WordPress options cache entry and
 * re-reads from the database.
 *
 * Secrets never belong in a message: redact() scrubs every stored secret out
 * of text that came back from the cloud before it is put in an error, and
 * fingerprint() gives callers a keyed, non-reversible identifier for a token
 * when they need to remember which one a failure belonged to.
 */
class Cloud_Credentials
{
    public const OPTION = 'wpmcp_cloud_credentials';

    private const LEGACY_URL_OPTION = 'wpmcp_cloud_url';
    private const LEGACY_KEY_OPTION = 'wpmcp_cloud_key';

    /** Fields whose values are secrets, and so are scrubbed by redact(). */
    private const SECRET_FIELDS = ['api_key', 'access_token', 'refresh_token', 'client_secret'];

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

    /** Fingerprint of the key the memoized blob was opened with. */
    private static string $memo_key = '';

    /** Whether the memoized blob failed to open (rotated salts, tampering). */
    private static bool $memo_unreadable = false;

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
     * True when a sealed vault exists but does not open under the current key:
     * wp_salt('auth') rotated, or the blob was tampered with. Distinct from
     * "never connected", which is what cloud-status token_status reports.
     */
    public static function is_unreadable(): bool
    {
        self::read_vault();
        return self::$memo_unreadable;
    }

    /**
     * Replace every stored secret that occurs in $message with a placeholder.
     * For text that originates outside this plugin (a cloud error body, a
     * transport error) and is about to be shown to an MCP client or logged:
     * a backend that echoes the presented key back must not turn an error
     * message into a credential leak.
     */
    public static function redact(string $message): string
    {
        if ('' === $message) {
            return $message;
        }
        $fields  = self::read_vault();
        $values  = [(string) get_option(self::LEGACY_KEY_OPTION, '')];
        foreach (self::SECRET_FIELDS as $field) {
            $values[] = (string) ($fields[ $field ] ?? '');
        }
        // The unmigrated legacy key too: a site whose import has not landed
        // yet authenticates with it, so a backend can echo it back.
        $secrets = [];
        foreach ($values as $value) {
            if ('' !== $value) {
                $secrets[ $value ] = '[redacted]';
            }
        }
        // Longest first, so a secret that contains another is scrubbed whole.
        uksort($secrets, static fn ($a, $b) => strlen((string) $b) <=> strlen((string) $a));
        return [] === $secrets ? $message : strtr($message, $secrets);
    }

    /**
     * Keyed, non-reversible identifier for a secret (HMAC-SHA256 under a key
     * derived from the site's auth salt with its own label). A bare hash of a
     * token stored in the database would be an offline guessing oracle for
     * it; this is not, without the wp-config salts.
     */
    public static function fingerprint(string $secret): string
    {
        return hash_hmac('sha256', $secret, self::derive_key('wpmcp-cloud-fingerprint'));
    }

    /**
     * Import plaintext phase A credentials on an ordinary page load, not only
     * on the next cloud call. Checks the autoloaded options WordPress has
     * already loaded, so a site without legacy options pays no query at all;
     * a legacy pair that was stored with autoload off is still caught by the
     * lazy import in all().
     */
    public static function maybe_migrate_on_boot(): void
    {
        $autoloaded = wp_load_alloptions();
        if (! isset($autoloaded[ self::LEGACY_KEY_OPTION ]) && ! isset($autoloaded[ self::LEGACY_URL_OPTION ])) {
            return;
        }
        // Legacy options can only coexist with a vault when an older build
        // wrote them after the vault existed (a downgrade and reconnect), so
        // they are the newer credentials: import them the same way a
        // cloud-connect would, which replaces the set.
        self::migrate_plaintext();
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
        if ($written) {
            // Only a set that actually landed resets health: a failed write
            // leaves the previous bundle in place, backoff included.
            Token_Refresher::clear_health();
        }
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
     * The exact stored state (the raw sealed blob, readable or not, plus the
     * refresh health markers) for a caller that must be able to put it back
     * untouched: cloud-connect writes the new set before it can probe it.
     * Restoring the raw blob rather than a decrypted copy is what keeps a
     * salt-rotated vault recoverable, and restoring the markers keeps a
     * rejected bundle in its backoff.
     *
     * The phase A plaintext pair is captured too: the write the caller is
     * about to make deletes it, and when it was never imported (a vault that
     * could not be written, or one that does not open) it is the only copy.
     *
     * @return array{blob:string,health:mixed,retry:mixed,legacy_url:string,legacy_key:string}
     */
    public static function snapshot(): array
    {
        self::all(true);
        return [
            'blob'       => (string) get_option(self::OPTION, ''),
            'health'     => get_option(Token_Refresher::HEALTH_OPTION, false),
            'retry'      => get_transient(Token_Refresher::RETRY_TRANSIENT),
            'legacy_url' => (string) get_option(self::LEGACY_URL_OPTION, ''),
            'legacy_key' => (string) get_option(self::LEGACY_KEY_OPTION, ''),
        ];
    }

    /** @param array{blob:string,health:mixed,retry:mixed,legacy_url:string,legacy_key:string} $snapshot from snapshot() */
    public static function restore(array $snapshot): void
    {
        self::forget_memos();
        if ('' === (string) $snapshot['blob']) {
            delete_option(self::OPTION);
        } else {
            update_option(self::OPTION, (string) $snapshot['blob'], false);
        }
        foreach ([self::LEGACY_URL_OPTION => 'legacy_url', self::LEGACY_KEY_OPTION => 'legacy_key'] as $option => $field) {
            if ('' !== (string) ($snapshot[ $field ] ?? '')) {
                update_option($option, (string) $snapshot[ $field ]);
            }
        }
        Token_Refresher::clear_health();
        if (false !== $snapshot['health']) {
            update_option(Token_Refresher::HEALTH_OPTION, $snapshot['health'], false);
        }
        if (is_string($snapshot['retry']) && '' !== $snapshot['retry']) {
            set_transient(Token_Refresher::RETRY_TRANSIENT, $snapshot['retry'], Token_Refresher::RETRY_BACKOFF);
        }
        self::read_vault(true);
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
            self::$memo_unreadable = false;
            return [];
        }
        $key_id = self::key_id();
        if (null !== self::$memo_blob && $blob === self::$memo_blob && hash_equals(self::$memo_key, $key_id)) {
            return self::$memo_fields;
        }

        $plain  = self::decrypt($blob);
        $data   = null === $plain ? null : json_decode($plain, true);
        $fields = is_array($data) ? array_intersect_key($data, array_flip(self::FIELDS)) : [];

        self::$memo_blob       = $blob;
        self::$memo_key        = $key_id;
        self::$memo_fields     = $fields;
        self::$memo_unreadable = ! is_array($data);
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
        $sealed = self::encrypt($json);
        if (null === $sealed) {
            // No usable sodium implementation: fail closed, store nothing.
            return false;
        }
        self::forget_memos();
        update_option(self::OPTION, $sealed, false);

        // A JSON round trip may reorder keys but must not change a value, so
        // compare key-sorted and strictly ("1e3" == "1000" in PHP).
        $read_back = self::read_vault(true);
        ksort($read_back);
        ksort($fields);
        if ($read_back !== $fields) {
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
        self::$memo_blob        = null;
        self::$memo_key         = '';
        self::$memo_fields      = [];
        self::$memo_unreadable  = false;
        self::$migration_failed = false;
    }

    /**
     * Import of the phase A plaintext options for a site that has not written
     * the vault yet. write() deletes the plaintext copies, and only once the
     * sealed blob reads back with the same values, so a write that fails (or
     * an encrypt that produced nothing) leaves the site connected on the
     * legacy options instead of destroying them.
     *
     * Migration also runs on init (maybe_migrate_on_boot(), for autoloaded
     * legacy options) and on activation, so on a normally loading site this
     * read path finds nothing left to do. It stays here as the backstop for
     * legacy options stored with autoload off, and its result is memoized per
     * request so a vault that cannot be written costs one attempt, not one per
     * read.
     *
     * @return array<string,mixed>
     */
    public static function migrate_plaintext(): array
    {
        $url = (string) get_option(self::LEGACY_URL_OPTION, '');
        $key = (string) get_option(self::LEGACY_KEY_OPTION, '');
        if ('' === $key) {
            // Nothing secret to protect, and a URL on its own was never a
            // working phase A connection. Importing it would REPLACE the
            // vault with a key-less set, which is exactly what a stray write
            // to wpmcp_cloud_url must never be able to do.
            return [];
        }

        $fields = ['base_url' => rtrim($url, '/'), 'api_key' => $key];
        if (self::$migration_failed || self::is_unreadable()) {
            // A vault that exists but does not open (rotated salts) may still
            // be recovered by restoring the old salts; the legacy pair is
            // usable meanwhile, but it must not be sealed over that blob.
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

    /** @return string|null base64(nonce . ciphertext), or null when no sodium implementation is usable. */
    private static function encrypt(string $plaintext): ?string
    {
        if (! self::crypto_available()) {
            return null;
        }
        try {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- makes the binary sodium nonce+ciphertext safe to store in the options table; not obfuscation.
            return base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, self::key()));
        } catch (\Throwable $e) {
            // SodiumException or a missing RNG. The message is not surfaced:
            // callers only learn that nothing was stored.
            return null;
        }
    }

    private static function decrypt(string $blob): ?string
    {
        if (! self::crypto_available()) {
            return null;
        }
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decodes the storage encoding written by encrypt(); not obfuscation.
        $raw = base64_decode($blob, true);
        if (false === $raw || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return null;
        }
        $nonce  = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        try {
            $plain = sodium_crypto_secretbox_open($cipher, $nonce, self::key());
        } catch (\Throwable $e) {
            return null;
        }
        return false === $plain ? null : $plain;
    }

    /**
     * The sodium extension, or the sodium_compat polyfill WordPress bundles.
     * Both define these functions and constants with identical semantics.
     */
    private static function crypto_available(): bool
    {
        return function_exists('sodium_crypto_secretbox')
            && function_exists('sodium_crypto_secretbox_open')
            && function_exists('sodium_crypto_generichash')
            && defined('SODIUM_CRYPTO_SECRETBOX_NONCEBYTES')
            && defined('SODIUM_CRYPTO_SECRETBOX_KEYBYTES')
            && defined('SODIUM_CRYPTO_SECRETBOX_MACBYTES');
    }

    private static function key(): string
    {
        return self::derive_key('wpmcp-cloud-credentials');
    }

    /** Identifies the current vault key for the memo without keeping a copy of it. */
    private static function key_id(): string
    {
        return hash('sha256', self::derive_key('wpmcp-cloud-memo'));
    }

    /**
     * 32-byte key for $label, bound to wp_salt('auth'). Labels separate the
     * sealing key from every other key derived from the same salt.
     */
    private static function derive_key(string $label): string
    {
        $material = $label . '|' . wp_salt('auth');
        if (function_exists('sodium_crypto_generichash')) {
            return sodium_crypto_generichash($material, '', 32);
        }
        return hash('sha256', $material, true);
    }
}
