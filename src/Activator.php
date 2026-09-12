<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- ABSPATH guard is an intentional side effect.

namespace WPMCP;

use WPMCP\Auth\OAuth_Config;
use WPMCP\Auth\Oauth_Gc;
use WPMCP\Cloud\Cloud_Credentials;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Redirects\Redirect_Store;
use WPMCP\Tools\Search\Search_Index_Store;

if (! defined('ABSPATH')) {
    exit;
}

class Activator
{
    public static function activate(): void
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
        if (OAuth_Config::is_enabled()) {
            Oauth_Gc::ensure_scheduled();
        }

        // Import any phase A plaintext cloud credentials into the encrypted
        // vault (issue #141). Cloud_Credentials also does this lazily on read,
        // but doing it here is what keeps the write off the read-only
        // cloud-status path on a normally updated site.
        if (class_exists(Cloud_Credentials::class)) {
            Cloud_Credentials::migrate_plaintext();
        }
    }
}
