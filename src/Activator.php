<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- ABSPATH guard is an intentional side effect.

namespace WPMCP;

use WPMCP\Auth\OAuth_Config;
use WPMCP\Auth\Oauth_Gc;
use WPMCP\Cloud\Cloud_Credentials;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Backup\Backup_Schedule;
use WPMCP\Tools\Redirects\Redirect_Store;
use WPMCP\Tools\Search\Search_Index_Store;

if (! defined('ABSPATH')) {
    exit;
}

class Activator
{
    /** @param bool $network_wide Passed by core for a network activation. */
    public static function activate($network_wide = false): void
    {
        Snapshot_Store::install();
        Redirect_Store::install();

        // The content search index table (issue #83). Search_Index_Store also
        // self-heals on first use, so an update that never re-runs activation
        // still works; creating it here keeps the common path free of DDL.
        // class_exists-guarded because vertical builds (wpmcp-for-woocommerce)
        // prune src/Tools/Search from the zip along with its ability group.
        if (class_exists(Search_Index_Store::class)) {
            Search_Index_Store::install();
        }

        // Daily OAuth store sweep (issue #133). Only scheduled when the
        // OAuth subsystem is actually on; boot() re-ensures it if OAuth is
        // enabled later, and unschedules it if it is turned back off.
        //
        // Deactivation clears every plugin cron event (issue #468), so this
        // is also where the recurring ones come back: the OAuth sweep while
        // OAuth is on, and each backup schedule still in its option, at its
        // configured time. Background jobs were canceled, so none returns.
        Cron_Registry::for_each_site((bool) $network_wide, static function (): void {
            if (OAuth_Config::is_enabled()) {
                Oauth_Gc::ensure_scheduled();
            }
            if (class_exists(Backup_Schedule::class)) {
                Backup_Schedule::restore();
            }
        });

        // Import any phase A plaintext cloud credentials into the encrypted
        // vault (issue #141). Activation does not fire on an update, so the
        // init hook (Cloud_Credentials::maybe_migrate_on_boot()) and the lazy
        // read-path import cover that case; this covers a reactivation.
        if (class_exists(Cloud_Credentials::class)) {
            Cloud_Credentials::migrate_plaintext();
        }
    }
}
