<?php

namespace WPMCP\Tools\Database;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Run a read-only SQL query (SELECT/SHOW/DESCRIBE/EXPLAIN/WITH). Every
 * query is validated by Database_Guard::is_read_only_sql() BEFORE
 * execution; writes, DDL, stacked statements, executable comments, and
 * file-access SQL are all rejected there. Reads touching the users or
 * usermeta tables are additionally refused by
 * Database_Guard::guard_user_table_read() (credential and session data);
 * sites can opt in via wpmcp_db_allow_user_table_reads, in which case
 * secret values are still masked in results unless
 * wpmcp_db_mask_user_secrets is filtered to false. Results are capped so a
 * broad query cannot pull the whole database into a single response.
 *
 * Direct read, no snapshot: nothing is mutated here.
 */
class Query
{
    public function handle(array $args): array
    {
        $sql = (string) ($args['sql'] ?? '');
        if ('' === trim($sql)) {
            throw new \InvalidArgumentException('A sql string is required.');
        }

        $read_only = Database_Guard::is_read_only_sql($sql);
        if (is_wp_error($read_only)) {
            throw new \RuntimeException(esc_html($read_only->get_error_message()));
        }

        $user_table_read = Database_Guard::guard_user_table_read($sql);
        if (is_wp_error($user_table_read)) {
            throw new \RuntimeException(esc_html($user_table_read->get_error_message()));
        }

        global $wpdb;

        $limit = isset($args['limit']) ? (int) $args['limit'] : Database_Guard::MAX_ROWS;
        $limit = min(Database_Guard::MAX_ROWS, max(1, $limit));

        // Validated read-only by Database_Guard::is_read_only_sql() above; no
        // user values are interpolated by this tool itself.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- running caller-supplied read-only SQL is this tool's purpose; the statement is validated by Database_Guard::is_read_only_sql() (src/Tools/Database/Database_Guard.php) and results must be live.
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (null === $rows) {
            throw new \RuntimeException(esc_html($wpdb->last_error) ?: 'Query failed.');
        }

        $truncated = count($rows) > $limit;
        if ($truncated) {
            $rows = array_slice($rows, 0, $limit);
        }

        // Opted-in user-table reads still get their secret values masked by
        // default; wpmcp_db_mask_user_secrets(false) restores raw output for
        // sites that explicitly need it (e.g. a migration audit).
        if (true === $user_table_read && apply_filters('wpmcp_db_mask_user_secrets', true)) {
            $rows = Database_Guard::mask_user_secrets($rows);
        }

        return [
            'rows'      => $rows,
            'row_count' => count($rows),
            'truncated' => $truncated,
        ];
    }
}
