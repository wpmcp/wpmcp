<?php

namespace WPMCP\Integrations;

use WPMCP\Privacy\Masking;
use WPMCP\Safety\Plugin_Table_Rows_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Restrict Content and Restrict Content Pro (issue #669) on the plugin-data
 * pair, free tier, so they add no top-level tools. Ops are prefixed rcp-
 * because the pair is shared. Both editions share one codebase and define
 * RCP_PLUGIN_VERSION.
 *
 * The plugin keeps its data in tables of its own (Restrict Content 4.0.6,
 * BerlinDB schemas under core/includes/database):
 *  - {prefix}restrict_content_pro: one row per membership level (name,
 *    description, duration and duration_unit, trial_duration and
 *    trial_duration_unit, price and fee as text, maximum_renewals,
 *    after_final_payment, level (the access level 0 to 10), role, status
 *    active or inactive, list_order, dates and uuid). The name comes from
 *    rcp_get_levels_db_name();
 *  - {prefix}rcp_memberships: one row per membership (customer_id, user_id,
 *    object_id is the level, status active, pending, cancelled or expired,
 *    amounts, dates, auto_renew, gateway ids, subscription_key, notes);
 *  - {prefix}rcp_customers: one row per customer (user_id, ips, notes).
 *
 * Reads: rcp-list-membership-levels (pricing, status and the active member
 * count per level) and rcp-list-members (memberships with the member's name
 * and email masked, src/Privacy/Masking.php; gateway ids, subscription key,
 * notes and IPs are left out).
 *
 * Write (off until a site opts in with wpmcp_enable_restrict_content_write,
 * since a level's price is what members pay): rcp-update-membership-level
 * changes the level's name, description, status, price, fee, duration,
 * trial and list order. It writes the one row the way
 * rcp_update_membership_level() does, with its checks (a price of 0 or more,
 * a trial only on a paid level with a duration, no payment plan on a
 * one-time level), and fires the plugin's rcp_pre_edit_subscription_level
 * and rcp_edit_subscription_level actions. The level's role and access
 * level are not writable: they decide what every member of the level can
 * do on the site. The row is snapshotted as a 'plugin_table_rows' image
 * (kind rcp_levels, suffix pro), so rollback-operation puts it back exactly.
 * A network-activated install, whose levels table carries no site prefix,
 * is refused for the write.
 *
 * Every op takes manage_options, the capability the plugin grants its own
 * membership screens to administrators with. Presence is filterable
 * (wpmcp_restrict_content_active).
 */
final class Restrict_Content_Pack
{
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads and writes the plugin's own tables live; it has no cached read API for a filtered list.

    public const CAPABILITY = 'manage_options';

    public const OPT_IN_FILTER = 'wpmcp_enable_restrict_content_write';

    public const MAX_PER_PAGE = 100;

    public const PLUGIN_FILES = [ 'restrict-content/restrictcontent.php', 'restrict-content-pro/restrict-content-pro.php' ];

    private const MEMBERSHIP_STATUSES = [ 'active', 'pending', 'cancelled', 'expired' ];

    private const UNITS = [ 'day', 'month', 'year' ];

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_restrict_content_active', defined('RCP_PLUGIN_VERSION'));
    }

    /** The levels table as the plugin names it. */
    public static function levels_table(): string
    {
        global $wpdb;
        return function_exists('rcp_get_levels_db_name') ? (string) rcp_get_levels_db_name() : $wpdb->prefix . 'restrict_content_pro';
    }

    public static function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'rcp_' . $name;
    }

    /** @return true|array{code: string, message: string, data: array} */
    public static function presence()
    {
        if (self::active()) {
            return true;
        }
        $installed = false;
        foreach (self::PLUGIN_FILES as $file) {
            $installed = $installed || (defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/' . $file));
        }
        $url = admin_url('plugins.php');

        return [
            'code'    => $installed ? 'plugin_inactive' : 'plugin_missing',
            'message' => sprintf(
                'Restrict Content is %s on this site. Install and activate Restrict Content or Restrict Content Pro (%s), then try again.',
                $installed ? 'installed but not active' : 'not installed',
                $url
            ),
            'data'    => [ 'plugin' => 'restrict-content', 'plugins_url' => $url ],
        ];
    }

    /** @return true|array{code: string, message: string, data: array} */
    private static function ready()
    {
        $present = self::presence();
        if (true !== $present) {
            return $present;
        }
        foreach ([ self::levels_table(), self::table('memberships'), self::table('customers') ] as $table) {
            if (! Plugin_Table_Rows_Snapshot::table_exists($table)) {
                return [
                    'code'    => 'rcp_tables_missing',
                    'message' => sprintf('Restrict Content\'s table %s does not exist on this site. Deactivate and reactivate Restrict Content so it creates its tables, then try again.', $table),
                    'data'    => [ 'plugins_url' => admin_url('plugins.php') ],
                ];
            }
        }
        return true;
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $base = [
            'capability' => self::CAPABILITY,
            'requires'   => static fn () => self::ready(),
        ];
        $unit = [ 'type' => 'string', 'enum' => self::UNITS ];

        return [
            'rcp-list-membership-levels'  => $base + [
                'mode'         => 'read',
                'description'  => 'List Restrict Content membership levels in their display order: id, name, description, status (active, inactive), price, sign-up fee, duration ("unlimited" when it never expires), trial, payment plan renewals, access level, role, active member count. Filter by status',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'status' => [ 'type' => 'string', 'enum' => [ 'active', 'inactive' ] ] ],
                ],
                'handler'      => static fn (array $args): array => self::list_levels($args),
            ],
            'rcp-list-members'            => $base + [
                'mode'         => 'read',
                'description'  => 'List Restrict Content memberships newest first: id, customer and user id, name and email MASKED, level id and name, status, created, activated, expiration, auto renew, times billed, amounts, currency, gateway. Filter by level_id and status (active, pending, cancelled, expired). page, per_page (max 100)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'level_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                        'status'   => [ 'type' => 'string', 'enum' => self::MEMBERSHIP_STATUSES ],
                        'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
                        'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE ],
                    ],
                ],
                'handler'      => static fn (array $args): array => self::list_members($args),
            ],
            'rcp-update-membership-level' => $base + [
                'mode'               => 'write',
                'enabled_by_default' => (bool) apply_filters('wpmcp_enable_restrict_content_write', false),
                'opt_in_filter'      => self::OPT_IN_FILTER,
                'description'        => 'Change a Restrict Content membership level; only the args given change: name, description, status (active, inactive), price, fee, duration and duration_unit (0 never expires), trial_duration and trial_duration_unit, list_order. Checked as the plugin checks it. Role and access level are not writable. Existing memberships keep the price they signed up at. Off unless ' . self::OPT_IN_FILTER . '. Undo: rollback-operation',
                'input_schema'       => [
                    'type'          => 'object',
                    'properties'    => [
                        'level_id'            => [ 'type' => 'integer', 'minimum' => 1 ],
                        'name'                => [ 'type' => 'string', 'maxLength' => 200 ],
                        'description'         => [ 'type' => 'string' ],
                        'status'              => [ 'type' => 'string', 'enum' => [ 'active', 'inactive' ] ],
                        'price'               => [ 'type' => 'number', 'minimum' => 0 ],
                        'fee'                 => [ 'type' => 'number' ],
                        'duration'            => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 65535 ],
                        'duration_unit'       => $unit,
                        'trial_duration'      => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 65535 ],
                        'trial_duration_unit' => $unit,
                        'list_order'          => [ 'type' => 'integer', 'minimum' => 0 ],
                    ],
                    'required'      => [ 'level_id' ],
                    'minProperties' => 2,
                ],
                'validate'           => static fn (array $args): ?array => self::update_refusal($args),
                'snapshot'           => static fn (array $args): array => [
                    'object_type' => Plugin_Table_Rows_Snapshot::TYPE,
                    'object_id'   => Plugin_Table_Rows_Snapshot::key('rcp_levels', 'pro', [ (int) $args['level_id'] ]),
                ],
                'handler'            => static fn (array $args): array => self::update_level($args),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    private static function level_row(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', self::levels_table(), $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private static function period(int $length, string $unit): ?string
    {
        return $length > 0 ? $length . ' ' . $unit : null;
    }

    /** A level row as the ops show it. */
    private static function view(array $row, ?int $members = null): array
    {
        $view = [
            'id'                  => (int) $row['id'],
            'name'                => (string) $row['name'],
            'description'         => (string) $row['description'],
            'status'              => (string) $row['status'],
            'price'               => (float) $row['price'],
            'fee'                 => (float) $row['fee'],
            'duration'            => self::period((int) $row['duration'], (string) $row['duration_unit']) ?? 'unlimited',
            'trial'               => self::period((int) $row['trial_duration'], (string) $row['trial_duration_unit']),
            'maximum_renewals'    => (int) $row['maximum_renewals'],
            'after_final_payment' => (string) $row['after_final_payment'],
            'access_level'        => (int) $row['level'],
            'role'                => (string) $row['role'],
            'list_order'          => (int) $row['list_order'],
            'date_modified'       => $row['date_modified'],
        ];
        if (null !== $members) {
            $view['active_members'] = $members;
        }
        return $view;
    }

    private static function list_levels(array $args): array
    {
        global $wpdb;
        $where = isset($args['status']) ? $wpdb->prepare(' WHERE status = %s', (string) $args['status']) : '';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is a prepared fragment.
        $rows   = $wpdb->get_results($wpdb->prepare("SELECT * FROM %i{$where} ORDER BY list_order ASC, id ASC", self::levels_table()), ARRAY_A);
        $counts = [];
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT object_id AS level, COUNT(*) AS n FROM %i WHERE status = 'active' AND (object_type = 'membership' OR object_type IS NULL) GROUP BY object_id", self::table('memberships')), ARRAY_A) as $count) {
            $counts[ (int) $count['level'] ] = (int) $count['n'];
        }
        $levels = [];
        foreach ((array) $rows as $row) {
            $levels[] = self::view($row, $counts[ (int) $row['id'] ] ?? 0);
        }
        return [ 'levels' => $levels, 'total' => count($levels) ];
    }

    private static function list_members(array $args): array
    {
        global $wpdb;
        $per_page = min(self::MAX_PER_PAGE, max(1, (int) ($args['per_page'] ?? 20)));
        $page     = max(1, (int) ($args['page'] ?? 1));
        $where    = '';
        if (isset($args['level_id'])) {
            $where .= $wpdb->prepare(' AND m.object_id = %d', (int) $args['level_id']);
        }
        if (isset($args['status'])) {
            $where .= $wpdb->prepare(' AND m.status = %s', (string) $args['status']);
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built from prepared fragments only.
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i m WHERE 1=1{$where}", self::table('memberships')));
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built from prepared fragments only.
                "SELECT m.id, m.customer_id, COALESCE(m.user_id, c.user_id) AS user_id, m.object_id, m.status, m.created_date, m.activated_date, m.trial_end_date, m.renewed_date, m.cancellation_date, m.expiration_date, m.auto_renew, m.times_billed, m.initial_amount, m.recurring_amount, m.currency, m.gateway FROM %i m LEFT JOIN %i c ON c.id = m.customer_id WHERE 1=1{$where} ORDER BY m.created_date DESC, m.id DESC LIMIT %d OFFSET %d",
                self::table('memberships'),
                self::table('customers'),
                $per_page,
                ($page - 1) * $per_page
            ),
            ARRAY_A
        );
        $names   = [];
        $members = [];
        foreach ((array) $rows as $r) {
            $level = (int) $r['object_id'];
            if (! array_key_exists($level, $names)) {
                $row             = self::level_row($level);
                $names[ $level ] = null === $row ? '' : (string) $row['name'];
            }
            $user      = get_userdata((int) $r['user_id']);
            $members[] = Masking::keys([
                'id'                => (int) $r['id'],
                'customer_id'       => (int) $r['customer_id'],
                'user_id'           => (int) $r['user_id'],
                'name'              => $user instanceof \WP_User ? (string) $user->display_name : '',
                'email'             => $user instanceof \WP_User ? (string) $user->user_email : '',
                'level_id'          => $level,
                'level_name'        => $names[ $level ],
                'status'            => (string) $r['status'],
                'created_date'      => $r['created_date'],
                'activated_date'    => $r['activated_date'],
                'trial_end_date'    => $r['trial_end_date'],
                'renewed_date'      => $r['renewed_date'],
                'cancellation_date' => $r['cancellation_date'],
                'expiration_date'   => $r['expiration_date'],
                'auto_renew'        => (bool) $r['auto_renew'],
                'times_billed'      => (int) $r['times_billed'],
                'initial_amount'    => (float) $r['initial_amount'],
                'recurring_amount'  => (float) $r['recurring_amount'],
                'currency'          => (string) $r['currency'],
                'gateway'           => (string) $r['gateway'],
            ], [ 'name', 'email' ]);
        }
        return [ 'members' => $members, 'total' => $total, 'page' => $page, 'per_page' => $per_page, 'masked' => true ];
    }

    // ------------------------------------------------------------------
    // Write
    // ------------------------------------------------------------------

    /** @return array{code: string, message: string, data: array} */
    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    /** The level row with the given args applied. */
    private static function merged(array $row, array $args): array
    {
        foreach ([ 'name', 'description', 'status', 'duration_unit', 'trial_duration_unit' ] as $key) {
            if (array_key_exists($key, $args)) {
                $row[ $key ] = (string) $args[ $key ];
            }
        }
        foreach ([ 'duration', 'trial_duration', 'list_order' ] as $key) {
            if (array_key_exists($key, $args)) {
                $row[ $key ] = (int) $args[ $key ];
            }
        }
        foreach ([ 'price', 'fee' ] as $key) {
            if (array_key_exists($key, $args)) {
                $row[ $key ] = self::amount($args[ $key ]);
            }
        }
        return $row;
    }

    /** An amount as the plugin stores it: plain decimal text, no thousands separator. */
    private static function amount($value): string
    {
        $text = rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');
        return '' === $text || '-0' === $text ? '0' : $text;
    }

    private static function update_refusal(array $args): ?array
    {
        global $wpdb;
        $id = (int) $args['level_id'];
        if (self::levels_table() !== $wpdb->prefix . 'restrict_content_pro') {
            return self::refusal('rcp_network_tables', 'Restrict Content keeps its membership levels in a network-wide table on this install, which WP MCP does not write. Change the level from Restrict Content\'s own screen.', [ 'level_id' => $id ]);
        }
        $row = self::level_row($id);
        if (null === $row) {
            return self::refusal('level_not_found', sprintf('Restrict Content has no membership level %d.', $id), [ 'level_id' => $id ]);
        }
        if (array_key_exists('name', $args) && '' === trim((string) $args['name'])) {
            return self::refusal('invalid_level_name', 'A membership level needs a name.', [ 'level_id' => $id ]);
        }
        $next = self::merged($row, $args);
        if ((int) $next['duration'] === 0 && (int) $next['maximum_renewals'] > 0) {
            return self::refusal('invalid_maximum_renewals', 'A one-time level (duration 0) cannot keep its payment plan: set a duration, or change the payment plan on Restrict Content\'s own screen first.', [ 'level_id' => $id ]);
        }
        if ((int) $next['trial_duration'] > 0 && ((float) $next['price'] <= 0 || (int) $next['duration'] <= 0)) {
            return self::refusal('invalid_level_trial', 'A membership level with a trial must have a price and a duration greater than zero.', [ 'level_id' => $id ]);
        }
        return null;
    }

    private static function update_level(array $args): array
    {
        global $wpdb;
        $id   = (int) $args['level_id'];
        $row  = (array) self::level_row($id);
        $next = self::merged($row, $args);
        $next['date_modified'] = current_time('mysql', true);
        $hook_args = $next;
        unset($hook_args['id']);

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Restrict Content's own hook, run so its extensions see the write as rcp_update_membership_level() does.
        do_action('rcp_pre_edit_subscription_level', $id, $hook_args);

        $changes = array_diff_key($next, [ 'id' => true ]);
        if (false === $wpdb->update(self::levels_table(), $changes, [ 'id' => $id ])) {
            throw new Operation_Refused('rcp_write_failed', 'Restrict Content\'s membership level could not be saved: ' . esc_html($wpdb->last_error));
        }
        Plugin_Table_Rows_Snapshot::flush_rcp_level_cache([ $id ]);

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Restrict Content's own hook, run so its extensions see the write.
        do_action('rcp_edit_subscription_level', $id, $hook_args);

        return [ 'level' => self::view((array) self::level_row($id)) ];
    }
}
