<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\BuddyPress_Rows_Snapshot;
use WPMCP\Safety\Snapshot_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * BuddyPress ops on the plugin-data pair (issue #354): groups, group
 * members, the activity stream and extended profile field definitions.
 *
 * Reads run at the pair's own capability. Hidden groups, and hidden or spam
 * activity, are only listed for a moderator (bp_moderate, which BuddyPress
 * maps onto manage_options). Member email is only returned to a caller with
 * list_users; a membership request's message and activity meta (where an IP
 * or other personal data can sit) are never returned. Activity content is.
 *
 * Writes run at manage_options and are prepared SQL on BuddyPress's own
 * tables, in the shape its models write, followed by the cache flushes its
 * models do. They skip BuddyPress's action hooks (no activity item or
 * notification for a new group). Each is snapshotted first as a
 * 'buddypress_rows' image of every row it touches, so rollback-operation
 * restores exactly: a created group is removed with its meta and the
 * creator's membership, and a deleted activity item returns with its whole
 * reply thread and meta at their own ids. Presence is filterable through
 * wpmcp_buddypress_active; an op whose component tables are missing answers
 * buddypress_component_inactive.
 */
final class BuddyPress_Pack
{
    private const MAX_PER_PAGE = 100;

    private const GROUP_STATUSES = [ 'public', 'private', 'hidden' ];

    private const VISIBILITIES = [ 'public', 'loggedin', 'adminsonly', 'friends' ];

    /** component => [label, tables it needs]. */
    private const COMPONENTS = [
        'groups'   => [ 'Groups', [ 'bp_groups', 'bp_groups_members', 'bp_groups_groupmeta' ] ],
        'activity' => [ 'Activity Streams', [ 'bp_activity', 'bp_activity_meta' ] ],
        'xprofile' => [ 'Extended Profiles', [ 'bp_xprofile_groups', 'bp_xprofile_fields', 'bp_xprofile_meta' ] ],
    ];

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_buddypress_active', function_exists('buddypress'));
    }

    /** @return true|array{code: string, message: string} */
    public static function requirement(string $component)
    {
        if (! self::active()) {
            return [ 'code' => 'buddypress_inactive', 'message' => 'BuddyPress is not active on this site.' ];
        }
        [$label, $tables] = self::COMPONENTS[ $component ];
        if (! BuddyPress_Rows_Snapshot::tables_exist(...$tables)) {
            return [
                'code'    => 'buddypress_component_inactive',
                'message' => sprintf('The BuddyPress %s component is not active (its tables are missing).', $label),
            ];
        }
        return true;
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $groups   = static fn () => self::requirement('groups');
        $activity = static fn () => self::requirement('activity');
        $xprofile = static fn () => self::requirement('xprofile');
        $id_only  = [
            'type'       => 'object',
            'properties' => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
            'required'   => [ 'id' ],
        ];
        $paging   = [
            'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE ],
            'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
        ];
        $row      = static fn (string $kind): \Closure => static fn (array $args): array => [
            'object_type' => BuddyPress_Rows_Snapshot::TYPE,
            'object_id'   => BuddyPress_Rows_Snapshot::key($kind, (int) $args['id']),
        ];

        return [
            'buddypress-list-groups'          => [
                'mode'         => 'read',
                'description'  => 'List BuddyPress groups (id, name, slug, status, member count), newest first; filter by status or search name and description. Hidden groups are listed for moderators only',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'status' => [ 'type' => 'string', 'enum' => self::GROUP_STATUSES ],
                        'search' => [ 'type' => 'string', 'maxLength' => 200 ],
                    ] + $paging,
                ],
                'requires'     => $groups,
                'handler'      => static fn (array $args): array => self::list_groups($args),
            ],
            'buddypress-get-group'            => [
                'mode'         => 'read',
                'description'  => 'Read one BuddyPress group by id, with its last activity time',
                'input_schema' => $id_only,
                'requires'     => $groups,
                'validate'     => static fn (array $args): ?array => self::group_refusal((int) $args['id']),
                'handler'      => static fn (array $args): array => [ 'group' => self::group_view(self::must_group((int) $args['id']), true) ],
            ],
            'buddypress-list-group-members'   => [
                'mode'         => 'read',
                'description'  => 'List a BuddyPress group\'s members (user id, name, role admin/mod/member/banned/pending, title). Email only for callers with list_users',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'group_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                        'role'     => [ 'type' => 'string', 'enum' => [ 'admin', 'mod', 'member', 'banned', 'pending' ] ],
                    ] + $paging,
                    'required'   => [ 'group_id' ],
                ],
                'requires'     => $groups,
                'validate'     => static fn (array $args): ?array => self::group_refusal((int) $args['group_id']),
                'handler'      => static fn (array $args): array => self::list_members($args),
            ],
            'buddypress-list-activity'        => [
                'mode'         => 'read',
                'description'  => 'List BuddyPress activity items newest first, with content; filter by component, type or user_id. Hidden and spam items are listed for moderators only. Email only for callers with list_users; activity meta is never returned',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'component' => [ 'type' => 'string', 'maxLength' => 75 ],
                        'type'      => [ 'type' => 'string', 'maxLength' => 75 ],
                        'user_id'   => [ 'type' => 'integer', 'minimum' => 1 ],
                    ] + $paging,
                ],
                'requires'     => $activity,
                'handler'      => static fn (array $args): array => self::list_activity($args),
            ],
            'buddypress-list-profile-fields'  => [
                'mode'         => 'read',
                'description'  => 'List BuddyPress extended profile field groups and their fields (type, name, required, order, default visibility, options)',
                'input_schema' => [ 'type' => 'object' ],
                'requires'     => $xprofile,
                'handler'      => static fn (): array => [ 'groups' => self::profile_groups() ],
            ],
            'buddypress-create-group'         => [
                'mode'         => 'write',
                'capability'   => 'manage_options',
                'description'  => 'Create a BuddyPress group (name, description, status public/private/hidden, default public; creator_id defaults to you and becomes its admin). The slug is made unique. Snapshotted; rollback-operation removes it',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'name'        => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 100 ],
                        'description' => [ 'type' => 'string' ],
                        'status'      => [ 'type' => 'string', 'enum' => self::GROUP_STATUSES ],
                        'creator_id'  => [ 'type' => 'integer', 'minimum' => 1 ],
                    ],
                    'required'   => [ 'name' ],
                ],
                'requires'     => $groups,
                'validate'     => static fn (array $args): ?array => self::create_refusal($args),
                'snapshot'     => static fn (array $args): array => self::create_target($args),
                'handler'      => static fn (array $args, array $context): array => self::create_group($args, $context),
            ],
            'buddypress-update-group'         => [
                'mode'         => 'write',
                'capability'   => 'manage_options',
                'description'  => 'Change a BuddyPress group\'s name, description or status; only the fields passed change and the slug is kept. Snapshotted and reversible',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'          => [ 'type' => 'integer', 'minimum' => 1 ],
                        'name'        => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 100 ],
                        'description' => [ 'type' => 'string' ],
                        'status'      => [ 'type' => 'string', 'enum' => self::GROUP_STATUSES ],
                    ],
                    'required'   => [ 'id' ],
                ],
                'requires'     => $groups,
                'validate'     => static fn (array $args): ?array => self::group_refusal((int) $args['id']) ?? self::name_refusal($args),
                'snapshot'     => $row('group'),
                'handler'      => static fn (array $args): array => self::update_group($args),
            ],
            'buddypress-update-profile-field' => [
                'mode'         => 'write',
                'capability'   => 'manage_options',
                'description'  => 'Change a BuddyPress profile field\'s name, description, is_required, field_order or default_visibility (public/loggedin/adminsonly/friends); only the fields passed change. Snapshotted and reversible',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'                 => [ 'type' => 'integer', 'minimum' => 1 ],
                        'name'               => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 150 ],
                        'description'        => [ 'type' => 'string' ],
                        'is_required'        => [ 'type' => 'boolean' ],
                        'field_order'        => [ 'type' => 'integer', 'minimum' => 0 ],
                        'default_visibility' => [ 'type' => 'string', 'enum' => self::VISIBILITIES ],
                    ],
                    'required'   => [ 'id' ],
                ],
                'requires'     => $xprofile,
                'validate'     => static fn (array $args): ?array => self::field_refusal((int) $args['id']) ?? self::name_refusal($args),
                'snapshot'     => $row('xprofile_field'),
                'handler'      => static fn (array $args): array => self::update_field($args),
            ],
            'buddypress-hide-activity'        => [
                'mode'         => 'write',
                'capability'   => 'manage_options',
                'description'  => 'Hide a BuddyPress activity item from the site-wide stream (hidden:false shows it again). Snapshotted and reversible',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'     => [ 'type' => 'integer', 'minimum' => 1 ],
                        'hidden' => [ 'type' => 'boolean' ],
                    ],
                    'required'   => [ 'id' ],
                ],
                'requires'     => $activity,
                'validate'     => static fn (array $args): ?array => self::activity_refusal((int) $args['id']),
                'snapshot'     => $row('activity'),
                'handler'      => static fn (array $args): array => self::hide_activity((int) $args['id'], (bool) ($args['hidden'] ?? true)),
            ],
            'buddypress-delete-activity'      => [
                'mode'         => 'destructive',
                'capability'   => 'manage_options',
                'description'  => 'Delete a BuddyPress activity item with every reply under it and their meta. All rows are snapshotted, so rollback-operation brings them back at their own ids; hide keeps them instead',
                'input_schema' => $id_only,
                'requires'     => $activity,
                'validate'     => static fn (array $args): ?array => self::activity_refusal((int) $args['id']),
                'snapshot'     => $row('activity'),
                'handler'      => static fn (array $args): array => self::delete_activity((int) $args['id']),
            ],
        ];
    }

    // -----------------------------------------------------------------
    // Access
    // -----------------------------------------------------------------

    /** Whether the current user moderates BuddyPress (sees hidden groups and hidden or spam activity). */
    private static function is_moderator(): bool
    {
        return current_user_can('bp_moderate') || current_user_can('manage_options');
    }

    /** Whether the current user may see member email addresses. */
    private static function sees_email(): bool
    {
        return current_user_can('list_users');
    }

    /**
     * A user as returned to the caller: id and display name, plus email only
     * for a caller with list_users.
     *
     * @param array<int, \WP_User|false> $cache
     */
    private static function user_view(int $user_id, array &$cache): array
    {
        if (! array_key_exists($user_id, $cache)) {
            $cache[ $user_id ] = $user_id > 0 ? get_userdata($user_id) : false;
        }
        $user = $cache[ $user_id ];
        $out  = [ 'id' => $user_id, 'name' => $user instanceof \WP_User ? (string) $user->display_name : '' ];
        if (self::sees_email()) {
            $out['email'] = $user instanceof \WP_User ? (string) $user->user_email : null;
        }
        return $out;
    }

    // -----------------------------------------------------------------
    // Groups
    // -----------------------------------------------------------------

    private static function group_row(int $id): ?array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- BuddyPress's own table, read live.
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', BuddyPress_Rows_Snapshot::table('bp_groups'), $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private static function must_group(int $id): array
    {
        $row = self::group_row($id);
        if (null === $row) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
            throw new Operation_Error('group_not_found', sprintf('BuddyPress group %d does not exist.', $id));
        }
        return $row;
    }

    /** @return array{code: string, message: string, data: array}|null */
    private static function group_refusal(int $id): ?array
    {
        $row = self::group_row($id);
        if (null === $row || ('hidden' === $row['status'] && ! self::is_moderator())) {
            return [ 'code' => 'group_not_found', 'message' => sprintf('BuddyPress group %d does not exist.', $id), 'data' => [] ];
        }
        return null;
    }

    /** @return array{code: string, message: string, data: array}|null */
    private static function name_refusal(array $args): ?array
    {
        if (isset($args['name']) && '' === sanitize_text_field((string) $args['name'])) {
            return [ 'code' => 'invalid_name', 'message' => 'The name is empty once markup is removed.', 'data' => [] ];
        }
        return null;
    }

    private static function group_view(array $row, bool $full = false): array
    {
        global $wpdb;

        $id = (int) $row['id'];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- BuddyPress's own table, read live.
        $count = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE group_id = %d AND is_confirmed = 1 AND is_banned = 0', BuddyPress_Rows_Snapshot::table('bp_groups_members'), $id));
        $out   = [
            'id'           => $id,
            'name'         => (string) $row['name'],
            'slug'         => (string) $row['slug'],
            'description'  => (string) $row['description'],
            'status'       => (string) $row['status'],
            'parent_id'    => (int) $row['parent_id'],
            'creator_id'   => (int) $row['creator_id'],
            'enable_forum' => 1 === (int) $row['enable_forum'],
            'date_created' => (string) $row['date_created'],
            'member_count' => $count,
        ];
        if ($full) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- BuddyPress's own table; one allowlisted meta key only.
            $last                 = $wpdb->get_var($wpdb->prepare('SELECT meta_value FROM %i WHERE group_id = %d AND meta_key = %s ORDER BY id DESC LIMIT 1', BuddyPress_Rows_Snapshot::table('bp_groups_groupmeta'), $id, 'last_activity'));
            $out['last_activity'] = null === $last ? null : (string) $last;
        }
        return $out;
    }

    private static function list_groups(array $args): array
    {
        global $wpdb;

        $where = [ '1=1' ];
        $vals  = [];
        if (isset($args['status'])) {
            $where[] = 'status = %s';
            $vals[]  = (string) $args['status'];
        }
        if (! self::is_moderator()) {
            $where[] = "status <> 'hidden'";
        }
        if (isset($args['search']) && '' !== (string) $args['search']) {
            $like    = '%' . $wpdb->esc_like((string) $args['search']) . '%';
            $where[] = '(name LIKE %s OR description LIKE %s)';
            array_push($vals, $like, $like);
        }
        [$per_page, $offset] = self::paging($args);
        $table               = BuddyPress_Rows_Snapshot::table('bp_groups');
        $sql                 = implode(' AND ', $where);

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- BuddyPress's own table; $sql holds only literals and placeholders, matched by the spread values.
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE {$sql}", $table, ...$vals));
        $rows  = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE {$sql} ORDER BY date_created DESC, id DESC LIMIT %d OFFSET %d", $table, ...array_merge($vals, [ $per_page, $offset ])), ARRAY_A);
        // phpcs:enable

        return [
            'total'  => $total,
            'groups' => array_map(static fn (array $row): array => self::group_view($row), $rows),
        ];
    }

    private static function list_members(array $args): array
    {
        global $wpdb;

        $group = (int) $args['group_id'];
        $roles = [
            'admin'   => 'is_admin = 1 AND is_banned = 0',
            'mod'     => 'is_mod = 1 AND is_banned = 0',
            'member'  => 'is_admin = 0 AND is_mod = 0 AND is_confirmed = 1 AND is_banned = 0',
            'banned'  => 'is_banned = 1',
            'pending' => 'is_confirmed = 0 AND is_banned = 0',
        ];
        $sql                 = 'group_id = %d' . (isset($args['role']) ? ' AND ' . $roles[ (string) $args['role'] ] : '');
        [$per_page, $offset] = self::paging($args);
        $table               = BuddyPress_Rows_Snapshot::table('bp_groups_members');

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- BuddyPress's own table; $sql holds only literals and placeholders, matched by the spread values.
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE {$sql}", $table, $group));
        $rows  = (array) $wpdb->get_results($wpdb->prepare("SELECT id, user_id, is_admin, is_mod, user_title, date_modified, is_confirmed, is_banned FROM %i WHERE {$sql} ORDER BY id ASC LIMIT %d OFFSET %d", $table, $group, $per_page, $offset), ARRAY_A);
        // phpcs:enable

        $users   = [];
        $members = [];
        foreach ($rows as $row) {
            $user = self::user_view((int) $row['user_id'], $users);
            $role = 'member';
            if (1 === (int) $row['is_banned']) {
                $role = 'banned';
            } elseif (0 === (int) $row['is_confirmed']) {
                $role = 'pending';
            } elseif (1 === (int) $row['is_admin']) {
                $role = 'admin';
            } elseif (1 === (int) $row['is_mod']) {
                $role = 'mod';
            }
            $members[] = [
                'user_id'       => $user['id'],
                'name'          => $user['name'],
                'role'          => $role,
                'title'         => (string) $row['user_title'],
                'date_modified' => (string) $row['date_modified'],
            ] + (array_key_exists('email', $user) ? [ 'email' => $user['email'] ] : []);
        }

        return [ 'group_id' => $group, 'total' => $total, 'members' => $members ];
    }

    /** @return array{code: string, message: string, data: array}|null */
    private static function create_refusal(array $args): ?array
    {
        $refusal = self::name_refusal($args);
        if (null !== $refusal) {
            return $refusal;
        }
        if (isset($args['creator_id']) && ! get_userdata((int) $args['creator_id'])) {
            return [ 'code' => 'user_not_found', 'message' => sprintf('User %d does not exist.', (int) $args['creator_id']), 'data' => [] ];
        }
        if (! isset($args['creator_id']) && get_current_user_id() <= 0) {
            return [ 'code' => 'user_not_found', 'message' => 'A group needs a creator: pass creator_id.', 'data' => [] ];
        }
        return null;
    }

    /**
     * The create's snapshot target: the reserved id, plus the slug the create
     * is about to write, so the undo removes the group only while it is still
     * this group.
     */
    private static function create_target(array $args): array
    {
        $slug = self::unique_slug(sanitize_text_field((string) $args['name']));
        return [
            'object_type'         => BuddyPress_Rows_Snapshot::TYPE,
            'object_id'           => BuddyPress_Rows_Snapshot::key('group_created', self::next_group_id()),
            'extra_snapshot_data' => [ 'created' => [ 'slug' => $slug ] ],
        ];
    }

    private static function create_group(array $args, array $context): array
    {
        global $wpdb;

        $target = (array) ($context['target'] ?? []);
        $id     = BuddyPress_Rows_Snapshot::parse((string) ($target['object_id'] ?? ''))['id'];
        $slug   = (string) ($target['extra_snapshot_data']['created']['slug'] ?? '');
        $now    = current_time('mysql', true);

        try {
            if ($id <= 0 || '' === $slug) {
                throw new Operation_Refused('write_failed', 'Could not reserve an id for the group.');
            }
            $creator = (int) ($args['creator_id'] ?? get_current_user_id());
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- BuddyPress's own tables; the id was reserved before the snapshot so the undo removes exactly this group.
            $ok = $wpdb->insert(BuddyPress_Rows_Snapshot::table('bp_groups'), [
                'id'           => $id,
                'creator_id'   => $creator,
                'name'         => sanitize_text_field((string) $args['name']),
                'slug'         => $slug,
                'description'  => wp_kses_data((string) ($args['description'] ?? '')),
                'status'       => (string) ($args['status'] ?? 'public'),
                'parent_id'    => 0,
                'enable_forum' => 0,
                'date_created' => $now,
            ]);
            if (false === $ok) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
                throw new Operation_Refused('write_failed', 'Could not create the group: ' . $wpdb->last_error);
            }
        } catch (\Throwable $e) {
            // Nothing was written (another create may have taken the reserved
            // id), but the snapshot is already persisted. Left in place,
            // rolling it back would remove whatever group now holds the id.
            if ('' !== (string) ($context['operation_id'] ?? '')) {
                Snapshot_Store::delete_operation((string) $context['operation_id']);
            }
            throw $e;
        }

        // The creator becomes the first member and admin, as
        // groups_create_group() does, and the member count and last
        // activity meta BP_Groups_Member::save() keeps are set.
        $wpdb->insert(BuddyPress_Rows_Snapshot::table('bp_groups_members'), [
            'group_id'      => $id,
            'user_id'       => $creator,
            'inviter_id'    => 0,
            'is_admin'      => 1,
            'is_mod'        => 0,
            'user_title'    => 'Group Admin',
            'date_modified' => $now,
            'comments'      => '',
            'is_confirmed'  => 1,
            'is_banned'     => 0,
            'invite_sent'   => 0,
        ]);
        foreach ([ 'total_member_count' => '1', 'last_activity' => $now ] as $key => $value) {
            $wpdb->insert(BuddyPress_Rows_Snapshot::table('bp_groups_groupmeta'), [ 'group_id' => $id, 'meta_key' => $key, 'meta_value' => $value ]);
        }
        // phpcs:enable
        BuddyPress_Rows_Snapshot::flush('group_created', [ $id ]);

        return [ 'group' => self::group_view(self::must_group($id), true) ];
    }

    private static function update_group(array $args): array
    {
        global $wpdb;

        $id     = (int) $args['id'];
        $fields = [];
        if (isset($args['name'])) {
            $fields['name'] = sanitize_text_field((string) $args['name']);
        }
        if (isset($args['description'])) {
            $fields['description'] = wp_kses_data((string) $args['description']);
        }
        if (isset($args['status'])) {
            $fields['status'] = (string) $args['status'];
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- BuddyPress's own table; snapshotted by the dispatcher first.
        if ([] !== $fields && false === $wpdb->update(BuddyPress_Rows_Snapshot::table('bp_groups'), $fields, [ 'id' => $id ])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
            throw new Operation_Refused('write_failed', 'Could not update the group: ' . $wpdb->last_error);
        }
        BuddyPress_Rows_Snapshot::flush('group', [ $id ]);

        return [ 'group' => self::group_view(self::must_group($id), true), 'changed' => array_keys($fields) ];
    }

    /** A slug no other group has, from the name, the way groups_check_slug() makes one. */
    private static function unique_slug(string $name): string
    {
        global $wpdb;

        $base = sanitize_title($name);
        $base = '' === $base ? 'group' : $base;
        $slug = $base;
        $n    = 1;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- BuddyPress's own table, read live.
        while (null !== $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE slug = %s', BuddyPress_Rows_Snapshot::table('bp_groups'), $slug))) {
            ++$n;
            $slug = $base . '-' . $n;
        }
        return $slug;
    }

    private static function next_group_id(): int
    {
        global $wpdb;

        $table = BuddyPress_Rows_Snapshot::table('bp_groups');
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- live id reservation on BuddyPress's own table.
        $max    = (int) $wpdb->get_var($wpdb->prepare('SELECT MAX(id) FROM %i', $table));
        $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $table), ARRAY_A);
        // phpcs:enable
        $auto = is_array($status) ? (int) ($status['Auto_increment'] ?? 0) : 0;

        return max($max + 1, $auto);
    }

    // -----------------------------------------------------------------
    // Activity
    // -----------------------------------------------------------------

    private static function activity_row(int $id): ?array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- BuddyPress's own table, read live.
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', BuddyPress_Rows_Snapshot::table('bp_activity'), $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @return array{code: string, message: string, data: array}|null */
    private static function activity_refusal(int $id): ?array
    {
        return null === self::activity_row($id)
            ? [ 'code' => 'activity_not_found', 'message' => sprintf('BuddyPress activity item %d does not exist.', $id), 'data' => [] ]
            : null;
    }

    /**
     * An activity item as returned to the caller: its content and public
     * columns, the author as user_view(), never its meta.
     *
     * @param array<int, \WP_User|false> $users
     */
    private static function activity_view(array $row, array &$users): array
    {
        return [
            'id'                => (int) $row['id'],
            'user'              => self::user_view((int) $row['user_id'], $users),
            'component'         => (string) $row['component'],
            'type'              => (string) $row['type'],
            'action'            => (string) $row['action'],
            'content'           => (string) $row['content'],
            'primary_link'      => (string) $row['primary_link'],
            'item_id'           => (int) $row['item_id'],
            'secondary_item_id' => (int) $row['secondary_item_id'],
            'date_recorded'     => (string) $row['date_recorded'],
            'hidden'            => 1 === (int) $row['hide_sitewide'],
            'spam'              => 1 === (int) $row['is_spam'],
        ];
    }

    private static function list_activity(array $args): array
    {
        global $wpdb;

        $where = [ '1=1' ];
        $vals  = [];
        foreach ([ 'component' => '%s', 'type' => '%s', 'user_id' => '%d' ] as $column => $format) {
            if (isset($args[ $column ])) {
                $where[] = "{$column} = {$format}";
                $vals[]  = $args[ $column ];
            }
        }
        if (! self::is_moderator()) {
            $where[] = 'hide_sitewide = 0 AND is_spam = 0';
        }
        [$per_page, $offset] = self::paging($args);
        $table               = BuddyPress_Rows_Snapshot::table('bp_activity');
        $sql                 = implode(' AND ', $where);

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- BuddyPress's own table; $sql holds only literals and placeholders, matched by the spread values.
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE {$sql}", $table, ...$vals));
        $rows  = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE {$sql} ORDER BY date_recorded DESC, id DESC LIMIT %d OFFSET %d", $table, ...array_merge($vals, [ $per_page, $offset ])), ARRAY_A);
        // phpcs:enable

        $users = [];
        $items = [];
        foreach ($rows as $row) {
            $items[] = self::activity_view($row, $users);
        }
        return [ 'total' => $total, 'activity' => $items ];
    }

    private static function hide_activity(int $id, bool $hidden): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- BuddyPress's own table; snapshotted by the dispatcher first.
        if (false === $wpdb->update(BuddyPress_Rows_Snapshot::table('bp_activity'), [ 'hide_sitewide' => $hidden ? 1 : 0 ], [ 'id' => $id ])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
            throw new Operation_Refused('write_failed', 'Could not change the activity item: ' . $wpdb->last_error);
        }
        BuddyPress_Rows_Snapshot::flush('activity', [ $id ]);

        $users = [];
        return [ 'activity' => self::activity_view((array) self::activity_row($id), $users) ];
    }

    private static function delete_activity(int $id): array
    {
        global $wpdb;

        $ids = BuddyPress_Rows_Snapshot::activity_thread($id);
        $in  = implode(',', array_fill(0, count($ids), '%d'));
        foreach ([ 'bp_activity_meta' => 'activity_id', 'bp_activity' => 'id' ] as $table => $column) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- BuddyPress's own tables; every row was snapshotted by the dispatcher first and $in holds only %d placeholders.
            if (false === $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE {$column} IN ($in)", BuddyPress_Rows_Snapshot::table($table), ...$ids))) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
                throw new Operation_Refused('write_failed', 'Could not delete the activity item: ' . $wpdb->last_error);
            }
        }
        BuddyPress_Rows_Snapshot::flush('activity', $ids);

        return [ 'deleted' => $ids ];
    }

    // -----------------------------------------------------------------
    // Extended profile fields
    // -----------------------------------------------------------------

    /** @return array<int, string> field id => default visibility */
    private static function visibilities(): array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- BuddyPress's own table, read live.
        $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT object_id, meta_value FROM %i WHERE object_type = %s AND meta_key = %s ORDER BY id ASC', BuddyPress_Rows_Snapshot::table('bp_xprofile_meta'), 'field', 'default_visibility'), ARRAY_A);
        $out  = [];
        foreach ($rows as $row) {
            $out[ (int) $row['object_id'] ] = (string) $row['meta_value'];
        }
        return $out;
    }

    private static function field_view(array $row, array $options, array $visibilities): array
    {
        $id = (int) $row['id'];
        return [
            'id'                 => $id,
            'group_id'           => (int) $row['group_id'],
            'type'               => (string) $row['type'],
            'name'               => (string) $row['name'],
            'description'        => (string) $row['description'],
            'is_required'        => 1 === (int) $row['is_required'],
            'field_order'        => (int) $row['field_order'],
            'can_delete'         => 1 === (int) $row['can_delete'],
            'default_visibility' => $visibilities[ $id ] ?? 'public',
            'options'            => array_map(static fn (array $option): array => [
                'id'         => (int) $option['id'],
                'name'       => (string) $option['name'],
                'is_default' => 1 === (int) $option['is_default_option'],
                'order'      => (int) $option['option_order'],
            ], $options),
        ];
    }

    private static function profile_groups(): array
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- BuddyPress's own tables, read live.
        $groups = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY group_order ASC, id ASC', BuddyPress_Rows_Snapshot::table('bp_xprofile_groups')), ARRAY_A);
        $fields = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY field_order ASC, option_order ASC, id ASC', BuddyPress_Rows_Snapshot::table('bp_xprofile_fields')), ARRAY_A);
        // phpcs:enable
        $visibilities = self::visibilities();

        $options = [];
        foreach ($fields as $field) {
            if (0 !== (int) $field['parent_id']) {
                $options[ (int) $field['parent_id'] ][] = $field;
            }
        }
        foreach ($options as $parent => $list) {
            usort($list, static fn (array $a, array $b): int => [ (int) $a['option_order'], (int) $a['id'] ] <=> [ (int) $b['option_order'], (int) $b['id'] ]);
            $options[ $parent ] = $list;
        }

        $by_group = [];
        foreach ($fields as $field) {
            if (0 === (int) $field['parent_id']) {
                $by_group[ (int) $field['group_id'] ][] = self::field_view($field, $options[ (int) $field['id'] ] ?? [], $visibilities);
            }
        }

        return array_map(static fn (array $group): array => [
            'id'          => (int) $group['id'],
            'name'        => (string) $group['name'],
            'description' => (string) $group['description'],
            'group_order' => (int) $group['group_order'],
            'can_delete'  => 1 === (int) $group['can_delete'],
            'fields'      => $by_group[ (int) $group['id'] ] ?? [],
        ], $groups);
    }

    private static function field_row(int $id): ?array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- BuddyPress's own table, read live.
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d AND parent_id = 0', BuddyPress_Rows_Snapshot::table('bp_xprofile_fields'), $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @return array{code: string, message: string, data: array}|null */
    private static function field_refusal(int $id): ?array
    {
        return null === self::field_row($id)
            ? [ 'code' => 'field_not_found', 'message' => sprintf('%d is not a BuddyPress profile field (options are edited through their field).', $id), 'data' => [] ]
            : null;
    }

    private static function update_field(array $args): array
    {
        global $wpdb;

        $id     = (int) $args['id'];
        $fields = [];
        if (isset($args['name'])) {
            $fields['name'] = sanitize_text_field((string) $args['name']);
        }
        if (isset($args['description'])) {
            $fields['description'] = wp_kses_data((string) $args['description']);
        }
        if (isset($args['is_required'])) {
            $fields['is_required'] = $args['is_required'] ? 1 : 0;
        }
        if (isset($args['field_order'])) {
            $fields['field_order'] = (int) $args['field_order'];
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- BuddyPress's own tables (meta rows keyed by object, not a meta query); snapshotted by the dispatcher first.
        if ([] !== $fields && false === $wpdb->update(BuddyPress_Rows_Snapshot::table('bp_xprofile_fields'), $fields, [ 'id' => $id ])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
            throw new Operation_Refused('write_failed', 'Could not update the profile field: ' . $wpdb->last_error);
        }
        if (isset($args['default_visibility'])) {
            $meta     = BuddyPress_Rows_Snapshot::table('bp_xprofile_meta');
            $existing = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE object_id = %d AND object_type = %s AND meta_key = %s ORDER BY id ASC LIMIT 1', $meta, $id, 'field', 'default_visibility'));
            $value    = (string) $args['default_visibility'];
            $ok       = null === $existing
                ? $wpdb->insert($meta, [ 'object_id' => $id, 'object_type' => 'field', 'meta_key' => 'default_visibility', 'meta_value' => $value ])
                : $wpdb->update($meta, [ 'meta_value' => $value ], [ 'id' => (int) $existing ]);
            if (false === $ok) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
                throw new Operation_Refused('write_failed', 'Could not update the profile field visibility: ' . $wpdb->last_error);
            }
            $fields['default_visibility'] = $value;
        }
        // phpcs:enable
        BuddyPress_Rows_Snapshot::flush('xprofile_field', [ $id ]);

        $row = (array) self::field_row($id);
        wp_cache_delete((int) $row['group_id'], 'bp_xprofile_groups');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- BuddyPress's own table, read live.
        $options = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE parent_id = %d ORDER BY option_order ASC, id ASC', BuddyPress_Rows_Snapshot::table('bp_xprofile_fields'), $id), ARRAY_A);

        return [ 'field' => self::field_view($row, $options, self::visibilities()), 'changed' => array_keys($fields) ];
    }

    // -----------------------------------------------------------------
    // Shared
    // -----------------------------------------------------------------

    /** @return array{0: int, 1: int} per_page and offset */
    private static function paging(array $args): array
    {
        $per_page = (int) ($args['per_page'] ?? 20);
        $page     = (int) ($args['page'] ?? 1);
        return [ $per_page, ($page - 1) * $per_page ];
    }
}
