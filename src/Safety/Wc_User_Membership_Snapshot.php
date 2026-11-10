<?php

// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional (matches the rest of WPMCP\Safety).
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional (matches the rest of WPMCP\Safety).

namespace WPMCP\Safety;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce Memberships snapshots (PR #573), and the one place wpmcp
 * reaches the Memberships API.
 *
 * A membership plan is a 'wc_membership_plan' post (access method, length
 * and products in its _access_* and _product_ids meta), its access rules
 * live in the 'wc_memberships_rules' option, and a user membership is a
 * 'wc_user_membership' post: its status is the post status with a 'wcm-'
 * prefix, its plan the post parent, its member the post author, and its
 * dates (_start_date, _end_date, _paused_date, _cancelled_date, UTC MySQL
 * datetimes) and links (_order_id, _product_id) post meta. Reads use that
 * storage directly; writes go through the plugin (wc_memberships_create_user_membership()
 * and WC_Memberships_User_Membership's pause_membership(),
 * activate_membership(), cancel_membership() and set_end_date()), so its
 * notes, emails and scheduled expiry events run as they do from wp-admin.
 * This storage and API are modelled from the plugin's public documentation
 * and have not yet been run against the real plugin.
 *
 * Two snapshot types:
 *
 *  - 'wc_user_membership': one membership's post fields and every meta key
 *    of its post. The restore writes them back directly (no status
 *    transition, so no emails on undo), then asks Memberships to reschedule
 *    its expiry and reminder events from the restored end date;
 *  - 'wc_user_membership_create': the creation row for a membership a tool
 *    granted; undoing it deletes that membership (when its creation time
 *    still matches).
 */
final class Wc_User_Membership_Snapshot
{
    public const TYPE        = 'wc_user_membership';
    public const CREATE_TYPE = 'wc_user_membership_create';

    /** The post types Memberships stores plans and user memberships in. */
    public const POST_TYPE      = 'wc_user_membership';
    public const PLAN_POST_TYPE = 'wc_membership_plan';

    /** The option holding every plan's access rules. */
    public const RULES_OPTION = 'wc_memberships_rules';

    /** The prefix Memberships puts on its post statuses. */
    public const STATUS_PREFIX = 'wcm-';

    /** User membership statuses, without the prefix, used when the plugin cannot be asked. */
    private const STATUSES = ['active', 'free_trial', 'delayed', 'complimentary', 'pending', 'paused', 'expired', 'cancelled'];

    /** Post meta that is WordPress's editing state, not the membership's. */
    private const SKIP_META = ['_edit_lock', '_edit_last'];

    /** @var object|null Test seam: stands in for the Memberships API. */
    private static $double = null;

    /**
     * Stand in for the Memberships API in the stub-backed suite: an object
     * with create(int $user_id, int $plan_id): int|WP_Error,
     * transition(int $id, string $action): bool (pause, resume, cancel),
     * set_end_date(int $id, string $utc): bool ('' for no end date),
     * remove(int $id): void and refresh(int $id): void. Ignored outside the
     * test suite.
     *
     * @param object|null $double
     */
    public static function set_double_for_tests($double): void
    {
        if (! defined('WPMCP_TESTING') || ! WPMCP_TESTING) {
            return;
        }
        self::$double = $double;
    }

    /** Whether WooCommerce Memberships is loaded (its main class and its wc_memberships() accessor). */
    public static function is_active(): bool
    {
        if (null !== self::$double) {
            return true;
        }
        return class_exists('WC_Memberships') && function_exists('wc_memberships') && function_exists('wc_memberships_get_user_membership');
    }

    // ------------------------------------------------------------ storage

    /**
     * One user membership as stored: post fields and every meta key of its
     * post, or null when the id names no user membership.
     *
     * @return array{id: int, status: string, plan_id: int, user_id: int, date_created: string, meta: array<string, array<int, mixed>>}|null
     */
    public static function row(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        clean_post_cache($id);
        $post = get_post($id);
        if (! $post instanceof \WP_Post || self::POST_TYPE !== $post->post_type) {
            return null;
        }
        $meta = [];
        foreach ((array) get_post_meta($id) as $key => $values) {
            if (in_array((string) $key, self::SKIP_META, true)) {
                continue;
            }
            $meta[ (string) $key ] = array_map('maybe_unserialize', (array) $values);
        }
        ksort($meta);
        return [
            'id'           => $id,
            'status'       => self::unprefixed((string) $post->post_status),
            'plan_id'      => (int) $post->post_parent,
            'user_id'      => (int) $post->post_author,
            'date_created' => (string) $post->post_date_gmt,
            'meta'         => $meta,
        ];
    }

    /** One meta value of a stored row (its first), or null. */
    public static function meta(array $row, string $key)
    {
        return $row['meta'][ $key ][0] ?? null;
    }

    /** The published membership plan an id names, or null. */
    public static function plan(int $id): ?\WP_Post
    {
        if ($id <= 0) {
            return null;
        }
        $post = get_post($id);
        return $post instanceof \WP_Post && self::PLAN_POST_TYPE === $post->post_type && 'publish' === $post->post_status ? $post : null;
    }

    /**
     * User membership statuses this site knows, without the prefix.
     *
     * @return string[]
     */
    public static function statuses(): array
    {
        if (null === self::$double && function_exists('wc_memberships_get_user_membership_statuses')) {
            $known = array_map(
                static fn ($status) => self::unprefixed((string) $status),
                array_keys((array) wc_memberships_get_user_membership_statuses())
            );
            if ([] !== $known) {
                return array_values(array_unique($known));
            }
        }
        return self::STATUSES;
    }

    /**
     * Statuses as the post statuses they are stored under (every known one
     * by default), named explicitly because 'any' skips statuses a plugin
     * registers as excluded from search.
     *
     * @param string[]|null $statuses
     * @return string[]
     */
    public static function post_statuses(?array $statuses = null): array
    {
        return array_map(static fn ($s) => self::STATUS_PREFIX . $s, $statuses ?? self::statuses());
    }

    public static function unprefixed(string $status): string
    {
        return str_starts_with($status, self::STATUS_PREFIX) ? substr($status, strlen(self::STATUS_PREFIX)) : $status;
    }

    // -------------------------------------------------------------- calls

    /**
     * Grant a plan to a user through wc_memberships_create_user_membership(),
     * as the plugin's add member screen does. Returns the membership id.
     *
     * @return int|\WP_Error
     */
    public static function create(int $user_id, int $plan_id)
    {
        if (null !== self::$double) {
            return self::$double->create($user_id, $plan_id);
        }
        if (! function_exists('wc_memberships_create_user_membership')) {
            return new \WP_Error('memberships_inactive', 'WooCommerce Memberships is not loaded.');
        }
        try {
            $membership = wc_memberships_create_user_membership([ 'plan_id' => $plan_id, 'user_id' => $user_id ], 'create');
        } catch (\Throwable $e) {
            return new \WP_Error('grant_failed', $e->getMessage());
        }
        if (is_wp_error($membership)) {
            return $membership;
        }
        return is_object($membership) && method_exists($membership, 'get_id') ? (int) $membership->get_id() : new \WP_Error('grant_failed', 'WooCommerce Memberships returned no membership.');
    }

    /**
     * Pause, resume or cancel a membership through the plugin's own
     * WC_Memberships_User_Membership methods, so its notes, emails and
     * scheduled events run.
     */
    public static function transition(int $id, string $action): bool
    {
        if (null !== self::$double) {
            return (bool) self::$double->transition($id, $action);
        }
        $method = [ 'pause' => 'pause_membership', 'resume' => 'activate_membership', 'cancel' => 'cancel_membership' ][ $action ] ?? '';
        $membership = self::membership($id);
        if ('' === $method || null === $membership || ! method_exists($membership, $method)) {
            return false;
        }
        $membership->$method('Changed through WP MCP.');
        return true;
    }

    /** Set a membership's end date (UTC MySQL datetime, '' for none) through set_end_date(), which reschedules its expiry. */
    public static function set_end_date(int $id, string $utc): bool
    {
        if (null !== self::$double) {
            return (bool) self::$double->set_end_date($id, $utc);
        }
        $membership = self::membership($id);
        if (null === $membership || ! method_exists($membership, 'set_end_date')) {
            return false;
        }
        $membership->set_end_date($utc);
        return true;
    }

    /** Delete a membership for good (the plugin's delete hooks run). */
    public static function remove(int $id): void
    {
        if (null !== self::$double) {
            self::$double->remove($id);
            return;
        }
        wp_delete_post($id, true);
    }

    /**
     * After a membership's fields were written back directly: let
     * Memberships schedule (or clear) its expiry and reminder events from
     * the restored end date.
     */
    public static function refresh(int $id): void
    {
        if (null !== self::$double) {
            self::$double->refresh($id);
            return;
        }
        $membership = self::membership($id);
        if (null === $membership || ! method_exists($membership, 'set_end_date')) {
            return;
        }
        $membership->set_end_date((string) get_post_meta($id, '_end_date', true));
    }

    private static function membership(int $id): ?object
    {
        if (! function_exists('wc_memberships_get_user_membership')) {
            return null;
        }
        $membership = wc_memberships_get_user_membership($id);
        return is_object($membership) ? $membership : null;
    }

    // ---------------------------------------------------------- snapshots

    public static function capture(int $id): array
    {
        $row = self::row($id);
        if (null === $row) {
            return [
                'object_type' => self::TYPE,
                'object_id'   => $id,
                'data'        => [ 'exists' => false ],
            ];
        }
        $post = get_post($id);

        return [
            'object_type' => self::TYPE,
            'object_id'   => $id,
            'data'        => [
                'exists'      => true,
                'post_status' => (string) $post->post_status,
                'plan_id'     => $row['plan_id'],
                'user_id'     => $row['user_id'],
                'meta'        => $row['meta'],
            ],
        ];
    }

    /**
     * Put a membership back to a 'wc_user_membership' snapshot. Returns a
     * warning when part of it could not be restored, else null.
     */
    public static function restore(array $snapshot): ?string
    {
        global $wpdb;

        $data = (array) ($snapshot['data'] ?? []);
        $id   = (int) ($snapshot['object_id'] ?? 0);
        if (empty($data['exists'])) {
            return null;
        }
        $row = self::row($id);
        if (null === $row) {
            return "User membership {$id} no longer exists, so its snapshot could not be restored.";
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a restore writes the stored fields without a status transition (no emails on undo); the post cache is cleaned below.
        $written = $wpdb->update(
            $wpdb->posts,
            [
                'post_status' => (string) $data['post_status'],
                'post_parent' => (int) ($data['plan_id'] ?? 0),
                'post_author' => (int) ($data['user_id'] ?? 0),
            ],
            [ 'ID' => $id ]
        );
        clean_post_cache($id);

        $wanted = (array) ($data['meta'] ?? []);
        foreach (array_keys($row['meta']) as $key) {
            if (! array_key_exists($key, $wanted)) {
                delete_post_meta($id, $key);
            }
        }
        foreach ($wanted as $key => $values) {
            $key = (string) $key;
            if (in_array($key, self::SKIP_META, true)) {
                continue;
            }
            if (($row['meta'][ $key ] ?? null) === $values) {
                continue;
            }
            delete_post_meta($id, $key);
            foreach ((array) $values as $value) {
                add_post_meta($id, $key, wp_slash($value));
            }
        }
        clean_post_cache($id);

        self::refresh($id);

        return false === $written ? "User membership {$id}'s status could not be put back." : null;
    }

    /**
     * Write the creation row for a membership a tool just granted. If the
     * row cannot be written the membership is deleted again and the error
     * rethrown.
     *
     * @param array<string, mixed> $args
     */
    public static function record_creation(int $id, string $tool_name, array $args, string $session_id): string
    {
        $row          = self::row($id);
        $operation_id = wp_generate_uuid4();
        try {
            Snapshot_Store::save(
                $operation_id,
                '' === $session_id ? 'default' : $session_id,
                [
                    'object_type' => self::CREATE_TYPE,
                    'object_id'   => $id,
                    'data'        => [ 'date_created' => (string) ($row['date_created'] ?? '') ],
                ],
                $tool_name,
                hash('sha256', (string) wp_json_encode($args))
            );
        } catch (\Throwable $e) {
            self::remove($id);
            throw $e;
        }
        Operation_Context::note($operation_id);
        Snapshot_Store::prune();

        return $operation_id;
    }

    /** Undo a 'wc_user_membership_create' row: delete the membership it granted. Returns a warning, or null. */
    public static function undo_creation(array $snapshot): ?string
    {
        $id  = (int) ($snapshot['object_id'] ?? 0);
        $row = self::row($id);
        if (null === $row) {
            return null; // Already gone: nothing to undo.
        }
        if ((string) ($snapshot['data']['date_created'] ?? '') !== $row['date_created']) {
            return "User membership {$id} is not the membership this operation granted (the id was reclaimed); it was left untouched.";
        }
        self::remove($id);
        return null === self::row($id) ? null : "User membership {$id} granted by this operation could not be deleted; it was left in place.";
    }
}
