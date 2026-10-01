<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- ABSPATH guard is an intentional side effect.

namespace WPMCP;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Uninstall (issue #468): leave no plugin cron event on any site.
 *
 * Two entry points, one per kind of build, because WordPress runs a plugin's
 * uninstall.php INSTEAD of a registered uninstall hook:
 *
 *  - the directory builds (wp.org, WooCommerce) ship uninstall.php, which
 *    loads this file and Cron_Registry directly (no autoloader) and calls
 *    uninstall();
 *  - the self-hosted build ships no uninstall.php, so the licensing SDK's
 *    own uninstall hook still runs, and Freemius\Bootstrap attaches
 *    uninstall() to its after_uninstall action.
 *
 * Deactivation already cleared the events (a plugin cannot be deleted while
 * active); this covers anything scheduled since, on every site of a network.
 * Data (options, tables, snapshots) is deliberately kept.
 */
class Uninstaller
{
    public static function uninstall(): void
    {
        Cron_Registry::for_each_site(true, [ Cron_Registry::class, 'clear_all' ]);
    }
}
