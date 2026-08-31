<?php

namespace WPMCP\Auth;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * OAuth 2.1 refresh tokens with rotation, an idempotent-refresh grace
 * window, and post-grace reuse detection (issue #133).
 *
 * Backed by a single wpmcp_oauth_refresh_tokens option, a map of the
 * SHA-256 hash of the token to its bound record:
 * { client_id, user_id, scope, chain_id, issued_at, rotated_at,
 * pass_fingerprint }.
 * Storage properties match Token_Store and Code_Store exactly: the
 * plaintext token is returned once at issuance and never persisted, so a
 * leaked options row cannot be replayed.
 *
 * WHY THIS EXISTS. Before this, access tokens hard-expired after an hour
 * with nothing to renew them, so a long agent session simply died
 * mid-conversation and the user had to reconnect. Refresh tokens fix that,
 * but naive rotation introduces a worse failure: OAuth 2.1 requires a
 * rotated refresh token to be invalidated, and the canonical response to
 * seeing one used twice is to revoke the whole grant as compromised. On a
 * flaky mobile or shared-host connection the rotation response gets
 * dropped often enough that the client legitimately retries with the token
 * it still holds, and a strict server answers by nuking the session. That
 * is the disconnect-mid-chat bug.
 *
 * THE THREE-STATE MODEL. Every token is in exactly one state:
 *
 *  - FRESH (rotated_at = 0). Redeeming it rotates: rotated_at is stamped,
 *    a successor is minted in the same chain, status 'ok'.
 *
 *  - IN GRACE (rotated_at set, within the grace window). Redeeming it
 *    again is treated as the retry it almost certainly is: status
 *    'grace', another successor is minted in the same chain, and the
 *    grant survives. Crucially the grace anchor is NOT moved forward on a
 *    grace hit, so an attacker holding a captured token cannot walk the
 *    window indefinitely by replaying it; the window closes a fixed
 *    interval after the first rotation, full stop.
 *
 *  - BURNED (rotated_at set, past the grace window). This is no longer
 *    explicable as a retry, so it is treated as evidence of a stolen
 *    token: status 'reuse_detected', and the ENTIRE chain is revoked --
 *    every refresh token descended from the same original grant, plus
 *    every access token issued along it. Both the attacker and the
 *    legitimate client are logged out, which is the correct outcome when
 *    you cannot tell which of the two you are talking to.
 *
 * The competing implementation we studied has the grace window but no
 * third state: it soft-expires the rotated token and lets it lapse, so a
 * genuinely stolen refresh token used after the window is simply rejected
 * once while any tokens the thief already minted keep working. Chain
 * revocation is what turns the grace window from a reliability patch into
 * something that is still safe to ship.
 *
 * A rotated token is deliberately retained until its natural TTL rather
 * than deleted at the end of its grace window: it is the tripwire, and
 * deleting it would downgrade a detected breach into an ordinary
 * "unknown token" rejection. gc() only removes records that are past TTL.
 *
 * Concurrency: two simultaneous redemptions of the same FRESH token can
 * both observe rotated_at = 0 and both rotate. That is harmless here by
 * construction -- the outcome is identical to one rotation plus one grace
 * hit, which is exactly the case this class is designed to forgive -- so
 * this store does not need Code_Store's compare-and-swap.
 */
class Refresh_Token_Store
{
    public const OPTION = 'wpmcp_oauth_refresh_tokens';

    /** Refresh token lifetime. Long by design: it is what keeps a session alive across days. */
    public const TTL_SECONDS = 2592000; // 30 days.

    /** How long a rotated token keeps working, for dropped-response retries. */
    public const GRACE_SECONDS = 120;

    private static $clock_override = null;

    public static function set_clock_override(?callable $clock): void
    {
        self::$clock_override = $clock;
    }

    private static function now(): int
    {
        return null !== self::$clock_override ? (int) (self::$clock_override)() : time();
    }

    /** Scope stamped on gateway refresh tokens (Gateway_Credential::SCOPE). */
    public const GATEWAY_SCOPE = 'gateway';

    /**
     * Refresh token lifetime in seconds, filterable. Floored at one minute.
     *
     * Gateway tokens (issue #142) get a second, narrower filter on top:
     * `wpmcp_gateway_refresh_ttl` receives the general value and may shorten
     * or lengthen it for the proxy credential alone, so an operator can run
     * a 30-day interactive session and a 24-hour gateway credential without
     * the two settings fighting. The literal scope string is used rather
     * than a use-statement so src/Auth keeps no dependency on src/Gateway.
     */
    public static function ttl(string $scope = ''): int
    {
        $ttl = max(60, (int) apply_filters('wpmcp_oauth_refresh_ttl', self::TTL_SECONDS));

        if (self::GATEWAY_SCOPE === $scope) {
            $ttl = max(60, (int) apply_filters('wpmcp_gateway_refresh_ttl', $ttl));
        }

        return $ttl;
    }

    /**
     * The idempotent-refresh grace window in seconds, filterable. Floored
     * at 0, which restores strict single-use rotation (any reuse is a
     * breach) for deployments that want it.
     */
    public static function grace(): int
    {
        return max(0, (int) apply_filters('wpmcp_oauth_refresh_grace', self::GRACE_SECONDS));
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
     * Mint a refresh token. Omit $chain_id to start a new grant chain (the
     * authorization_code exchange); pass the redeemed token's chain_id to
     * continue an existing one (a rotation).
     *
     * @return string The plaintext token, returned exactly once.
     *
     * @throws \InvalidArgumentException When $user_id does not resolve to a
     *         user, so the token could not be bound to a credential state.
     */
    public static function issue(string $client_id, int $user_id, string $scope, string $chain_id = ''): string
    {
        // May be null when the user does not resolve (synthetic ids in the
        // store's own unit tests, a user deleted between authorisation and
        // issuance). A null is stored as "unbound", not as a value to
        // compare against, and redeem() adopts the real fingerprint the
        // first time the user does resolve -- see the binding block there.
        $fingerprint = Token_Store::pass_fingerprint($user_id);

        $token = 'rt_' . bin2hex(random_bytes(32));

        $stored                      = self::load();
        $stored[ self::hash($token) ] = [
            'client_id'        => $client_id,
            'user_id'          => $user_id,
            'scope'            => $scope,
            'chain_id'         => '' !== $chain_id ? $chain_id : self::new_chain_id(),
            'issued_at'        => self::now(),
            'rotated_at'       => 0,
            'pass_fingerprint' => $fingerprint,
        ];
        self::save($stored);

        return $token;
    }

    /**
     * Redeem a refresh token, applying the three-state model described in
     * the class docblock.
     *
     * @param string $client_id When non-empty, the token must be bound to
     *                           this client. The check runs BEFORE any
     *                           state change, so a token presented by the
     *                           wrong client is rejected without being
     *                           rotated or burned -- an attacker who has
     *                           only guessed a token must not be able to
     *                           invalidate it for its rightful owner.
     * @return array{status: string, record?: array} status is one of
     *         'ok' (fresh, rotated now), 'grace' (rotated already but
     *         within the window), 'unknown', 'expired', 'client_mismatch',
     *         'credential_changed' (the bound user was deleted or changed
     *         their password; chain revoked as a side effect), or
     *         'reuse_detected' (chain revoked as a side effect).
     */
    public static function redeem(string $token, string $client_id = ''): array
    {
        $key    = self::hash($token);
        $stored = self::load();

        if (! isset($stored[ $key ])) {
            return ['status' => 'unknown'];
        }

        $record = $stored[ $key ];
        $now    = self::now();

        if ('' !== $client_id && (string) ($record['client_id'] ?? '') !== $client_id) {
            return ['status' => 'client_mismatch'];
        }

        if ($now > (int) $record['issued_at'] + self::ttl((string) ($record['scope'] ?? ''))) {
            unset($stored[ $key ]);
            self::save($stored);
            return ['status' => 'expired'];
        }

        $rotated_at = (int) ($record['rotated_at'] ?? 0);

        // Reuse detection runs FIRST, before the credential-binding check
        // below. A burned token presented after a password change is still
        // a reuse event, and it is the one case the #133 alarm exists for
        // (leaked token, owner reacts by changing their password); letting
        // 'credential_changed' win would take the dedicated
        // oauth/refresh-reuse audit row out of the governance log in
        // exactly that scenario. Both outcomes revoke the chain, so
        // reporting the more serious one costs nothing.
        if (0 !== $rotated_at && $now > $rotated_at + self::grace()) {
            self::revoke_chain((string) ($record['chain_id'] ?? ''));
            return ['status' => 'reuse_detected'];
        }

        // Credential binding (issue #142). Access tokens already die on a
        // password change or account deletion via Token_Store's
        // fingerprint check, but a refresh token lives 30 days and mints
        // fresh access tokens for that whole window, so without this a
        // password change would not actually end the session it is
        // supposed to end.
        //
        // Absent and null are both "unbound" and take the same path
        // deliberately: there is no action that would differ between them,
        // and denying on a null would turn a momentary user-lookup failure
        // into a whole-chain revocation. What must NOT happen is an
        // unbound record staying unbound forever, and it does not: see
        // ADOPTION below. A token whose user cannot be resolved at all is
        // still refused a grant by Token_Grant::refresh(), which checks
        // get_userdata() before minting.
        $current = Token_Store::pass_fingerprint((int) ($record['user_id'] ?? 0));
        $bound   = $record['pass_fingerprint'] ?? null;

        if (is_string($bound)) {
            if (null === $current || ! hash_equals($bound, $current)) {
                self::revoke_chain((string) ($record['chain_id'] ?? ''));
                return ['status' => 'credential_changed'];
            }
        } elseif (null !== $current) {
            // ADOPTION, and the reason the upgrade window is not left open.
            // An unbound record is either pre-#142 or one whose user did not
            // resolve at issuance. Revoking it outright would log every
            // existing session out on deploy, so instead the current
            // fingerprint is stamped on the first successful redeem: the
            // session in flight survives, and every password change AFTER
            // this moment kills it like any post-#142 token.
            $stored[ $key ]['pass_fingerprint'] = $current;
            $record['pass_fingerprint']         = $current;
            self::save($stored);
        }

        if (0 === $rotated_at) {
            $stored[ $key ]['rotated_at'] = $now;
            self::save($stored);
            return ['status' => 'ok', 'record' => $record];
        }

        // Deliberately does not re-stamp rotated_at: the window is anchored
        // to the FIRST rotation and cannot be walked forward.
        return ['status' => 'grace', 'record' => $record];
    }

    /**
     * Revoke every refresh token in a grant chain, and every access token
     * issued along it. Returns the number of refresh tokens removed.
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

        Token_Store::revoke_chain($chain_id);

        return $removed;
    }

    /** Revoke every refresh token bound to a client. Returns the number removed. */
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

    /** Whether any refresh token is currently bound to a client. */
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
     * Drop records past their TTL. Rotated-but-unexpired records are kept
     * on purpose: they are the reuse tripwire (see class docblock).
     *
     * @return int Number of records removed.
     */
    public static function gc(): int
    {
        $stored  = self::load();
        $now     = self::now();
        $removed = 0;

        foreach ($stored as $key => $record) {
            if ($now > (int) ($record['issued_at'] ?? 0) + self::ttl((string) ($record['scope'] ?? ''))) {
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
     * A fresh grant-chain identifier. Public so the token endpoint can mint
     * one and stamp the SAME chain onto both the access token and the
     * refresh token it issues together.
     */
    public static function new_chain_id(): string
    {
        return 'chain_' . bin2hex(random_bytes(16));
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
