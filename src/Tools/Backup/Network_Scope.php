<?php

namespace WPMCP\Tools\Backup;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Who may run the whole-database tools (issue #525).
 *
 * On multisite, Db_Dumper and the restore prefix check work on the BASE
 * prefix: a backup holds every site's tables plus the global ones
 * (users with their password hashes, usermeta, sitemeta, blogs), and a
 * restore can write any of them. That is network administration, so on
 * multisite these tools take manage_network_options (super admins), not
 * the manage_options every sub-site administrator holds on its own blog.
 * A sub-site admin restoring sitemeta with its own login in site_admins
 * was a full network takeover.
 *
 * Gating rather than scoping a sub-site caller to its own $wpdb->prefix
 * tables: the archive format, the restore prefix check and the import
 * policy all assume one whole install, and a per-site variant would need
 * its own format, table filter and restore rules to be safe. Refusing is
 * the fail-closed fix; a per-site backup can come later as its own
 * feature. Single site is unchanged: capability() is manage_options there
 * and assert_caller() never refuses.
 *
 * The ability layer enforces capability(). assert_caller() repeats the
 * check inside the engine (Db_Dumper, Restore_Site_Backup, Safety_Archive
 * and the migration handlers), so a path that reaches them without going
 * through an ability's permission check is refused too. A request with no
 * signed-in user (the WP-Cron run of a backup job already queued through
 * the gated trigger-backup) is let through: there is no caller to scope,
 * and the job could only have been queued by someone who passed the gate.
 */
class Network_Scope
{
    public const NETWORK_CAPABILITY = 'manage_network_options';

    /** The capability the whole-database tools are registered with. */
    public static function capability(): string
    {
        return is_multisite() ? self::NETWORK_CAPABILITY : 'manage_options';
    }

    /** Whether the current request may work on the whole database. */
    public static function allows_current_user(): bool
    {
        if (! is_multisite() || 0 === get_current_user_id()) {
            return true;
        }

        return current_user_can(self::NETWORK_CAPABILITY);
    }

    /**
     * @param string $what What was refused, for the message.
     * @throws \RuntimeException When a signed-in user on multisite lacks the network capability.
     */
    public static function assert_caller(string $what): void
    {
        if (self::allows_current_user()) {
            return;
        }

        throw new \RuntimeException(sprintf(
            '%s refused: on a multisite network this works on every site\'s tables and the network user table, so it needs a network administrator (manage_network_options).',
            esc_html($what)
        ));
    }
}
