<?php

namespace WPMCP\Auth;

use WPMCP\Governance\Governance_Audit_Log;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The authorization_code grant exchange (RFC 6749 4.1.3), PKCE-bound per RFC
 * 7636 (OAuth 2.1 makes this mandatory, S256-only -- see PKCE::verify()).
 * The REST token endpoint hands this the decoded request body and this does
 * everything the exchange step is responsible for: redeem the code (single-
 * use, short-lived -- Code_Store::consume() enforces both), verify the PKCE
 * verifier against the challenge the code was issued with, confirm the
 * presented client_id and redirect_uri match what the code was bound to, and
 * mint a bearer access token. Every outcome is recorded to the governance
 * audit log under ability 'oauth/token' with only the allow/deny outcome and
 * client_id, never the code, secret, or the minted token.
 *
 * Client authentication (client_id + client_secret) happens FIRST, before the
 * code is ever consumed: every client this plugin registers via DCR is
 * issued a secret (Client_Store::create() has no "public client" mode yet),
 * so client_secret is required and verified here, and a request that fails
 * client authentication must not burn the code (Code_Store::consume() is
 * single-use, so checking auth first preserves the code for a legitimate
 * retry). This uses the distinct RFC 6749 'invalid_client' error code, since
 * that failure is meaningfully different from an invalid/expired/mismatched
 * grant.
 *
 * Every OTHER rejection (bad/expired/replayed code, wrong redirect_uri,
 * wrong verifier) intentionally returns the single generic 'invalid_grant'
 * code rather than a more specific one per reason, so a caller probing the
 * endpoint (who has already authenticated as a real client) cannot use
 * varying error codes to distinguish "code doesn't exist" from "wrong
 * verifier" from "wrong redirect_uri" -- that distinction is exactly the
 * kind of oracle an attacker attempting to brute force or hijack a code
 * would want. The refresh_token grant added in issue #133 answers to the
 * same rule: unknown, expired, wrong-client and reused-past-grace all come
 * back as a flat 'invalid_grant'.
 *
 * REFRESH GRANT (issue #133). A successful authorization_code exchange now
 * also mints a refresh token, and both tokens are stamped with the same
 * grant chain id so a later breach can be unwound as a unit. The
 * refresh_token grant rotates: each redemption returns a new access token
 * AND a new refresh token in the same chain. Reliability and safety are
 * reconciled in Refresh_Token_Store, whose docblock has the three-state
 * model (fresh / in grace / burned) -- the short version is that a
 * dropped-response retry is forgiven for a couple of minutes, and a reuse
 * after that revokes the whole chain, access tokens included.
 */
class Token_Grant
{
    /**
     * @param array $params Decoded token request body.
     * @return array{access_token: string, token_type: string, expires_in: int, scope: string, refresh_token: string}|\WP_Error
     */
    public static function exchange(array $params): array|\WP_Error
    {
        $grant_type = (string) ($params['grant_type'] ?? '');
        if (! in_array($grant_type, ['authorization_code', 'refresh_token'], true)) {
            return new \WP_Error(
                'unsupported_grant_type',
                'Only the authorization_code and refresh_token grant types are supported.'
            );
        }

        $client_id     = (string) ($params['client_id'] ?? '');
        $client_secret = (string) ($params['client_secret'] ?? '');
        if (! Client_Store::verify_secret($client_id, $client_secret)) {
            self::audit(false, $client_id);
            return new \WP_Error('invalid_client', 'Client authentication failed.');
        }

        // Opportunistic, throttled housekeeping (issue #133). Runs after
        // client authentication so an unauthenticated caller cannot use the
        // endpoint to trigger store rewrites.
        Oauth_Gc::run_throttled();

        return 'refresh_token' === $grant_type
            ? self::refresh($params, $client_id)
            : self::authorization_code($params, $client_id);
    }

    /** The RFC 6749 4.1.3 authorization_code exchange, PKCE-verified. */
    private static function authorization_code(array $params, string $client_id): array|\WP_Error
    {
        $code = (string) ($params['code'] ?? '');
        if ('' === $code) {
            return self::deny($client_id);
        }

        $record = Code_Store::consume($code);
        if (null === $record) {
            return self::deny($client_id);
        }

        if ($client_id !== $record['client_id']) {
            return self::deny($client_id);
        }

        $redirect_uri = (string) ($params['redirect_uri'] ?? '');
        if ($redirect_uri !== $record['redirect_uri']) {
            return self::deny($client_id);
        }

        $verifier = (string) ($params['code_verifier'] ?? '');
        if (! PKCE::verify($verifier, $record['code_challenge'], $record['code_challenge_method'])) {
            return self::deny($client_id);
        }

        self::audit(true, $client_id);

        return self::mint(
            $client_id,
            (int) $record['user_id'],
            (string) $record['scope'],
            Refresh_Token_Store::new_chain_id()
        );
    }

    /**
     * The RFC 6749 6 refresh_token grant, with rotation. The presented
     * token is bound to the authenticated client before any state change,
     * so a guessed token cannot be burned by a caller who does not own it.
     */
    private static function refresh(array $params, string $client_id): array|\WP_Error
    {
        $presented = (string) ($params['refresh_token'] ?? '');
        if ('' === $presented) {
            return self::deny($client_id);
        }

        $outcome = Refresh_Token_Store::redeem($presented, $client_id);
        $status  = (string) $outcome['status'];

        if ('reuse_detected' === $status) {
            // The chain is already revoked by redeem(); this records the
            // detection under its own ability name so a site owner can
            // find it in the governance audit log without guessing.
            self::audit(false, $client_id, 'oauth/refresh-reuse');
            return self::invalid_grant();
        }

        if ('ok' !== $status && 'grace' !== $status) {
            return self::deny($client_id);
        }

        $record   = $outcome['record'];
        $user_id  = (int) $record['user_id'];
        $chain_id = (string) ($record['chain_id'] ?? '');

        // A grant must not outlive the account it was issued for. The
        // access token's own credential fingerprint (Token_Store) already
        // catches a deleted or re-passworded user at validation time; this
        // stops us from cheerfully minting a token for one first.
        if (false === get_userdata($user_id)) {
            Refresh_Token_Store::revoke_chain($chain_id);
            return self::deny($client_id);
        }

        self::audit(true, $client_id);

        // Carry the record's own lifetime forward, so a long-lived machine
        // credential (the gateway chain, issue #130) does not silently fall
        // back to the ordinary session TTL the first time it rotates.
        return self::mint($client_id, $user_id, (string) $record['scope'], $chain_id, (int) ($record['ttl'] ?? 0));
    }

    /**
     * Issue an access + refresh token pair on the same grant chain. Both
     * plaintext values exist only in this return array; the stores keep
     * hashes.
     *
     * @return array{access_token: string, token_type: string, expires_in: int, scope: string, refresh_token: string}
     */
    private static function mint(string $client_id, int $user_id, string $scope, string $chain_id, int $refresh_ttl = 0): array
    {
        return [
            'access_token'  => Token_Store::issue($client_id, $user_id, $scope, $chain_id),
            'token_type'    => 'Bearer',
            'expires_in'    => Token_Store::TTL_SECONDS,
            'scope'         => $scope,
            'refresh_token' => Refresh_Token_Store::issue($client_id, $user_id, $scope, $chain_id, $refresh_ttl),
        ];
    }

    private static function deny(string $client_id): \WP_Error
    {
        self::audit(false, $client_id);

        return self::invalid_grant();
    }

    private static function invalid_grant(): \WP_Error
    {
        return new \WP_Error('invalid_grant', 'The provided authorization grant is invalid, expired, or does not match.');
    }

    private static function audit(bool $allowed, string $client_id, string $ability = 'oauth/token'): void
    {
        try {
            Governance_Audit_Log::record($ability, 'client:' . $client_id, $allowed);
        } catch (\Throwable $e) {
            // Auditing must never break the token exchange outcome it is observing.
        }
    }
}
