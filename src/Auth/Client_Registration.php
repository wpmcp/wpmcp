<?php

namespace WPMCP\Auth;

use WPMCP\Governance\Governance_Audit_Log;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * RFC 7591 Dynamic Client Registration request handler: the REST layer
 * (Registration_Endpoint) hands this the decoded request body plus a caller
 * identity key, and this validates redirect_uris, enforces a dedicated
 * registration rate limit, enforces Client_Store's total-client cap, and
 * records the outcome to the governance audit log under ability
 * 'oauth/register' (never the secret; only the allow/deny outcome and
 * identity key are ever written).
 *
 * The registration rate limit is a self-contained fixed-window transient
 * counter, deliberately NOT WPMCP\RateLimit\Rate_Limiter: that class's
 * check() has no way to use a different limit/window than the shared
 * wpmcp_rate_limit filters, and DCR needs its own budget decoupled from the
 * general per-ability throttle (used post-auth by Registrar), since
 * registration happens before any WP user/client identity exists and is
 * keyed on whatever caller identity is available (the remote IP in
 * production) via its own filters (wpmcp_oauth_registration_rate_limit and
 * _window) so site owners can tune DCR abuse resistance independently of
 * tool-call throttling.
 */
class Client_Registration
{
    private const DEFAULT_LIMIT  = 5;
    private const DEFAULT_WINDOW = 3600;
    private const KEY_PREFIX     = 'wpmcp_oauth_dcr_';

    /** Test seam: force the current time, mirroring Rate_Limiter's clock override. */
    private static $clock_override = null;

    public static function set_clock_override(?callable $clock): void
    {
        self::$clock_override = $clock;
    }

    private static function now(): int
    {
        return null !== self::$clock_override ? (int) (self::$clock_override)() : time();
    }

    /**
     * @param array $params Decoded request body; expects 'client_name' and
     *                       'redirect_uris' (string[]).
     * @param string $client_key Caller identity to key the registration rate
     *                            limit on (e.g. 'ip:1.2.3.4').
     * @return array{client_id: string, client_secret: string}|\WP_Error
     */
    public static function register(array $params, string $client_key): array|\WP_Error
    {
        if (! self::rate_limit_check($client_key)) {
            return self::deny('registration_rate_limited', 'Too many client registration attempts. Try again later.');
        }

        $raw_redirect_uris = $params['redirect_uris'] ?? [];
        if (! is_array($raw_redirect_uris)) {
            return self::deny('invalid_redirect_uri', 'redirect_uris must be an array of strings.');
        }
        foreach ($raw_redirect_uris as $uri) {
            if (! is_string($uri) && ! is_numeric($uri)) {
                return self::deny('invalid_redirect_uri', 'Every redirect_uri must be a string.');
            }
        }

        $redirect_uris = array_values(array_filter(array_map('strval', $raw_redirect_uris)));
        if ([] === $redirect_uris) {
            return self::deny('invalid_redirect_uri', 'At least one redirect_uri is required.');
        }
        foreach ($redirect_uris as $uri) {
            if (! Redirect_Uri_Validator::is_valid($uri)) {
                return self::deny('invalid_redirect_uri', "redirect_uri \"{$uri}\" is not a valid absolute HTTPS (or loopback) URI.");
            }
        }

        $client_name = (string) ($params['client_name'] ?? '');

        // Opportunistic, throttled housekeeping (issue #133): DCR is the
        // one endpoint whose whole job is to add rows, so it is the right
        // place to also pay off expired tokens/codes and orphan clients
        // before the cap check below can be tripped by dead bookkeeping.
        Oauth_Gc::run_throttled();

        try {
            // $client_key is threaded through so the dedup fingerprint is
            // bound to the registering caller; see Client_Store::create().
            $created = Client_Store::create([$client_name], $redirect_uris, $client_key);
        } catch (\RuntimeException $e) {
            return self::deny('client_cap_reached', 'The maximum number of registered OAuth clients has been reached.');
        }

        self::audit(true);

        return $created;
    }

    private static function deny(string $code, string $message, array $data = []): \WP_Error
    {
        self::audit(false);
        return new \WP_Error($code, $message, $data);
    }

    private static function audit(bool $allowed): void
    {
        try {
            Governance_Audit_Log::record('oauth/register', 'none', $allowed);
        } catch (\Throwable $e) {
            // Auditing must never break the registration outcome it is observing.
        }
    }

    private static function limit(): int
    {
        return (int) apply_filters('wpmcp_oauth_registration_rate_limit', self::DEFAULT_LIMIT);
    }

    private static function window(): int
    {
        return (int) apply_filters('wpmcp_oauth_registration_rate_limit_window', self::DEFAULT_WINDOW);
    }

    /** True iff $client_key is still within its registration budget for the current window. */
    private static function rate_limit_check(string $client_key): bool
    {
        $window = self::window();
        $limit  = self::limit();
        $now    = self::now();
        $bucket = (int) floor($now / $window);

        $transient = self::KEY_PREFIX . md5($client_key . ':' . $bucket);
        $count     = (int) get_transient($transient);
        $count++;
        set_transient($transient, $count, $window);

        return $count <= $limit;
    }
}
