<?php

namespace WPMCP\Tools\Media\Stock;

use WPMCP\Crypto\Secret_Box;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Encrypted-at-rest storage for BYO stock-provider API keys (issue #64
 * acceptance criterion). Keys are sealed with libsodium's secretbox
 * (XSalsa20-Poly1305, authenticated) under a key derived from this site's
 * auth salt, so the persisted option never contains the plaintext and a
 * copied database without the site's wp-config salts cannot recover keys.
 * Decryption failures (tampered blob, rotated salts) return null, which the
 * caller treats as "not configured" rather than using a corrupt key.
 *
 * The seal itself is Crypto\Secret_Box (shared with the cloud credential
 * vault, issue #141) under the 'wpmcp-stock-keys' label, which derives the
 * same key and wire format this class always used, so stored keys keep
 * opening. When no sodium implementation is usable, set() stores nothing
 * rather than a plaintext key.
 */
class Stock_Key_Store
{
    public const OPTION = 'wpmcp_stock_keys';

    /** Secret_Box domain label; changing it would orphan every stored key. */
    private const LABEL = 'wpmcp-stock-keys';

    public static function set(string $provider, string $key): void
    {
        $sealed = Secret_Box::seal($key, self::LABEL);
        if (null === $sealed) {
            // Fail closed: never store a provider key unsealed.
            return;
        }
        $keys = self::all();
        $keys[ sanitize_key($provider) ] = $sealed;
        update_option(self::OPTION, $keys, false);
    }

    public static function get(string $provider): ?string
    {
        $blob = self::all()[ sanitize_key($provider) ] ?? null;
        return is_string($blob) ? Secret_Box::open($blob, self::LABEL) : null;
    }

    public static function clear(string $provider): void
    {
        $keys = self::all();
        unset($keys[ sanitize_key($provider) ]);
        if (empty($keys)) {
            delete_option(self::OPTION);
            return;
        }
        update_option(self::OPTION, $keys, false);
    }

    /** @return string[] provider slugs with a stored key, sorted. */
    public static function configured(): array
    {
        $providers = array_keys(self::all());
        sort($providers);
        return $providers;
    }

    /** @return array<string, string> */
    private static function all(): array
    {
        $keys = get_option(self::OPTION, []);
        return is_array($keys) ? $keys : [];
    }
}
