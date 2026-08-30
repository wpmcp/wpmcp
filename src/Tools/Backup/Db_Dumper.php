<?php

namespace WPMCP\Tools\Backup;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Produces a portable SQL dump of this site's tables, written incrementally
 * through a caller-supplied writer callable rather than returned as a string.
 *
 * Streaming is the whole point: a modest site's wp_options and wp_postmeta
 * dump to tens of megabytes, and buffering that into one PHP string is what
 * turns "backup" into "fatal, memory exhausted" on the shared hosting this
 * plugin has to run on. Rows are read in bounded batches and handed to the
 * writer as soon as each statement is built, so peak memory tracks the batch
 * size, not the database size.
 *
 * No shell-out. mysqldump is absent or disabled on most managed WordPress
 * hosts, and wp.org forbids shipping code that shells out to it, so the dump
 * is generated entirely through $wpdb.
 *
 * KNOWN LIMIT, still open: tables are read one after another outside any
 * enclosing transaction, so the archive is not a point-in-time snapshot of
 * the whole database. A post written midway through a dump can land in
 * wp_posts with its wp_postmeta already read, so the restore sees the post
 * without its meta. Per-table reads are now stable under concurrent writes
 * (see dump_table()'s keyset pagination), which is the larger half of the
 * problem; closing the rest needs START TRANSACTION WITH CONSISTENT SNAPSHOT
 * around the whole dump, which is InnoDB-specific and commits any
 * transaction already open on the connection — so it needs its own change
 * with its own tests rather than being smuggled in here.
 *
 * KNOWN LIMIT, deliberately not papered over: values are emitted as escaped
 * string literals via $wpdb::prepare(). That round-trips every column type
 * WordPress core and the plugin ecosystem actually use (MySQL coerces the
 * literal back to the column type on import), but a true binary BLOB
 * containing invalid UTF-8 can be mangled. This engine reports which tables
 * held BLOB columns in the manifest (see blob_tables()) so a restore can warn
 * rather than silently produce a corrupt row.
 */
class Db_Dumper
{
    /** Rows read per SELECT. Bounds peak memory during the dump. */
    public const BATCH = 500;

    /** Soft cap on a single generated INSERT statement, in bytes. */
    public const MAX_STATEMENT_BYTES = 512000;

    /**
     * Every table belonging to this install, in a stable (sorted) order.
     *
     * Scoped by table prefix so a shared database hosting several WordPress
     * installs only dumps this one. On multisite the BASE prefix is used, so
     * a network backup captures the global tables plus every sub-site's
     * tables (wp_2_posts and friends) instead of only the current site's.
     *
     * @return string[]
     */
    public function tables(): array
    {
        global $wpdb;

        $prefix = is_multisite() ? $wpdb->base_prefix : $wpdb->prefix;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Enumerating tables to dump; there is no core API for this and caching a backup's table list would be wrong.
        $tables = $wpdb->get_col(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($prefix) . '%')
        );

        $tables = is_array($tables) ? $tables : [];
        sort($tables);

        return $tables;
    }

    /**
     * Dump $tables (defaulting to every table on this install) as SQL,
     * handing each chunk to $write as it is produced.
     *
     * Returns per-table row counts plus the tables that carry BLOB columns,
     * which the archive manifest records for restore-time warnings.
     *
     * @param callable(string):void $write
     * @param string[]|null         $tables
     * @return array{tables: array<string,int>, blob_tables: string[], bytes: int}
     */
    public function dump(callable $write, ?array $tables = null): array
    {
        $all = $this->tables();
        // Never dump a table that is not ours: $only is caller-supplied, and
        // table names cannot be bound as query placeholders, so the
        // intersection against the enumerated list is what keeps the
        // interpolation below safe.
        $targets = null === $tables ? $all : array_values(array_intersect($tables, $all));

        $counts      = [];
        $blob_tables = [];
        $bytes       = 0;

        $emit = static function (string $sql) use ($write, &$bytes): void {
            $bytes += strlen($sql);
            $write($sql);
        };

        $emit($this->header());

        foreach ($targets as $table) {
            if ($this->has_blob_column($table)) {
                $blob_tables[] = $table;
            }
            $counts[ $table ] = $this->dump_table($table, $emit);
        }

        $emit($this->footer());

        return [
            'tables'      => $counts,
            'blob_tables' => $blob_tables,
            'bytes'       => $bytes,
        ];
    }

    /**
     * Dump one table: structure, then its rows in batches. Returns the row
     * count written.
     *
     * @param callable(string):void $emit
     */
    private function dump_table(string $table, callable $emit): int
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- $table came from tables() above (SHOW TABLES on our own prefix); identifiers cannot be bound as placeholders. SHOW CREATE TABLE reads the schema, it does not change it.
        $create = $wpdb->get_row('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`', ARRAY_N);

        if (! is_array($create) || ! isset($create[1])) {
            // A table that vanished between enumeration and dump (a plugin
            // uninstall mid-backup) is skipped rather than aborting the whole
            // archive: a backup missing one dropped table beats no backup.
            return 0;
        }

        $quoted = '`' . str_replace('`', '``', $table) . '`';

        $emit("\n--\n-- Table structure for {$table}\n--\n\n");
        $emit("DROP TABLE IF EXISTS {$quoted};\n");
        $emit($create[1] . ";\n\n");

        $key    = $this->primary_key($table);
        $order  = null !== $key ? '`' . str_replace('`', '``', $key) . '`' : $this->column_order($table);
        $offset = 0;
        $cursor = null;
        $total  = 0;
        $buffer = '';

        while (true) {
            if (null !== $key) {
                // Keyset pagination. LIMIT/OFFSET reads a shifting window:
                // an INSERT or DELETE by ordinary site traffic while the dump
                // is running moves every later row across the boundary, which
                // duplicates or skips rows in a file nothing will validate
                // until someone tries to restore it. Seeking past the last
                // key read is stable under concurrent writes, and it also
                // avoids the O(n^2) scan OFFSET costs on a large postmeta.
                $sql = null === $cursor
                    ? $wpdb->prepare('SELECT * FROM %i ORDER BY %i ASC LIMIT %d', $table, $key, self::BATCH)
                    : $wpdb->prepare('SELECT * FROM %i WHERE %i > %s ORDER BY %i ASC LIMIT %d', $table, $key, $cursor, $key, self::BATCH);
            } else {
                // No single-column primary key to seek on, so OFFSET is the
                // only option, but ordered, so at least the sequence is
                // deterministic rather than whatever the optimiser returns.
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $order is a backtick-quoted column list built by column_order() from SHOW COLUMNS on this validated table; a multi-identifier list cannot be expressed with %i.
                $sql = $wpdb->prepare("SELECT * FROM %i ORDER BY {$order} LIMIT %d OFFSET %d", $table, self::BATCH, $offset);
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Identifiers come from the validated table list and from SHOW KEYS/SHOW COLUMNS on that table; every value is bound.
            $rows = $wpdb->get_results($sql, ARRAY_A);

            if (! is_array($rows) || [] === $rows) {
                break;
            }

            if (null !== $key) {
                $last   = $rows[ count($rows) - 1 ];
                $cursor = (string) ($last[ $key ] ?? '');
            }

            foreach ($rows as $row) {
                $values = $this->value_list($row);

                if ('' === $buffer) {
                    $buffer = "INSERT INTO {$quoted} ({$this->column_list($row)}) VALUES\n{$values}";
                } else {
                    $buffer .= ",\n{$values}";
                }

                if (strlen($buffer) >= self::MAX_STATEMENT_BYTES) {
                    $emit($buffer . ";\n");
                    $buffer = '';
                }
            }

            $total += count($rows);

            if (count($rows) < self::BATCH) {
                break;
            }

            $offset += self::BATCH;
        }

        if ('' !== $buffer) {
            $emit($buffer . ";\n");
        }

        if ($total > 0) {
            $emit("\n");
        }

        return $total;
    }

    /**
     * The table's primary key, when it is a single column.
     *
     * A composite key is deliberately not stitched into a keyset seek: the
     * multi-column comparison is easy to get subtly wrong, and the ordered
     * OFFSET fallback is already correct for the rare WordPress table that
     * has one (term_relationships being the notable case).
     */
    private function primary_key(string $table): ?string
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Identifier from the validated table list; no user input reaches this query.
        $keys = $wpdb->get_results('SHOW KEYS FROM `' . str_replace('`', '``', $table) . "` WHERE Key_name = 'PRIMARY'", ARRAY_A);

        if (! is_array($keys) || 1 !== count($keys)) {
            return null;
        }

        $column = (string) ($keys[0]['Column_name'] ?? '');

        return '' === $column ? null : $column;
    }

    /** Every column, backtick-quoted, as a deterministic ORDER BY clause. */
    private function column_order(string $table): string
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Identifier from the validated table list; no user input reaches this query.
        $columns = $wpdb->get_results('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`', ARRAY_A);

        if (! is_array($columns) || [] === $columns) {
            return '1';
        }

        $names = array_map(
            static fn(array $c): string => '`' . str_replace('`', '``', (string) $c['Field']) . '`',
            $columns
        );

        return implode(', ', $names);
    }

    /** Backtick-quoted column list for an INSERT, derived from the row keys. */
    private function column_list(array $row): string
    {
        $columns = array_map(
            static fn(string $name): string => '`' . str_replace('`', '``', $name) . '`',
            array_keys($row)
        );

        return implode(', ', $columns);
    }

    /**
     * One row rendered as a VALUES tuple. NULL stays a real SQL NULL (not the
     * string "NULL", which would silently turn every nullable column into
     * text on restore); everything else is escaped through $wpdb::prepare(),
     * the same escaping path every write in this plugin already trusts.
     */
    private function value_list(array $row): string
    {
        global $wpdb;

        $values = [];
        foreach ($row as $value) {
            if (null === $value) {
                $values[] = 'NULL';
                continue;
            }
            $values[] = $wpdb->prepare('%s', (string) $value);
        }

        return '(' . implode(', ', $values) . ')';
    }

    /**
     * Whether a table has any BLOB/BINARY column, recorded in the manifest so
     * a restore can warn about the string-literal round-trip limit above.
     */
    private function has_blob_column(string $table): bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Identifier from the validated table list; no user input reaches this query.
        $columns = $wpdb->get_results('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`', ARRAY_A);

        if (! is_array($columns)) {
            return false;
        }

        foreach ($columns as $column) {
            $type = strtolower((string) ($column['Type'] ?? ''));
            if (str_contains($type, 'blob') || str_contains($type, 'binary')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Dump preamble. Foreign key and uniqueness checks are suspended for the
     * duration of an import because tables arrive in alphabetical order, not
     * dependency order, and the session sql_mode is pinned so a dump taken on
     * a permissive server does not fail row-by-row on a strict one.
     */
    private function header(): string
    {
        global $wpdb;

        $charset = $wpdb->charset ?: 'utf8mb4';

        return "-- WP MCP site backup\n"
            . '-- Generated: ' . gmdate('c') . "\n"
            . '-- Plugin version: ' . (defined('WPMCP_VERSION') ? WPMCP_VERSION : '0.0.0') . "\n\n"
            . "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n"
            . "SET FOREIGN_KEY_CHECKS = 0;\n"
            . "SET UNIQUE_CHECKS = 0;\n"
            . "SET NAMES {$charset};\n\n";
    }

    private function footer(): string
    {
        return "\nSET FOREIGN_KEY_CHECKS = 1;\nSET UNIQUE_CHECKS = 1;\n";
    }
}
