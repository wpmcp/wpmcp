<?php

namespace WPMCP\Integrations;

use WPMCP\Privacy\Masking;
use WPMCP\Tools\Content\Readable_Posts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Paid Member Subscriptions by Cozmoslabs (issue #666) on the plugin-data
 * pair, free tier and read only, so it adds no top-level tools. Ops are
 * prefixed pms- because the pair is shared.
 *
 * The plugin keeps a subscription plan as a `pms-subscription` post (the
 * title is the plan name, post_parent links the plans of one group) with its
 * settings in pms_subscription_plan_* post meta, read by
 * PMS_Subscription_Plan::init(): price, status (active or inactive),
 * duration and duration_unit (a duration of 0 never expires), sign_up_fee,
 * trial_duration and trial_duration_unit, recurring, user_role and type.
 * A member's subscription is a row of its own table
 * {prefix}pms_member_subscriptions (Paid_Member_Subscriptions::create_tables:
 * id, user_id, subscription_plan_id, start_date, expiration_date, status,
 * payment_profile_id, payment_gateway, billing_amount, billing_duration,
 * billing_duration_unit, billing_cycles, billing_next_payment,
 * billing_last_payment, trial_end); status is active, canceled, expired,
 * pending, abandoned or completed.
 *
 * Reads: pms-list-plans (pricing, status and the active member count per
 * plan) and pms-list-members (member subscriptions with the member's name
 * and email masked, src/Privacy/Masking.php; the gateway profile id is left
 * out). Both take manage_options, the capability the plugin's own member
 * screens are behind. Writes are not part of the first version (issue
 * #666). Presence is filterable (wpmcp_pms_active, default: PMS_VERSION is
 * defined).
 */
final class Paid_Member_Subscriptions_Pack
{
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads the plugin's own member subscriptions table live; it has no cached read API for a filtered list.

    public const POST_TYPE = 'pms-subscription';

    public const MAX_PER_PAGE = 100;

    public const PLUGIN_FILES = [ 'paid-member-subscriptions/index.php' ];

    private const STATUSES = [ 'active', 'canceled', 'expired', 'pending', 'abandoned', 'completed' ];

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_pms_active', defined('PMS_VERSION'));
    }

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->get_blog_prefix() . 'pms_member_subscriptions';
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
                'Paid Member Subscriptions is %s on this site. Install and activate Paid Member Subscriptions (%s), then try again.',
                $installed ? 'installed but not active' : 'not installed',
                $url
            ),
            'data'    => [ 'plugin' => 'paid-member-subscriptions', 'plugins_url' => $url ],
        ];
    }

    /** @return true|array{code: string, message: string, data: array} */
    private static function ready()
    {
        $present = self::presence();
        if (true !== $present) {
            return $present;
        }
        global $wpdb;
        if (null === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like(self::table())))) {
            return [
                'code'    => 'pms_tables_missing',
                'message' => sprintf('Paid Member Subscriptions\' table %s does not exist on this site. Deactivate and reactivate Paid Member Subscriptions so it creates its tables, then try again.', self::table()),
                'data'    => [ 'plugins_url' => admin_url('plugins.php') ],
            ];
        }
        return true;
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $base = [
            'mode'       => 'read',
            'capability' => 'manage_options',
            'requires'   => static fn () => self::ready(),
        ];

        return [
            'pms-list-plans'   => $base + [
                'description'  => 'List Paid Member Subscriptions plans: id, name, status (active, inactive), price, sign-up fee, duration ("unlimited" when it never expires), trial, recurring, user role, group parent and active member count',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => static fn (): array => self::list_plans(),
            ],
            'pms-list-members' => $base + [
                'description'  => 'List Paid Member Subscriptions member subscriptions newest first: id, user id, name and email MASKED, plan id and name, status, start, expiration, billing amount and cycle, gateway, next payment. Filter by plan_id and status (active, canceled, expired, pending, abandoned, completed). page, per_page (max 100)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'plan_id'  => [ 'type' => 'integer', 'minimum' => 1 ],
                        'status'   => [ 'type' => 'string', 'enum' => self::STATUSES ],
                        'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
                        'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE ],
                    ],
                ],
                'handler'      => static fn (array $args): array => self::list_members($args),
            ],
        ];
    }

    private static function list_plans(): array
    {
        $posts = Readable_Posts::get_posts([
            'post_type'      => self::POST_TYPE,
            'post_status'    => [ 'publish', 'draft', 'pending', 'private', 'future' ],
            'posts_per_page' => 500,
            'orderby'        => [ 'menu_order' => 'ASC', 'ID' => 'ASC' ],
            'no_found_rows'  => true,
        ]);
        $counts = self::active_counts();
        $plans  = [];
        foreach ($posts as $post) {
            $id       = (int) $post->ID;
            $meta     = static fn (string $key): string => (string) get_post_meta($id, 'pms_subscription_plan_' . $key, true);
            $duration = (int) $meta('duration');
            $trial    = (int) $meta('trial_duration');
            $plans[]  = [
                'id'             => $id,
                'name'           => (string) $post->post_title,
                'status'         => '' === $meta('status') ? 'active' : $meta('status'),
                'price'          => (float) $meta('price'),
                'sign_up_fee'    => (float) $meta('sign_up_fee'),
                'duration'       => 0 === $duration ? 'unlimited' : $duration . ' ' . $meta('duration_unit'),
                'trial'          => 0 === $trial ? null : $trial . ' ' . $meta('trial_duration_unit'),
                'recurring'      => '' !== $meta('recurring') && '0' !== $meta('recurring'),
                'user_role'      => $meta('user_role'),
                'type'           => '' === $meta('type') ? 'regular' : $meta('type'),
                'parent_id'      => (int) $post->post_parent,
                'active_members' => $counts[ $id ] ?? 0,
            ];
        }
        return [ 'plans' => $plans, 'total' => count($plans) ];
    }

    /** @return array<int, int> plan id => active member subscriptions */
    private static function active_counts(): array
    {
        global $wpdb;
        $rows   = $wpdb->get_results(
            $wpdb->prepare("SELECT subscription_plan_id AS plan, COUNT(*) AS n FROM %i WHERE status = 'active' GROUP BY subscription_plan_id", self::table()),
            ARRAY_A
        );
        $counts = [];
        foreach ((array) $rows as $row) {
            $counts[ (int) $row['plan'] ] = (int) $row['n'];
        }
        return $counts;
    }

    private static function list_members(array $args): array
    {
        global $wpdb;
        $per_page = min(self::MAX_PER_PAGE, max(1, (int) ($args['per_page'] ?? 20)));
        $page     = max(1, (int) ($args['page'] ?? 1));
        $where    = '';
        if (isset($args['plan_id'])) {
            $where .= $wpdb->prepare(' AND subscription_plan_id = %d', (int) $args['plan_id']);
        }
        if (isset($args['status'])) {
            $where .= $wpdb->prepare(' AND status = %s', (string) $args['status']);
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built from prepared fragments only.
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE 1=1{$where}", self::table()));
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built from prepared fragments only.
                "SELECT id, user_id, subscription_plan_id, start_date, expiration_date, status, payment_gateway, billing_amount, billing_duration, billing_duration_unit, billing_cycles, billing_next_payment, billing_last_payment, trial_end FROM %i WHERE 1=1{$where} ORDER BY start_date DESC, id DESC LIMIT %d OFFSET %d",
                self::table(),
                $per_page,
                ($page - 1) * $per_page
            ),
            ARRAY_A
        );
        $members = [];
        foreach ((array) $rows as $r) {
            $user      = get_userdata((int) $r['user_id']);
            $plan      = get_post((int) $r['subscription_plan_id']);
            $members[] = Masking::keys([
                'id'                   => (int) $r['id'],
                'user_id'              => (int) $r['user_id'],
                'name'                 => $user instanceof \WP_User ? (string) $user->display_name : '',
                'email'                => $user instanceof \WP_User ? (string) $user->user_email : '',
                'plan_id'              => (int) $r['subscription_plan_id'],
                'plan_name'            => $plan instanceof \WP_Post ? (string) $plan->post_title : '',
                'status'               => (string) $r['status'],
                'start_date'           => $r['start_date'],
                'expiration_date'      => $r['expiration_date'],
                'billing_amount'       => (float) $r['billing_amount'],
                'billing_cycle'        => (int) $r['billing_duration'] > 0 ? (int) $r['billing_duration'] . ' ' . (string) $r['billing_duration_unit'] : null,
                'billing_cycles'       => (int) $r['billing_cycles'],
                'payment_gateway'      => (string) $r['payment_gateway'],
                'billing_next_payment' => $r['billing_next_payment'],
                'billing_last_payment' => $r['billing_last_payment'],
                'trial_end'            => $r['trial_end'],
            ], [ 'name', 'email' ]);
        }
        return [ 'members' => $members, 'total' => $total, 'page' => $page, 'per_page' => $per_page, 'masked' => true ];
    }
}
