<?php

namespace WPMCP\Auth;

use WPMCP\Governance\Governance_Audit_Log;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Wires Token_Store::validate() (the OAuth bearer-token-validation helper,
 * issue #43) into WordPress's own determine_current_user filter -- the same
 * seam WP core's Application Passwords feature uses to authenticate REST
 * requests from a header instead of a cookie. Registrar's existing
 * permission_callback already calls current_user_can($a->capability); once
 * this filter resolves an OAuth Bearer token to its bound WP user id, that
 * check (and every other current_user_can()/wp_get_current_user() call for
 * the rest of the request) sees that user automatically, with no change to
 * Registrar itself.
 *
 * Gated by OAuth_Config::is_enabled(): resolve() reads the Authorization
 * header at all ONLY when the subsystem is enabled, so a default (disabled)
 * install's unauthenticated-in-dev behavior -- and every other auth path --
 * is completely unaffected.
 *
 * Scope note: this wires user IDENTITY resolution only. Token_Store::validate()
 * also returns the client's granted scope, but no ability/domain-level scope
 * enforcement consults it yet (see the issue #43 report for why that is
 * explicitly out of scope for this pass); a Bearer-authenticated caller is
 * subject to exactly the same capability + Governance gates as any other WP
 * user, nothing more permissive and nothing scope-restricted yet.
 */
class Bearer_Auth
{
    /**
     * The record of the bearer token that authenticated THIS request, or
     * null when the request was not bearer-authenticated.
     *
     * resolve() clears it on entry, not only on a failed validation, so the
     * invariant holds in a process that resolves more than once (WP-CLI, a
     * wp_set_current_user() re-resolution, a batch or test run): a caller
     * presenting no token must never inherit the previous caller's record.
     *
     * Kept because resolve() returns only the user id: layers above need
     * the token's client_id to decide what else the caller is (issue #130
     * looks it up against the stored gateway credential to bind the request
     * to a named Identity). Deliberately the client_id and scope only,
     * never the token, and never written from anything but a successful
     * Token_Store::validate().
     *
     * @var array{client_id: string, user_id: int, scope: string}|null
     */
    private static ?array $current = null;

    /** @return array{client_id: string, user_id: int, scope: string}|null */
    public static function current_token(): ?array
    {
        return self::$current;
    }

    /** The client_id that authenticated this request, or '' when none did. */
    public static function current_client_id(): string
    {
        return (string) (self::$current['client_id'] ?? '');
    }

    /** Test seam: forget the request-scoped token record. */
    public static function reset_for_tests(): void
    {
        self::$current = null;
    }

    public function register(): void
    {
        add_filter('determine_current_user', [self::class, 'resolve'], 20);
    }

    /**
     * @param int|false $incoming_user_id Whatever a higher-priority
     *                                    determine_current_user listener (or
     *                                    WordPress's own cookie auth) has
     *                                    already resolved; passed through
     *                                    unchanged whenever this filter has
     *                                    nothing to add.
     * @return int|false
     */
    public static function resolve($incoming_user_id)
    {
        self::$current = null;

        if (! OAuth_Config::is_enabled()) {
            return $incoming_user_id;
        }

        $token = self::bearer_token_from_request();
        if (null === $token) {
            return $incoming_user_id;
        }

        $record = Token_Store::validate($token);
        if (null === $record) {
            self::audit(false);
            return $incoming_user_id;
        }

        /**
         * Last refusal on an otherwise valid token, for listeners that know
         * something about the CALLER this store cannot: issue #130's gateway
         * credential is only allowed on the MCP transport surface, so it
         * refuses itself here rather than authenticating an administrator on
         * /wp/v2/users. A listener may only refuse; it can never turn an
         * invalid token into a valid one, because this runs after validate().
         *
         * @param bool  $accepted Whether the validated token authenticates this request.
         * @param array $record   { client_id, user_id, scope } of the validated token.
         */
        if (! apply_filters('wpmcp_bearer_token_accepted', true, $record)) {
            self::audit(false);
            return $incoming_user_id;
        }

        self::$current = $record;

        self::audit(true);

        return $record['user_id'];
    }

    private static function bearer_token_from_request(): ?string
    {
        $header = isset($_SERVER['HTTP_AUTHORIZATION']) ? (string) $_SERVER['HTTP_AUTHORIZATION'] : '';
        if (! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($header, strlen('Bearer ')));

        return '' === $token ? null : $token;
    }

    private static function audit(bool $allowed): void
    {
        try {
            Governance_Audit_Log::record('oauth/validate', 'none', $allowed);
        } catch (\Throwable $e) {
            // Auditing must never break the authentication outcome it is observing.
        }
    }
}
