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
 * MemberPress rule access conditions (issue #668, the
 * `mepr_rule_access_conditions` table) are keyed by the rule they belong to
 * (`rule_id`) rather than by their own id, so a snapshot taken before a
 * write covers the rows the write inserts as well: the restore removes every
 * row of the captured rules and puts back the ones that were there. That
 * kind allows the one suffix, "conditions", so it can never name another
 * MemberPress table.
 *
 * Rank Math redirections (issue #681) are the rows of its
 * `rank_math_redirections` table (the one suffix, "redirections"), keyed by
 * id. The lookup cache table beside it is derived data: after a restore the
 * cache rows of the captured ids are purged through Rank Math's own cache
 * class, so the next request rebuilds them. Restoring takes manage_options,
 * the capability the pack's writes need.
 *
 * Wordfence (issue #577) keeps its state in network-wide tables named the
 * way Wordfence names them (the network prefix, lowercased when Wordfence's
 * own wordfence_case option says so). Two kinds cover them, each with the one
 * suffix "network": scan issues (`wfIssues`) keyed by id, and IP blocks
 * (`wfBlocks7`) keyed by the 16-byte `IP` column, so a snapshot taken before
 * a block covers the row Wordfence inserts and one taken before an unblock
 * covers every row it deletes. A binary column cannot ride in the JSON
 * snapshot as raw bytes, so its key and its values are kept as lowercase
 * hex and turned back into bytes in SQL (UNHEX) on restore. After a block
 * restore, Wordfence's firewall is told to resync its copy of the blocks,
 * and after an issue restore its scan notice is recounted, the way its own
 * screens do after the same change.
 *
 * Pretty Links links (issue #682, the `prli_links` table, the one suffix
 * "links") are keyed by their own id. A create reserves its id before the
 * snapshot, so the restore removes the row the write inserted.
 *
 * Paid Memberships Pro (issue #667) keeps levels, memberships and discount
 * codes in tables of its own, each kind with its one suffix: levels
 * (`pmpro_membership_levels`) and discount codes (`pmpro_discount_codes`)
 * by id, and a member's membership rows (`pmpro_memberships_users`) by
 * user_id, so a snapshot taken before a level change covers the row PMPro
 * inserts as well as the ones it ends. A level's group assignment
 * (`pmpro_membership_levels_groups`, by level) and a code's level pricing
 * (`pmpro_discount_codes_levels`, by code_id) are companion rows: captured
 * and put back with their parent rows, when their table exists. After a
 * membership restore PMPro's cached levels of each captured user are
 * dropped, so its next read sees the restored rows.
 *
 * Restrict Content membership levels (issue #669, the
 * `restrict_content_pro` table the plugin names its levels table, so kind
 * rcp_levels with the prefix "restrict_content_" and the one suffix
 * "pro") are keyed by their own id. After a restore the plugin's cached
 * copies of the rows are dropped (its BerlinDB cache group
 * rcp-membership_levels), as its own query does after a write.
 *
 * Easy Digital Downloads discounts (issue #664, the `edd_adjustments` table,
 * the one suffix "adjustments") are keyed by their own id; a create
 * reserves its id the same way. EDD reads the table through BerlinDB, which
 * caches rows and query results in the object cache, so after a restore the
 * cached lookups of the table are retired (purge_edd_cache()), the way
 * BerlinDB's own writes do.
 *
 * Restoring takes the capability the write itself needs: edit_post on each
 * captured post for a Pods row or a MemberPress rule's rows, manage_options
 * for a dictionary row, a Pretty Links link, a Restrict Content level or an EDD discount, and Wordfence's own admin capability (manage_options,
 * manage_network on a multisite network) for a Wordfence row.
 */
final class Plugin_Table_Rows_Snapshot
{
    public const TYPE = 'plugin_table_rows';

    /** kind => table prefix after the site prefix. */
    public const KINDS = [
        'pods'             => 'pods_',
        'trp_dictionary'   => 'trp_dictionary_',
        'mepr_rule_access' => 'mepr_rule_access_',
        'rank_math'        => 'rank_math_',
        'prli'             => 'prli_',
        'pmpro_levels'     => 'pmpro_',
        'pmpro_members'    => 'pmpro_',
        'pmpro_codes'      => 'pmpro_',
        'rcp_levels'       => 'restrict_content_',
        'edd'              => 'edd_',
    ];

    /**
     * kind => the Wordfence table it covers, unprefixed and as Wordfence
     * spells it before its own case conversion. Suffix "network" only.
     */
    public const WORDFENCE_KINDS = [
        'wordfence_issues' => 'wfIssues',
        'wordfence_blocks' => 'wfBlocks7',
    ];

    /** kind => the column its ids name, for a kind not keyed by `id`. */
    private const KEY_COLUMNS = [
        'mepr_rule_access' => 'rule_id',
        'wordfence_blocks' => 'IP',
        'pmpro_members'    => 'user_id',
    ];

    /** kind => the only suffixes it allows, for a kind with a fixed table. */
    private const SUFFIXES = [
        'mepr_rule_access' => [ 'conditions' ],
        'rank_math'        => [ 'redirections' ],
        'prli'             => [ 'links' ],
        'pmpro_levels'     => [ 'membership_levels' ],
        'pmpro_members'    => [ 'memberships_users' ],
        'pmpro_codes'      => [ 'discount_codes' ],
        'rcp_levels'       => [ 'pro' ],
        'edd'              => [ 'adjustments' ],
    ];

    /**
     * kind => [companion table suffix (after the kind's own prefix) => the
     * column holding the parent id]. Companion rows are captured and
     * restored with their parent rows, when the table exists.
     */
    private const COMPANIONS = [
        'pmpro_levels' => [ 'membership_levels_groups' => 'level' ],
        'pmpro_codes'  => [ 'discount_codes_levels' => 'code_id' ],
    ];

    /** kind => its binary columns, carried as lowercase hex in the snapshot and in the key. */
    private const BINARY_COLUMNS = [
        'wordfence_blocks' => [ 'IP' ],
    ];

    /** The column a kind's ids name. */
    public static function key_column(string $kind): string
    {
        return self::KEY_COLUMNS[ $kind ] ?? 'id';
    }

    /** Whether a kind's ids are binary values (lowercase hex in the key) rather than integers. */
    private static function binary_key(string $kind): bool
    {
        return in_array(self::key_column($kind), self::BINARY_COLUMNS[ $kind ] ?? [], true);
    }

    /**
     * A kind's ids, cleaned: positive integers, or for a binary-keyed kind
     * lowercase hex of up to 64 bytes. Anything else is dropped.
     *
     * @return array<int, int|string>
     */
    private static function clean_ids(string $kind, array $ids): array
    {
        if (self::binary_key($kind)) {
            $ids = array_map(static fn ($id): string => strtolower((string) $id), $ids);
            return array_values(array_filter($ids, static fn (string $id): bool => 1 === preg_match('/^(?:[0-9a-f]{2}){1,64}$/', $id)));
        }
        return array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
    }

    /** The snapshot key for a set of rows. */
    public static function key(string $kind, string $suffix, array $ids): string
    {
        $ids = self::binary_key($kind) ? array_values(array_unique(self::clean_ids($kind, $ids))) : array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        return $kind . ':' . $suffix . ':' . implode(',', $ids);
    }

    /**
     * A Wordfence table as Wordfence names it: the network prefix and the
     * name, lowercased when Wordfence's wordfence_case option (a network
     * option on multisite) says its tables are lowercase, as on every
     * install since it switched.
     */
    public static function wordfence_table(string $name): string
    {
        global $wpdb;

        $lower = is_multisite() ? get_network_option(null, 'wordfence_case') : get_option('wordfence_case');
        return $wpdb->base_prefix . ($lower ? strtolower($name) : $name);
    }

    /** The prefixed table for a kind and suffix, or null when either is not allowed. */
    public static function table(string $kind, string $suffix): ?string
    {
        global $wpdb;

        if (isset(self::WORDFENCE_KINDS[ $kind ])) {
            return 'network' === $suffix ? self::wordfence_table(self::WORDFENCE_KINDS[ $kind ]) : null;
        }
        if (! isset(self::KINDS[ $kind ]) || 1 !== preg_match('/^[a-z0-9_-]{1,64}$/', $suffix)) {
            return null;
        }
        if (isset(self::SUFFIXES[ $kind ]) && ! in_array($suffix, self::SUFFIXES[ $kind ], true)) {
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
     * Every row under the given ids (in $column, `id` unless the kind keys
     * its rows by another column), oldest id first, read live.
     *
     * @param int[] $ids
     * @return array<int, array<string, string|null>>
     */
    public static function rows(string $table, array $ids, string $column = 'id'): array
    {
        global $wpdb;

        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if ([] === $ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '%d'));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- a snapshot must read the live rows of a third-party table; $in holds only %d placeholders.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE %i IN ($in) ORDER BY id ASC", $table, $column, ...$ids), ARRAY_A);

        return array_values((array) $rows);
    }

    /** @return array{kind: string, suffix: string, ids: array<int, int|string>} */
    public static function parse(string $key): array
    {
        [$kind, $suffix, $ids] = array_pad(explode(':', $key, 3), 3, '');
        $ids = self::clean_ids((string) $kind, explode(',', (string) $ids));

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
                'rows'         => $exists ? self::kind_rows($parsed['kind'], (string) $table, $parsed['ids']) : [],
            ] + (isset(self::COMPANIONS[ $parsed['kind'] ]) ? [ 'companions' => self::capture_companions($parsed['kind'], $parsed['ids']) ] : []),
        ];
    }

    /**
     * The companion rows of a kind under its ids: suffix => [exists, rows].
     *
     * @param array<int, int|string> $ids
     * @return array<string, array{exists: bool, rows: array}>
     */
    private static function capture_companions(string $kind, array $ids): array
    {
        $out = [];
        foreach (self::COMPANIONS[ $kind ] ?? [] as $suffix => $column) {
            $table          = self::companion_table($kind, (string) $suffix);
            $exists         = self::table_exists($table);
            $out[ $suffix ] = [ 'exists' => $exists, 'rows' => $exists ? self::companion_rows($table, (string) $column, $ids) : [] ];
        }
        return $out;
    }

    /** A kind's companion table: the site prefix, the kind's prefix and the fixed companion suffix. */
    private static function companion_table(string $kind, string $suffix): string
    {
        global $wpdb;
        return $wpdb->prefix . self::KINDS[ $kind ] . $suffix;
    }

    /**
     * Rows of a companion table under the parent ids, ordered by the parent
     * column (a companion table need not have an id column).
     *
     * @param array<int, int|string> $ids
     * @return array<int, array<string, string|null>>
     */
    private static function companion_rows(string $table, string $column, array $ids): array
    {
        global $wpdb;

        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if ([] === $ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '%d'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- a snapshot must read the live rows of a third-party table; $in holds only %d placeholders.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE %i IN ($in) ORDER BY %i ASC", $table, $column, ...array_merge($ids, [ $column ])), ARRAY_A);

        return array_values((array) $rows);
    }

    /**
     * Put a kind's companion rows back: delete every row under the captured
     * ids, then insert each captured row. A companion table that did not
     * exist at capture, or is gone now, is left alone.
     *
     * @param array<int, int|string> $ids
     */
    private static function restore_companions(string $kind, array $ids, array $captured): void
    {
        global $wpdb;

        $in = implode(',', array_fill(0, count($ids), '%d'));
        foreach (self::COMPANIONS[ $kind ] ?? [] as $suffix => $column) {
            $data  = (array) ($captured[ $suffix ] ?? []);
            $table = self::companion_table($kind, (string) $suffix);
            if (empty($data['exists']) || ! self::table_exists($table)) {
                continue;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- third-party table with no WP API; $in holds only %d placeholders.
            if (false === $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE %i IN ($in)", $table, (string) $column, ...$ids))) {
                throw new Mutation_Failed('Could not clear the rows being restored in ' . esc_html($table) . '.');
            }
            foreach ((array) ($data['rows'] ?? []) as $row) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- third-party table with no WP API.
                if (false === $wpdb->insert($table, (array) $row)) {
                    throw new Mutation_Failed('Could not restore a row of ' . esc_html($table) . ': ' . esc_html($wpdb->last_error));
                }
            }
        }
    }

    /**
     * Drop Paid Memberships Pro's cached levels of each user whose
     * membership rows were restored (its own cache keys, group pmpro).
     *
     * @param array<int, int|string> $ids
     */
    private static function clear_pmpro_user_cache(array $ids): void
    {
        global $all_membership_levels;
        foreach (array_map('intval', $ids) as $user_id) {
            if (is_array($all_membership_levels)) {
                unset($all_membership_levels[ $user_id ]);
            }
            foreach ([ '', '_all', '_active' ] as $suffix) {
                wp_cache_delete('user_' . $user_id . '_levels' . $suffix, 'pmpro');
            }
        }
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
        $ids   = self::clean_ids($kind, (array) ($data['ids'] ?? []));
        $table = self::table($kind, (string) ($data['suffix'] ?? ''));

        if (null === $table || [] === $ids || empty($data['table_exists']) || ! self::table_exists($table)) {
            return;
        }

        self::authorize($kind, $ids);

        if (isset(self::BINARY_COLUMNS[ $kind ])) {
            self::restore_binary($kind, $table, $ids, (array) ($data['rows'] ?? []));
            self::after_wordfence_restore($kind);
            return;
        }

        global $wpdb;
        $in = implode(',', array_fill(0, count($ids), '%d'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- third-party table with no WP API; $in holds only %d placeholders.
        if (false === $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE %i IN ($in)", $table, self::key_column($kind), ...$ids))) {
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
        if ('rank_math' === $kind) {
            self::purge_rank_math_cache($ids);
        }
        if (isset(self::COMPANIONS[ $kind ])) {
            self::restore_companions($kind, $ids, (array) ($data['companions'] ?? []));
        }
        if ('pmpro_members' === $kind) {
            self::clear_pmpro_user_cache($ids);
        }
        if ('rcp_levels' === $kind) {
            self::flush_rcp_level_cache($ids);
        }
        if ('edd' === $kind) {
            self::purge_edd_cache((string) $data['suffix'], $ids);
        }
        self::after_wordfence_restore($kind);
    }

    /**
     * Drop Restrict Content's cached copies of the given membership level
     * rows and move its level query cache on, as its own BerlinDB query does
     * after a write (cache group rcp-membership_levels).
     *
     * @param array<int, int|string> $ids
     */
    public static function flush_rcp_level_cache(array $ids): void
    {
        foreach ($ids as $id) {
            wp_cache_delete((int) $id, 'rcp-membership_levels');
        }
        wp_cache_set('last_changed', microtime(), 'rcp-membership_levels');
    }

    /**
     * Retire Easy Digital Downloads' cached lookups for rows of one of its
     * tables, as BerlinDB does after its own writes: the rows cached by id
     * (group edd-<suffix>) are dropped, and the last_changed stamp of the
     * table's query cache and of each by-column lookup group
     * (edd-<suffix>-by-<column>) is rotated, so every cached result is
     * rebuilt on the next read.
     *
     * @param array<int, int|string> $ids
     */
    public static function purge_edd_cache(string $suffix, array $ids): void
    {
        $table = self::table('edd', $suffix);
        if (null === $table) {
            return;
        }
        $group = 'edd-' . $suffix;
        foreach ($ids as $id) {
            wp_cache_delete((int) $id, $group);
        }
        wp_cache_set('last_changed', microtime(), $group);
        foreach (self::columns($table) as $column) {
            wp_cache_set('last_changed', microtime(), $group . '-by-' . $column);
        }
    }

    /**
     * Drop Rank Math's derived lookup rows for redirections whose row was
     * written or restored. A no-op while Rank Math's cache class is absent.
     *
     * @param array<int, int|string> $ids
     */
    public static function purge_rank_math_cache(array $ids): void
    {
        $class = '\\RankMath\\Redirections\\Cache';
        if (class_exists($class) && method_exists($class, 'purge')) {
            $class::purge(array_map('intval', $ids));
        }
    }

    /**
     * Rows of a kind under its ids. A binary-keyed kind is matched with
     * UNHEX() and its binary columns come back as lowercase hex.
     *
     * @param array<int, int|string> $ids
     * @return array<int, array<string, string|null>>
     */
    private static function kind_rows(string $kind, string $table, array $ids): array
    {
        if (! isset(self::BINARY_COLUMNS[ $kind ])) {
            return self::rows($table, $ids, self::key_column($kind));
        }
        if ([] === $ids) {
            return [];
        }

        global $wpdb;
        $in = implode(',', array_fill(0, count($ids), 'UNHEX(%s)'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- a snapshot must read the live rows of a third-party table; $in holds only UNHEX(%s) placeholders.
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE %i IN ($in) ORDER BY id ASC", $table, self::key_column($kind), ...$ids), ARRAY_A);

        $out = [];
        foreach ($rows as $row) {
            foreach (self::BINARY_COLUMNS[ $kind ] as $column) {
                if (isset($row[ $column ])) {
                    $row[ $column ] = bin2hex((string) $row[ $column ]);
                }
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * The binary-keyed restore: delete every row under the captured keys,
     * then insert each captured row as it was, its binary columns turned
     * back into bytes with UNHEX(). Column names are taken only from the
     * table's live columns.
     *
     * @param array<int, int|string> $ids
     */
    private static function restore_binary(string $kind, string $table, array $ids, array $rows): void
    {
        global $wpdb;

        $in = implode(',', array_fill(0, count($ids), 'UNHEX(%s)'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- third-party table with no WP API; $in holds only UNHEX(%s) placeholders.
        if (false === $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE %i IN ($in)", $table, self::key_column($kind), ...$ids))) {
            throw new Mutation_Failed('Could not clear the rows being restored in ' . esc_html($table) . '.');
        }

        $live   = self::columns($table);
        $binary = self::BINARY_COLUMNS[ $kind ];
        foreach ($rows as $row) {
            $values = [];
            $names  = [];
            $args   = [];
            foreach ((array) $row as $column => $value) {
                $column = (string) $column;
                if (! in_array($column, $live, true)) {
                    throw new Mutation_Failed('Could not restore a row of ' . esc_html($table) . ': its column ' . esc_html($column) . ' is gone.');
                }
                $names[] = $column;
                // Each value is one of three literals: NULL, %s, or UNHEX(%s)
                // for a binary column.
                $values[] = (null === $value ? 'NULL' : (in_array($column, $binary, true) ? 'UNHEX(%s)' : '%s'));
                if (null !== $value) {
                    $args[] = (string) $value;
                }
            }
            if ([] === $names) {
                continue;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- third-party table with no WP API; one %i per column, and $values holds only %s, UNHEX(%s) and NULL.
            if (false === $wpdb->query($wpdb->prepare('INSERT INTO %i (' . implode(', ', array_fill(0, count($names), '%i')) . ') VALUES (' . implode(', ', $values) . ')', $table, ...$names, ...$args))) {
                throw new Mutation_Failed('Could not restore a row of ' . esc_html($table) . ': ' . esc_html($wpdb->last_error));
            }
        }
    }

    /**
     * Bring Wordfence's derived state back in line after one of its tables
     * was restored, the way its own screens do after the same change: its
     * firewall keeps a copy of the IP blocks, resynced at the end of the
     * request, and its scan notice counts the open issues.
     */
    private static function after_wordfence_restore(string $kind): void
    {
        if ('wordfence_blocks' === $kind && class_exists('wfWAFIPBlocksController', false) && ! (defined('WFWAF_SUBDIRECTORY_INSTALL') && WFWAF_SUBDIRECTORY_INSTALL)) {
            \wfWAFIPBlocksController::setNeedsSynchronizeConfigSettings();
        }
        if ('wordfence_issues' === $kind && class_exists('wfScanEngine', false) && class_exists('wfIssues', false)) {
            \wfScanEngine::refreshScanNotification(\wfIssues::shared());
        }
    }

    /** Wordfence's own admin capability: manage_options, manage_network on a network. */
    public static function wordfence_capability(): string
    {
        return is_multisite() ? 'manage_network' : 'manage_options';
    }

    /** Drop TranslatePress's cached dictionary lookups (its 'trp' cache group). */
    public static function flush_translatepress_cache(): void
    {
        if (function_exists('wp_cache_supports') && wp_cache_supports('flush_group')) {
            wp_cache_flush_group('trp');
        }
    }

    /** @param array<int, int|string> $ids */
    private static function authorize(string $kind, array $ids): void
    {
        if (isset(self::WORDFENCE_KINDS[ $kind ])) {
            if (! current_user_can(self::wordfence_capability())) {
                throw new Mutation_Failed('Rollback refused: restoring Wordfence ' . ('wordfence_blocks' === $kind ? 'IP blocks' : 'scan issues') . ' requires the ' . esc_html(self::wordfence_capability()) . ' capability.');
            }
            return;
        }
        if ('rank_math' === $kind) {
            if (! current_user_can('manage_options')) {
                throw new Mutation_Failed('Rollback refused: restoring a Rank Math redirection requires the manage_options capability.');
            }
            return;
        }
        if ('trp_dictionary' === $kind) {
            if (! current_user_can('manage_options')) {
                throw new Mutation_Failed('Rollback refused: restoring TranslatePress translations requires the manage_options capability.');
            }
            return;
        }
        if (str_starts_with($kind, 'pmpro_')) {
            if (! current_user_can('manage_options')) {
                throw new Mutation_Failed('Rollback refused: restoring Paid Memberships Pro levels, memberships or discount codes requires the manage_options capability.');
            }
            return;
        }
        if ('prli' === $kind) {
            if (! current_user_can('manage_options')) {
                throw new Mutation_Failed('Rollback refused: restoring Pretty Links links requires the manage_options capability.');
            }
            return;
        }
        if ('rcp_levels' === $kind) {
            if (! current_user_can('manage_options')) {
                throw new Mutation_Failed('Rollback refused: restoring a Restrict Content membership level requires the manage_options capability.');
            }
            return;
        }
        if ('edd' === $kind) {
            if (! current_user_can('manage_options')) {
                throw new Mutation_Failed('Rollback refused: restoring Easy Digital Downloads discounts requires the manage_options capability.');
            }
            return;
        }
        foreach ($ids as $id) {
            if (current_user_can('edit_post', $id)) {
                continue;
            }
            if ('mepr_rule_access' === $kind) {
                throw new Mutation_Failed('Rollback refused: restoring the MemberPress access conditions of rule ' . (int) $id . ' requires permission to edit it.');
            }
            throw new Mutation_Failed('Rollback refused: restoring the Pods fields of post ' . (int) $id . ' requires permission to edit it.');
        }
    }
}
