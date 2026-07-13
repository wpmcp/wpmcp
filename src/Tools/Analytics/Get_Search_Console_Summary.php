<?php

namespace WPMCP\Tools\Analytics;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: clicks/impressions/ctr/position summary over a date range, via
 * Analytics_Adapter::search_console_summary(). start_date/end_date are
 * optional (Y-m-d; default a trailing 28-day window ending yesterday) and
 * validated via Analytics_Adapter::validate_date_range(), whose WP_Error is
 * returned as-is on malformed input. Returns a
 * wpmcp_analytics_not_connected WP_Error when no Search Console provider is
 * connected.
 */
class Get_Search_Console_Summary
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

        return Analytics_Adapter::search_console_summary($range['start_date'], $range['end_date']);
    }
}
