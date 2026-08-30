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
            throw new \RuntimeException($table->get_error_message());
        }

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection tool; DESCRIBE has no core API and must reflect the live schema, so caching would be wrong.
        $columns = $wpdb->get_results('DESCRIBE `' . str_replace('`', '', $table) . '`', ARRAY_A);

        return [
            'table'   => $table,
            'columns' => is_array($columns) ? $columns : [],
        ];
    }
}
