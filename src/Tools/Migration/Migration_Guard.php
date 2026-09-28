<?php

namespace WPMCP\Tools\Migration;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The two default-off opt-in gates for site-to-site migration (issue #191),
 * in the same constant-plus-filter shape as Bridge_Guard and Wp_Cli_Guard,
 * and listed in Opt_In_Gates so the ability grid can never appear to open
 * them.
 *
 * Receiving (WPMCP_ACCEPT_INCOMING_MIGRATIONS / wpmcp_accept_incoming_migrations)
 * is gated because an accepted push replaces the target's whole database.
 * The failure this prevents is not an attacker, it is a typo: a target URL
 * pointing at production instead of staging. A site only accepts a
 * migration after its owner has said, on that site, that it may.
 *
 * Sending (WPMCP_ALLOW_OUTGOING_MIGRATIONS / wpmcp_allow_outgoing_migrations)
 * is gated because a pushed archive carries every password hash and secret
 * key on the site to a host named in the call. An agent steered by text it
 * read somewhere must not be able to do that on a site whose owner never
 * asked for migration.
 *
 * Opening a gate never widens access: both abilities still require
 * manage_options, governance and rate limiting on the site they run on,
 * and the push authenticates to the target as a user of the target.
 */
class Migration_Guard
{
    public static function accepts_incoming(): bool
    {
        $default = defined('WPMCP_ACCEPT_INCOMING_MIGRATIONS') && WPMCP_ACCEPT_INCOMING_MIGRATIONS;

        return (bool) apply_filters('wpmcp_accept_incoming_migrations', $default);
    }

    public static function allows_outgoing(): bool
    {
        $default = defined('WPMCP_ALLOW_OUTGOING_MIGRATIONS') && WPMCP_ALLOW_OUTGOING_MIGRATIONS;

        return (bool) apply_filters('wpmcp_allow_outgoing_migrations', $default);
    }

    public static function incoming_disabled_error(): \WP_Error
    {
        return new \WP_Error(
            'wpmcp_migration_receive_disabled',
            'This site does not accept incoming migrations (default). Its owner can allow them with define(\'WPMCP_ACCEPT_INCOMING_MIGRATIONS\', true) or the wpmcp_accept_incoming_migrations filter, on this site, for the duration of the move.',
            ['status' => 403]
        );
    }

    public static function outgoing_disabled_error(): \WP_Error
    {
        return new \WP_Error(
            'wpmcp_migration_send_disabled',
            'Pushing a site archive to another site is disabled here (default). Allow it with define(\'WPMCP_ALLOW_OUTGOING_MIGRATIONS\', true) or the wpmcp_allow_outgoing_migrations filter.'
        );
    }
}
