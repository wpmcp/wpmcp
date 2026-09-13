<?php

namespace WPMCP\Tools\Database;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: return the columns, types, and keys of a table. Direct read,
 * no snapshot: nothing is mutated here.
 */
class Describe_Table
{
    public function handle(array $args): array
    {
        $requested = (string) ($args['table'] ?? '');
        if ('' === $requested) {
            throw new \InvalidArgumentException('A table name is required.');
        }

        $table = Database_Guard::valid_table($requested);
        if (is_wp_error($table)) {
            throw new \RuntimeException(esc_html($table->get_error_message()));
        }

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema introspection has no WP API and must reflect the live table structure.
        $columns = $wpdb->get_results($wpdb->prepare('DESCRIBE %i', $table), ARRAY_A);

        return [
            'table'   => $table,
            'columns' => is_array($columns) ? $columns : [],
        ];
    }
}
