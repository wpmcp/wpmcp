<?php

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Validated table name is stripped of backticks and checked via Database_Guard::valid_table().
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only table schema inspection.

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
        $columns = $wpdb->get_results('DESCRIBE `' . str_replace('`', '', $table) . '`', ARRAY_A);

        return [
            'table'   => $table,
            'columns' => is_array($columns) ? $columns : [],
        ];
    }
}
