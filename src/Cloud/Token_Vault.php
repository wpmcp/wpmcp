<?php

namespace WPMCP\Cloud;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Encrypted-at-rest storage for the WP MCP Cloud OAuth token bundle
 * (issue #135, phase B step 1).
 *
 * The bundle { access_token, refresh_token, expires_at, issuer } is sealed
 * with a sodium secretbox whose key is derived from the site's AUTH_KEY
 * constant. When AUTH_KEY is defined in wp-config.php (the normal case), a
 * database dump alone is not enough to impersonate the site against the
 * cloud. On a site that leaves AUTH_KEY undefined, the derivation falls back
 * to wp_salt('auth'), which WordPress itself persists in the options table:
 * on that path the seal protects against casual inspection and against a
 * partial dump, but NOT against a full dump, and the honest statement is that
 * the guarantee holds only with AUTH_KEY as a constant.
 *
 * Envelope, following the Pro\Chat\Key_Vault convention:
 *
 *     wpmcp_v1:<8-hex key fingerprint>:<base64(nonce || box)>
 *
 * The fingerprint lets read() tell "the key material rotated" apart from
 * "the blob was tampered with"; both force a reconnect, but only the first
 * is an operator's own doing.
 *
 * Refresh rotation uses the lock / re-read / treat-loser-as-success pattern.
 * The lock is a real mutex: an INSERT IGNORE against the options table's
 * unique option_name key, so exactly one concurrent worker inserts the row.
 * (add_option() is NOT usable for this: core reads the option first and then
 * runs INSERT ... ON DUPLICATE KEY UPDATE, which succeeds for every writer.)
 * A worker that loses the lock re-reads the vault: if the winner already
 * rotated, that bundle is adopted as this call's success; if it has not
 * finished yet, the caller gets a cloud_refresh_race error rather than the
 * stale access token it came in with. That error means "come back", not "give
 * up": nothing here retries on the caller's behalf, and Cloud_Client
 * deliberately surfaces the original 401 instead of blocking a request while a
 * peer finishes rotating.
 */
class Token_Vault
{
    private const OPTION      = 'wpmcp_cloud_token_bundle';
    private const LOCK_OPTION = 'wpmcp_cloud_token_refresh_lock';
    private const LOCK_TTL    = 30; // seconds
    private const PREFIX      = 'wpmcp_v1:';

    /**
     * Store the token bundle encrypted. $issuer is the cloud base URL the
     * bundle was minted by, so a reconnect against a different cloud cannot
     * silently present the previous cloud's token (see
     * Cloud_Config::bearer_token()).
     */
    public static function store(string $access_token, string $refresh_token, int $expires_at, string $issuer = ''): bool
    {
        $plain = (string) wp_json_encode([
            'access_token'  => $access_token,
            'refresh_token' => $refresh_token,
            'expires_at'    => $expires_at,
            'issuer'        => $issuer,
        ]);

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box   = sodium_crypto_secretbox($plain, $nonce, self::key());

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary ciphertext needs a text-safe encoding for the options table; not obfuscation.
        $packed = self::PREFIX . self::fingerprint() . ':' . base64_encode($nonce . $box);

        return update_option(self::OPTION, $packed, false);
    }

    /** @return array{access_token:string,refresh_token:string,expires_at:int,issuer:string}|null */
    public static function read(): ?array
    {
        $raw = (string) get_option(self::OPTION, '');
        if ('' === $raw || 0 !== strpos($raw, self::PREFIX)) {
            return null;
        }

        $parts = explode(':', $raw, 3);
        if (3 !== count($parts)) {
            return null;
        }

        if (! hash_equals(self::fingerprint(), $parts[1])) {
            // Key material rotated: the blob can never open again. Treated the
            // same as tampering by the caller (reconnect), but distinguishable
            // here without touching the crypto.
            return null;
        }

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Reverses the encode above; not obfuscation.
        $blob = base64_decode($parts[2], true);
        if (false === $blob || strlen($blob) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $nonce = substr($blob, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box   = substr($blob, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plain = sodium_crypto_secretbox_open($box, $nonce, self::key());
        if (false === $plain) {
            return null; // Tampered blob: force reconnect.
        }

        $bundle = json_decode($plain, true);
        if (! is_array($bundle) || ! isset($bundle['access_token'], $bundle['refresh_token'], $bundle['expires_at'])) {
            return null;
        }

        return [
            'access_token'  => (string) $bundle['access_token'],
            'refresh_token' => (string) $bundle['refresh_token'],
            'expires_at'    => (int) $bundle['expires_at'],
            'issuer'        => (string) ($bundle['issuer'] ?? ''),
        ];
    }

    /**
     * Why read() came back empty, mirroring Pro\Chat\Key_Vault::get_status().
     * The fingerprint envelope exists precisely so "the operator rotated
     * AUTH_KEY" is distinguishable from "somebody edited the blob"; without an
     * accessor that distinction was computed and then thrown away.
     *
     * @return string one of missing|valid|key_rotated|corrupted
     */
    public static function status(): string
    {
        $raw = (string) get_option(self::OPTION, '');
        if ('' === $raw) {
            return 'missing';
        }
        if (0 !== strpos($raw, self::PREFIX)) {
            return 'corrupted';
        }

        $parts = explode(':', $raw, 3);
        if (3 !== count($parts)) {
            return 'corrupted';
        }
        if (! hash_equals(self::fingerprint(), $parts[1])) {
            return 'key_rotated';
        }

        return null !== self::read() ? 'valid' : 'corrupted';
    }

    public static function clear(): void
    {
        delete_option(self::OPTION);
        delete_option(self::LOCK_OPTION);
    }

    public static function has_bundle(): bool
    {
        return null !== self::read();
    }

    /**
     * Run $rotate under the refresh mutex. $rotate receives the current bundle
     * and must return the new one (or WP_Error).
     *
     * $stale_access_token is the token the caller actually presented and had
     * refused. When it is genuinely one of the vault's own live tokens it, not
     * the bundle read on entry, is what "did somebody else already rotate for
     * me?" is measured against: by the time a losing worker gets here the
     * winner may already have written, and comparing against a fresh read
     * would then compare the winner's bundle with itself.
     *
     * It is IGNORED when the entry bundle is not itself live, because then the
     * credential the caller presented cannot have come from this vault. That
     * is the phase A case: bearer_token() falls back to wpmcp_cloud_key
     * whenever the bundle is expired, Cloud_Client hands that API key back as
     * $stale_access_token, and comparing the vault's token against an API key
     * is trivially "not equal" -- which used to report a rotation that never
     * happened and left the expired bundle in place forever.
     *
     * @param callable(array):(array|\WP_Error) $rotate
     * @return array|\WP_Error the bundle now in the vault
     */
    public static function with_refresh_lock(callable $rotate, string $stale_access_token = '')
    {
        $bundle = self::read();
        if (null === $bundle) {
            return new \WP_Error('cloud_no_token', 'No cloud token bundle stored; run cloud-connect first.');
        }

        $stale = self::rotation_marker($bundle, $stale_access_token);

        if (! self::acquire_lock()) {
            $row        = self::lock_row();
            $held_since = self::held_since($row);

            if (null === $row) {
                // The winner released the lock in the window between our failed
                // INSERT IGNORE and this read. Nobody holds it now, so this is
                // not a race: take it rather than reporting a spurious one.
                if (! self::acquire_lock()) {
                    return new \WP_Error('cloud_refresh_race', 'A cloud token refresh is already in progress; retry the request.');
                }
            } elseif ((time() - $held_since) < self::LOCK_TTL) {
                // Loser path. Only the winner's *finished* rotation counts as
                // our success; an unchanged bundle means the winner is still
                // in flight, and returning it would hand the caller the same
                // token that just 401'd.
                $current = self::read();
                if (self::already_rotated($current, $stale)) {
                    return $current;
                }
                return new \WP_Error('cloud_refresh_race', 'A cloud token refresh is already in progress; retry the request.');
            } elseif (! self::steal_lock($row)) {
                // The lock row is older than the TTL (or carries a value we do
                // not trust to age out): steal it with a compare-and-set on the
                // exact bytes we observed, so only one stealer wins.
                return new \WP_Error('cloud_refresh_race', 'A cloud token refresh is already in progress; retry the request.');
            }
        }

        try {
            // Re-read under the lock: another request may have rotated between
            // our first read and acquiring the lock.
            $current = self::read();
            if (self::already_rotated($current, $stale)) {
                return $current;
            }

            $next = $rotate($bundle);
            if (is_wp_error($next)) {
                return $next;
            }
            self::store(
                (string) $next['access_token'],
                (string) $next['refresh_token'],
                (int) $next['expires_at'],
                (string) ($next['issuer'] ?? $bundle['issuer'])
            );
            return $next;
        } finally {
            self::release_lock();
        }
    }

    /**
     * The token a completed rotation must differ from. See with_refresh_lock().
     *
     * @param array{access_token:string,expires_at:int} $bundle bundle read on entry
     */
    private static function rotation_marker(array $bundle, string $stale_access_token): string
    {
        $entry = (string) $bundle['access_token'];
        $live  = '' !== $entry && (0 === (int) $bundle['expires_at'] || (int) $bundle['expires_at'] > time());

        return ('' !== $stale_access_token && $live) ? $stale_access_token : $entry;
    }

    /**
     * True only when the vault now holds a token that is both real and
     * different from the one we came in with. An empty marker can never prove
     * a rotation: it means the entry bundle had no access token at all.
     *
     * @param array{access_token:string}|null $current
     */
    private static function already_rotated(?array $current, string $marker): bool
    {
        return '' !== $marker
            && null !== $current
            && '' !== $current['access_token']
            && $current['access_token'] !== $marker;
    }

    /** True while some worker holds the refresh mutex. */
    public static function is_refresh_locked(): bool
    {
        return null !== self::lock_row();
    }

    /**
     * Take the refresh mutex without running a rotation. Test-only seam for
     * simulating the "another worker got there first" branch; a no-op outside
     * the test suite.
     */
    public static function acquire_refresh_lock_for_tests(): bool
    {
        if (! defined('WPMCP_TESTING') || ! WPMCP_TESTING) {
            return false;
        }
        return self::acquire_lock();
    }

    /**
     * INSERT IGNORE on the options table's unique option_name index: the
     * insert either creates the row (1 affected) or does nothing (0), with no
     * read-then-write window. Direct SQL is required precisely because the
     * options API has no atomic create.
     */
    private static function acquire_lock(): bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- An atomic create is the point; the options API cannot express it.
        $inserted = $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                self::LOCK_OPTION,
                (string) time()
            )
        );

        if (1 === (int) $inserted) {
            self::flush_lock_cache();
            return true;
        }

        return false;
    }

    /**
     * Compare-and-set steal of an expired lock: the UPDATE only matches while
     * the row still carries the timestamp we observed, so concurrent stealers
     * cannot both win. A lock whose value we could not read is left alone.
     */
    private static function steal_lock(?string $held_row): bool
    {
        global $wpdb;

        if (null === $held_row) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-set; see acquire_lock().
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                (string) time(),
                self::LOCK_OPTION,
                $held_row
            )
        );

        self::flush_lock_cache();

        return 1 === (int) $updated;
    }

    private static function release_lock(): void
    {
        delete_option(self::LOCK_OPTION);
        self::flush_lock_cache();
    }

    /**
     * The lock row's raw bytes, or null when unheld. Raw rather than parsed
     * because steal_lock()'s compare-and-set has to match what is actually
     * stored: a value we normalized on the way out would never equal the row.
     */
    private static function lock_row(): ?string
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The lock must never be answered from a cache.
        $value = $wpdb->get_var(
            $wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION)
        );

        return null === $value ? null : (string) $value;
    }

    /**
     * Unix time a held lock is treated as taken.
     *
     * A value that is not a plausible PAST timestamp is dated to expiry, not to
     * now. Garbage would otherwise read as "held since 1970" and be stolen
     * instantly, and -- the reason this is not cosmetic -- a FUTURE timestamp
     * (clock skew between web nodes, or a planted value) makes time() - $held
     * negative, which is always below the TTL: the loser branch would then be
     * pinned forever and steal_lock() unreachable, so every refresh on the site
     * returns cloud_refresh_race until somebody deletes the row by hand.
     * Treating both as already expired routes them to the compare-and-set
     * steal, which is safe because it still matches on the exact stored bytes.
     */
    private static function held_since(?string $row): int
    {
        $held = (int) $row;
        $now  = time();

        return ($held > 0 && $held <= $now) ? $held : ($now - self::LOCK_TTL);
    }

    /** Direct SQL bypasses the options cache; keep the two in step. */
    private static function flush_lock_cache(): void
    {
        wp_cache_delete(self::LOCK_OPTION, 'options');
        wp_cache_delete('notoptions', 'options');
    }

    /** 32-byte secretbox key derived from AUTH_KEY (see the class docblock). */
    private static function key(): string
    {
        return sodium_crypto_generichash(
            'wpmcp-cloud-vault|' . self::key_material(),
            '',
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES
        );
    }

    private static function key_material(): string
    {
        $material = defined('AUTH_KEY') ? (string) AUTH_KEY : '';

        return '' !== $material ? $material : wp_salt('auth');
    }

    /** Short fingerprint of the key material, so a rotation is recognizable. */
    private static function fingerprint(): string
    {
        return substr(hash('sha256', 'wpmcp-cloud-vault-fp|' . self::key_material()), 0, 8);
    }
}
