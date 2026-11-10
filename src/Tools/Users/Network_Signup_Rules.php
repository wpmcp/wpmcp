<?php

namespace WPMCP\Tools\Users;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The multisite network's rules for new accounts, applied before
 * create-user writes anything (issue #561).
 *
 * Core's user-new.php (site and network admin alike) runs every new
 * account through wpmu_validate_user_signup(): banned email domains, the
 * limited email domains allow-list, illegal names, the multisite username
 * format and pending signups. create-user called wp_insert_user() directly
 * and skipped all of it. It also re-checks create_users inside the handler:
 * core maps that capability to do_not_allow for a site admin when the
 * network's "Add New Users" setting (add_new_users) is off, and the ability
 * layer already enforces it; the repeat keeps a path that reaches the
 * handler without the ability check from creating users either. A request
 * with no signed-in user is left to the ability layer, as in Network_Scope.
 *
 * Single site: no-op.
 */
class Network_Signup_Rules
{
    /**
     * @throws \RuntimeException When the network's rules refuse the account.
     */
    public static function assert_allowed(string $username, string $email): void
    {
        if (! is_multisite()) {
            return;
        }

        if (0 !== get_current_user_id() && ! current_user_can('create_users')) {
            throw new \RuntimeException('This network does not let site administrators add new users (Network Settings, Add New Users).');
        }

        $result = wpmu_validate_user_signup($username, $email);
        $errors = $result['errors'] ?? null;
        if ($errors instanceof \WP_Error && $errors->has_errors()) {
            throw new \RuntimeException(sprintf(
                'Could not create user: the network\'s signup rules refuse it (%s)',
                esc_html(implode(' ', $errors->get_error_messages()))
            ));
        }
    }
}
