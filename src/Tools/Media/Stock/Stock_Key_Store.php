<?php

namespace WPMCP\Tools\Media\Stock;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Encrypted-at-rest storage for BYO stock-provider API keys (issue #64
 * acceptance criterion). Keys are sealed with libsodium's secretbox
 * (XSalsa20-Poly1305, authenticated) under a key derived from this site's
 * auth salt, so the persisted option never contains the plaintext and a
 * copied database without the site's wp-config salts cannot recover keys.
 * Decryption failures (tampered blob, rotated salts) return null — the
 * caller treats that as "not configured" rather than using a corrupt key.
 */
class Stock_Key_Store
{
    public const OPTION = 'wpmcp_stock_keys';

    public static function set(string $provider, string $key): void
    {
        $keys = self::all();
        $keys[ sanitize_key($provider) ] = self::encrypt($key);
        update_option(self::OPTION, $keys, false);
    }

    public static function get(string $provider): ?string
    {
        $blob = self::all()[ sanitize_key($provider) ] ?? null;
        return is_string($blob) ? self::decrypt($blob) : null;
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
        return sodium_crypto_generichash('wpmcp-stock-keys|' . wp_salt('auth'), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
