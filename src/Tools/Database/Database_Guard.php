<?php

namespace WPMCP\Tools\Database;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Security core for the database tools: validates read-only SQL for the
 * `query` tool, and validates/protects table names for the structured write
 * tools. is_read_only_sql() is the safety boundary for the flexible read
 * path, so every rejection case here is exercised exhaustively by
 * DatabaseGuardTest.
 */
class Database_Guard
{
    public const MAX_ROWS = 1000;
    public const BEFORE_IMAGE_CAP = 500;
    public const AUDIT_OPTION = 'wpmcp_db_write_audit_log';
    public const AUDIT_MAX = 100;

    /**
     * Normalize SQL for safe keyword scanning: replace every comment with a
     * space and every string/backtick-identifier literal with an empty
     * placeholder, so keywords cannot hide inside comments, strings, or
     * quoted identifiers. Pure (no DB). Does NOT special-case /*! executable
     * comments; the caller rejects those before normalizing.
     */
    public static function normalize_sql(string $sql): string
    {
        $out = '';
        $len = strlen($sql);
        $i   = 0;

        while ($i < $len) {
            $c   = $sql[ $i ];
            $two = substr($sql, $i, 2);

            if ('--' === $two || '#' === $c) {
                $newline = strpos($sql, "\n", $i);
                $i       = (false === $newline) ? $len : $newline + 1;
                $out    .= ' ';
                continue;
            }

            if ('/*' === $two) {
                $end = strpos($sql, '*/', $i + 2);
                $i   = (false === $end) ? $len : $end + 2;
                $out .= ' ';
                continue;
            }

            if ("'" === $c || '"' === $c) {
                $quote = $c;
                $i++;
                while ($i < $len) {
                    if ('\\' === $sql[ $i ]) {
                        $i += 2;
                        continue;
                    }
                    if ($sql[ $i ] === $quote) {
                        if ($i + 1 < $len && $sql[ $i + 1 ] === $quote) {
                            $i += 2;
                            continue;
                        }
                        $i++;
                        break;
                    }
                    $i++;
                }
                $out .= "''";
                continue;
            }

            if ('`' === $c) {
                $i++;
                while ($i < $len && '`' !== $sql[ $i ]) {
                    $i++;
                }
                $i++;
                $out .= '``';
                continue;
            }

            $out .= $c;
            $i++;
        }

        return $out;
    }

    /**
     * Validate that $sql is a single read-only statement. Pure (no DB).
     *
     * @return true|\WP_Error
     */
    public static function is_read_only_sql(string $sql)
    {
        // MySQL executes the body of /*! ... *\/ executable comments, so the
        // guard cannot safely strip-and-trust them like ordinary comments.
        // Refuse any SQL containing the marker outright.
        if (false !== strpos($sql, '/*!')) {
            return new \WP_Error('executable_comment', 'MySQL executable comments (/*! ... */) are not allowed.');
        }

        $normalized = trim(self::normalize_sql($sql));
        if ('' === $normalized) {
            return new \WP_Error('empty_sql', 'Empty query.');
        }

        // Multi-statement: any ';' that isn't the sole trailing character.
        $without_trailing = rtrim($normalized, "; \t\r\n");
        if (false !== strpos($without_trailing, ';')) {
            return new \WP_Error('multi_statement', 'Multiple SQL statements are not allowed.');
        }

        // File-access vectors (comments are already normalized to spaces).
        // No trailing \b: the load_file(...) branch ends in '(', and '('
        // followed by another non-word char has no word boundary there, so a
        // trailing \b would incorrectly let LOAD_FILE through.
        if (preg_match('/\b(into\s+outfile|into\s+dumpfile|load_file\s*\(|load\s+data\b)/i', $normalized)) {
            return new \WP_Error('file_access_blocked', 'File-access SQL (OUTFILE/DUMPFILE/LOAD_FILE/LOAD DATA) is not allowed.');
        }

        // First keyword must be read-only.
        if (! preg_match('/^([a-z]+)/i', $normalized, $matches)) {
            return new \WP_Error('not_read_only', 'Only read-only queries are allowed.');
        }

        $keyword = strtoupper($matches[1]);
        $allowed = ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'WITH'];
        if (! in_array($keyword, $allowed, true)) {
            return new \WP_Error('not_read_only', "Only read-only queries are allowed (got {$keyword}).");
        }

        // Whole-statement write/DDL denylist. Literals/comments are already
        // stripped, so these match only real keyword tokens, never strings
        // or identifiers (e.g. a column named delete_count, or the string
        // 'please delete this').
        if (preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE|MERGE|DROP|TRUNCATE|ALTER|CREATE|RENAME|GRANT|REVOKE|HANDLER|CALL|LOCK|UNLOCK|PREPARE|EXECUTE|INTO)\b/i', $normalized)) {
            return new \WP_Error('not_read_only', 'The query contains a write or unsafe keyword.');
        }

        return true;
    }

    /**
     * Resolve a table name against the live table list (table names cannot
     * be parameterized with $wpdb->prepare()). Returns the exact real name,
     * or a WP_Error if it does not exist.
     *
     * @return string|\WP_Error
     */
    public static function valid_table(string $table)
    {
        global $wpdb;

        $table = trim($table);
        if ('' === $table) {
            return new \WP_Error('unknown_table', 'A table name is required.');
        }

        $tables = (array) $wpdb->get_col('SHOW TABLES');
        foreach ($tables as $candidate) {
            if (strtolower((string) $candidate) === strtolower($table)) {
                return (string) $candidate;
            }
        }

        return new \WP_Error('unknown_table', 'Unknown table.');
    }

    /** Pure: is $table in the protected list (case-insensitive)? */
    public static function table_is_protected(string $table, array $protected): bool
    {
        $needle = strtolower($table);
        foreach ($protected as $candidate) {
            if (strtolower((string) $candidate) === $needle) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether writes to $table are refused. Defaults to the users and
     * usermeta tables (credential/identity data with no business being
     * touched by a generic row-write tool); sites may extend the list via
     * the wpmcp_db_protected_tables filter.
     */
    public static function is_protected(string $table): bool
    {
        global $wpdb;
        $protected = apply_filters('wpmcp_db_protected_tables', [$wpdb->users, $wpdb->usermeta]);
        return self::table_is_protected($table, (array) $protected);
    }

    /**
     * Capture the rows an equality-AND WHERE will affect, before an
     * update/delete runs, so the caller can return an honest before-image
     * even though a full generic-table rollback is not offered.
     *
     * @param string $table A validated real table name.
     * @param array  $where col => value (equality AND).
     */
    public static function before_image(string $table, array $where): array
    {
        global $wpdb;

        if ([] === $where) {
            return [];
        }

        $conditions = [];
        $values     = [];
        foreach ($where as $column => $value) {
            $conditions[] = '`' . str_replace('`', '', (string) $column) . '` = %s';
            $values[]     = $value;
        }

        $sql  = 'SELECT * FROM `' . str_replace('`', '', $table) . '` WHERE '
            . implode(' AND ', $conditions) . ' LIMIT ' . self::BEFORE_IMAGE_CAP;
        $rows = $wpdb->get_results($wpdb->prepare($sql, $values), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Append a structured write to the capped audit log. This is the
     * recoverability record for Update_Rows/Delete_Rows: since a generic
     * arbitrary-table rollback is not offered (see those tools' docblocks),
     * the before-image captured here is the only trail a human has to
     * manually reconstruct a change if needed.
     */
    public static function audit(string $operation, string $table, int $affected, array $before = []): void
    {
        $log = get_option(self::AUDIT_OPTION, []);
        if (! is_array($log)) {
            $log = [];
        }

        $log[] = [
            'op'       => $operation,
            'table'    => $table,
            'affected' => $affected,
            'before'   => $before,
            'user'     => get_current_user_id(),
            'time'     => time(),
        ];

        if (count($log) > self::AUDIT_MAX) {
            $log = array_slice($log, -self::AUDIT_MAX);
        }

        update_option(self::AUDIT_OPTION, $log, false);
    }
}
