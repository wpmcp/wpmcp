<?php

namespace WPMCP\Integrations;

use WPMCP\Privacy\Masking;
use WPMCP\Safety\Plugin_Table_Rows_Snapshot;
use WPMCP\Safety\Snapshot_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Paid Memberships Pro members, orders, discount codes and writes (issue
 * #667), on the existing pmpro-read / pmpro-write pair, so no top-level tool
 * is added. Every op here is paid-license only and takes manage_options, the
 * capability PMPro's own admin screens fall back to.
 *
 * PMPro (detected by PMPRO_VERSION, filterable through wpmcp_pmpro_active)
 * keeps its data in tables of its own (includes/upgradecheck.php in 3.2.2):
 * pmpro_membership_levels, pmpro_memberships_users (one row per membership
 * a user held, status active or how it ended), pmpro_membership_orders,
 * pmpro_discount_codes with pmpro_discount_codes_levels (per level pricing)
 * and pmpro_discount_codes_uses, pmpro_subscriptions, and since 3.0 the
 * level groups (pmpro_groups, pmpro_membership_levels_groups).
 *
 * Reads: list-members (login, name and email masked), get-member (full
 * values, every membership row, subscription and order counts), list-orders
 * (billing name masked; card, address, phone and notes left out) and
 * list-discount-codes (with level pricing and the times each was used).
 *
 * Writes (off until the site opts in with wpmcp_enable_pmpro_write):
 *  - create-level and update-level write the one level row the way PMPro's
 *    level screen does (adminpages/levels/save-level.php), put a new level
 *    in a level group (the one named, else the first), and fire
 *    pmpro_save_membership_level. Snapshotted as a plugin_table_rows image
 *    (kind pmpro_levels, the group row as a companion): an update goes back
 *    exactly and a create, whose id is reserved first, is removed again.
 *  - change-member-level runs PMPro's own pmpro_changeMembershipLevel() as
 *    an admin change, so PMPro ends the user's other levels in the group and
 *    its add-ons see the change. PMPro may also cancel the user's
 *    subscription at the payment gateway, which nothing can undo, so the op
 *    is destructive, needs confirm:true in its own arguments after a preview
 *    naming the levels and subscriptions involved, and answers
 *    recoverable:false. The user's membership rows are still snapshotted
 *    (kind pmpro_members, keyed by user), so rollback-operation puts them
 *    back.
 *  - create-discount-code writes the code row and its level pricing rows
 *    the way PMPro's discount code screen does (adminpages/discountcodes.php)
 *    and fires its pmpro_save_discount_code_level and
 *    pmpro_save_discount_code actions. Snapshotted as kind pmpro_codes (the
 *    pricing rows as companions), so rollback removes both.
 */
final class Paid_Memberships_Pro_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads and writes PMPro's own tables live; it has no cached read API for them.
    // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- PMPro's own hooks, fired so its add-ons see the write.

    public const CAPABILITY = 'manage_options';

    public const OPT_IN_FILTER = 'wpmcp_enable_pmpro_write';

    public const PLUGIN = 'Paid Memberships Pro';

    public const DEFAULT_PAGE_SIZE = 20;

    public const MAX_PAGE_SIZE = 100;

    /** The billing periods PMPro's level screen offers. */
    private const PERIODS = [ 'Day', 'Week', 'Month', 'Year' ];

    /** Membership statuses PMPro writes to pmpro_memberships_users. */
    private const MEMBER_STATUSES = [ 'active', 'inactive', 'cancelled', 'admin_cancelled', 'changed', 'admin_changed', 'expired', 'token', 'review' ];

    /** Level columns an op may set, as PMPro's level screen saves them. */
    private const LEVEL_TEXT = [ 'name', 'description', 'confirmation' ];

    private const LEVEL_MONEY = [ 'initial_payment', 'billing_amount', 'trial_amount' ];

    private const LEVEL_INT = [ 'cycle_number', 'billing_limit', 'trial_limit', 'expiration_number' ];

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_pmpro_active', defined('PMPRO_VERSION'));
    }

    public static function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'pmpro_' . $name;
    }

    /**
     * Whether PMPro is active and the tables an op reads exist.
     *
     * @param string[] $tables
     * @return true|array{code: string, message: string, data: array}
     */
    public static function presence(array $tables = [ 'membership_levels', 'memberships_users' ])
    {
        if (! self::active()) {
            $refusal = Plugin_Refusal::make('plugin_missing', self::PLUGIN);
            if ('inactive' === ($refusal['data']['state'] ?? '')) {
                $refusal['code'] = 'plugin_inactive';
            }
            return $refusal;
        }
        foreach ($tables as $name) {
            if (! Plugin_Table_Rows_Snapshot::table_exists(self::table($name))) {
                return [
                    'code'    => 'pmpro_tables_missing',
                    'message' => sprintf('Paid Memberships Pro\'s table %s does not exist on this site. Deactivate and reactivate Paid Memberships Pro so it creates its tables, then try again.', self::table($name)),
                    'data'    => [ 'plugins_url' => admin_url('plugins.php') ],
                ];
            }
        }
        return true;
    }

    // ------------------------------------------------------------------
    // Catalog
    // ------------------------------------------------------------------

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $id     = [ 'type' => 'integer', 'minimum' => 1 ];
        $money  = [ 'type' => 'number', 'minimum' => 0 ];
        $count  = [ 'type' => 'integer', 'minimum' => 0 ];
        $date   = [ 'type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$' ];
        $paging = [
            'page_size' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE_SIZE ],
            'offset'    => [ 'type' => 'integer', 'minimum' => 0 ],
        ];
        $level  = [
            'name'              => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 255 ],
            'description'       => [ 'type' => 'string' ],
            'confirmation'      => [ 'type' => 'string' ],
            'initial_payment'   => $money,
            'billing_amount'    => $money,
            'cycle_number'      => $count,
            'cycle_period'      => [ 'type' => 'string', 'enum' => self::PERIODS ],
            'billing_limit'     => $count,
            'trial_amount'      => $money,
            'trial_limit'       => $count,
            'expiration_number' => $count,
            'expiration_period' => [ 'type' => 'string', 'enum' => self::PERIODS ],
            'allow_signups'     => [ 'type' => 'boolean' ],
        ];
        $pricing = [
            'type'                 => 'object',
            'properties'           => [ 'level_id' => $id ] + array_intersect_key($level, array_flip([ 'initial_payment', 'billing_amount', 'cycle_number', 'cycle_period', 'billing_limit', 'trial_amount', 'trial_limit', 'expiration_number', 'expiration_period' ])),
            'required'             => [ 'level_id' ],
            'additionalProperties' => false,
        ];
        $base  = [
            'tier'       => 'pro',
            'capability' => self::CAPABILITY,
            'requires'   => static fn () => self::presence(),
        ];
        $write = $base + [
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_pmpro_write', false),
            'opt_in_filter'      => self::OPT_IN_FILTER,
        ];
        $user_read  = [ 'user_id' => [ 'type' => 'user', 'access' => 'read' ] ];
        $level_rows = static fn (array $ids): array => [
            'object_type' => Plugin_Table_Rows_Snapshot::TYPE,
            'object_id'   => Plugin_Table_Rows_Snapshot::key('pmpro_levels', 'membership_levels', $ids),
        ];

        return [
            'list-members'         => $base + [
                'mode'         => 'read',
                'description'  => 'List Paid Memberships Pro membership rows newest first: row id, user id, login, display name and email (all three MASKED, masked: true; get-member has full values), level id and name, status, start and end date, price. status: active (default), any, or one PMPro status (cancelled, expired, changed, admin_changed, ...); level_id and search (login, email or name) filter; page_size (max 100) and offset',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'level_id' => $id,
                        'status'   => [ 'type' => 'string', 'enum' => array_merge([ 'any' ], self::MEMBER_STATUSES) ],
                        'search'   => [ 'type' => 'string', 'maxLength' => 200 ],
                    ] + $paging,
                ],
                'handler'      => static fn (array $args): array => self::list_members($args),
            ],
            'get-member'           => $base + [
                'mode'         => 'read',
                'objects'      => $user_read,
                'description'  => 'One Paid Memberships Pro member in full: login, email, display name, active levels, every membership row (history, newest first), and counts of active subscriptions and orders',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'user_id' => $id ], 'required' => [ 'user_id' ] ],
                'validate'     => static fn (array $args): ?array => self::user_refusal((int) $args['user_id']),
                'handler'      => static fn (array $args): array => [ 'member' => self::member((int) $args['user_id']) ],
            ],
            'list-orders'          => $base + [
                'requires'     => static fn () => self::presence([ 'membership_levels', 'membership_orders' ]),
                'mode'         => 'read',
                'objects'      => $user_read,
                'description'  => 'List Paid Memberships Pro orders newest first: id, code, user, level, subtotal, tax, total, status, gateway and environment, payment type, transaction ids, date, billing country and billing name (MASKED, masked: true). Card, address, phone and notes are left out. Filters: status (success, refunded, pending, ...), level_id, user_id; page_size and offset. Read-only: no refund or charge',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'status'   => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 32 ],
                        'level_id' => $id,
                        'user_id'  => $id,
                    ] + $paging,
                ],
                'handler'      => static fn (array $args): array => self::list_orders($args),
            ],
            'list-discount-codes'  => $base + [
                'requires'     => static fn () => self::presence([ 'membership_levels', 'discount_codes', 'discount_codes_levels', 'discount_codes_uses' ]),
                'mode'         => 'read',
                'description'  => 'List Paid Memberships Pro discount codes newest first: id, code, starts, expires, allowed uses (0 is unlimited), times used, and per level pricing. search matches the code; page_size and offset',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'search' => [ 'type' => 'string', 'maxLength' => 32 ] ] + $paging,
                ],
                'handler'      => static fn (array $args): array => self::list_codes($args),
            ],
            'create-level'         => $write + [
                'mode'         => 'write',
                'description'  => 'Create a Paid Memberships Pro membership level: name (required), description, confirmation, initial_payment, recurring billing_amount every cycle_number cycle_period (Day, Week, Month, Year), billing_limit, trial_amount and trial_limit, expiration_number and expiration_period, allow_signups (default true), group_id (default: the first level group). Undo: rollback-operation removes it',
                'input_schema' => [ 'type' => 'object', 'properties' => $level + [ 'group_id' => $id ], 'required' => [ 'name' ] ],
                'validate'     => static fn (array $args): ?array => self::level_refusal($args, null),
                'snapshot'     => static fn (array $args): array => $level_rows([ self::next_id(self::table('membership_levels')) ]),
                'handler'      => static fn (array $args, array $context): array => self::create_level($args, $context),
            ],
            'update-level'         => $write + [
                'mode'         => 'write',
                'description'  => 'Change a Paid Memberships Pro level; only the args given change (name, description, confirmation, pricing, billing cycle, trial, expiration, allow_signups). New prices apply to new signups; existing members keep theirs. Undo: rollback-operation',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'level_id' => $id ] + $level, 'required' => [ 'level_id' ], 'minProperties' => 2 ],
                'validate'     => static function (array $args): ?array {
                    $row = self::level_row((int) $args['level_id']);
                    return null === $row ? self::level_missing((int) $args['level_id']) : self::level_refusal($args, $row);
                },
                'snapshot'     => static fn (array $args): array => $level_rows([ (int) $args['level_id'] ]),
                'handler'      => static fn (array $args): array => self::update_level($args),
            ],
            'change-member-level'  => $write + [
                'mode'            => 'destructive',
                'undoable'        => false,
                'objects'         => [ 'user_id' => [ 'type' => 'user', 'access' => 'write' ] ],
                'description'     => 'Give a user a Paid Memberships Pro level through PMPro itself, as an admin change: PMPro ends their other active levels in the same level group (status admin_changed) and may CANCEL their subscription at the payment gateway, which cannot be undone. Asks first with what would change, then needs confirm:true. rollback-operation restores the membership rows only',
                'input_schema'    => [ 'type' => 'object', 'properties' => [ 'user_id' => $id, 'level_id' => $id ], 'required' => [ 'user_id', 'level_id' ] ],
                'validate'        => static fn (array $args): ?array => self::change_refusal((int) $args['user_id'], (int) $args['level_id']),
                'confirm_preview' => static fn (array $args): array => self::change_preview((int) $args['user_id'], (int) $args['level_id']),
                'snapshot'        => static fn (array $args): array => [
                    'object_type' => Plugin_Table_Rows_Snapshot::TYPE,
                    'object_id'   => Plugin_Table_Rows_Snapshot::key('pmpro_members', 'memberships_users', [ (int) $args['user_id'] ]),
                ],
                'handler'         => static fn (array $args, array $context): array => self::change_level((int) $args['user_id'], (int) $args['level_id'], $context),
            ],
            'create-discount-code' => $write + [
                'requires'     => static fn () => self::presence([ 'membership_levels', 'discount_codes', 'discount_codes_levels' ]),
                'mode'         => 'write',
                'description'  => 'Create a Paid Memberships Pro discount code: code (letters, digits and -, up to 32, unique), starts (YYYY-MM-DD, default today), expires (default one year on), uses (0 is unlimited), and levels: one entry per level it applies to with level_id and that level\'s price under the code (initial_payment, billing_amount, cycle_number, cycle_period, billing_limit, trial_amount, trial_limit, expiration_number, expiration_period; unset ones are 0). Undo: rollback-operation removes it',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'code'    => [ 'type' => 'string', 'minLength' => 1 ],
                        'starts'  => $date,
                        'expires' => $date,
                        'uses'    => $count,
                        'levels'  => [ 'type' => 'array', 'minItems' => 1, 'items' => $pricing ],
                    ],
                    'required'   => [ 'code', 'levels' ],
                ],
                'validate'     => static fn (array $args): ?array => self::code_refusal($args),
                'snapshot'     => static fn (array $args): array => [
                    'object_type' => Plugin_Table_Rows_Snapshot::TYPE,
                    'object_id'   => Plugin_Table_Rows_Snapshot::key('pmpro_codes', 'discount_codes', [ self::next_id(self::table('discount_codes')) ]),
                ],
                'handler'      => static fn (array $args, array $context): array => self::create_code($args, $context),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @return array{code: string, message: string, data: array} */
    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    /** @return array<string, mixed>|null */
    private static function level_row(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', self::table('membership_levels'), $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private static function level_missing(int $id): array
    {
        return self::refusal('level_not_found', sprintf('Paid Memberships Pro has no membership level %d.', $id), [ 'level_id' => $id ]);
    }

    private static function user_refusal(int $user_id): ?array
    {
        return false !== get_userdata($user_id) ? null : self::refusal('member_not_found', sprintf('There is no user %d on this site.', $user_id), [ 'user_id' => $user_id ]);
    }

    private static function page(array $args): array
    {
        return [
            max(1, min(self::MAX_PAGE_SIZE, (int) ($args['page_size'] ?? self::DEFAULT_PAGE_SIZE))),
            max(0, (int) ($args['offset'] ?? 0)),
        ];
    }

    private static function has_table(string $name): bool
    {
        return Plugin_Table_Rows_Snapshot::table_exists(self::table($name));
    }

    /** The level group a level is in, or null (before PMPro 3.0, or ungrouped). */
    private static function group_of(int $level_id): ?int
    {
        global $wpdb;
        if (! self::has_table('membership_levels_groups')) {
            return null;
        }
        $group = $wpdb->get_var($wpdb->prepare('SELECT %i FROM %i WHERE %i = %d LIMIT 1', 'group', self::table('membership_levels_groups'), 'level', $level_id));
        return null === $group ? null : (int) $group;
    }

    private static function cycle(array $row): string
    {
        return (int) ($row['cycle_number'] ?? 0) > 0 ? trim((int) $row['cycle_number'] . ' ' . (string) ($row['cycle_period'] ?? '')) : '';
    }

    private static function level_view(array $row): array
    {
        return [
            'id'                => (int) $row['id'],
            'name'              => (string) $row['name'],
            'description'       => (string) $row['description'],
            'confirmation'      => (string) $row['confirmation'],
            'initial_payment'   => (string) $row['initial_payment'],
            'billing_amount'    => (string) $row['billing_amount'],
            'cycle_number'      => (int) $row['cycle_number'],
            'cycle_period'      => (string) ($row['cycle_period'] ?? ''),
            'cycle'             => self::cycle($row),
            'billing_limit'     => (int) $row['billing_limit'],
            'trial_amount'      => (string) $row['trial_amount'],
            'trial_limit'       => (int) $row['trial_limit'],
            'expiration_number' => (int) $row['expiration_number'],
            'expiration_period' => (string) $row['expiration_period'],
            'allow_signups'     => ! empty($row['allow_signups']),
            'group_id'          => self::group_of((int) $row['id']),
        ];
    }

    private static function membership_view(array $row): array
    {
        return [
            'id'              => (int) $row['id'],
            'level_id'        => (int) $row['membership_id'],
            'level_name'      => null === ($row['level_name'] ?? null) ? null : (string) $row['level_name'],
            'status'          => (string) $row['status'],
            'startdate'       => (string) $row['startdate'],
            'enddate'         => null === $row['enddate'] || '0000-00-00 00:00:00' === $row['enddate'] ? null : (string) $row['enddate'],
            'initial_payment' => (string) $row['initial_payment'],
            'billing_amount'  => (string) $row['billing_amount'],
            'cycle'           => self::cycle($row),
        ];
    }

    /** The id the next insert takes: MAX(id) + 1 or the table's auto-increment, whichever is higher. */
    private static function next_id(string $table): int
    {
        global $wpdb;
        $max    = (int) $wpdb->get_var($wpdb->prepare('SELECT MAX(id) FROM %i', $table));
        $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $table), ARRAY_A);
        $auto   = is_array($status) ? (int) ($status['Auto_increment'] ?? 0) : 0;
        return max($max + 1, $auto);
    }

    /** Void a persisted undo point whose write did not happen, so it can never remove another row. */
    private static function void(array $context): void
    {
        if ('' !== (string) ($context['operation_id'] ?? '')) {
            Snapshot_Store::delete_operation((string) $context['operation_id']);
        }
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function list_members(array $args): array
    {
        global $wpdb;
        $where  = [];
        $params = [];
        $status = (string) ($args['status'] ?? 'active');
        if ('any' !== $status) {
            $where[]  = 'mu.status = %s';
            $params[] = $status;
        }
        if (isset($args['level_id'])) {
            $where[]  = 'mu.membership_id = %d';
            $params[] = (int) $args['level_id'];
        }
        $search = trim((string) ($args['search'] ?? ''));
        if ('' !== $search) {
            $like     = '%' . $wpdb->esc_like($search) . '%';
            $where[]  = '(u.user_login LIKE %s OR u.user_email LIKE %s OR u.display_name LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        $sql          = [] === $where ? '' : ' WHERE ' . implode(' AND ', $where);
        $from         = ' FROM %i mu LEFT JOIN %i l ON l.id = mu.membership_id LEFT JOIN %i u ON u.ID = mu.user_id';
        $tables       = [ self::table('memberships_users'), self::table('membership_levels'), $wpdb->users ];
        [$size, $skip] = self::page($args);
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $from and $sql hold only fixed column conditions and placeholders.
        $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*)' . $from . $sql, ...array_merge($tables, $params)));
        $rows  = (array) $wpdb->get_results($wpdb->prepare('SELECT mu.*, l.name AS level_name, u.user_login, u.user_email, u.display_name' . $from . $sql . ' ORDER BY mu.id DESC LIMIT %d OFFSET %d', ...array_merge($tables, $params, [ $size, $skip ])), ARRAY_A);
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        $members = [];
        foreach ($rows as $row) {
            $members[] = [ 'user_id' => (int) $row['user_id'] ] + Masking::keys([
                'user_login'   => (string) ($row['user_login'] ?? ''),
                'display_name' => (string) ($row['display_name'] ?? ''),
                'user_email'   => (string) ($row['user_email'] ?? ''),
            ], [ 'user_login', 'display_name', 'user_email' ]) + self::membership_view($row);
        }
        return [
            'members'     => $members,
            'total'       => $total,
            'masked'      => true,
            'next_offset' => $skip + count($rows) < $total ? $skip + count($rows) : null,
        ];
    }

    /** @return array<string, mixed> */
    private static function member(int $user_id): array
    {
        global $wpdb;
        $user = get_userdata($user_id);
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT mu.*, l.name AS level_name FROM %i mu LEFT JOIN %i l ON l.id = mu.membership_id WHERE mu.user_id = %d ORDER BY mu.id DESC',
            self::table('memberships_users'),
            self::table('membership_levels'),
            $user_id
        ), ARRAY_A);
        $history = array_map(static fn (array $row): array => self::membership_view($row), $rows);
        $active  = array_values(array_filter($history, static fn (array $row): bool => 'active' === $row['status']));

        return [
            'user_id'              => $user_id,
            'user_login'           => false === $user ? '' : (string) $user->user_login,
            'user_email'           => false === $user ? '' : (string) $user->user_email,
            'display_name'         => false === $user ? '' : (string) $user->display_name,
            'active_levels'        => $active,
            'history'              => $history,
            'active_subscriptions' => self::active_subscriptions($user_id),
            'orders'               => self::has_table('membership_orders') ? (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE user_id = %d', self::table('membership_orders'), $user_id)) : null,
        ];
    }

    private static function active_subscriptions(int $user_id): ?int
    {
        global $wpdb;
        if (! self::has_table('subscriptions')) {
            return null;
        }
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE user_id = %d AND status = %s', self::table('subscriptions'), $user_id, 'active'));
    }

    /** @return array<string, mixed> */
    private static function list_orders(array $args): array
    {
        global $wpdb;
        $where  = [];
        $params = [];
        foreach ([ 'status' => 'status = %s', 'level_id' => 'membership_id = %d', 'user_id' => 'user_id = %d' ] as $key => $clause) {
            if (isset($args[ $key ])) {
                $where[]  = $clause;
                $params[] = $args[ $key ];
            }
        }
        $sql           = [] === $where ? '' : ' WHERE ' . implode(' AND ', $where);
        [$size, $skip] = self::page($args);
        $columns       = 'id, code, user_id, membership_id, billing_name, billing_country, subtotal, tax, total, payment_type, status, gateway, gateway_environment, payment_transaction_id, subscription_transaction_id, timestamp';
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $sql and $columns hold only fixed column names and placeholders.
        $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i' . $sql, self::table('membership_orders'), ...$params));
        $rows  = (array) $wpdb->get_results($wpdb->prepare('SELECT ' . $columns . ' FROM %i' . $sql . ' ORDER BY id DESC LIMIT %d OFFSET %d', self::table('membership_orders'), ...array_merge($params, [ $size, $skip ])), ARRAY_A);
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        $orders = [];
        foreach ($rows as $row) {
            $orders[] = [
                'id'                          => (int) $row['id'],
                'code'                        => (string) $row['code'],
                'user_id'                     => (int) $row['user_id'],
                'level_id'                    => (int) $row['membership_id'],
                'billing_name'                => Masking::value((string) $row['billing_name']),
                'billing_country'             => (string) $row['billing_country'],
                'subtotal'                    => (string) $row['subtotal'],
                'tax'                         => (string) $row['tax'],
                'total'                       => (string) $row['total'],
                'payment_type'                => (string) $row['payment_type'],
                'status'                      => (string) $row['status'],
                'gateway'                     => (string) $row['gateway'],
                'gateway_environment'         => (string) $row['gateway_environment'],
                'payment_transaction_id'      => (string) $row['payment_transaction_id'],
                'subscription_transaction_id' => (string) $row['subscription_transaction_id'],
                'timestamp'                   => (string) $row['timestamp'],
            ];
        }
        return [
            'orders'      => $orders,
            'total'       => $total,
            'masked'      => true,
            'next_offset' => $skip + count($rows) < $total ? $skip + count($rows) : null,
        ];
    }

    /** @return array<string, mixed> */
    private static function list_codes(array $args): array
    {
        global $wpdb;
        $sql    = '';
        $params = [];
        $search = trim((string) ($args['search'] ?? ''));
        if ('' !== $search) {
            $sql      = ' WHERE code LIKE %s';
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }
        [$size, $skip] = self::page($args);
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $sql holds only a fixed condition and a placeholder.
        $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i' . $sql, self::table('discount_codes'), ...$params));
        $rows  = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i' . $sql . ' ORDER BY id DESC LIMIT %d OFFSET %d', self::table('discount_codes'), ...array_merge($params, [ $size, $skip ])), ARRAY_A);
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        return [
            'codes'       => array_map(static fn (array $row): array => self::code_view($row), $rows),
            'total'       => $total,
            'next_offset' => $skip + count($rows) < $total ? $skip + count($rows) : null,
        ];
    }

    private static function code_view(array $row): array
    {
        global $wpdb;
        $id     = (int) $row['id'];
        $levels = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT cl.*, l.name AS level_name FROM %i cl LEFT JOIN %i l ON l.id = cl.level_id WHERE cl.code_id = %d ORDER BY cl.level_id ASC',
            self::table('discount_codes_levels'),
            self::table('membership_levels'),
            $id
        ), ARRAY_A);
        $used   = self::has_table('discount_codes_uses') ? (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE code_id = %d', self::table('discount_codes_uses'), $id)) : null;

        return [
            'id'         => $id,
            'code'       => (string) $row['code'],
            'starts'     => (string) $row['starts'],
            'expires'    => (string) $row['expires'],
            'uses'       => (int) $row['uses'],
            'times_used' => $used,
            'levels'     => array_map(static fn (array $l): array => [
                'level_id'          => (int) $l['level_id'],
                'level_name'        => null === $l['level_name'] ? null : (string) $l['level_name'],
                'initial_payment'   => (string) $l['initial_payment'],
                'billing_amount'    => (string) $l['billing_amount'],
                'cycle'             => self::cycle($l),
                'billing_limit'     => (int) $l['billing_limit'],
                'trial_amount'      => (string) $l['trial_amount'],
                'trial_limit'       => (int) $l['trial_limit'],
                'expiration_number' => (int) $l['expiration_number'],
                'expiration_period' => (string) $l['expiration_period'],
            ], $levels),
        ];
    }

    // ------------------------------------------------------------------
    // Planning
    // ------------------------------------------------------------------

    /**
     * Refusal for level args, or null. $row is the level being updated, so
     * the checks see the values the row will hold after the write.
     */
    private static function level_refusal(array $args, ?array $row): ?array
    {
        $merged = $args + (array) $row;
        if (isset($args['name']) && '' === trim(wp_strip_all_tags((string) $args['name']))) {
            return self::refusal('invalid_name', 'A level needs a name.');
        }
        if ((float) ($merged['billing_amount'] ?? 0) > 0 && (int) ($merged['cycle_number'] ?? 0) < 1) {
            return self::refusal('invalid_billing', 'A recurring billing_amount needs cycle_number of at least 1 (bill every cycle_number cycle_period).');
        }
        if ((int) ($merged['expiration_number'] ?? 0) > 0 && ! in_array((string) ($merged['expiration_period'] ?? ''), self::PERIODS, true)) {
            return self::refusal('invalid_expiration', 'An expiration_number needs an expiration_period (Day, Week, Month or Year).');
        }
        if (isset($args['group_id']) && ! self::group_exists((int) $args['group_id'])) {
            return self::refusal('group_not_found', sprintf('Paid Memberships Pro has no level group %d.', (int) $args['group_id']), [ 'group_id' => (int) $args['group_id'] ]);
        }
        return null;
    }

    private static function group_exists(int $group_id): bool
    {
        global $wpdb;
        return self::has_table('groups') && self::has_table('membership_levels_groups')
            && null !== $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE id = %d', self::table('groups'), $group_id));
    }

    /** The first level group in display order, as PMPro's level screen offers it, or null. */
    private static function first_group(): ?int
    {
        global $wpdb;
        if (! self::has_table('groups') || ! self::has_table('membership_levels_groups')) {
            return null;
        }
        $id = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i ORDER BY displayorder ASC, id ASC LIMIT 1', self::table('groups')));
        return null === $id ? null : (int) $id;
    }

    /** The level columns from the args given, cleaned the way PMPro's level screen saves them. */
    private static function level_columns(array $args): array
    {
        $set = [];
        foreach (self::LEVEL_TEXT as $key) {
            if (array_key_exists($key, $args)) {
                $set[ $key ] = wp_kses_post((string) $args[ $key ]);
            }
        }
        foreach (self::LEVEL_MONEY as $key) {
            if (array_key_exists($key, $args)) {
                $set[ $key ] = (string) round((float) $args[ $key ], 8);
            }
        }
        foreach (self::LEVEL_INT as $key) {
            if (array_key_exists($key, $args)) {
                $set[ $key ] = (int) $args[ $key ];
            }
        }
        foreach ([ 'cycle_period', 'expiration_period' ] as $key) {
            if (array_key_exists($key, $args)) {
                $set[ $key ] = (string) $args[ $key ];
            }
        }
        if (array_key_exists('allow_signups', $args)) {
            $set['allow_signups'] = $args['allow_signups'] ? 1 : 0;
        }
        return $set;
    }

    private static function active_level_ids(int $user_id): array
    {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare('SELECT membership_id FROM %i WHERE user_id = %d AND status = %s ORDER BY id ASC', self::table('memberships_users'), $user_id, 'active'));
        return array_values(array_unique(array_map('intval', (array) $ids)));
    }

    private static function change_refusal(int $user_id, int $level_id): ?array
    {
        $user = self::user_refusal($user_id);
        if (null !== $user) {
            return $user;
        }
        if (null === self::level_row($level_id)) {
            return self::level_missing($level_id);
        }
        if (in_array($level_id, self::active_level_ids($user_id), true)) {
            return self::refusal('already_member', sprintf('User %d already holds level %d.', $user_id, $level_id), [ 'user_id' => $user_id, 'level_id' => $level_id ]);
        }
        if (! function_exists('pmpro_changeMembershipLevel')) {
            return self::refusal('pmpro_api_unavailable', 'Paid Memberships Pro\'s pmpro_changeMembershipLevel() is not loaded, so the level cannot be changed through PMPro. Update Paid Memberships Pro, then try again.');
        }
        return null;
    }

    /** @return array{message: string, data: array} */
    private static function change_preview(int $user_id, int $level_id): array
    {
        $level   = (array) self::level_row($level_id);
        $current = self::active_level_ids($user_id);
        $subs    = (int) self::active_subscriptions($user_id);

        return [
            'message' => sprintf(
                'Paid Memberships Pro gives user %1$d level %2$d (%3$s) as an admin change. Their active levels in the same level group (now: %4$s) end with status admin_changed, and PMPro may cancel their %5$d active gateway subscription(s); a gateway cancel cannot be undone, and rollback-operation restores only the membership rows.',
                $user_id,
                $level_id,
                (string) ($level['name'] ?? ''),
                [] === $current ? 'none' : implode(', ', $current),
                $subs
            ),
            'data'    => [
                'user_id'              => $user_id,
                'level_id'             => $level_id,
                'current_level_ids'    => $current,
                'active_subscriptions' => $subs,
            ],
        ];
    }

    private static function code_refusal(array $args): ?array
    {
        global $wpdb;
        $code = (string) $args['code'];
        if (1 !== preg_match('/^[A-Za-z0-9-]{1,32}$/', $code)) {
            return self::refusal('invalid_code', 'A discount code takes only letters, digits and "-", up to 32 characters (Paid Memberships Pro strips anything else). Nothing was written.', [ 'code' => $code ]);
        }
        if (null !== $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE code = %s LIMIT 1', self::table('discount_codes'), $code))) {
            return self::refusal('code_in_use', sprintf('Paid Memberships Pro already has the discount code "%s".', $code), [ 'code' => $code ]);
        }
        [$starts, $expires] = self::code_dates($args);
        if ($expires < $starts) {
            return self::refusal('invalid_dates', 'expires must be on or after starts.', [ 'starts' => $starts, 'expires' => $expires ]);
        }
        $seen = [];
        foreach ((array) $args['levels'] as $entry) {
            $level_id = (int) $entry['level_id'];
            if (isset($seen[ $level_id ])) {
                return self::refusal('duplicate_level', sprintf('Level %d is listed twice.', $level_id), [ 'level_id' => $level_id ]);
            }
            $seen[ $level_id ] = true;
            if (null === self::level_row($level_id)) {
                return self::level_missing($level_id);
            }
            $bad = self::level_refusal(array_intersect_key((array) $entry, array_flip([ 'billing_amount', 'cycle_number', 'expiration_number', 'expiration_period' ])), null);
            if (null !== $bad) {
                $bad['data'] = [ 'level_id' => $level_id ] + (array) $bad['data'];
                return $bad;
            }
        }
        return null;
    }

    /** @return array{0: string, 1: string} starts and expires, defaulted the way PMPro's screen offers them. */
    private static function code_dates(array $args): array
    {
        $today = current_time('Y-m-d');
        $start = (string) ($args['starts'] ?? $today);
        return [ $start, (string) ($args['expires'] ?? gmdate('Y-m-d', (int) strtotime($start . ' +1 year'))) ];
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    private static function forget_level_cache(int $id): void
    {
        global $pmpro_levels;
        if (is_array($pmpro_levels)) {
            unset($pmpro_levels[ $id ]);
        }
    }

    /** @return array<string, mixed> */
    private static function create_level(array $args, array $context): array
    {
        global $wpdb;
        $parsed = Plugin_Table_Rows_Snapshot::parse((string) $context['target']['object_id']);
        $id     = (int) ($parsed['ids'][0] ?? 0);
        $set    = self::level_columns($args);
        $row    = [ 'id' => $id ] + $set + [
            'description'       => '',
            'confirmation'      => '',
            'initial_payment'   => '0',
            'billing_amount'    => '0',
            'cycle_number'      => 0,
            'cycle_period'      => (int) ($set['cycle_number'] ?? 0) > 0 ? 'Month' : '',
            'billing_limit'     => 0,
            'trial_amount'      => '0',
            'trial_limit'       => 0,
            'allow_signups'     => 1,
            'expiration_number' => 0,
            'expiration_period' => '',
        ];
        if (false === $wpdb->insert(self::table('membership_levels'), $row)) {
            self::void($context);
            throw new Operation_Refused('insert_failed', 'Could not create the level: ' . $wpdb->last_error);
        }
        $group = isset($args['group_id']) ? (int) $args['group_id'] : self::first_group();
        if (null !== $group) {
            $wpdb->insert(self::table('membership_levels_groups'), [ 'level' => $id, 'group' => $group ], [ '%d', '%d' ]);
        }
        self::forget_level_cache($id);
        do_action('pmpro_save_membership_level', $id);

        return [ 'level' => self::level_view((array) self::level_row($id)) ];
    }

    /** @return array<string, mixed> */
    private static function update_level(array $args): array
    {
        global $wpdb;
        $id  = (int) $args['level_id'];
        $set = self::level_columns($args);
        if ([] !== $set && false === $wpdb->update(self::table('membership_levels'), $set, [ 'id' => $id ])) {
            throw new Operation_Refused('update_failed', sprintf('Could not update level %d: %s', $id, $wpdb->last_error));
        }
        self::forget_level_cache($id);
        do_action('pmpro_save_membership_level', $id);

        return [ 'level' => self::level_view((array) self::level_row($id)) ];
    }

    /** @return array<string, mixed> */
    private static function change_level(int $user_id, int $level_id, array $context): array
    {
        global $pmpro_error;
        $done = pmpro_changeMembershipLevel($level_id, $user_id, 'admin_changed');
        if (true !== $done) {
            self::void($context);
            $why = is_string($pmpro_error ?? null) && '' !== $pmpro_error ? $pmpro_error : 'Paid Memberships Pro did not change the level.';
            throw new Operation_Refused('change_failed', sprintf('Could not give user %d level %d: %s', $user_id, $level_id, $why));
        }
        return [ 'member' => self::member($user_id) ];
    }

    /** @return array<string, mixed> */
    private static function create_code(array $args, array $context): array
    {
        global $wpdb;
        $parsed             = Plugin_Table_Rows_Snapshot::parse((string) $context['target']['object_id']);
        $id                 = (int) ($parsed['ids'][0] ?? 0);
        [$starts, $expires] = self::code_dates($args);
        $row                = [ 'id' => $id, 'code' => (string) $args['code'], 'starts' => $starts, 'expires' => $expires, 'uses' => (int) ($args['uses'] ?? 0) ];
        if (false === $wpdb->insert(self::table('discount_codes'), $row, [ '%d', '%s', '%s', '%s', '%d' ])) {
            self::void($context);
            throw new Operation_Refused('insert_failed', 'Could not create the discount code: ' . $wpdb->last_error);
        }
        foreach ((array) $args['levels'] as $entry) {
            $level   = self::level_columns((array) $entry);
            $pricing = [
                'code_id'           => $id,
                'level_id'          => (int) $entry['level_id'],
                'initial_payment'   => $level['initial_payment'] ?? '0',
                'billing_amount'    => $level['billing_amount'] ?? '0',
                'cycle_number'      => $level['cycle_number'] ?? 0,
                'cycle_period'      => $level['cycle_period'] ?? 'Month',
                'billing_limit'     => $level['billing_limit'] ?? 0,
                'trial_amount'      => $level['trial_amount'] ?? '0',
                'trial_limit'       => $level['trial_limit'] ?? 0,
                'expiration_number' => $level['expiration_number'] ?? 0,
                'expiration_period' => $level['expiration_period'] ?? 'Month',
            ];
            if (false === $wpdb->insert(self::table('discount_codes_levels'), $pricing)) {
                throw new Operation_Refused('insert_failed', sprintf('Could not save the code\'s price for level %d: %s', (int) $entry['level_id'], $wpdb->last_error));
            }
            do_action('pmpro_save_discount_code_level', $id, (int) $entry['level_id']);
        }
        do_action('pmpro_save_discount_code', $id);

        $saved = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', self::table('discount_codes'), $id), ARRAY_A);
        return [ 'code' => self::code_view((array) $saved) ];
    }
}
