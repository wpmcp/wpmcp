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
 *  - group:          the bp_groups row, its bp_groups_groupmeta rows, the
 *                    group's membership requests and invitations
 *                    (bp_invitations), which BuddyPress accepts when a private
 *                    group goes public, and the groups notifications about
 *                    it, which that marks read;
 *  - group_created:  the same plus the group's bp_groups_members rows, for a
 *                    create whose id is reserved before the snapshot, so the
 *                    undo removes the group, its meta and the membership the
 *                    create added;
 *  - activity:       the whole thread the item belongs to (its root and every
 *                    activity_comment row threaded below it through
 *                    secondary_item_id, since BuddyPress renumbers the thread
 *                    when a reply goes), their bp_activity_meta rows and the
 *                    activity notifications about them;
 *  - xprofile_field: the bp_xprofile_fields row, its option rows and its
 *                    bp_xprofile_meta rows.
 * The invitation and notification sets are left out while their tables are
 * missing (the component is off).
 *
 * A write that runs through BuddyPress fires its hooks, and those add rows of
 * their own (a "created the group" activity item, a notification, an accepted
 * membership). While the write runs, the ids BuddyPress reports through its
 * own after-save and meta-added hooks are recorded, and a row counts as
 * created only when its id is also above the table's highest id from just
 * before the write (an after-save hook fires for updates too). Rows
 * BuddyPress inserts without a hook (a field's options, a member's
 * last_activity item) are taken from that id range only when they belong to
 * the write: an option of a field it touched, or the acting user's own
 * last_activity item. A row another request adds meanwhile is never taken
 * (issue #372). Each created row is persisted in the snapshot
 * ('created_rows') with the columns that say whose it is, and the restore
 * deletes it first, unless those columns changed since, in which case it is
 * left and reported. A create is also re-keyed on the id BuddyPress assigned,
 * when that is not the one reserved for the snapshot.
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
        'bp_notifications',
        'bp_notifications_meta',
        'bp_invitations',
    ];

    /**
     * BuddyPress's own hooks that report a saved or added row, and the table
     * of that row: after-save hooks pass the object, meta-added hooks the
     * meta id.
     */
    private const HOOKS = [
        'groups_group_after_save'    => 'bp_groups',
        'groups_member_after_save'   => 'bp_groups_members',
        'added_group_meta'           => 'bp_groups_groupmeta',
        'bp_activity_after_save'     => 'bp_activity',
        'added_activity_meta'        => 'bp_activity_meta',
        'bp_notification_after_save' => 'bp_notifications',
        'added_notification_meta'    => 'bp_notifications_meta',
        'bp_invitation_after_save'   => 'bp_invitations',
        'xprofile_group_after_save'  => 'bp_xprofile_groups',
        'xprofile_field_after_save'  => 'bp_xprofile_fields',
        'added_xprofile_field_meta'  => 'bp_xprofile_meta',
        'added_xprofile_group_meta'  => 'bp_xprofile_meta',
        'added_xprofile_data_meta'   => 'bp_xprofile_meta',
    ];

    /** Per table, the columns that say whose a row is: a created row is removed only while they are unchanged. */
    private const OWNERS = [
        'bp_groups'             => [ 'creator_id' ],
        'bp_groups_members'     => [ 'group_id', 'user_id' ],
        'bp_groups_groupmeta'   => [ 'group_id', 'meta_key' ],
        'bp_activity'           => [ 'user_id', 'component', 'type', 'item_id', 'secondary_item_id' ],
        'bp_activity_meta'      => [ 'activity_id', 'meta_key' ],
        'bp_xprofile_groups'    => [],
        'bp_xprofile_fields'    => [ 'group_id', 'parent_id', 'type' ],
        'bp_xprofile_meta'      => [ 'object_id', 'object_type', 'meta_key' ],
        'bp_notifications'      => [ 'user_id', 'item_id', 'secondary_item_id', 'component_name', 'component_action' ],
        'bp_notifications_meta' => [ 'notification_id', 'meta_key' ],
        'bp_invitations'        => [ 'user_id', 'inviter_id', 'class', 'item_id', 'type' ],
    ];

    /** The bp_invitations class of group invitations and membership requests. */
    private const GROUP_INVITATIONS = 'BP_Groups_Invitation_Manager';

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

    /** The root of the thread an activity item belongs to: itself unless it is a reply. */
    private static function thread_root(int $id): int
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- BuddyPress's own table, read live for a snapshot.
        $row  = $wpdb->get_row($wpdb->prepare('SELECT type, item_id FROM %i WHERE id = %d', self::table('bp_activity'), $id), ARRAY_A);
        $root = is_array($row) && 'activity_comment' === $row['type'] ? (int) $row['item_id'] : 0;
        return $root > 0 && [] !== self::activity_thread($root) ? $root : $id;
    }

    /**
     * The row sets a kind covers: each a table, the column matched against
     * the ids, and optionally one further column it is limited to (for
     * xprofile meta the object_type, kept under that key for snapshots
     * written before #363). An optional set is dropped while its table is
     * missing.
     *
     * @param int[] $ids
     * @return array<int, array{table: string, column: string, ids: int[], object_type: ?string, match?: array<string, string>, optional?: bool}>
     */
    private static function sets(string $kind, int $id, array $ids): array
    {
        $set = static fn (string $table, string $column, array $values, ?string $object_type = null): array => [
            'table'       => $table,
            'column'      => $column,
            'ids'         => $values,
            'object_type' => $object_type,
        ];
        $optional = static fn (array $set, string $column, string $value): array => $set + [ 'match' => [ $column => $value ], 'optional' => true ];

        switch ($kind) {
            case 'group':
                return [
                    $set('bp_groups', 'id', [ $id ]),
                    $set('bp_groups_groupmeta', 'group_id', [ $id ]),
                    $optional($set('bp_invitations', 'item_id', [ $id ]), 'class', self::GROUP_INVITATIONS),
                    $optional($set('bp_notifications', 'item_id', [ $id ]), 'component_name', 'groups'),
                ];
            case 'group_created':
                return [
                    $set('bp_groups', 'id', [ $id ]),
                    $set('bp_groups_groupmeta', 'group_id', [ $id ]),
                    $set('bp_groups_members', 'group_id', [ $id ]),
                    $optional($set('bp_invitations', 'item_id', [ $id ]), 'class', self::GROUP_INVITATIONS),
                    $optional($set('bp_notifications', 'item_id', [ $id ]), 'component_name', 'groups'),
                ];
            case 'activity':
                return [ $set('bp_activity', 'id', $ids), $set('bp_activity_meta', 'activity_id', $ids), $optional($set('bp_notifications', 'item_id', $ids), 'component_name', 'activity') ];
            case 'xprofile_field':
                return [ $set('bp_xprofile_fields', 'id', [ $id ]), $set('bp_xprofile_fields', 'parent_id', [ $id ]), $set('bp_xprofile_meta', 'object_id', [ $id ], 'field') ];
        }
        return [];
    }

    /**
     * A kind's sets, less the optional ones whose table is missing.
     *
     * @param int[] $ids
     */
    private static function present_sets(string $kind, int $id, array $ids): array
    {
        return array_values(array_filter(
            self::sets($kind, $id, $ids),
            static fn (array $set): bool => empty($set['optional']) || self::tables_exist($set['table'])
        ));
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
        $ids    = 'activity' === $kind && self::tables_exist('bp_activity') ? self::activity_thread(self::thread_root($parsed['id'])) : [ $parsed['id'] ];
        $sets   = '' === $kind ? [] : self::present_sets($kind, $parsed['id'], $ids);
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
        $touched = [];
        $skipped = [];

        // The rows the write and its hooks created go first, each only while
        // it still belongs to the write.
        foreach ((array) ($data['created_rows'] ?? []) as $name => $created) {
            $name = (string) $name;
            if (! in_array($name, self::TABLES, true) || ! self::tables_exist($name)) {
                continue;
            }
            $ids = [];
            foreach ((array) $created as $entry) {
                $row_id = is_array($entry) ? (int) ($entry['id'] ?? 0) : (int) $entry;
                $row    = $row_id > 0 ? (self::rows([ 'table' => $name, 'column' => 'id', 'ids' => [ $row_id ], 'object_type' => null ])[0] ?? null) : null;
                if (null === $row) {
                    continue; // Already gone.
                }
                // An entry recorded before #372 is a bare id, without owner.
                if (is_array($entry) && ! self::still_owned($row, (array) ($entry['owner'] ?? []))) {
                    $skipped[] = $name . ' #' . $row_id;
                    continue;
                }
                $ids[] = $row_id;
            }
            if ([] === $ids) {
                continue;
            }
            $set                = [ 'table' => $name, 'column' => 'id', 'ids' => $ids, 'object_type' => null ];
            $touched[ $name ][] = self::rows($set);
            self::delete($set);
        }
        foreach ($sets as $set) {
            $set                                 = (array) $set;
            $touched[ (string) $set['table'] ][] = self::rows($set);
            self::delete($set);
        }
        foreach ($sets as $set) {
            $set = (array) $set;
            foreach ((array) ($set['rows'] ?? []) as $row) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- BuddyPress's own table; rows restored verbatim.
                if (false === $wpdb->insert(self::table((string) $set['table']), (array) $row)) {
                    throw new Mutation_Failed('Could not restore a BuddyPress row: ' . esc_html($wpdb->last_error));
                }
            }
            $touched[ (string) $set['table'] ][] = (array) ($set['rows'] ?? []);
        }

        $ids = (array) (((array) $sets[0])['ids'] ?? [ $id ]);
        self::flush($kind, array_map('intval', $ids));
        self::flush_rows(array_map(static fn (array $lists): array => array_merge(...$lists), $touched));

        return [] === $skipped ? null : sprintf(
            'BuddyPress rows this operation created have changed hands since and were left in place: %s.',
            implode(', ', $skipped)
        );
    }

    /**
     * Whether a row still holds the owner columns recorded when the write
     * created it.
     *
     * @param array<string, string|null> $row
     * @param array<string, mixed>       $owner
     */
    private static function still_owned(array $row, array $owner): bool
    {
        foreach ($owner as $column => $value) {
            $now = $row[ (string) $column ] ?? null;
            if ((null === $value) !== (null === $now) || (string) $value !== (string) $now) {
                return false;
            }
        }
        return true;
    }

    /** Delete the rows one set matches. */
    private static function delete(array $set): void
    {
        global $wpdb;

        [$sql, $args] = self::where($set);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- BuddyPress's own table; where() builds only placeholders from an allowlisted table and column.
        if (false === $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE ' . $sql, self::table((string) $set['table']), ...$args))) {
            throw new Mutation_Failed('Could not clear the BuddyPress rows being restored.');
        }
    }

    // -----------------------------------------------------------------
    // Rows a write created
    // -----------------------------------------------------------------

    /**
     * The highest id in every BuddyPress table this type covers that exists,
     * taken just before a write: a row at or below it existed before.
     *
     * @return array<string, int> table => highest id
     */
    public static function watermarks(): array
    {
        global $wpdb;

        $marks = [];
        foreach (self::TABLES as $name) {
            if (self::tables_exist($name)) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- BuddyPress's own table, read live around a write.
                $marks[ $name ] = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(MAX(id), 0) FROM %i', self::table($name)));
            }
        }
        return $marks;
    }

    /**
     * The ids above each watermark: every row added since it was taken, by
     * this request or any other. Only a candidate list; tracked() narrows it
     * to the rows the write created.
     *
     * @param array<string, int> $marks
     * @return array<string, int[]> table => new ids, tables with none left out
     */
    public static function created_since(array $marks): array
    {
        global $wpdb;

        $created = [];
        foreach ($marks as $name => $mark) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- BuddyPress's own table, read live around a write.
            $ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare('SELECT id FROM %i WHERE id > %d ORDER BY id ASC', self::table((string) $name), (int) $mark)));
            if ([] !== $ids) {
                $created[ (string) $name ] = $ids;
            }
        }
        return $created;
    }

    /**
     * Add the rows a write created to its persisted snapshot, so its undo
     * removes them too, each with the columns that say whose it is. For a
     * create, $group names the group BuddyPress wrote (id and slug); when
     * that is not the id reserved for the snapshot, the snapshot is re-keyed
     * on it, so the undo neither misses the new group nor touches whatever
     * now holds the reserved id.
     *
     * @param array<string, int[]>             $created table => ids
     * @param array{id: int, slug: string}|null $group
     */
    public static function record_created(string $operation_id, array $created, ?array $group = null): void
    {
        $row = Snapshot_Store::get_by_operation($operation_id);
        if (null === $row || self::TYPE !== ($row['snapshot']['object_type'] ?? '')) {
            return;
        }
        $snapshot = (array) $row['snapshot'];
        $data     = (array) ($snapshot['data'] ?? []);

        $merged = (array) ($data['created_rows'] ?? []);
        foreach ($created as $name => $ids) {
            $name = (string) $name;
            if (! in_array($name, self::TABLES, true)) {
                continue;
            }
            $entries = [];
            foreach ((array) ($merged[ $name ] ?? []) as $entry) {
                $entry_id             = is_array($entry) ? (int) ($entry['id'] ?? 0) : (int) $entry;
                $entries[ $entry_id ] = $entry;
            }
            $ids  = array_values(array_diff(array_map('intval', (array) $ids), array_keys($entries)));
            $rows = [] === $ids ? [] : self::rows([ 'table' => $name, 'column' => 'id', 'ids' => $ids, 'object_type' => null ]);
            foreach ($rows as $found) {
                $owner = [];
                foreach (self::OWNERS[ $name ] as $column) {
                    $owner[ $column ] = $found[ $column ] ?? null;
                }
                $entries[ (int) $found['id'] ] = [ 'id' => (int) $found['id'], 'owner' => $owner ];
            }
            ksort($entries);
            $merged[ $name ] = array_values($entries);
        }
        $data['created_rows'] = $merged;

        if (null !== $group && 'group_created' === ($data['kind'] ?? '')) {
            $id = (int) $group['id'];
            if ($id > 0 && $id !== (int) ($data['id'] ?? 0)) {
                $sets = self::present_sets('group_created', $id, [ $id ]);
                foreach ($sets as $i => $set) {
                    $sets[ $i ]['rows'] = [];
                }
                $data['id']            = $id;
                $data['sets']          = $sets;
                $snapshot['object_id'] = self::key('group_created', $id);
            }
            $data['created'] = [ 'slug' => (string) $group['slug'] ];
        }

        $snapshot['data'] = $data;
        Snapshot_Store::update_snapshot($operation_id, $snapshot);
    }

    /**
     * Run one write with the rows it created recorded into its snapshot. The
     * write returns its result, or [result, group] for a create that learns
     * its group only once it ran. BuddyPress's own hooks are listened to only
     * while the write runs, and the rows are recorded even when the write
     * throws, so a half-done write stays fully undoable.
     *
     * @param callable(): mixed $write
     * @return mixed the write's result
     */
    public static function tracked(string $operation_id, callable $write, bool $returns_group = false)
    {
        if ('' === $operation_id) {
            $out = $write();
            return $returns_group ? $out[0] : $out;
        }

        $marks     = self::watermarks();
        $actor     = get_current_user_id();
        $reported  = [];
        $listeners = [];
        foreach (self::HOOKS as $hook => $name) {
            $listeners[ $hook ] = static function ($subject = null) use ($name, &$reported): void {
                $id = is_object($subject) ? (int) ($subject->id ?? 0) : (int) $subject;
                if ($id > 0) {
                    $reported[ $name ][ $id ] = true;
                }
            };
            add_action($hook, $listeners[ $hook ], 10, 1);
        }

        $group = null;
        try {
            $out              = $write();
            [$result, $group] = $returns_group ? $out : [ $out, null ];
        } finally {
            foreach ($listeners as $hook => $listener) {
                remove_action($hook, $listener, 10);
            }
            self::record_created($operation_id, self::created_by_write($marks, $reported, $actor, $operation_id), $group);
        }
        return $result;
    }

    /**
     * The rows a write created: those BuddyPress reported through its hooks
     * while the write ran and that were not there before it, plus the rows
     * it inserts without a hook that belong to the write (an option of a
     * field the write touched, the acting user's own last_activity item).
     *
     * @param array<string, int>              $marks    table => highest id before the write
     * @param array<string, array<int, true>> $reported table => ids the hooks reported
     * @return array<string, int[]> table => ids
     */
    private static function created_by_write(array $marks, array $reported, int $actor, string $operation_id): array
    {
        $created = [];
        foreach ($reported as $name => $ids) {
            if (! isset($marks[ $name ])) {
                continue;
            }
            $mark = $marks[ $name ];
            $new  = array_values(array_filter(array_keys($ids), static fn (int $id): bool => $id > $mark));
            if ([] !== $new) {
                $created[ $name ] = $new;
            }
        }

        // Rows BuddyPress inserts without a hook, taken from the id range
        // only when they belong to the write.
        $row    = Snapshot_Store::get_by_operation($operation_id);
        $data   = (array) ($row['snapshot']['data'] ?? []);
        $fields = array_keys($reported['bp_xprofile_fields'] ?? []);
        if ('xprofile_field' === ($data['kind'] ?? '')) {
            $fields[] = (int) ($data['id'] ?? 0);
        }
        $unhooked = [
            'bp_xprofile_fields' => [ 'type' => 'option', 'parent_id' => $fields ],
            'bp_activity'        => [ 'type' => 'last_activity', 'user_id' => $actor > 0 ? [ $actor ] : [] ],
        ];
        foreach ($unhooked as $name => $match) {
            if (! isset($marks[ $name ])) {
                continue;
            }
            $ids = self::owned_since($name, $marks[ $name ], $match);
            if ([] !== $ids) {
                $created[ $name ] = array_values(array_unique(array_merge($created[ $name ] ?? [], $ids)));
            }
        }
        return $created;
    }

    /**
     * The ids above a watermark in one table whose type is $match['type']
     * and whose owner column holds one of the listed ids.
     *
     * @param array{type: string} $match plus one column => int[] owner ids
     * @return int[]
     */
    private static function owned_since(string $name, int $mark, array $match): array
    {
        global $wpdb;

        $type = (string) $match['type'];
        unset($match['type']);
        $column = (string) array_key_first($match);
        $owners = array_values(array_filter(array_map('intval', (array) $match[ $column ]), static fn (int $id): bool => $id > 0));
        if ([] === $owners || ! in_array($column, [ 'parent_id', 'user_id' ], true)) {
            return [];
        }
        $in = implode(',', array_fill(0, count($owners), '%d'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- BuddyPress's own table, read live around a write; the column is allowlisted and $in holds only %d placeholders.
        return array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT id FROM %i WHERE id > %d AND type = %s AND %i IN ($in) ORDER BY id ASC", self::table($name), $mark, $type, $column, ...$owners)));
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
                wp_cache_delete($id, 'xprofile_field_meta');
            }
            wp_cache_delete('all', 'bp_xprofile_groups');
            $reset('bp_xprofile_fields_by_name');
            $reset('bp_xprofile_groups');
        }
    }

    /**
     * Drop BuddyPress's caches of rows a restore removed or put back, beyond
     * what flush() does for the snapshot's own kind: memberships, requests,
     * notifications and created activity, and the member and group counts
     * BuddyPress keeps from them. No-ops for anything BuddyPress is not
     * loaded to recount.
     *
     * @param array<string, array<int, array<string, mixed>>> $touched table => rows
     */
    private static function flush_rows(array $touched): void
    {
        $column = static fn (string $name, string $key): array => array_values(array_unique(array_map('intval', array_column($touched[ $name ] ?? [], $key))));

        if ([] !== ($ids = $column('bp_groups', 'id'))) {
            self::flush('group', $ids);
        }
        if ([] !== ($ids = $column('bp_activity', 'id'))) {
            self::flush('activity', $ids);
        }
        if ([] !== ($ids = $column('bp_xprofile_fields', 'id'))) {
            self::flush('xprofile_field', $ids);
        }
        foreach ($column('bp_groups_members', 'id') as $id) {
            wp_cache_delete($id, 'bp_groups_memberships');
        }
        foreach ($column('bp_groups_members', 'group_id') as $id) {
            self::flush('group', [ $id ]);
        }
        foreach ($column('bp_invitations', 'id') as $id) {
            wp_cache_delete($id, 'bp_invitations');
        }
        if ([] !== $column('bp_invitations', 'id') && function_exists('bp_core_reset_incrementor')) {
            bp_core_reset_incrementor('bp_invitations');
        }
        foreach ($column('bp_notifications', 'id') as $id) {
            wp_cache_delete($id, 'bp_notifications');
        }

        foreach (array_unique(array_merge($column('bp_groups_members', 'user_id'), $column('bp_invitations', 'user_id'))) as $user) {
            wp_cache_delete($user, 'bp_groups_memberships_for_user');
            wp_cache_delete($user, 'bp_groups_invitations_as_memberships');
        }
        if (class_exists('BP_Groups_Member')) {
            foreach ($column('bp_groups_members', 'user_id') as $user) {
                \BP_Groups_Member::refresh_total_group_count_for_user($user);
            }
        }
        foreach ($column('bp_notifications', 'user_id') as $user) {
            if (function_exists('bp_notifications_clear_all_for_user_cache')) {
                bp_notifications_clear_all_for_user_cache($user);
            }
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
        if (! in_array($column, [ 'id', 'group_id', 'activity_id', 'parent_id', 'object_id', 'item_id' ], true)) {
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
        foreach ((array) ($set['match'] ?? []) as $name => $value) {
            if (! in_array($name, [ 'class', 'component_name' ], true)) {
                throw new \InvalidArgumentException('Not a BuddyPress filter column.');
            }
            $sql   .= ' AND %i = %s';
            $args[] = (string) $name;
            $args[] = (string) $value;
        }
        return [ $sql, $args ];
    }
}
