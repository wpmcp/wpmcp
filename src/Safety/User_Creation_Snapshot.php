<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- ABSPATH guard is an intentional side effect.
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional (matches the rest of WPMCP\Safety).
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional (matches the rest of WPMCP\Safety).

namespace WPMCP\Safety;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The ledger row for a user create-user CREATED (issue #598).
 *
 * Like Post_Creation_Snapshot, there is no prior state to capture, so the
 * row records what the call made: the user id, the login, and a fingerprint
 * of the account as the call left it (the wp_users row, password hash and
 * activation key included, and every usermeta row). It holds no email
 * address or other profile value in the clear.
 *
 * Undoing it deletes the user, and only a user nothing depends on: one who
 * authored no post of any type or status, wrote no comment and owns no link,
 * whose account is unchanged since (a new password, a login, a profile or
 * role edit all change the fingerprint), and, on a network, who belongs to
 * this site only. Otherwise the undo is refused with the reason and nothing
 * is deleted or reassigned. It takes delete_users, the capability deleting
 * a user takes in wp-admin.
 *
 * The row is written AFTER the user exists (and after the new user email
 * set the activation key). If it cannot be written, the user this call just
 * created is deleted again and the error is rethrown: a creation is never
 * left behind without its undo point.
 */
class User_Creation_Snapshot
{
    public const OBJECT_TYPE = 'user_create';

    /**
     * @return string The operation id of the written row.
     * @throws Mutation_Failed When the row could not be written.
     */
    public static function record(string $tool_name, int $user_id, array $args, string $session_id): string
    {
        $user = get_userdata($user_id);
        if (! $user) {
            throw new \InvalidArgumentException('A creation row needs the created user.');
        }

        $operation_id = wp_generate_uuid4();
        try {
            Snapshot_Store::save(
                $operation_id,
                '' === $session_id ? 'default' : $session_id,
                [
                    'object_type' => self::OBJECT_TYPE,
                    'object_id'   => $user_id,
                    'data'        => [
                        'user_login'  => (string) $user->user_login,
                        'fingerprint' => self::fingerprint($user_id),
                    ],
                ],
                $tool_name,
                hash('sha256', (string) wp_json_encode($args))
            );
        } catch (\Throwable $e) {
            // No undo point, no creation. Created by this same call a moment
            // ago, so nothing else can reference it yet.
            self::delete($user_id);
            throw $e;
        }
        Operation_Context::note($operation_id);
        Snapshot_Store::prune();

        return $operation_id;
    }

    /**
     * Why undoing this creation would delete more than the call made, or
     * null when it may go ahead (also null for a user that is already gone:
     * there is nothing left to undo).
     */
    public static function refusal(array $snapshot): ?string
    {
        if (self::OBJECT_TYPE !== ($snapshot['object_type'] ?? '')) {
            return null;
        }
        $user_id = (int) ($snapshot['object_id'] ?? 0);
        $user    = $user_id > 0 ? get_userdata($user_id) : false;
        if (! $user) {
            return null;
        }
        if ((string) $user->user_login !== (string) ($snapshot['data']['user_login'] ?? '')) {
            return sprintf('user %d is not the user this operation created (the id was reclaimed), so it was kept.', $user_id);
        }
        if ($user_id === get_current_user_id()) {
            return sprintf('user %d is the user running this rollback, so it was kept.', $user_id);
        }
        $content = self::content_count($user_id);
        if ($content > 0) {
            return sprintf('user %d has %d item(s) of content (posts of any type or status, comments or links), so it was kept: undoing a creation never deletes or reassigns content.', $user_id, $content);
        }
        if (! hash_equals((string) ($snapshot['data']['fingerprint'] ?? ''), self::fingerprint($user_id))) {
            return sprintf('user %d changed since create-user made it (a new password, a login, or a profile, role or settings change), so it was kept.', $user_id);
        }
        if (is_multisite() && count(get_blogs_of_user($user_id, true)) > 1) {
            return sprintf('user %d belongs to other sites of this network since it was created, so it was kept.', $user_id);
        }
        return null;
    }

    /**
     * Delete the created user, unless refusal() objects (then warn and keep
     * it). A user that is already gone needs nothing.
     *
     * @return string|null A warning, or null.
     */
    public static function undo_creation(array $snapshot): ?string
    {
        $user_id = (int) ($snapshot['object_id'] ?? 0);
        if ($user_id <= 0 || ! get_userdata($user_id)) {
            return null;
        }
        $refused = self::refusal($snapshot);
        if (null !== $refused) {
            return 'Undo of create-user skipped: ' . $refused;
        }
        if (! self::delete($user_id)) {
            return sprintf('User %d created by this operation could not be deleted; it was left in place.', $user_id);
        }
        return null;
    }

    /**
     * Delete the user outright, with no reassignment: callers have made sure
     * there is nothing to reassign. On a network the user is removed from
     * the network, not only from this site, since it was made for this site.
     */
    private static function delete(int $user_id): bool
    {
        if (is_multisite()) {
            require_once ABSPATH . 'wp-admin/includes/ms.php';
            require_once ABSPATH . 'wp-admin/includes/user.php';
            return (bool) wpmu_delete_user($user_id);
        }
        require_once ABSPATH . 'wp-admin/includes/user.php';
        return (bool) wp_delete_user($user_id);
    }

    /** Posts (every type and status), comments and links the user owns on this site. */
    private static function content_count(int $user_id): int
    {
        global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- an exact count across every post type and status, which no core API gives uncached; read just before a delete.
        $posts    = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE post_author = %d', $wpdb->posts, $user_id));
        $comments = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE user_id = %d', $wpdb->comments, $user_id));
        $links    = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE link_owner = %d', $wpdb->links, $user_id));
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $posts + $comments + $links;
    }

    /** The wp_users row and every usermeta row of the user, as one hash. */
    private static function fingerprint(int $user_id): string
    {
        global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the raw rows, uncached: the fingerprint must see what is stored now, not a cached user object.
        $row  = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE ID = %d', $wpdb->users, $user_id), ARRAY_A);
        $meta = $wpdb->get_results($wpdb->prepare('SELECT meta_key, meta_value FROM %i WHERE user_id = %d ORDER BY meta_key, umeta_id', $wpdb->usermeta, $user_id), ARRAY_A);
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return hash('sha256', (string) wp_json_encode([ 'user' => $row, 'meta' => $meta ]));
    }
}
