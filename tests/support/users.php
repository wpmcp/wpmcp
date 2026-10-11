<?php

/**
 * Deletes a user account the way the tests mean it, on single site and on a
 * network alike (issue #530). On multisite wp_delete_user() only removes the
 * user from the current site and leaves the account, so a test that checks
 * what happens once the account is gone deletes it from the network instead.
 */
function wpmcp_test_delete_account(int $user_id): void
{
    if (is_multisite()) {
        require_once ABSPATH . 'wp-admin/includes/ms.php';
        wpmu_delete_user($user_id);
        return;
    }

    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($user_id);
}

/**
 * Makes <user_id> the network's administrator on multisite, so a test written
 * for a single-site administrator (who holds unfiltered_html, install_plugins,
 * edit_users and the rest) means the same on a network, where WordPress keeps
 * those for super admins (issue #530). A no-op on single site.
 */
function wpmcp_test_grant_network_admin(int $user_id): void
{
    if (! is_multisite()) {
        return;
    }

    grant_super_admin($user_id);
    // set_current_user already ran kses_init() while the user lacked
    // unfiltered_html; run it again so the content filters match the grant.
    if (get_current_user_id() === $user_id) {
        kses_remove_filters();
        kses_init();
    }
}

/**
 * Denies <capability> to everyone for the rest of the test, super admins
 * included. A super admin skips the user_has_cap filter and user-level
 * denials, and only do_not_allow from map_meta_cap stops one, so a test that
 * takes one capability away from a network administrator does it here
 * (issue #530). WP_UnitTestCase restores the hooks after the test.
 */
function wpmcp_test_deny_capability(string $capability): void
{
    add_filter(
        'map_meta_cap',
        static fn (array $caps, string $cap): array => $capability === $cap ? [ 'do_not_allow' ] : $caps,
        10,
        2
    );
}
