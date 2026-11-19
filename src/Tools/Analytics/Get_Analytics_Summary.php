<?php

namespace WPMCP\Tools\Analytics;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: sessions/users/pageviews summary over a date range, via
 * Analytics_Adapter::analytics_summary(). start_date/end_date are optional
 * (Y-m-d; default a trailing 28-day window ending yesterday) and validated
 * via Analytics_Adapter::validate_date_range(), whose WP_Error is returned
 * as-is on malformed input. Returns a wpmcp_analytics_not_connected
 * WP_Error when no analytics provider is connected. An optional `source`
 * names the provider (site-kit or a stats plugin, issue #699).
 */
class Get_Analytics_Summary
{
    public function handle(array $args)
    {
        $range = Analytics_Adapter::validate_date_range(
            $args['start_date'] ?? null,
            $args['end_date'] ?? null
        );
        if ($range instanceof \WP_Error) {
            return $range;
        }

        return Analytics_Adapter::analytics_summary($range['start_date'], $range['end_date'], self::source($args));
    }

    /** The optional `source` argument (issue #699), null when absent or not a string. */
    public static function source(array $args): ?string
    {
        return isset($args['source']) && is_string($args['source']) && '' !== $args['source'] ? sanitize_key($args['source']) : null;
    }
}
