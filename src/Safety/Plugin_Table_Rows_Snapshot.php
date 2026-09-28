<?php

// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional (matches the rest of WPMCP\Safety).
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional (matches the rest of WPMCP\Safety).

namespace WPMCP\Safety;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Snapshots of rows in a host plugin's own table, keyed by the integer `id`
 * column (issue #299): a Pods table-storage row (the `pods_<pod>` table,
 * whose id is the post id) or TranslatePress dictionary rows (a
 * `trp_dictionary_<default>_<language>` table). The key is
 * "<kind>:<suffix>:<id>[,<id>...]", and the table is always rebuilt from the
 * site prefix, the kind's own prefix and a suffix of [a-z0-9_-] only, so a
 * snapshot can never name any other table.
 *
 * Every captured row is kept verbatim (NULLs included), so the restore puts
 * back exactly that set: it deletes every row under the captured ids and
 * re-inserts what was there. A row the write inserted is removed again, and
 * a row the write changed returns in place. Not db_rows: that type restores
 * only rows a WHERE matched, so it cannot undo an insert.
 *
 * Restoring takes the capability the write itself needs: edit_post on each
 * captured post for a Pods row, manage_options for a dictionary row.
 */
final class Plugin_Table_Rows_Snapshot
{
    public const TYPE = 'plugin_table_rows';

    /** kind => table prefix after the site prefix. */
    public const KINDS = [
        'pods'           => 'pods_',
        'trp_dictionary' => 'trp_dictionary_',
    ];

    /** The snapshot key for a set of rows. */
    public static function key(string $kind, string $suffix, array $ids): string
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        return $kind . ':' . $suffix . ':' . implode(',', $ids);
    }

    /** The prefixed table for a kind and suffix, or null when either is not allowed. */
    public static function table(string $kind, string $suffix): ?string
    {
        global $wpdb;

        if (! isset(self::KINDS[ $kind ]) || 1 !== preg_match('/^[a-z0-9_-]{1,64}$/', $suffix)) {
            return null;
        }
        return $wpdb->prefix . self::KINDS[ $kind ] . $suffix;
    }

    /**
     * The suffix of a full table name under a kind, or null when the table is
     * not one of that kind's tables on this site.
     */
    public static function suffix_of(string $kind, string $table): ?string
    {
        global $wpdb;

        if (! isset(self::KINDS[ $kind ])) {
            return null;
        }
        $prefix = $wpdb->prefix . self::KINDS[ $kind ];
        if (! str_starts_with($table, $prefix)) {
            return null;
        }
        $suffix = substr($table, strlen($prefix));
        return self::table($kind, $suffix) === $table ? $suffix : null;
    }

    /** Whether a table exists (SHOW COLUMNS, which also sees temporary tables). */
    public static function table_exists(string $table): bool
    {
        return [] !== self::columns($table);
    }

    /**
     * The live column names of a table, or [] when it does not exist.
     *
     * @return string[]
     */
    public static function columns(string $table): array
    {
        global $wpdb;

        $suppress = $wpdb->suppress_errors(true);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- probing a third-party table's live shape; nothing to cache.
        $columns = $wpdb->get_col($wpdb->prepare('SHOW COLUMNS FROM %i', $table));
        $wpdb->suppress_errors($suppress);

        return array_map('strval', (array) $columns);
    }

    /**
     * Every row under the given ids, oldest id first, read live.
     *
     * @param int[] $ids
     * @return array<int, array<string, string|null>>
     */
    public static function rows(string $table, array $ids): array
    {
        global $wpdb;

        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if ([] === $ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '%d'));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- a snapshot must read the live rows of a third-party table; $in holds only %d placeholders.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE id IN ($in) ORDER BY id ASC", $table, ...$ids), ARRAY_A);

        return array_values((array) $rows);
    }

    /** @return array{kind: string, suffix: string, ids: int[]} */
    public static function parse(string $key): array
    {
        [$kind, $suffix, $ids] = array_pad(explode(':', $key, 3), 3, '');
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $ids)), static fn (int $id): bool => $id > 0));

        return [ 'kind' => (string) $kind, 'suffix' => (string) $suffix, 'ids' => $ids ];
    }

    public static function capture(string $key): array
    {
        $parsed = self::parse($key);
        $table  = self::table($parsed['kind'], $parsed['suffix']);
        $exists = null !== $table && self::table_exists($table);

        return [
            'object_type' => self::TYPE,
            'object_id'   => $key,
            'data'        => [
                'kind'         => $parsed['kind'],
                'suffix'       => $parsed['suffix'],
                'ids'          => $parsed['ids'],
                'table_exists' => $exists,
                'rows'         => $exists ? self::rows((string) $table, $parsed['ids']) : [],
            ],
        ];
    }

    /**
     * Put the captured rows back: delete every row under the captured ids,
     * then insert each captured row as it was. Any failure is a
     * Mutation_Failed.
     */
    public static function restore(array $snapshot): void
    {
        $data  = (array) ($snapshot['data'] ?? []);
        $kind  = (string) ($data['kind'] ?? '');
        $ids   = array_values(array_filter(array_map('intval', (array) ($data['ids'] ?? [])), static fn (int $id): bool => $id > 0));
        $table = self::table($kind, (string) ($data['suffix'] ?? ''));

        if (null === $table || [] === $ids || empty($data['table_exists']) || ! self::table_exists($table)) {
            return;
        }

        self::authorize($kind, $ids);

        global $wpdb;
        $in = implode(',', array_fill(0, count($ids), '%d'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- third-party table with no WP API; $in holds only %d placeholders.
        if (false === $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE id IN ($in)", $table, ...$ids))) {
            throw new Mutation_Failed('Could not clear the rows being restored in ' . esc_html($table) . '.');
        }
        foreach ((array) ($data['rows'] ?? []) as $row) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- third-party table with no WP API.
            if (false === $wpdb->insert($table, (array) $row)) {
                throw new Mutation_Failed('Could not restore a row of ' . esc_html($table) . ': ' . esc_html($wpdb->last_error));
            }
        }

        if ('pods' === $kind) {
            foreach ($ids as $id) {
                clean_post_cache($id);
            }
        }
        if ('trp_dictionary' === $kind) {
            self::flush_translatepress_cache();
        }
    }

    /** Drop TranslatePress's cached dictionary lookups (its 'trp' cache group). */
    public static function flush_translatepress_cache(): void
    {
        if (function_exists('wp_cache_supports') && wp_cache_supports('flush_group')) {
            wp_cache_flush_group('trp');
        }
    }

    /** @param int[] $ids */
    private static function authorize(string $kind, array $ids): void
    {
        if ('trp_dictionary' === $kind) {
            if (! current_user_can('manage_options')) {
                throw new Mutation_Failed('Rollback refused: restoring TranslatePress translations requires the manage_options capability.');
            }
            return;
        }
        foreach ($ids as $id) {
            if (! current_user_can('edit_post', $id)) {
                throw new Mutation_Failed('Rollback refused: restoring the Pods fields of post ' . (int) $id . ' requires permission to edit it.');
            }
        }
    }
}
