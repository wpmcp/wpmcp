<?php

namespace WPMCP\Tools\Database;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only listing of database tables with estimated row counts and sizes,
 * via information_schema. No snapshot: nothing is mutated here.
 */
class List_Tables
{
    public function handle(array $args): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- information_schema inventory has no WP API; row counts and sizes must be live.
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
            $tables[] = [
                'table'      => (string) $row['n'],
                'rows'       => (int) $row['r'],
                'size_bytes' => (int) $row['sz'],
            ];
        }

        return ['tables' => $tables];
    }
}
