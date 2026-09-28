<?php

namespace WPMCP\Crypto;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The one at-rest sealing primitive for secrets this plugin stores in the
 * options table (issue #141): the WP MCP Cloud credential vault and the BYO
 * stock-provider keys both go through it, so there is a single place that
 * decides how a secret is sealed and what happens when that is impossible.
 *
 *  - AEAD: libsodium crypto_secretbox (XSalsa20-Poly1305) with a fresh random
 *    24-byte nonce per seal, stored in front of the ciphertext, base64
 *    encoded for storage. The Poly1305 tag authenticates the blob, so a
 *    tampered or truncated value fails to open instead of decrypting to
 *    garbage.
 *  - Key: BLAKE2b-256 over "<label>|" . wp_salt('auth'). The label is the
 *    caller's domain separator, so every store gets its own key from the
 *    same salt, and a blob sealed for one store never opens in another.
 *  - Fallback: PHP's sodium extension, otherwise the sodium_compat polyfill
 *    WordPress core has bundled since 5.2 (same API, same wire format). With
 *    neither, seal() returns null and open() returns null: callers fail
 *    CLOSED and store nothing rather than falling back to plaintext.
 *  - Failure semantics: seal() and open() never throw and never return a
 *    message; a caller only learns "not sealed" / "did not open".
 *
 * Rotating wp_salt('auth') changes every derived key, so existing blobs stop
 * opening. What that means is each store's documented behaviour (see
 * Cloud\Cloud_Credentials).
 */
final class Secret_Box
{
    /** @return string|null base64(nonce . ciphertext), or null when nothing could be sealed. */
    public static function seal(string $plaintext, string $label): ?string
    {
        if (! self::available()) {
            return null;
        }
        try {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- makes the binary sodium nonce+ciphertext safe to store in the options table; not obfuscation.
            return base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, self::key($label)));
        } catch (\Throwable $e) {
            // SodiumException or a missing RNG. Deliberately not surfaced.
            return null;
        }
    }

    /** @return string|null the plaintext, or null when the blob is malformed, tampered or sealed under another key. */
    public static function open(string $blob, string $label): ?string
    {
        if (! self::available()) {
            return null;
        }
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decodes the storage encoding written by seal(); not obfuscation.
        $raw = base64_decode($blob, true);
        if (false === $raw || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return null;
        }
        try {
            $plain = sodium_crypto_secretbox_open(
                substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                self::key($label)
            );
        } catch (\Throwable $e) {
            return null;
        }
        return false === $plain ? null : $plain;
    }

    /**
     * 32-byte key for $label bound to wp_salt('auth'). Public for the few
     * callers that need a keyed MAC (HMAC fingerprints) rather than a seal;
     * always pass a label no other caller uses.
     */
    public static function key(string $label): string
    {
        $material = $label . '|' . wp_salt('auth');
        if (function_exists('sodium_crypto_generichash')) {
            try {
                return sodium_crypto_generichash($material, '', 32);
            } catch (\Throwable $e) {
                // Fall through to the SHA-256 derivation below.
            }
        }
        return hash('sha256', $material, true);
    }

    /** The sodium extension or the sodium_compat polyfill WordPress bundles. */
    public static function available(): bool
    {
        return function_exists('sodium_crypto_secretbox')
            && function_exists('sodium_crypto_secretbox_open')
            && function_exists('sodium_crypto_generichash')
            && defined('SODIUM_CRYPTO_SECRETBOX_NONCEBYTES')
            && defined('SODIUM_CRYPTO_SECRETBOX_MACBYTES');
    }
}
