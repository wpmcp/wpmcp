<?php

namespace WPMCP\Tools\Analytics;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: top pages by pageviews over a date range, via
 * Analytics_Adapter::top_pages(). start_date/end_date are optional (Y-m-d;
 * default a trailing 28-day window ending yesterday) and validated via
 * Analytics_Adapter::validate_date_range(), whose WP_Error is returned as-is
 * on malformed input. limit is optional and clamped by Analytics_Adapter
 * (default DEFAULT_LIMIT, hard-capped at MAX_LIMIT), so a malformed or
 * hostile argument never fatals. Returns a wpmcp_analytics_not_connected
 * WP_Error when no analytics provider is connected.
 */
class Get_Top_Pages
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

        $limit = Analytics_Adapter::clamp_limit($this->to_int_or_null($args['limit'] ?? null));

        return Analytics_Adapter::top_pages($range['start_date'], $range['end_date'], $limit);
    }

    /**
     * Coerce a caller-supplied limit value to an int, treating non-numeric
     * input the same as "not given" rather than throwing, so a malformed
     * argument degrades to the adapter's default/clamped value instead of a
     * fatal. Copied from List_Network_Sites's private helper of the same
     * idiom.
     */
    private function to_int_or_null($value): ?int
    {
        if (null === $value || ! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
