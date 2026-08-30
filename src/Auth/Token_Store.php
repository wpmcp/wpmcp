<?php

namespace WPMCP\Auth;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Issues and validates OAuth 2.1 bearer access tokens. Backed by a single
 * wpmcp_oauth_tokens option, a map of a SHA-256 hash of the token to its
 * bound record: { client_id, user_id, scope, issued_at, pass_fingerprint }.
 *
 * Token_Store::validate() is the helper the MCP permission layer
 * (Registrar's execute/permission_callback wiring) calls to authenticate a
 * caller-presented Bearer token to a WP user id and the client's granted
 * scope.
 *
 * Security properties:
 *  - the token is stored hashed, never plaintext (SHA-256, matching
 *    Client_Store/Code_Store: the token is already full-entropy random data
 *    looked up by exact value, not a human secret needing slow hashing), so
 *    a leaked options row cannot be replayed as a bearer token -- an
 *    attacker with read access to the stored hash still cannot present it as
 *    a valid Authorization header, because validate() hashes whatever is
 *    presented and looks up THAT, so presenting the raw hash just looks up
 *    hash(hash(token)), which was never stored;
 *  - tokens expire after TTL_SECONDS; validate() rejects (and evicts) an
 *    expired token. Unlike Code_Store's single-use codes, a valid
 *    unexpired token may be validated repeatedly (that is the point of a
 *    bearer access token);
 *  - tokens are bound to the user's credential state (issue #43 C1/C2): at
 *    issuance, a SHA-256 fingerprint of the user's current password hash
 *    (`user_pass`) is stored alongside the record (never the raw
 *    `user_pass` itself). validate() re-resolves the user via
 *    get_userdata() and rejects if the user no longer exists OR the
 *    fingerprint no longer matches -- so a stolen token stops authenticating
 *    the instant the account is deleted or its password changes, with no
 *    separate revocation store needed. The fingerprint is an internal
 *    bookkeeping field only: it is never included in validate()'s returned
 *    record, never logged, and never surfaced in any endpoint response.
 */
class Token_Store
{
    public const OPTION      = 'wpmcp_oauth_tokens';
    public const TTL_SECONDS = 3600;

    private static $clock_override = null;

    public static function set_clock_override(?callable $clock): void
    {
        self::$clock_override = $clock;
    }

    private static function now(): int
    {
        return null !== self::$clock_override ? (int) (self::$clock_override)() : time();
    }

    private static function load(): array
    {
        $stored = get_option(self::OPTION, []);
        return is_array($stored) ? $stored : [];
    }

    private static function save(array $stored): void
    {
        update_option(self::OPTION, $stored);
    }

    /**
     * @param string $chain_id Optional refresh-grant chain this token was
     *                          issued along (issue #133). Recording it is
     *                          what lets Refresh_Token_Store's reuse
     *                          detection revoke the access tokens a thief
     *                          already minted, not just the refresh tokens.
     * @return string The plaintext bearer token, returned exactly once.
     */
    public static function issue(string $client_id, int $user_id, string $scope, string $chain_id = ''): string
    {
        $token = 'at_' . bin2hex(random_bytes(32));

        $stored                       = self::load();
        $stored[ self::hash($token) ] = [
            'client_id'        => $client_id,
            'user_id'          => $user_id,
            'scope'            => $scope,
            'chain_id'         => $chain_id,
            'issued_at'        => self::now(),
            'pass_fingerprint' => self::pass_fingerprint($user_id),
        ];
        self::save($stored);

        return $token;
    }

    /**
     * Validate a presented bearer token. Returns its bound record
     * ({ client_id, user_id, scope }) if the token's hash matches a stored,
     * unexpired record AND the bound user still exists with an unchanged
     * password, otherwise null. An expired match is also evicted.
     */
    public static function validate(string $token): ?array
    {
        $key    = self::hash($token);
        $stored = self::load();

        if (! isset($stored[ $key ])) {
            return null;
        }

        $record = $stored[ $key ];

        if (self::now() > $record['issued_at'] + self::TTL_SECONDS) {
            unset($stored[ $key ]);
            self::save($stored);
            return null;
        }

        $stored_fingerprint  = $record['pass_fingerprint'] ?? null;
        $current_fingerprint = self::pass_fingerprint((int) $record['user_id']);
        if (null === $stored_fingerprint || null === $current_fingerprint) {
            return null;
        }
        if (! hash_equals($stored_fingerprint, $current_fingerprint)) {
            return null;
        }

        return [
            'client_id' => $record['client_id'],
            'user_id'   => $record['user_id'],
            'scope'     => $record['scope'],
        ];
    }

    /**
     * Revoke every access token issued along a refresh-grant chain
     * (issue #133). Called by Refresh_Token_Store when it detects a
     * rotated token being reused past its grace window: revoking only the
     * refresh chain would leave the thief holding a working access token
     * for up to TTL_SECONDS.
     *
     * @return int Number of tokens removed.
     */
    public static function revoke_chain(string $chain_id): int
    {
        if ('' === $chain_id) {
            return 0;
        }

        $stored  = self::load();
        $removed = 0;

        foreach ($stored as $key => $record) {
            if ((string) ($record['chain_id'] ?? '') === $chain_id) {
                unset($stored[ $key ]);
                $removed++;
            }
        }

        if ($removed > 0) {
            self::save($stored);
        }

        return $removed;
    }

    /** Revoke every access token bound to a client. Returns the number removed. */
    public static function revoke_for_client(string $client_id): int
    {
        $stored  = self::load();
        $removed = 0;

        foreach ($stored as $key => $record) {
            if ((string) ($record['client_id'] ?? '') === $client_id) {
                unset($stored[ $key ]);
                $removed++;
            }
        }

        if ($removed > 0) {
            self::save($stored);
        }

        return $removed;
    }

    /** Whether any access token is currently bound to a client. */
    public static function has_tokens_for_client(string $client_id): bool
    {
        foreach (self::load() as $record) {
            if ((string) ($record['client_id'] ?? '') === $client_id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sweep expired access tokens (issue #133). Until now eviction was
     * lazy and on-touch only: an expired token was removed the next time
     * someone happened to present it, so tokens from clients that simply
     * went away accumulated in the option forever. Idempotent.
     *
     * @return int Number of tokens removed.
     */
    public static function gc(): int
    {
        $stored  = self::load();
        $now     = self::now();
        $removed = 0;

        foreach ($stored as $key => $record) {
            if ($now > (int) ($record['issued_at'] ?? 0) + self::TTL_SECONDS) {
                unset($stored[ $key ]);
                $removed++;
            }
        }

        if ($removed > 0) {
            self::save($stored);
        }

        return $removed;
    }

    /**
     * A SHA-256 fingerprint of the user's current password hash, or null if
     * the user no longer exists. Public since issue #142 so
     * Refresh_Token_Store can bind long-lived gateway refresh tokens to the
     * same credential state, using one definition of "fingerprint" rather
     * than two that could drift apart. Never the raw user_pass itself; used only
     * to detect "this user still exists and their credentials have not
     * changed since token issuance" (issue #43 C1/C2). Never returned from
     * validate(), never logged.
     */
    public static function pass_fingerprint(int $user_id): ?string
    {
        $user = get_userdata($user_id);
        if (false === $user) {
            return null;
        }

        return hash('sha256', $user->user_pass);
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
