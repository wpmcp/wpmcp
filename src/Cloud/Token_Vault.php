<?php

namespace WPMCP\Cloud;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Encrypted-at-rest storage for the WP MCP Cloud OAuth token bundle
 * (issue #135, phase B step 1).
 *
 * The bundle { access_token, refresh_token, expires_at } is sealed with a
 * sodium secretbox whose key is derived from the site's AUTH_KEY constant, so
 * a database dump alone is not enough to impersonate the site against the
 * cloud. A random nonce is stored alongside the ciphertext.
 *
 * Refresh rotation uses the lock / re-read / treat-loser-as-success pattern:
 * two concurrent refreshes race, one wins the lock and rotates, the loser
 * re-reads the vault and adopts the winner's bundle instead of burning the
 * already-rotated refresh token.
 */
class Token_Vault
{
    private const OPTION      = 'wpmcp_cloud_token_bundle';
    private const LOCK_OPTION = 'wpmcp_cloud_token_refresh_lock';
    private const LOCK_TTL    = 30; // seconds

    /** Store the token bundle encrypted. */
    public static function store(string $access_token, string $refresh_token, int $expires_at): bool
    {
        $plain = (string) wp_json_encode([
            'access_token'  => $access_token,
            'refresh_token' => $refresh_token,
            'expires_at'    => $expires_at,
        ]);

        $key   = self::key();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box   = sodium_crypto_secretbox($plain, $nonce, $key);

        return update_option(self::OPTION, base64_encode($nonce . $box), false);
    }

    /** @return array{access_token:string,refresh_token:string,expires_at:int}|null */
    public static function read(): ?array
    {
        $raw = (string) get_option(self::OPTION, '');
        if ('' === $raw) {
            return null;
        }

        $blob = base64_decode($raw, true);
        if (false === $blob || strlen($blob) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $nonce = substr($blob, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box   = substr($blob, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plain = sodium_crypto_secretbox_open($box, $nonce, self::key());
        if (false === $plain) {
            return null; // AUTH_KEY changed or blob tampered: force reconnect.
        }

        $bundle = json_decode($plain, true);
        if (! is_array($bundle) || ! isset($bundle['access_token'], $bundle['refresh_token'], $bundle['expires_at'])) {
            return null;
        }

        return [
            'access_token'  => (string) $bundle['access_token'],
            'refresh_token' => (string) $bundle['refresh_token'],
            'expires_at'    => (int) $bundle['expires_at'],
        ];
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
     * and must return the new one (or WP_Error). A caller that loses the lock
     * re-reads the vault and returns whatever the winner stored: losing the
     * race is success, because the token was refreshed either way.
     *
     * @param callable(array):(array|\WP_Error) $rotate
     * @return array|\WP_Error the bundle now in the vault
     */
    public static function with_refresh_lock(callable $rotate)
    {
        $bundle = self::read();
        if (null === $bundle) {
            return new \WP_Error('cloud_no_token', 'No cloud token bundle stored; run cloud-connect first.');
        }

        // add_option is atomic per option name: only one caller creates it.
        $acquired = add_option(self::LOCK_OPTION, time(), '', false);
        if (! $acquired) {
            $held_since = (int) get_option(self::LOCK_OPTION, 0);
            if ($held_since > 0 && (time() - $held_since) < self::LOCK_TTL) {
                // Loser path: wait is not worth it in-request; re-read and
                // treat the winner's rotation as our success.
                $current = self::read();
                return null !== $current ? $current : new \WP_Error('cloud_refresh_race', 'Token refresh in progress; retry.');
            }
            // Stale lock: steal it.
            update_option(self::LOCK_OPTION, time(), false);
        }

        try {
            // Re-read under the lock: another request may have rotated between
            // our first read and acquiring the lock.
            $current = self::read();
            if (null !== $current && $current['access_token'] !== $bundle['access_token']) {
                return $current;
            }

            $next = $rotate($bundle);
            if (is_wp_error($next)) {
                return $next;
            }
            self::store((string) $next['access_token'], (string) $next['refresh_token'], (int) $next['expires_at']);
            return $next;
        } finally {
            delete_option(self::LOCK_OPTION);
        }
    }

    /** 32-byte secretbox key derived from AUTH_KEY. */
    private static function key(): string
    {
        $material = defined('AUTH_KEY') ? (string) AUTH_KEY : '';
        if ('' === $material) {
            // Fallback so the vault still functions on misconfigured sites;
            // wp_salt() is stable per install.
            $material = wp_salt('auth');
        }
        return sodium_crypto_generichash($material, 'wpmcp-cloud-vault', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
