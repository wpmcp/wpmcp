<?php

namespace WPMCP\Tools\Users;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Shared "is this an admin-capable user?" check for the user tools.
 *
 * The test is capability-based, not a role-name string match: any of these
 * capabilities means the account can manage the site or other users, so the
 * tools treat it as protected regardless of which role (or custom role)
 * granted them. Get_User uses this for its is_admin flag; Update_User uses it
 * to refuse editing such accounts. is_reachable() keeps both tools inside
 * the current site's membership on multisite.
 */
class Admin_Guard
{
    private const ADMIN_CAPS = ['manage_options', 'edit_users', 'promote_users', 'delete_users'];

    public static function is_admin_user(\WP_User $user): bool
    {
        foreach (self::ADMIN_CAPS as $cap) {
            if ($user->has_cap($cap)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the caller may reach $user at all (issue #560). On multisite
     * the users table is shared by every site, so a user who is not a
     * member of the current site is out of reach unless the caller manages
     * network users; the tools then answer exactly as for a missing user,
     * which is what core REST does (rest_user_invalid_id). Single site:
     * always reachable.
     */
    public static function is_reachable(\WP_User $user): bool
    {
        return ! is_multisite()
            || is_user_member_of_blog((int) $user->ID)
            || current_user_can('manage_network_users');
    }
}
