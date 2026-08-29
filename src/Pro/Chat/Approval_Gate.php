<?php

namespace WPMCP\Pro\Chat;

if (! defined('ABSPATH')) {
    exit;
}

class Approval_Gate
{
    private const TRANSIENT_PREFIX = 'wpmcp_chat_appr_';
    private const DEFAULT_TTL = 300; // 5 minutes

    public function __construct(private ?string $salt = null)
    {
    }

    private function get_salt(): string
    {
        return $this->salt ?? (function_exists('wp_salt') ? wp_salt('auth') : 'wpmcp_approval_fallback_salt');
    }

    /**
     * Recursively sorts associative arrays by string keys for deterministic serialization.
     */
    private static function recursive_ksort(array &$arr): void
    {
        ksort($arr, SORT_STRING);
        foreach ($arr as &$value) {
            if (is_array($value)) {
                self::recursive_ksort($value);
            }
        }
    }

    /**
     * Normalizes arguments into a deterministic canonical JSON string.
     * Throws InvalidArgumentException on recursion depth exceed or invalid UTF-8.
     */
    public static function normalize_args(array $args): string
    {
        self::recursive_ksort($args);

        $json = wp_json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, 1024);
        if (false === $json) {
            throw new \InvalidArgumentException('Arguments are not canonicalizable (invalid depth or non-serializable values).');
        }

        // Ensure string is valid UTF-8 and wasn't silently corrupted with replacement question marks
        if (function_exists('mb_check_encoding') && ! mb_check_encoding($json, 'UTF-8')) {
            throw new \InvalidArgumentException('Arguments contain invalid UTF-8 byte sequences.');
        }

        return $json;
    }

    /**
     * Generates a single-use approval token bound to user, ability, args hash, and expiry.
     *
     * @throws \InvalidArgumentException on invalid user or non-canonicalizable arguments.
     * @throws \RuntimeException on transient storage failure.
     */
    public function issue_token(int $user_id, string $ability_name, array $args, int $ttl_seconds = self::DEFAULT_TTL): string
    {
        if ($user_id <= 0) {
            throw new \InvalidArgumentException('A valid positive user_id is required to issue an approval token.');
        }

        $expiry = time() + $ttl_seconds;
        $args_hash = hash('sha256', self::normalize_args($args));
        $salt = $this->get_salt();

        // Encode ability_name to safely permit pipe characters inside names
        $encoded_ability = rawurlencode($ability_name);
        $payload = sprintf('%d|%s|%s|%d', $user_id, $encoded_ability, $args_hash, $expiry);
        $sig = hash_hmac('sha256', $payload, $salt);

        $token = base64_encode($payload . '|' . $sig);
        $token_hash = hash('sha256', $token);

        // Store server-side to enforce single-use consumption
        $stored = set_transient(self::TRANSIENT_PREFIX . $token_hash, 1, max(1, $ttl_seconds));
        if (! $stored) {
            throw new \RuntimeException('Failed to store approval token transient.');
        }

        return $token;
    }

    /**
     * Validates and atomically consumes the single-use approval token.
     * Fails closed on signature mismatch, expiry, replay, arg tampering, wrong user, or wrong ability.
     */
    public function validate_and_consume(string $token, int $expected_user_id, string $expected_ability, array $actual_args): bool
    {
        $raw = base64_decode($token, true);
        if (false === $raw) {
            return false;
        }

        $parts = explode('|', $raw);
        if (5 !== count($parts)) {
            return false;
        }

        [$user_id_str, $encoded_ability, $args_hash, $expiry_str, $signature] = $parts;
        $user_id = (int) $user_id_str;
        $ability_name = rawurldecode($encoded_ability);
        $expiry = (int) $expiry_str;

        // 1. Signature verification FIRST (fail-fast before processing untrusted fields)
        $salt = $this->get_salt();
        $payload = sprintf('%d|%s|%s|%d', $user_id, $encoded_ability, $args_hash, $expiry);
        $expected_sig = hash_hmac('sha256', $payload, $salt);
        if (! hash_equals($expected_sig, $signature)) {
            return false;
        }

        // 2. Expiration check
        if (time() > $expiry) {
            return false;
        }

        // 3. User isolation check
        if ($user_id !== $expected_user_id) {
            return false;
        }

        // 4. Ability name check
        if ($ability_name !== $expected_ability) {
            return false;
        }

        // 5. Argument tamper check
        try {
            $actual_hash = hash('sha256', self::normalize_args($actual_args));
        } catch (\InvalidArgumentException) {
            return false;
        }

        if (! hash_equals($args_hash, $actual_hash)) {
            return false;
        }

        // 6. Atomic single-use consumption check
        // delete_transient returns true only if the transient existed and was deleted
        $token_hash = hash('sha256', $token);
        $transient_key = self::TRANSIENT_PREFIX . $token_hash;
        if (! delete_transient($transient_key)) {
            return false; // Replay attempt, expired, or already consumed
        }

        return true;
    }
}
