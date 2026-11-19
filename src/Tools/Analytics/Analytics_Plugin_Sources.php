<?php

namespace WPMCP\Tools\Analytics;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Analytics read from the stats plugin a site runs (issue #699), behind
 * get-analytics-summary and get-top-pages: MonsterInsights, Independent
 * Analytics, Burst Statistics, WP Statistics and Jetpack Stats.
 *
 * Three of them keep their numbers on the site, so they are read from their
 * own tables with the same counts their dashboards use: Independent
 * Analytics' Public_API (distinct views, sessions and visitors joined
 * through views.session_id), Burst's statistics table (hits, distinct
 * session_id, distinct uid_id) and WP Statistics' pages table (summed daily
 * counts) and visitor table (one row per visitor and day). The other two
 * fetch their numbers from a remote service, so they are read through the
 * plugin's own PHP API, which carries its own credentials and cache:
 * MonsterInsights' overview report and Jetpack's WPCOM_Stats. Every metric
 * follows the plugin's own definition; one a plugin does not track
 * (sessions in WP Statistics and Jetpack) is null, not zero.
 *
 * READ-ONLY and aggregate: counts and page paths, nothing per visitor. Paths
 * lose their query string and fragment, so a tracked URL never returns an
 * address or token someone put in a link.
 *
 * Detection and the two remote calls go through filters
 * (wpmcp_analytics_source_active, wpmcp_analytics_remote_report) so tests
 * can stand in for the plugins without defining their global symbols.
 */
final class Analytics_Plugin_Sources
{
    /**
     * Source id => [display name, main plugin file]. Local sources first: in
     * auto mode they are preferred, since they answer without a remote round
     * trip or a connected account.
     */
    public const SOURCES = [
        'independent-analytics' => [ 'Independent Analytics', 'independent-analytics/iawp.php' ],
        'burst'                 => [ 'Burst Statistics', 'burst-statistics/burst.php' ],
        'wp-statistics'         => [ 'WP Statistics', 'wp-statistics/wp-statistics.php' ],
        'monsterinsights'       => [ 'MonsterInsights', 'google-analytics-for-wordpress/googleanalytics.php' ],
        'jetpack'               => [ 'Jetpack', 'jetpack/jetpack.php' ],
    ];

    /** Whether a source's plugin is loaded, filterable through wpmcp_analytics_source_active. */
    public static function is_active(string $source): bool
    {
        switch ($source) {
            case 'independent-analytics':
                $default = defined('IAWP_VERSION');
                break;
            case 'burst':
                $default = defined('BURST_VERSION');
                break;
            case 'wp-statistics':
                $default = defined('WP_STATISTICS_VERSION');
                break;
            case 'monsterinsights':
                $default = defined('MONSTERINSIGHTS_VERSION') && function_exists('MonsterInsights');
                break;
            case 'jetpack':
                $default = defined('JETPACK__VERSION') && class_exists('Automattic\\Jetpack\\Stats\\WPCOM_Stats')
                    && (! is_callable([ 'Jetpack', 'is_module_active' ]) || \Jetpack::is_module_active('stats'));
                break;
            default:
                return false;
        }

        return (bool) apply_filters('wpmcp_analytics_source_active', $default, $source);
    }

    /** @return string[] The ids of every active source, in preference order. */
    public static function active(): array
    {
        return array_values(array_filter(array_keys(self::SOURCES), [ self::class, 'is_active' ]));
    }

    /** The display name of a source. */
    public static function label(string $source): string
    {
        return self::SOURCES[ $source ][0] ?? $source;
    }

    /**
     * sessions/users/pageviews for an inclusive Y-m-d range in the site's
     * timezone.
     *
     * @return array<string,mixed>|\WP_Error
     */
    public static function summary(string $source, string $start, string $end)
    {
        switch ($source) {
            case 'independent-analytics':
                $row = self::local_row(
                    'SELECT COUNT(DISTINCT v.id) AS pageviews, COUNT(DISTINCT s.visitor_id) AS users, COUNT(DISTINCT s.session_id) AS sessions'
                    . ' FROM %i v JOIN %i s ON s.session_id = v.session_id WHERE v.viewed_at BETWEEN %s AND %s',
                    [ self::iawp('views'), self::iawp('sessions'), self::utc($start, '00:00:00'), self::utc($end, '23:59:59') ]
                );
                break;
            case 'burst':
                $row = self::local_row(
                    'SELECT COUNT(ID) AS pageviews, COUNT(DISTINCT uid_id) AS users, COUNT(DISTINCT session_id) AS sessions FROM %i WHERE time BETWEEN %d AND %d',
                    [ self::burst(), self::stamp($start, '00:00:00'), self::stamp($end, '23:59:59') ]
                );
                break;
            case 'wp-statistics':
                $views = self::local_row('SELECT COALESCE(SUM(count), 0) AS pageviews FROM %i WHERE date BETWEEN %s AND %s', [ self::wps('pages'), $start, $end ]);
                if ($views instanceof \WP_Error) {
                    return $views;
                }
                $row = self::local_row('SELECT COUNT(*) AS users FROM %i WHERE last_counter BETWEEN %s AND %s', [ self::wps('visitor'), $start, $end ]);
                if (is_array($row)) {
                    $row += $views + [ 'sessions' => null ];
                }
                break;
            case 'monsterinsights':
                $data = self::monsterinsights_overview($start, $end);
                if ($data instanceof \WP_Error) {
                    return $data;
                }
                $box = is_array($data['infobox'] ?? null) ? $data['infobox'] : [];
                $row = [
                    'sessions'  => $box['sessions']['value'] ?? null,
                    'users'     => $box['totalusers']['value'] ?? null,
                    'pageviews' => $box['pageviews']['value'] ?? null,
                ];
                break;
            case 'jetpack':
                $raw = self::remote('jetpack', 'visits', [
                    'unit'        => 'day',
                    'quantity'    => self::days($start, $end),
                    'date'        => $end,
                    'stat_fields' => 'views,visitors',
                ]);
                if ($raw instanceof \WP_Error) {
                    return $raw;
                }
                $row = self::jetpack_totals($raw, $start);
                break;
            default:
                return self::invalid_source($source);
        }

        if ($row instanceof \WP_Error) {
            return $row;
        }

        return [
            'source'     => $source,
            'start_date' => $start,
            'end_date'   => $end,
            'sessions'   => self::count($row['sessions'] ?? null),
            'users'      => self::count($row['users'] ?? null),
            'pageviews'  => self::count($row['pageviews'] ?? null),
        ];
    }

    /**
     * The top pages by views: [path, pageviews, title when the plugin keeps
     * one], at most $limit rows.
     *
     * @return array<int,array<string,mixed>>|\WP_Error
     */
    public static function top_pages(string $source, string $start, string $end, int $limit)
    {
        switch ($source) {
            case 'independent-analytics':
                $rows = self::local_rows(
                    'SELECT r.cached_url AS url, r.cached_title AS title, COUNT(DISTINCT v.id) AS views FROM %i v JOIN %i r ON r.id = v.resource_id'
                    . ' WHERE v.viewed_at BETWEEN %s AND %s GROUP BY r.id, r.cached_url, r.cached_title ORDER BY views DESC, r.id ASC LIMIT %d',
                    [ self::iawp('views'), self::iawp('resources'), self::utc($start, '00:00:00'), self::utc($end, '23:59:59'), $limit ]
                );
                break;
            case 'burst':
                $rows = self::local_rows(
                    'SELECT page_url AS url, COUNT(ID) AS views FROM %i WHERE time BETWEEN %d AND %d GROUP BY page_url ORDER BY views DESC, page_url ASC LIMIT %d',
                    [ self::burst(), self::stamp($start, '00:00:00'), self::stamp($end, '23:59:59'), $limit ]
                );
                break;
            case 'wp-statistics':
                // WP Statistics may keep a query string in uri; group without it.
                $rows = self::local_rows(
                    "SELECT SUBSTRING_INDEX(uri, '?', 1) AS url, SUM(count) AS views FROM %i WHERE date BETWEEN %s AND %s GROUP BY url ORDER BY views DESC, url ASC LIMIT %d",
                    [ self::wps('pages'), $start, $end, $limit ]
                );
                break;
            case 'monsterinsights':
                $data = self::monsterinsights_overview($start, $end);
                if ($data instanceof \WP_Error) {
                    return $data;
                }
                $rows = [];
                foreach (is_array($data['toppages'] ?? null) ? $data['toppages'] : [] as $page) {
                    if (is_array($page)) {
                        // The overview's per-page metric is page views, shown in its column as 'sessions'.
                        $rows[] = [ 'url' => $page['url'] ?? '', 'title' => $page['title'] ?? null, 'views' => $page['sessions'] ?? 0 ];
                    }
                }
                break;
            case 'jetpack':
                $raw = self::remote('jetpack', 'top-posts', [
                    'period'    => 'day',
                    'date'      => $end,
                    'num'       => self::days($start, $end),
                    'max'       => $limit,
                    'summarize' => 1,
                ]);
                if ($raw instanceof \WP_Error) {
                    return $raw;
                }
                $summary = $raw['summary'] ?? ($raw['days']['summary'] ?? []);
                $rows    = [];
                foreach (is_array($summary['postviews'] ?? null) ? $summary['postviews'] : [] as $post) {
                    if (is_array($post)) {
                        $rows[] = [ 'url' => $post['href'] ?? '', 'title' => $post['title'] ?? null, 'views' => $post['views'] ?? 0 ];
                    }
                }
                break;
            default:
                return self::invalid_source($source);
        }

        if ($rows instanceof \WP_Error) {
            return $rows;
        }

        $out = [];
        foreach (array_slice($rows, 0, $limit) as $row) {
            $page = [
                'path'      => self::path((string) ($row['url'] ?? '')),
                'pageviews' => (int) self::count($row['views'] ?? 0),
            ];
            if (array_key_exists('title', $row) && is_string($row['title']) && '' !== $row['title']) {
                $page['title'] = sanitize_text_field($row['title']);
            }
            $out[] = $page;
        }

        return $out;
    }

    /**
     * The refusal for a named source whose plugin is not active: the plugin
     * named, installed or not, and the plugins screen to activate it.
     */
    public static function inactive_error(string $source): \WP_Error
    {
        [ $name, $file ] = self::SOURCES[ $source ];
        $refusal         = \WPMCP\Integrations\Plugin_Refusal::make('plugin_missing', $name, $file);
        $code            = 'inactive' === ($refusal['data']['state'] ?? '') ? 'plugin_inactive' : 'plugin_missing';

        return new \WP_Error($code, $refusal['message'], $refusal['data']);
    }

    public static function invalid_source(string $source): \WP_Error
    {
        return new \WP_Error(
            'wpmcp_invalid_source',
            sprintf('Unknown analytics source "%s". Use one of: site-kit, %s.', sanitize_text_field($source), implode(', ', array_keys(self::SOURCES)))
        );
    }

    // ------------------------------------------------------------- helpers

    private static function iawp(string $table): string
    {
        global $wpdb;
        return $wpdb->prefix . 'independent_analytics_' . $table;
    }

    private static function burst(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'burst_statistics';
    }

    private static function wps(string $table): string
    {
        global $wpdb;
        return $wpdb->prefix . 'statistics_' . $table;
    }

    /** A site-timezone day boundary as a Unix time. */
    private static function stamp(string $date, string $time): int
    {
        return (new \DateTimeImmutable($date . ' ' . $time, wp_timezone()))->getTimestamp();
    }

    /** A site-timezone day boundary as a UTC datetime string. */
    private static function utc(string $date, string $time): string
    {
        return gmdate('Y-m-d H:i:s', self::stamp($date, $time));
    }

    /** Days in an inclusive Y-m-d range. */
    private static function days(string $start, string $end): int
    {
        return (int) (new \DateTimeImmutable($start))->diff(new \DateTimeImmutable($end))->days + 1;
    }

    /** A URL or path reduced to its path, without query string or fragment. */
    private static function path(string $url): string
    {
        $path = wp_parse_url($url, PHP_URL_PATH);
        return is_string($path) && '' !== $path ? $path : '/';
    }

    /** A non-negative int from a plugin's count, or null when there is none. */
    private static function count($value): ?int
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if (is_string($value)) {
            $value = str_replace(',', '', $value);
        }
        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    /**
     * One row from a plugin's own table, or an error when the table is not
     * there or not shaped as expected (an older or half-migrated install).
     *
     * @param array<int,mixed> $args
     * @return array<string,mixed>|\WP_Error
     */
    private static function local_row(string $sql, array $args)
    {
        $rows = self::local_rows($sql, $args);
        return $rows instanceof \WP_Error ? $rows : ($rows[0] ?? []);
    }

    /**
     * @param array<int,mixed> $args
     * @return array<int,array<string,mixed>>|\WP_Error
     */
    private static function local_rows(string $sql, array $args)
    {
        global $wpdb;

        $suppress = $wpdb->suppress_errors(true);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- the stats plugins' own tables have no WP API; $sql is a fixed template from this class.
        $rows  = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
        $error = $wpdb->last_error;
        $wpdb->suppress_errors($suppress);

        if ('' !== $error || ! is_array($rows)) {
            return new \WP_Error('wpmcp_analytics_source_error', 'The analytics plugin\'s tables could not be read. Open the plugin\'s dashboard once so it can finish setting up its tables, then try again.');
        }

        return $rows;
    }

    /**
     * A remote-backed plugin's raw report, through the plugin's own API
     * unless the wpmcp_analytics_remote_report filter answers first.
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>|\WP_Error
     */
    private static function remote(string $source, string $kind, array $args)
    {
        $raw = apply_filters('wpmcp_analytics_remote_report', null, $source, $kind, $args);

        if (null === $raw) {
            $raw = self::call_plugin($source, $kind, $args);
        }
        if (is_wp_error($raw)) {
            return new \WP_Error('wpmcp_analytics_source_error', self::label($source) . ' could not return the report: ' . wp_strip_all_tags($raw->get_error_message()));
        }

        return is_array($raw) ? $raw : [];
    }

    /**
     * The real plugin call. Production-only: neither plugin is loaded in the
     * unit suite, and both answer from a remote service.
     *
     * @param array<string,mixed> $args
     * @return mixed
     */
    private static function call_plugin(string $source, string $kind, array $args)
    {
        if ('monsterinsights' === $source) {
            $reporting = function_exists('MonsterInsights') ? (MonsterInsights()->reporting ?? null) : null;
            $report    = is_object($reporting) && method_exists($reporting, 'get_report') ? $reporting->get_report($kind) : null;
            return is_object($report) && method_exists($report, 'get_data') ? $report->get_data($args) : null;
        }

        $class = 'Automattic\\Jetpack\\Stats\\WPCOM_Stats';
        if ('jetpack' === $source && class_exists($class)) {
            $stats = new $class();
            return 'visits' === $kind ? $stats->get_visits($args) : $stats->get_top_posts($args);
        }

        return null;
    }

    /**
     * MonsterInsights' overview report data for a range.
     *
     * @return array<string,mixed>|\WP_Error
     */
    private static function monsterinsights_overview(string $start, string $end)
    {
        $raw = self::remote('monsterinsights', 'overview', [ 'start' => $start, 'end' => $end ]);
        if ($raw instanceof \WP_Error) {
            return $raw;
        }
        if (empty($raw['success']) || ! empty($raw['upgrade'])) {
            $why = is_string($raw['error'] ?? null) && '' !== $raw['error'] ? wp_strip_all_tags($raw['error']) : 'the report is not available for this site or license.';
            return new \WP_Error('wpmcp_analytics_source_error', 'MonsterInsights could not return the report: ' . $why);
        }

        return is_array($raw['data'] ?? null) ? $raw['data'] : [];
    }

    /**
     * Jetpack's visits series summed over the days inside the range.
     *
     * @param array<string,mixed> $raw
     * @return array<string,int>
     */
    private static function jetpack_totals(array $raw, string $start): array
    {
        $fields = array_flip(array_map('strval', is_array($raw['fields'] ?? null) ? $raw['fields'] : []));
        $views  = 0;
        $users  = 0;
        foreach (is_array($raw['data'] ?? null) ? $raw['data'] : [] as $row) {
            if (! is_array($row) || (string) ($row[ $fields['period'] ?? 0 ] ?? '') < $start) {
                continue;
            }
            $views += (int) self::count($row[ $fields['views'] ?? 1 ] ?? 0);
            $users += (int) self::count($row[ $fields['visitors'] ?? 2 ] ?? 0);
        }

        return [ 'pageviews' => $views, 'users' => $users ];
    }
}
