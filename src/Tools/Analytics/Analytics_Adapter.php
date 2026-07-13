<?php

namespace WPMCP\Tools\Analytics;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only analytics and Search Console reporting, backed by whichever
 * provider is active: Google Site Kit (preferred, when its plugin constant
 * or class is detected), or a minimal explicitly-configured set of
 * credentials stored in the 'wpmcp_analytics_config' option. Mirrors how
 * I18n_Adapter sits in front of Polylang/WPML and Multisite_Adapter sits in
 * front of WordPress's own network API: a thin, static, adapter-shaped class
 * so the tool classes in this namespace stay simple argument-validation
 * wrappers.
 *
 * This is deliberately READ-ONLY: analytics/Search Console summaries and
 * reports, nothing that writes back to Google or to this site.
 *
 * Honesty note on test coverage: Google Site Kit is not installed in this
 * unit-test harness (unlike Polylang, which ships a test-detectable constant
 * here, Site Kit is not one of the plugins bundled into this environment), and
 * a real 'configured' setup would still require an outbound call to Google's
 * GA4 Data API / Search Console API to return real numbers, which this
 * harness has neither credentials nor network access for. That is a
 * production-only gap, not an oversight, matching how Multisite_Adapter
 * documents network_info()/list_sites()/site_details() and how I18n_Adapter
 * documents its WPML paths as untested against a real install.
 *
 * To keep active_provider()'s Site Kit detection unit-testable without
 * installing the real plugin or permanently declaring a throwaway
 * class/constant (which, once defined, cannot be undefined and so could leak
 * into other tests), the check is routed through a filter seam:
 * apply_filters('wpmcp_analytics_site_kit_active', <default detection>). A
 * test can flip this with add_filter()/remove_filter() without touching any
 * global PHP symbol table state.
 */
class Analytics_Adapter
{
    /**
     * The option name holding explicitly-configured analytics credentials,
     * shaped as ['property_id' => string, 'site_url' => string, ...]. Both
     * keys are required (non-empty) for the 'configured' provider to be
     * considered active; any other keys (e.g. a Search Console site
     * verification token) are provider-specific and not required here.
     */
    public const CONFIG_OPTION = 'wpmcp_analytics_config';

    /**
     * Default/max row limits for the "top N" reporting tools (top_pages,
     * search_console_queries). Smaller than Multisite_Adapter's 500-cap:
     * these are reporting rows returned inline in a single tool response,
     * not paginated entity listings, so a much smaller cap keeps responses
     * a reasonable size for an LLM caller to consume in one go.
     */
    public const DEFAULT_LIMIT = 10;
    public const MAX_LIMIT     = 100;

    /**
     * Which analytics provider is active: 'site-kit', 'configured', or ''
     * when neither is. Site Kit is checked first, so it wins if a site
     * somehow has both Site Kit active and a manual config option set,
     * matching I18n_Adapter's Polylang-wins-over-WPML precedence.
     */
    public static function active_provider(): string
    {
        $site_kit_active = apply_filters(
            'wpmcp_analytics_site_kit_active',
            defined('GOOGLESITEKIT_VERSION') || class_exists('Google\\Site_Kit\\Plugin')
        );

        if ($site_kit_active) {
            return 'site-kit';
        }

        $config = get_option(self::CONFIG_OPTION);
        if (self::is_valid_config(is_array($config) ? $config : [])) {
            return 'configured';
        }

        return '';
    }

    /**
     * Whether a configured-credentials array has the minimum required,
     * non-empty keys: property_id and site_url. Pure, so it is directly
     * testable without touching the options table.
     */
    public static function is_valid_config(array $config): bool
    {
        return ! empty($config['property_id']) && ! empty($config['site_url']);
    }

    /**
     * Always-safe connection status: ['provider' => 'site-kit'|'configured'|
     * 'none', 'connected' => bool, 'detail' => string]. Never a WP_Error, so
     * a caller can always discover state before deciding whether to use the
     * rest of the tool group (compare wpmcp/is-multisite and get-seo-status).
     *
     * 'connected' is reported conservatively: for 'configured' it is true
     * only when the stored config actually has usable values (mirroring
     * active_provider()'s own check). For 'site-kit', presence of the
     * plugin constant/class is NOT the same as being authenticated against
     * Google, and this adapter has no safe way to verify Site Kit's actual
     * OAuth/token state without calling into a booted Site Kit instance
     * (which is production-only here); rather than assert a certainty this
     * code cannot verify, 'connected' is left false for 'site-kit' and
     * 'detail' says plainly that authentication could not be confirmed.
     */
    public static function connection_status(): array
    {
        $provider = self::active_provider();

        if ('site-kit' === $provider) {
            return [
                'provider'  => 'site-kit',
                'connected' => false,
                'detail'    => 'Google Site Kit is active, but this adapter cannot verify its '
                    . 'authentication state without a live, booted Site Kit instance. Treat as '
                    . 'unconfirmed until a real report call succeeds.',
            ];
        }

        if ('configured' === $provider) {
            return [
                'provider'  => 'configured',
                'connected' => true,
                'detail'    => 'Analytics credentials are configured via the ' . self::CONFIG_OPTION . ' option.',
            ];
        }

        return [
            'provider'  => 'none',
            'connected' => false,
            'detail'    => 'No analytics provider is active. Activate and connect Google Site Kit, '
                . 'or configure analytics credentials.',
        ];
    }

    /**
     * Validate and coerce a caller-supplied start/end date pair to
     * ['start_date' => 'Y-m-d', 'end_date' => 'Y-m-d'], or a WP_Error when
     * the input cannot be made sense of. Pure aside from reading the current
     * date, so it is directly testable.
     *
     * Rules:
     *  - Both null/empty default to a trailing 28-day window ending
     *    yesterday. Yesterday (not today) is the default end date because
     *    both GA4 and Search Console data lag by roughly 1-3 days; reporting
     *    "today" as the end of a default range would mostly return zeros for
     *    the final day(s) and mislead a caller who didn't ask for a specific
     *    range.
     *  - Both dates must be strict Y-m-d format; anything else is a
     *    wpmcp_invalid_date_range error.
     *  - start must be <= end (after any clamping below); otherwise a
     *    wpmcp_invalid_date_range error.
     *  - end_date is clamped to today when it is in the future, rather than
     *    erroring: a caller asking for "up to next week" most likely means
     *    "up to now", and silently clamping is more useful than a hard
     *    failure for what is likely a benign off-by-a-few-days request.
     *
     * @return array|\WP_Error
     */
    public static function validate_date_range(?string $start, ?string $end)
    {
        $today = gmdate('Y-m-d');

        if (empty($start) && empty($end)) {
            return [
                'start_date' => gmdate('Y-m-d', strtotime('-28 days')),
                'end_date'   => gmdate('Y-m-d', strtotime('-1 day')),
            ];
        }

        if (! self::is_valid_ymd($start) || ! self::is_valid_ymd($end)) {
            return new \WP_Error(
                'wpmcp_invalid_date_range',
                'start_date and end_date must both be in Y-m-d format.'
            );
        }

        if ($end > $today) {
            $end = $today;
        }

        if ($start > $end) {
            return new \WP_Error(
                'wpmcp_invalid_date_range',
                'start_date must not be after end_date.'
            );
        }

        return [
            'start_date' => $start,
            'end_date'   => $end,
        ];
    }

    /**
     * Whether a string is a real calendar date in strict Y-m-d format (not
     * just date-parseable: e.g. rejects "2026-1-1" and "01/01/2026").
     */
    private static function is_valid_ymd(?string $value): bool
    {
        if (null === $value || '' === $value) {
            return false;
        }

        $date = \DateTime::createFromFormat('Y-m-d', $value);

        return $date instanceof \DateTime && $date->format('Y-m-d') === $value;
    }

    /**
     * Clamp a caller-supplied limit to [1, MAX_LIMIT], defaulting to
     * DEFAULT_LIMIT when null. Mirrors Multisite_Adapter::clamp_limit().
     */
    public static function clamp_limit(?int $limit): int
    {
        $limit = $limit ?? self::DEFAULT_LIMIT;
        $limit = max(1, $limit);

        return min($limit, self::MAX_LIMIT);
    }

    /**
     * Normalize a GA4 Data API runReport response into the neutral summary
     * shape: ['start_date'=>, 'end_date'=>, 'sessions'=>int, 'users'=>int,
     * 'pageviews'=>int]. Pure: takes an already-fetched raw payload, so it is
     * testable without a live GA4 call.
     *
     * Expects a single totals-style row (no dimensions requested) with
     * exactly three metricValues in the order [sessions, activeUsers,
     * screenPageViews], matching fetch_ga4_report()'s production request
     * shape. Missing/empty rows default every count to 0 rather than erroring,
     * since an empty report (e.g. a brand-new property with no traffic yet)
     * is a valid, if unexciting, result.
     *
     * Honesty note: this fixture/mapping shape approximates the GA4 Data API
     * runReport response (a "rows" array of objects, each with a
     * "metricValues" array of {"value": "<numeric string>"} objects) based on
     * the public API reference. It has not been verified against a live GA4
     * response.
     */
    public static function normalize_ga4_summary(array $raw, string $start_date, string $end_date): array
    {
        $values = $raw['rows'][0]['metricValues'] ?? [];

        return [
            'start_date' => $start_date,
            'end_date'   => $end_date,
            'sessions'   => (int) ($values[0]['value'] ?? 0),
            'users'      => (int) ($values[1]['value'] ?? 0),
            'pageviews'  => (int) ($values[2]['value'] ?? 0),
        ];
    }

    /**
     * Normalize a GA4 Data API runReport response (one dimension: page path,
     * one metric: screenPageViews) into a list of
     * ['path'=>string,'pageviews'=>int]. Pure, same honesty caveat as
     * normalize_ga4_summary(): the fixture shape approximates, but is not
     * verified against, a live GA4 response.
     */
    public static function normalize_ga4_top_pages(array $raw): array
    {
        $rows = $raw['rows'] ?? [];
        $out  = [];

        foreach ($rows as $row) {
            $out[] = [
                'path'      => (string) ($row['dimensionValues'][0]['value'] ?? ''),
                'pageviews' => (int) ($row['metricValues'][0]['value'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Normalize a Search Console searchanalytics.query response (queried
     * with no dimensions, so a single totals row) into the neutral summary
     * shape: ['start_date'=>, 'end_date'=>, 'clicks'=>int,
     * 'impressions'=>int, 'ctr'=>float, 'position'=>float]. Pure, so it is
     * testable without a live Search Console call.
     *
     * Missing/empty rows default every value to 0/0.0 rather than erroring,
     * matching normalize_ga4_summary()'s treatment of an empty report.
     *
     * Honesty note: this fixture/mapping shape approximates the Search
     * Console searchanalytics.query response (a "rows" array of objects,
     * each with numeric "clicks", "impressions", "ctr", "position" fields
     * directly, unlike GA4's nested value-wrapper) based on the public API
     * reference. It has not been verified against a live Search Console
     * response.
     */
    public static function normalize_gsc_summary(array $raw, string $start_date, string $end_date): array
    {
        $row = $raw['rows'][0] ?? [];

        return [
            'start_date'  => $start_date,
            'end_date'    => $end_date,
            'clicks'      => (int) ($row['clicks'] ?? 0),
            'impressions' => (int) ($row['impressions'] ?? 0),
            'ctr'         => (float) ($row['ctr'] ?? 0.0),
            'position'    => (float) ($row['position'] ?? 0.0),
        ];
    }

    /**
     * Normalize a Search Console searchanalytics.query response (queried
     * with dimensions=['query']) into a list of
     * ['query'=>string,'clicks'=>int,'impressions'=>int]. Pure, same
     * honesty caveat as normalize_gsc_summary(): the fixture shape
     * approximates, but is not verified against, a live Search Console
     * response. Each row's "keys" array holds the dimension value(s)
     * requested, in this case a single query string.
     */
    public static function normalize_gsc_queries(array $raw): array
    {
        $rows = $raw['rows'] ?? [];
        $out  = [];

        foreach ($rows as $row) {
            $out[] = [
                'query'       => (string) ($row['keys'][0] ?? ''),
                'clicks'      => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
            ];
        }

        return $out;
    }
}
