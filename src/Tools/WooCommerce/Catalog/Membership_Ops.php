<?php

namespace WPMCP\Tools\WooCommerce\Catalog;

use WPMCP\Safety\Wc_User_Membership_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce Memberships ops behind the memberships.* rows (PR #573), run
 * in-process, so every response is a curated view and every write goes
 * through the calls the plugin's own screens make:
 *
 *  - reads come straight from Memberships' storage
 *    (Wc_User_Membership_Snapshot::row(), plan posts and the rules option);
 *  - grant creates a membership through wc_memberships_create_user_membership().
 *    It can send the plugin's activation email, so it needs confirm:true;
 *  - pause, resume and cancel go through the membership's own
 *    pause_membership(), activate_membership() and cancel_membership(), so
 *    its notes, emails and scheduled events run. Resume can email, so it
 *    needs confirm:true; cancel is destructive (off by default,
 *    confirm:true) and not recoverable;
 *  - extend moves the end date through set_end_date(), which reschedules
 *    the plugin's expiry and reminder emails.
 *
 * Every write is snapshotted (Safety\Wc_User_Membership_Snapshot), so
 * rollback-operation undoes it; a grant's rollback deletes the membership.
 */
final class Membership_Ops
{
    private const MAX_PER_PAGE     = 50;
    private const DEFAULT_PER_PAGE = 20;

    /** Statuses a membership can be paused from. */
    private const PAUSABLE = ['active', 'complimentary', 'free_trial'];

    /** Statuses a membership can be cancelled or extended from. */
    private const OPEN = ['active', 'complimentary', 'free_trial', 'delayed', 'pending', 'paused'];

    /** Rule fields shown by memberships.plans, as the rules option stores them. */
    private const RULE_FIELDS = ['rule_type', 'content_type', 'content_type_name', 'object_ids', 'access_type', 'access_schedule', 'discount_type', 'discount_amount', 'active'];

    /** Said on every write that can email: what a rollback cannot take back. */
    private const EMAIL_NOTE = 'WooCommerce Memberships may email the member about this (its membership emails, when enabled); a rollback restores the membership but cannot unsend them.';

    /** Said on every write: the plugin's note on the membership stays. */
    private const NOTE_NOTE = ' Memberships adds a note to the membership, which a rollback leaves as history.';

    // ------------------------------------------------------------- reads

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function read(string $handler, array $params): array
    {
        try {
            switch ($handler) {
                case 'membership_plans':
                    return [ 'status' => 200, 'body' => self::plans($params) ];
                case 'membership_members':
                    return [ 'status' => 200, 'body' => self::members($params) ];
                case 'membership_user':
                    return [ 'status' => 200, 'body' => self::of_user($params) ];
            }
        } catch (Order_Op_Refused $e) {
            return Op_Guard::error($e->code_name, $e->getMessage(), $e->data);
        }
        return Op_Guard::error('unknown_op', "No membership read named \"{$handler}\".");
    }

    /** @return array<string, mixed> */
    private static function plans(array $params): array
    {
        self::refuse_unknown($params, ['page', 'per_page'], 'memberships.plans');
        $page     = self::positive($params['page'] ?? 1, 'page');
        $per_page = min(self::MAX_PER_PAGE, self::positive($params['per_page'] ?? self::DEFAULT_PER_PAGE, 'per_page'));

        $query = new \WP_Query([
            'post_type'           => Wc_User_Membership_Snapshot::PLAN_POST_TYPE,
            'post_status'         => 'publish',
            'posts_per_page'      => $per_page,
            'paged'               => $page,
            'orderby'             => 'ID',
            'order'               => 'ASC',
            'ignore_sticky_posts' => true,
        ]);

        $rules = get_option(Wc_User_Membership_Snapshot::RULES_OPTION, []);
        $rules = is_array($rules) ? $rules : [];
        $rows  = [];
        foreach ($query->posts as $plan) {
            $rows[] = self::plan_view($plan, $rules);
        }
        return [ 'page' => $page, 'per_page' => $per_page, 'total' => (int) $query->found_posts, 'plans' => $rows ];
    }

    /** @return array<string, mixed> */
    private static function members(array $params): array
    {
        self::refuse_unknown($params, ['plan_id', 'status', 'page', 'per_page'], 'memberships.members');
        $known    = Wc_User_Membership_Snapshot::statuses();
        $statuses = $known;
        if (array_key_exists('status', $params)) {
            $statuses = [];
            foreach ((array) $params['status'] as $status) {
                $status = is_string($status) ? trim($status) : '';
                if (! in_array($status, $known, true)) {
                    self::refuse('invalid_params', 'status must be one of: ' . implode(', ', $known) . '.', [ 'allowed' => $known ]);
                }
                $statuses[] = $status;
            }
        }
        $page     = self::positive($params['page'] ?? 1, 'page');
        $per_page = min(self::MAX_PER_PAGE, self::positive($params['per_page'] ?? self::DEFAULT_PER_PAGE, 'per_page'));

        $args = [
            'post_type'           => Wc_User_Membership_Snapshot::POST_TYPE,
            'post_status'         => Wc_User_Membership_Snapshot::post_statuses($statuses),
            'posts_per_page'      => $per_page,
            'paged'               => $page,
            'fields'              => 'ids',
            'orderby'             => 'ID',
            'order'               => 'DESC',
            'ignore_sticky_posts' => true,
        ];
        if (array_key_exists('plan_id', $params)) {
            $args['post_parent'] = self::existing_plan($params['plan_id'])->ID;
        }
        $query = new \WP_Query($args);

        $rows = [];
        foreach ($query->posts as $id) {
            $row = Wc_User_Membership_Snapshot::row((int) $id);
            if (null !== $row) {
                $user   = get_userdata($row['user_id']);
                $rows[] = self::view($row) + [
                    'user_email'   => $user ? (string) $user->user_email : null,
                    'display_name' => $user ? (string) $user->display_name : null,
                ];
            }
        }
        return [ 'page' => $page, 'per_page' => $per_page, 'total' => (int) $query->found_posts, 'members' => $rows ];
    }

    /** @return array<string, mixed> */
    private static function of_user(array $params): array
    {
        self::refuse_unknown($params, ['user_id'], 'memberships.user');
        $user_id = self::user($params['user_id'] ?? null);

        $ids  = get_posts([
            'post_type'      => Wc_User_Membership_Snapshot::POST_TYPE,
            'post_status'    => Wc_User_Membership_Snapshot::post_statuses(),
            'author'         => $user_id,
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ]);
        $rows = [];
        foreach ($ids as $id) {
            $row = Wc_User_Membership_Snapshot::row((int) $id);
            if (null !== $row) {
                $rows[] = self::view($row);
            }
        }
        return [ 'user_id' => $user_id, 'memberships' => $rows ];
    }

    // ------------------------------------------------------------ writes

    /**
     * Validate one write. Returns a structured error, or ['body' => plan,
     * 'report' => what the response says beside the result]. Writes
     * nothing.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function prepare(string $handler, array $params, bool $confirm = false): array
    {
        try {
            switch ($handler) {
                case 'membership_grant':
                    return self::prepare_grant($params, $confirm);
                case 'membership_pause':
                    return self::prepare_transition($params, 'pause', self::PAUSABLE, false, $confirm);
                case 'membership_resume':
                    return self::prepare_transition($params, 'resume', ['paused'], true, $confirm);
                case 'membership_cancel':
                    return self::prepare_transition($params, 'cancel', self::OPEN, false, $confirm);
                case 'membership_extend':
                    return self::prepare_extend($params);
            }
        } catch (Order_Op_Refused $e) {
            return Op_Guard::error($e->code_name, $e->getMessage(), $e->data);
        }
        return Op_Guard::error('unknown_op', "No membership write named \"{$handler}\".");
    }

    /**
     * Grant the membership a validated memberships.grant planned, and record
     * its creation row.
     *
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    public static function create(array $plan, string $session_id, string $op): array
    {
        $made = Wc_User_Membership_Snapshot::create((int) $plan['user_id'], (int) $plan['plan_id']);
        if (is_wp_error($made)) {
            return Op_Guard::error('membership_grant_failed', 'WooCommerce Memberships did not grant the membership: ' . wp_strip_all_tags($made->get_error_message()) . ' Nothing was created.');
        }
        $id = (int) $made;
        if ($id <= 0 || null === Wc_User_Membership_Snapshot::row($id)) {
            return Op_Guard::error('membership_grant_failed', 'WooCommerce Memberships did not grant the membership. Nothing was created.');
        }
        $operation_id = Wc_User_Membership_Snapshot::record_creation($id, 'woo-write', [ 'op' => $op, 'params' => $plan ], $session_id);

        return [
            'status'       => 201,
            'body'         => self::view((array) Wc_User_Membership_Snapshot::row($id)),
            'operation_id' => $operation_id,
        ];
    }

    /**
     * Apply a validated write. Runs inside Safe_Mutation, after the
     * snapshot is written.
     *
     * @param array<string, mixed> $plan
     * @return array{status: int, body: mixed}
     */
    public static function apply(string $handler, int $id, array $plan): array
    {
        try {
            $done = 'membership_extend' === $handler
                ? Wc_User_Membership_Snapshot::set_end_date($id, (string) $plan['end_date'])
                : Wc_User_Membership_Snapshot::transition($id, (string) $plan['action']);
        } catch (\Throwable $e) {
            return [ 'status' => 409, 'body' => [ 'code' => 'membership_refused', 'message' => 'WooCommerce Memberships refused the change: ' . $e->getMessage() ] ];
        }
        if (! $done) {
            return [ 'status' => 409, 'body' => [ 'code' => 'membership_refused', 'message' => "WooCommerce Memberships did not change membership {$id}." ] ];
        }
        $row = Wc_User_Membership_Snapshot::row($id);
        return [ 'status' => 200, 'body' => null === $row ? null : self::view($row) ];
    }

    /** @return array<string, mixed> */
    private static function prepare_grant(array $params, bool $confirm): array
    {
        self::refuse_unknown($params, ['user_id', 'plan_id'], 'memberships.grant');
        $user_id = self::user($params['user_id'] ?? null);
        $plan    = self::existing_plan($params['plan_id'] ?? null);

        $held = get_posts([
            'post_type'      => Wc_User_Membership_Snapshot::POST_TYPE,
            'post_status'    => Wc_User_Membership_Snapshot::post_statuses(),
            'author'         => $user_id,
            'post_parent'    => $plan->ID,
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);
        if ([] !== $held) {
            $row = Wc_User_Membership_Snapshot::row((int) $held[0]);
            self::refuse(
                'already_member',
                "User {$user_id} already has membership " . (int) $held[0] . " in plan {$plan->ID} (" . ($row['status'] ?? 'unknown') . '). Resume or extend it instead.',
                [ 'membership_id' => (int) $held[0], 'status' => $row['status'] ?? null ]
            );
        }
        if (! $confirm) {
            self::refuse('confirmation_required', "Granting plan {$plan->ID} to user {$user_id} needs confirm:true. " . self::EMAIL_NOTE, [ 'plan' => $plan->post_title ]);
        }

        return [
            'body'   => [ 'user_id' => $user_id, 'plan_id' => $plan->ID ],
            'report' => [ 'side_effects' => self::EMAIL_NOTE . ' The member gets the plan\'s access, discounts and its scheduled expiry.' ],
        ];
    }

    /**
     * @param string[] $from
     * @return array<string, mixed>
     */
    private static function prepare_transition(array $params, string $action, array $from, bool $needs_confirm, bool $confirm): array
    {
        $op = 'memberships.' . $action;
        self::refuse_unknown($params, ['id'], $op);
        $row = self::existing($params);
        if (! in_array($row['status'], $from, true)) {
            self::refuse(
                'status_not_allowed',
                "Membership {$row['id']} is {$row['status']}; {$op} applies to " . implode(', ', $from) . ' memberships.',
                [ 'current' => $row['status'], 'allowed_from' => $from ]
            );
        }
        if ($needs_confirm && ! $confirm) {
            self::refuse('confirmation_required', "{$op} on membership {$row['id']} needs confirm:true. " . self::EMAIL_NOTE, [ 'current' => $row['status'] ]);
        }

        $effects = [
            'pause'  => 'The member loses the plan\'s access while paused.' . self::NOTE_NOTE,
            'resume' => self::EMAIL_NOTE . ' The member gets the plan\'s access back.' . self::NOTE_NOTE,
            'cancel' => self::EMAIL_NOTE . ' The member loses access now, and a subscription tied to the membership may react.' . self::NOTE_NOTE,
        ][ $action ];

        return [
            'body'   => [ 'id' => $row['id'], 'action' => $action ],
            'report' => [ 'side_effects' => $effects ],
        ];
    }

    /** @return array<string, mixed> */
    private static function prepare_extend(array $params): array
    {
        self::refuse_unknown($params, ['id', 'end_date'], 'memberships.extend');
        $row = self::existing($params);
        if (! in_array($row['status'], self::OPEN, true)) {
            self::refuse('status_not_allowed', "Membership {$row['id']} is {$row['status']}; grant a new membership instead.", [ 'current' => $row['status'] ]);
        }
        if (! array_key_exists('end_date', $params)) {
            self::refuse('invalid_params', 'end_date is required: "YYYY-MM-DD" or "YYYY-MM-DD HH:MM:SS" (UTC), or null for no end date.');
        }

        $current = (string) Wc_User_Membership_Snapshot::meta($row, '_end_date');
        $end     = '';
        if (null !== $params['end_date']) {
            $end   = self::utc($params['end_date']);
            $floor = max(time(), '' === $current ? 0 : (int) strtotime($current . ' UTC'));
            if ((int) strtotime($end . ' UTC') <= $floor) {
                self::refuse('invalid_params', 'end_date must be in the future and later than the current end date' . ('' === $current ? '' : " ({$current} UTC)") . '.', [ 'current_end_date' => '' === $current ? null : $current ]);
            }
        } elseif ('' === $current) {
            self::refuse('invalid_params', "Membership {$row['id']} already has no end date.");
        }

        return [
            'body'   => [ 'id' => $row['id'], 'end_date' => $end ],
            'report' => [
                'previous_end_date' => '' === $current ? null : $current,
                'side_effects'      => 'Memberships reschedules its expiry and its ending-soon and renewal reminder emails from the new date.',
            ],
        ];
    }

    // ------------------------------------------------------------- views

    /** @return array<string, mixed> */
    private static function view(array $row): array
    {
        $plan = Wc_User_Membership_Snapshot::plan($row['plan_id']);
        $date = static function (string $key) use ($row): ?string {
            $value = (string) Wc_User_Membership_Snapshot::meta($row, $key);
            return '' === $value ? null : $value;
        };
        return [
            'id'              => $row['id'],
            'status'          => $row['status'],
            'plan_id'         => $row['plan_id'],
            'plan_name'       => $plan ? $plan->post_title : null,
            'user_id'         => $row['user_id'],
            'start_date'      => $date('_start_date'),
            'end_date'        => $date('_end_date'),
            'paused_date'     => $date('_paused_date'),
            'cancelled_date'  => $date('_cancelled_date'),
            'order_id'        => (int) Wc_User_Membership_Snapshot::meta($row, '_order_id'),
            'product_id'      => (int) Wc_User_Membership_Snapshot::meta($row, '_product_id'),
            'subscription_id' => (int) Wc_User_Membership_Snapshot::meta($row, '_subscription_id'),
        ];
    }

    /**
     * @param array<int, mixed> $rules
     * @return array<string, mixed>
     */
    private static function plan_view(\WP_Post $plan, array $rules): array
    {
        $own = [];
        foreach ($rules as $rule) {
            if (is_array($rule) && (int) ($rule['membership_plan_id'] ?? 0) === $plan->ID) {
                $own[] = array_intersect_key($rule, array_flip(self::RULE_FIELDS));
            }
        }
        $counts = [];
        foreach (Wc_User_Membership_Snapshot::statuses() as $status) {
            $n = count(get_posts([
                'post_type'      => Wc_User_Membership_Snapshot::POST_TYPE,
                'post_status'    => Wc_User_Membership_Snapshot::STATUS_PREFIX . $status,
                'post_parent'    => $plan->ID,
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ]));
            if ($n > 0) {
                $counts[ $status ] = $n;
            }
        }
        $products = get_post_meta($plan->ID, '_product_ids', true);
        return [
            'id'                => $plan->ID,
            'name'              => $plan->post_title,
            'slug'              => $plan->post_name,
            'access_method'     => (string) get_post_meta($plan->ID, '_access_method', true),
            'access_length'     => (string) get_post_meta($plan->ID, '_access_length', true),
            'access_start_date' => (string) get_post_meta($plan->ID, '_access_start_date', true),
            'access_end_date'   => (string) get_post_meta($plan->ID, '_access_end_date', true),
            'product_ids'       => array_values(array_map('intval', is_array($products) ? $products : [])),
            'members'           => (object) $counts,
            'rules'             => $own,
        ];
    }

    // -------------------------------------------------------- validation

    /** @return array{id: int, status: string, plan_id: int, user_id: int, date_created: string, meta: array<string, array<int, mixed>>} */
    private static function existing(array $params): array
    {
        $raw = $params['id'] ?? null;
        $id  = is_int($raw) || (is_string($raw) && ctype_digit($raw)) ? (int) $raw : 0;
        if ($id <= 0) {
            self::refuse('invalid_params', 'id must be a positive integer user membership id.');
        }
        $row = Wc_User_Membership_Snapshot::row($id);
        if (null === $row) {
            self::refuse('unknown_membership', "No user membership has id {$id}. Find one with memberships.members or memberships.user.", [ 'id' => $id ]);
        }
        return $row;
    }

    private static function existing_plan($raw): \WP_Post
    {
        $id   = self::positive($raw, 'plan_id');
        $plan = Wc_User_Membership_Snapshot::plan($id);
        if (null === $plan) {
            self::refuse('unknown_plan', "No published membership plan has id {$id}. List them with memberships.plans.", [ 'plan_id' => $id ]);
        }
        return $plan;
    }

    private static function user($raw): int
    {
        $id = self::positive($raw, 'user_id');
        if (false === get_userdata($id)) {
            self::refuse('invalid_params', "No user has id {$id}.");
        }
        return $id;
    }

    /** A UTC date or datetime as a MySQL datetime. */
    private static function utc($value): string
    {
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2}):(\d{2}))?$/', trim($value), $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            self::refuse('invalid_params', 'end_date must be "YYYY-MM-DD" or "YYYY-MM-DD HH:MM:SS" (UTC), or null for no end date.');
        }
        $time = isset($m[4]) && '' !== $m[4] ? "{$m[4]}:{$m[5]}:{$m[6]}" : '23:59:59';
        if (isset($m[4]) && '' !== $m[4] && ((int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 59)) {
            self::refuse('invalid_params', 'end_date has an invalid time.');
        }
        return "{$m[1]}-{$m[2]}-{$m[3]} {$time}";
    }

    private static function positive($value, string $name): int
    {
        $n = is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : 0;
        if ($n < 1) {
            self::refuse('invalid_params', "{$name} must be a positive integer.");
        }
        return $n;
    }

    /** @param array<string, mixed> $data */
    private static function refuse(string $code, string $message, array $data = []): never
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught and returned as a JSON error, never rendered.
        throw new Order_Op_Refused($code, $message, $data);
    }

    private static function refuse_unknown(array $given, array $allowed, string $where): void
    {
        $unknown = array_values(array_diff(array_map('strval', array_keys($given)), $allowed));
        if ([] !== $unknown) {
            self::refuse(
                'invalid_params',
                'Unknown field(s) for ' . $where . ': ' . implode(', ', $unknown) . '. Accepted: ' . implode(', ', $allowed) . '.',
                [ 'unknown' => $unknown ]
            );
        }
    }
}
