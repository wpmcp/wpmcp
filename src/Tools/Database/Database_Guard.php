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

    /** Per-request cache of the detected NO_BACKSLASH_ESCAPES state. Null = not yet detected. */
    private static ?bool $no_backslash_escapes_cache = null;

    /** Test seam: whether an override for NO_BACKSLASH_ESCAPES is active. */
    private static bool $no_backslash_escapes_override_set = false;

    /** Test seam: the forced NO_BACKSLASH_ESCAPES value, when overridden. */
    private static ?bool $no_backslash_escapes_override = null;

    /** Test seam: force no_backslash_escapes_active() to a fixed value. Pass null to clear the override and resume live detection. */
    public static function set_no_backslash_escapes_override(?bool $value): void
    {
        self::$no_backslash_escapes_override     = $value;
        self::$no_backslash_escapes_override_set = null !== $value;
    }

    /**
     * Whether the active MySQL sql_mode contains NO_BACKSLASH_ESCAPES, which
     * changes how string literals end (backslash stops being an escape
     * character). Detected once per request via $wpdb and cached; a test
     * override seam lets pure-unit tests force either state without a live
     * server actually running in that mode. Defaults to false (the ordinary,
     * backslash-escapes-quotes MySQL default) if $wpdb is unavailable.
     */
    public static function no_backslash_escapes_active(): bool
    {
        if (self::$no_backslash_escapes_override_set) {
            return (bool) self::$no_backslash_escapes_override;
        }

        if (null !== self::$no_backslash_escapes_cache) {
            return self::$no_backslash_escapes_cache;
        }

        global $wpdb;
        $mode = '';
        if (isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'get_var')) {
            $mode = (string) $wpdb->get_var('SELECT @@SESSION.sql_mode');
        }

        self::$no_backslash_escapes_cache = false !== stripos($mode, 'NO_BACKSLASH_ESCAPES');
        return self::$no_backslash_escapes_cache;
    }

    /**
     * Normalize SQL for safe keyword scanning: replace every comment with a
     * space and every string/backtick-identifier literal with an empty
     * placeholder, so keywords cannot hide inside comments, strings, or
     * quoted identifiers. Pure (no DB) when $no_backslash_escapes is passed
     * explicitly; otherwise resolves it via no_backslash_escapes_active(),
     * which may query $wpdb once per request. Does NOT special-case /*!
     * executable comments; the caller rejects those before normalizing.
     */
    public static function normalize_sql(string $sql, ?bool $no_backslash_escapes = null): string
    {
        $no_backslash_escapes ??= self::no_backslash_escapes_active();

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
                    if (! $no_backslash_escapes && '\\' === $sql[ $i ]) {
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

        // Raw, sql_mode-independent file-access pre-scan. normalize_sql()
        // decides where string literals end based on an assumed escape rule
        // (backslash-escapes quotes) that only holds when the server's
        // sql_mode does NOT contain NO_BACKSLASH_ESCAPES. When it does, a
        // literal like 'a\' actually ends at that quote (the backslash is an
        // ordinary character), one character earlier than normalize_sql()
        // assumes, letting a real INTO OUTFILE/LOAD_FILE token hide inside
        // what the guard mistakes for string content. Scanning the untouched
        // raw SQL for these tokens closes that vector regardless of sql_mode
        // or how literals are eventually parsed. A legitimate query that
        // merely contains the literal word "outfile" or "dumpfile" would be
        // rejected too; that rare false positive is an acceptable trade for
        // a read-only tool where file I/O is never a legitimate need.
        if (preg_match('/(into\s+outfile|into\s+dumpfile|outfile|dumpfile|load_file\s*\()/i', $sql)) {
            return new \WP_Error('file_access_blocked', 'File-access SQL (OUTFILE/DUMPFILE/LOAD_FILE) is not allowed.');
        }

        // Raw, sql_mode-independent stacked-statement pre-scan. The guard
        // does not rely solely on normalize_sql() (or on the driver) to
        // reject a second statement: a literal-boundary desync between the
        // guard and the live server (the same NO_BACKSLASH_ESCAPES class of
        // bug fixed above, or any future normalize_sql regression) could
        // otherwise hide a live ';' inside what the guard mistakes for
        // string content. Normalize under BOTH escape assumptions and
        // reject if either parse finds a non-trailing ';', so correctness
        // does not depend on sql_mode detection having succeeded.
        foreach ([false, true] as $assume_no_backslash_escapes) {
            $probe = trim(self::normalize_sql($sql, $assume_no_backslash_escapes));
            $probe = rtrim($probe, "; \t\r\n");
            if (false !== strpos($probe, ';')) {
                return new \WP_Error('multi_statement', 'Multiple SQL statements are not allowed.');
            }
        }

        $normalized = trim(self::normalize_sql($sql));
        if ('' === $normalized) {
            return new \WP_Error('empty_sql', 'Empty query.');
        }

        // Multi-statement: any ';' that isn't the sole trailing character.
        // (Redundant with the raw pre-scan above for the sql_mode that is
        // actually active; kept so the error still surfaces even if the
        // pre-scan above is ever narrowed.)
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
     * update/delete runs. Since issue #82 this doubles as the snapshot
     * payload for the recoverable path (see recoverability_probe()) and,
     * with a limit of 1 and a primary-key WHERE, as the current-row fetch
     * used by Rollback_Service's conflict detection.
     *
     * @param string   $table A validated real table name.
     * @param array    $where col => value (equality AND).
     * @param int|null $limit Row cap; defaults to BEFORE_IMAGE_CAP.
     */
    public static function before_image(string $table, array $where, ?int $limit = null): array
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
            . implode(' AND ', $conditions) . ' LIMIT ' . max(1, (int) ($limit ?? self::BEFORE_IMAGE_CAP));
        $rows = $wpdb->get_results($wpdb->prepare($sql, $values), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * The table's PRIMARY KEY column names in index order, or [] when the
     * table declares no primary key. The PK is what makes a captured row
     * re-identifiable at rollback time, so its absence is one of the cases
     * where update-rows/delete-rows must stay honestly recoverable:false.
     * SHOW KEYS output is filtered in PHP rather than with a WHERE clause so
     * the query works identically across MySQL and MariaDB versions.
     */
    public static function primary_key(string $table): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            'SHOW KEYS FROM `' . str_replace('`', '', $table) . '`',
            ARRAY_A
        );

        $pk = [];
        foreach ((array) $rows as $row) {
            if ('PRIMARY' === ($row['Key_name'] ?? '')) {
                $pk[ (int) $row['Seq_in_index'] ] = (string) $row['Column_name'];
            }
        }

        ksort($pk);
        return array_values($pk);
    }

    /**
     * The table's live column names, for re-validating a db_rows snapshot
     * against the CURRENT schema at rollback time (see
     * Rollback_Service::apply_db_rows_snapshot()): a captured column that no
     * longer exists means the exact restore that was promised is impossible,
     * and a column name that never existed means the snapshot is forged.
     */
    public static function columns(string $table): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            'SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`',
            ARRAY_A
        );

        $columns = [];
        foreach ((array) $rows as $row) {
            $columns[] = (string) $row['Field'];
        }
        return $columns;
    }

    /**
     * Decide whether an exact, snapshot-backed rollback can be PROMISED for
     * the rows $where matches in $table, and capture the before-image either
     * way. recoverable is true only when every condition for a faithful
     * restore holds:
     *  - the table has a PRIMARY KEY (rows are re-identifiable later);
     *  - the WHERE matched no more rows than BEFORE_IMAGE_CAP (a truncated
     *    before-image would silently restore only some of the rows);
     *  - every captured value survives the JSON snapshot encoding losslessly
     *    (non-UTF-8 binary bytes would be mangled by wp_json_encode, so a
     *    "restore" would write corrupted data back).
     * When any condition fails, 'reason' says which one, so the tool can
     * report recoverable:false honestly instead of degrading silently.
     *
     * @param string $table A validated real table name.
     * @param array  $where col => value (equality AND).
     * @return array{recoverable:bool,reason:?string,primary_key:array,rows:array}
     */
    public static function recoverability_probe(string $table, array $where): array
    {
        $primary_key = self::primary_key($table);

        // Fetch one row past the cap so truncation is detectable, then trim
        // back to the cap so callers see the same before-image as before.
        $rows      = self::before_image($table, $where, self::BEFORE_IMAGE_CAP + 1);
        $truncated = count($rows) > self::BEFORE_IMAGE_CAP;
        if ($truncated) {
            $rows = array_slice($rows, 0, self::BEFORE_IMAGE_CAP);
        }

        $reason = null;
        if ([] === $primary_key) {
            $reason = 'the table has no primary key, so affected rows cannot be re-identified for rollback';
        } elseif ($truncated) {
            $reason = 'the WHERE matches more than ' . self::BEFORE_IMAGE_CAP
                . ' rows (before-image cap), so a complete before-image cannot be captured';
        } elseif (! self::rows_are_utf8($rows)) {
            $reason = 'matched rows contain non-UTF-8 binary values that cannot be captured losslessly in a snapshot';
        }

        return [
            'recoverable' => null === $reason,
            'reason'      => $reason,
            'primary_key' => $primary_key,
            'rows'        => $rows,
        ];
    }

    /**
     * True when every string value in $rows is valid UTF-8. Snapshot blobs
     * are JSON (see Snapshot::serialize()), and wp_json_encode() mangles or
     * drops invalid-UTF-8 bytes rather than round-tripping them, so binary
     * column content (BLOB etc.) cannot be promised back byte-for-byte.
     */
    public static function rows_are_utf8(array $rows): bool
    {
        foreach ($rows as $row) {
            foreach ((array) $row as $value) {
                if (is_string($value) && ! preg_match('//u', $value)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Append a structured write to the capped audit log. Every structured
     * write lands here regardless of recoverability; for the honestly
     * non-recoverable cases (no primary key, cap exceeded, binary values —
     * see recoverability_probe()) the before-image captured here is the only
     * trail a human has to manually reconstruct a change if needed.
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
