<?php

namespace WPMCP\RateLimit;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Per-client rate limiter: caps how many ability/tool calls a single client
 * may make per time window, so a runaway agent loop or an abusive client
 * cannot hammer the site.
 *
 * The counter is a fixed window: for a window of W seconds the current bucket
 * is floor(now / W), and each client gets one budget across ALL abilities per
 * bucket. Counters live in transients keyed by client identity plus bucket, so
 * a stale bucket self-expires (TTL = the window) and never needs cleanup.
 *
 * A static clock override (mirroring Database_Guard's test-seam pattern) lets
 * tests advance the window deterministically without sleeping.
 */
class Rate_Limiter
{
    /** Default number of calls allowed per window, before the wpmcp_rate_limit filter. */
    public const DEFAULT_LIMIT = 120;

    /** Default window length in seconds, before the wpmcp_rate_limit_window filter. */
    public const DEFAULT_WINDOW = 60;

    /** Transient key prefix for a per-client, per-bucket counter. */
    private const PREFIX = 'wpmcp_rl_';

    /** Test seam: when set, called to obtain the current unix timestamp. */
    private static $clock_override = null;

    /**
     * Test seam: force the current time. Pass a callable returning an int unix
     * timestamp to freeze/advance the window; pass null to resume live time().
     */
    public static function set_clock_override(?callable $clock): void
    {
        self::$clock_override = $clock;
    }

    /** Current unix timestamp, honoring the test clock override when present. */
    private static function now(): int
    {
        if (null !== self::$clock_override) {
            return (int) (self::$clock_override)();
        }
        return time();
    }

    /** Calls allowed per window (wpmcp_rate_limit filter). */
    private static function limit(): int
    {
        return (int) apply_filters('wpmcp_rate_limit', self::DEFAULT_LIMIT);
    }

    /** Window length in seconds (wpmcp_rate_limit_window filter). */
    private static function window(): int
    {
        return (int) apply_filters('wpmcp_rate_limit_window', self::DEFAULT_WINDOW);
    }

    /**
     * Record one call for $key and report whether it is allowed under the
     * current window's budget.
     *
     * @return array{allowed: bool, remaining: int, retry_after: int}
     */
    public static function check(string $key): array
    {
        $window = self::window();
        $limit  = self::limit();
        $now    = self::now();
        $bucket = (int) floor($now / $window);

        $transient = self::PREFIX . md5($key . ':' . $bucket);
        $count     = (int) get_transient($transient);
        $count++;
        set_transient($transient, $count, $window);

        $remaining   = max(0, $limit - $count);
        $allowed     = $count <= $limit;
        $retry_after = $allowed ? 0 : (($bucket + 1) * $window) - $now;

        return [
            'allowed'     => $allowed,
            'remaining'   => $remaining,
            'retry_after' => $retry_after,
        ];
    }
}
