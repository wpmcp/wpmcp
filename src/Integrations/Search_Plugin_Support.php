<?php

namespace WPMCP\Integrations;

use WPMCP\Privacy\Masking;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * What the search plugin ops share (issue #700): the opt-in switch for their
 * settings writes, the settings-object input schema, and the top searches
 * read over a plugin's own search log table.
 *
 * Top searches are aggregated per query (how often, the most results it got,
 * when last), so no row ties a search to a visitor: user ids, IP addresses,
 * session ids and languages are never selected. A query is still text a
 * visitor typed, so one holding an email address or a phone-length run of
 * digits is masked through Masking::value() and the answer says masked:true.
 */
final class Search_Plugin_Support
{
    public const ENABLE_FILTER = 'wpmcp_enable_search_write';

    /** A run of digits at least this long reads as a phone or account number. */
    private const DIGIT_RUN = 7;

    /** Whether the site opted into the search settings writes. */
    public static function writes_enabled(): bool
    {
        return (bool) apply_filters('wpmcp_enable_search_write', false);
    }

    /** @return array<string,mixed> */
    public static function settings_schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'settings' => [ 'type' => 'object', 'minProperties' => 1, 'maxProperties' => 50 ],
            ],
            'required'   => [ 'settings' ],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    public static function top_searches_schema(): array
    {
        return [
            'limit'     => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ],
            'days'      => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 3650 ],
            'zero_hits' => [ 'type' => 'boolean' ],
        ];
    }

    /** An invalid_setting refusal. */
    public static function invalid(string $message, string $setting): array
    {
        return [ 'code' => 'invalid_setting', 'message' => $message, 'data' => [ 'setting' => $setting ] ];
    }

    /**
     * The most searched queries in a search log table.
     *
     * @param string $table       full table name
     * @param string $query_col   the column holding the query text
     * @param string $hits_col    the column holding the result count
     * @param string $time_col    the column holding the UTC search time
     * @param array<string,mixed> $args limit, days, zero_hits
     * @return array{searches:list<array<string,mixed>>,masked:bool,days:int}
     */
    public static function top_searches(string $table, string $query_col, string $hits_col, string $time_col, array $args): array
    {
        global $wpdb;

        $limit = max(1, min(100, (int) ($args['limit'] ?? 20)));
        $days  = max(1, min(3650, (int) ($args['days'] ?? 30)));
        $out   = [ 'searches' => [], 'masked' => false, 'days' => $days ];
        if (! Ops_Status_Packs::table_exists($table)) {
            return $out;
        }

        $since = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $zero  = true === ($args['zero_hits'] ?? false) ? ' HAVING MAX(%i) = 0' : '';
        $sql   = 'SELECT %i AS q, COUNT(*) AS searches, MAX(%i) AS hits, MAX(%i) AS last_searched FROM %i WHERE %i >= %s GROUP BY %i'
            . $zero . ' ORDER BY searches DESC, last_searched DESC LIMIT %d';
        $params = [ $query_col, $hits_col, $time_col, $table, $time_col, $since, $query_col ];
        if ('' !== $zero) {
            $params[] = $hits_col;
        }
        $params[] = $limit;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- a third-party log table, prepared with %i identifiers; nothing to cache.
        $rows = (array) $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

        foreach ($rows as $row) {
            $query = (string) $row['q'];
            if (self::is_personal($query)) {
                $query         = (string) Masking::value($query);
                $out['masked'] = true;
            }
            $out['searches'][] = [
                'query'         => $query,
                'searches'      => (int) $row['searches'],
                'hits'          => (int) $row['hits'],
                'last_searched' => Ops_Status_Packs::iso_datetime((string) $row['last_searched']),
            ];
        }
        return $out;
    }

    /** Whether a search query holds an email address or a phone-length run of digits. */
    private static function is_personal(string $query): bool
    {
        return 1 === preg_match('/[^@\s]+@[^@\s]+\.[^@\s]+/u', $query)
            || 1 === preg_match('/\d{' . self::DIGIT_RUN . ',}/', (string) preg_replace('/[\s().+-]/', '', $query));
    }
}
