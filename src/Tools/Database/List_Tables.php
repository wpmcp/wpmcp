<?php

namespace WPMCP\Tools\Database;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only listing of database tables with estimated row counts and sizes,
 * via information_schema. On multisite, a caller without network rights
 * sees only the current site's tables (Database_Guard::table_in_scope()).
 * No snapshot: nothing is mutated here.
 */
class List_Tables
{
    public function handle(array $args): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- information_schema listing for a live diagnostics tool; there is no core API for table sizes and a cached listing would misreport them.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT table_name AS n, table_rows AS r, (data_length + index_length) AS sz '
                . 'FROM information_schema.TABLES WHERE table_schema = %s ORDER BY n ASC',
                DB_NAME
            ),
            ARRAY_A
        );

        $tables = [];
        foreach ((array) $rows as $row) {
            if (! Database_Guard::table_in_scope((string) $row['n'])) {
                continue;
            }
            $tables[] = [
                'table'      => (string) $row['n'],
                'rows'       => (int) $row['r'],
                'size_bytes' => (int) $row['sz'],
            ];
        }

        return ['tables' => $tables];
    }
}
