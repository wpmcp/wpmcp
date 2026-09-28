<?php

// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional (matches the rest of WPMCP\Safety).
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional (matches the rest of WPMCP\Safety).

namespace WPMCP\Safety;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Snapshots of the BuddyPress rows one write touches (issue #354), keyed
 * "<kind>:<id>":
 *  - group:          the bp_groups row and its bp_groups_groupmeta rows;
 *  - group_created:  the same plus the group's bp_groups_members rows, for a
 *                    create whose id is reserved before the snapshot, so the
 *                    undo removes the group, its meta and the membership the
 *                    create added;
 *  - activity:       the item and every reply under it (the activity_comment
 *                    rows threaded below it through secondary_item_id), with
 *                    their bp_activity_meta rows;
 *  - xprofile_field: the bp_xprofile_fields row, its option rows and its
 *                    bp_xprofile_meta rows.
 *
 * Every captured row is kept verbatim (NULLs and ids included), and the
 * restore deletes every row the capture's predicates match and re-inserts
 * what was there, so an edit goes back in place, a deleted thread returns at
 * its own ids, and a created group is removed. The tables are always built
 * from BuddyPress's table prefix and a fixed allowlist of names, so a key can
 * never reach any other table.
 *
 * Undoing a create removes the group only while it is still the group the
 * create wrote (same slug, recorded as 'created'); otherwise it is left and
 * reported. Restoring takes manage_options, the capability every BuddyPress
 * write here requires (BuddyPress maps its own bp_moderate onto it).
 */
final class BuddyPress_Rows_Snapshot
{
    public const TYPE = 'buddypress_rows';

    public const KINDS = [ 'group', 'group_created', 'activity', 'xprofile_field' ];

    /** Every BuddyPress table this type may read or write, unprefixed. */
    private const TABLES = [
        'bp_groups',
        'bp_groups_members',
        'bp_groups_groupmeta',
        'bp_activity',
        'bp_activity_meta',
        'bp_xprofile_groups',
        'bp_xprofile_fields',
        'bp_xprofile_meta',
    ];

    public static function key(string $kind, int $id): string
    {
        return $kind . ':' . $id;
    }

    /** BuddyPress's table prefix: the network base prefix unless BuddyPress filters it. */
    public static function prefix(): string
    {
        global $wpdb;
        return function_exists('bp_core_get_table_prefix') ? (string) bp_core_get_table_prefix() : (string) $wpdb->base_prefix;
    }

    /** The prefixed table for an allowlisted name. */
    public static function table(string $name): string
    {
        if (! in_array($name, self::TABLES, true)) {
            throw new \InvalidArgumentException('Not a BuddyPress table this snapshot type covers.');
        }
        return self::prefix() . $name;
    }

    /** Whether every named table exists (SHOW COLUMNS, which also sees temporary tables). */
    public static function tables_exist(string ...$names): bool
    {
        global $wpdb;

        foreach ($names as $name) {
            $suppress = $wpdb->suppress_errors(true);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- probing a third-party table's live shape; nothing to cache.
            $columns = $wpdb->get_col($wpdb->prepare('SHOW COLUMNS FROM %i', self::table($name)));
            $wpdb->suppress_errors($suppress);
            if ([] === (array) $columns) {
                return false;
            }
        }
        return true;
    }

    /**
     * An activity item's id and the ids of every reply threaded below it,
     * ascending. Replies are activity_comment rows whose secondary_item_id is
     * their parent, which is how BuddyPress threads them.
     *
     * @return int[]
     */
    public static function activity_thread(int $id): array
    {
        global $wpdb;

        $table = self::table('bp_activity');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- BuddyPress's own table, read live for a snapshot.
        if (null === $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE id = %d', $table, $id))) {
            return [];
        }

        $ids      = [ $id ];
        $frontier = [ $id ];
        while ([] !== $frontier) {
            $in = implode(',', array_fill(0, count($frontier), '%d'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- BuddyPress's own table; $in holds only %d placeholders.
            $children = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT id FROM %i WHERE type = 'activity_comment' AND secondary_item_id IN ($in)", $table, ...$frontier)));
            $frontier = array_values(array_diff($children, $ids));
            $ids      = array_merge($ids, $frontier);
        }
        sort($ids);
        return $ids;
    }

    /**
     * The row sets a kind covers: each a table, the column matched against
     * the ids, and for xprofile meta the object_type it is limited to.
     *
     * @param int[] $ids
     * @return array<int, array{table: string, column: string, ids: int[], object_type: ?string}>
     */
    private static function sets(string $kind, int $id, array $ids): array
    {
        $set = static fn (string $table, string $column, array $values, ?string $object_type = null): array => [
            'table'       => $table,
            'column'      => $column,
            'ids'         => $values,
            'object_type' => $object_type,
        ];

        switch ($kind) {
            case 'group':
                return [ $set('bp_groups', 'id', [ $id ]), $set('bp_groups_groupmeta', 'group_id', [ $id ]) ];
            case 'group_created':
                return [ $set('bp_groups', 'id', [ $id ]), $set('bp_groups_groupmeta', 'group_id', [ $id ]), $set('bp_groups_members', 'group_id', [ $id ]) ];
            case 'activity':
                return [ $set('bp_activity', 'id', $ids), $set('bp_activity_meta', 'activity_id', $ids) ];
            case 'xprofile_field':
                return [ $set('bp_xprofile_fields', 'id', [ $id ]), $set('bp_xprofile_fields', 'parent_id', [ $id ]), $set('bp_xprofile_meta', 'object_id', [ $id ], 'field') ];
        }
        return [];
    }

    /** @return array{kind: string, id: int} */
    public static function parse(string $key): array
    {
        [$kind, $id] = array_pad(explode(':', $key, 2), 2, '');
        return [ 'kind' => (string) $kind, 'id' => (int) $id ];
    }

    public static function capture(string $key): array
    {
        $parsed = self::parse($key);
        $kind   = in_array($parsed['kind'], self::KINDS, true) && $parsed['id'] > 0 ? $parsed['kind'] : '';
        $ids    = 'activity' === $kind && self::tables_exist('bp_activity') ? self::activity_thread($parsed['id']) : [ $parsed['id'] ];
        $sets   = '' === $kind ? [] : self::sets($kind, $parsed['id'], $ids);
        $exists = [] !== $sets && self::tables_exist(...array_unique(array_column($sets, 'table')));

        if ($exists) {
            foreach ($sets as $i => $set) {
                $sets[ $i ]['rows'] = self::rows($set);
            }
        }

        return [
            'object_type' => self::TYPE,
            'object_id'   => $key,
            'data'        => [
                'kind'         => $kind,
                'id'           => $parsed['id'],
                'tables_exist' => $exists,
                'sets'         => $exists ? $sets : [],
            ],
        ];
    }

    /**
     * Put the captured rows back. Any failure is a Mutation_Failed. Returns a
     * warning when a created group was left in place, or null.
     */
    public static function restore(array $snapshot): ?string
    {
        if (! current_user_can('manage_options')) {
            throw new Mutation_Failed('Rollback refused: restoring BuddyPress data requires the manage_options capability.');
        }

        $data = (array) ($snapshot['data'] ?? []);
        $kind = (string) ($data['kind'] ?? '');
        $id   = (int) ($data['id'] ?? 0);
        $sets = (array) ($data['sets'] ?? []);
        if (! in_array($kind, self::KINDS, true) || $id <= 0 || empty($data['tables_exist']) || [] === $sets) {
            return null;
        }
        if (! self::tables_exist(...array_unique(array_map(static fn ($set): string => (string) ((array) $set)['table'], $sets)))) {
            return null;
        }

        if ('group_created' === $kind && is_array($data['created'] ?? null)) {
            $current = self::rows([ 'table' => 'bp_groups', 'column' => 'id', 'ids' => [ $id ], 'object_type' => null ]);
            if ([] === $current) {
                return null; // Already gone: nothing to undo.
            }
            if ((string) ($data['created']['slug'] ?? '') !== (string) ($current[0]['slug'] ?? '')) {
                return sprintf('BuddyPress group %d is not the group this operation created (its slug differs); it was left untouched.', $id);
            }
        }

        global $wpdb;
        foreach ($sets as $set) {
            $set = (array) $set;
            [$sql, $args] = self::where($set);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- BuddyPress's own table; where() builds only placeholders from an allowlisted table and column.
            if (false === $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE ' . $sql, self::table((string) $set['table']), ...$args))) {
                throw new Mutation_Failed('Could not clear the BuddyPress rows being restored.');
            }
        }
        foreach ($sets as $set) {
            $set = (array) $set;
            foreach ((array) ($set['rows'] ?? []) as $row) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- BuddyPress's own table; rows restored verbatim.
                if (false === $wpdb->insert(self::table((string) $set['table']), (array) $row)) {
                    throw new Mutation_Failed('Could not restore a BuddyPress row: ' . esc_html($wpdb->last_error));
                }
            }
        }

        $ids = (array) (((array) $sets[0])['ids'] ?? [ $id ]);
        self::flush($kind, array_map('intval', $ids));
        return null;
    }

    /**
     * Tell BuddyPress its data changed: drop the object caches its own
     * writes drop, and move its query incrementors on. No-ops while the
     * plugin is not loaded, apart from the plain cache deletes.
     *
     * @param int[] $ids
     */
    public static function flush(string $kind, array $ids): void
    {
        $reset = static function (string $group): void {
            if (function_exists('bp_core_reset_incrementor')) {
                bp_core_reset_incrementor($group);
            }
        };

        if (in_array($kind, [ 'group', 'group_created' ], true)) {
            foreach ($ids as $id) {
                wp_cache_delete($id, 'bp_groups');
                wp_cache_delete($id, 'group_meta');
                wp_cache_delete($id, 'bp_group_admins');
                wp_cache_delete($id, 'bp_group_mods');
            }
            wp_cache_delete('bp_total_group_count', 'bp');
            $reset('bp_groups');
            return;
        }
        if ('activity' === $kind) {
            foreach ($ids as $id) {
                wp_cache_delete($id, 'bp_activity');
                wp_cache_delete($id, 'activity_meta');
                wp_cache_delete($id, 'bp_activity_comments');
            }
            wp_cache_delete('bp_activity_sitewide_front', 'bp');
            $reset('bp_activity');
            $reset('bp_activity_with_last_activity');
            return;
        }
        if ('xprofile_field' === $kind) {
            foreach ($ids as $id) {
                wp_cache_delete($id, 'bp_xprofile_fields');
                wp_cache_delete($id, 'xprofile_meta');
            }
            wp_cache_delete('all', 'bp_xprofile_groups');
            $reset('bp_xprofile_fields_by_name');
            $reset('bp_xprofile_groups');
        }
    }

    /**
     * The rows one set matches, oldest id first, read live.
     *
     * @param array{table: string, column: string, ids: int[], object_type: ?string} $set
     * @return array<int, array<string, string|null>>
     */
    private static function rows(array $set): array
    {
        global $wpdb;

        [$sql, $args] = self::where($set);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- a snapshot must read the live rows of BuddyPress's own table; where() builds only placeholders.
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE ' . $sql . ' ORDER BY id ASC', self::table((string) $set['table']), ...$args), ARRAY_A);

        return array_values((array) $rows);
    }

    /**
     * The WHERE clause of a set as placeholders and their values.
     *
     * @return array{0: string, 1: array<int, int|string>}
     */
    private static function where(array $set): array
    {
        $column = (string) ($set['column'] ?? '');
        if (! in_array($column, [ 'id', 'group_id', 'activity_id', 'parent_id', 'object_id' ], true)) {
            throw new \InvalidArgumentException('Not a BuddyPress key column.');
        }
        $ids = array_values(array_filter(array_map('intval', (array) ($set['ids'] ?? [])), static fn (int $id): bool => $id > 0));
        if ([] === $ids) {
            $ids = [ 0 ];
        }
        $sql  = '%i IN (' . implode(',', array_fill(0, count($ids), '%d')) . ')';
        $args = array_merge([ $column ], $ids);
        if (null !== ($set['object_type'] ?? null)) {
            $sql   .= ' AND object_type = %s';
            $args[] = (string) $set['object_type'];
        }
        return [ $sql, $args ];
    }
}
