<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- ABSPATH guard is an intentional side effect.

namespace WPMCP;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Deactivation (issue #468): take every plugin cron event off the schedule
 * (Cron_Registry). Settings are left alone, so Activator can put back the
 * recurring events they still ask for. Wired in Plugin::boot(), which every
 * build's main file runs, so the full, wp.org and WooCommerce builds all
 * get it; a build that stood down for another WP MCP copy never registers
 * it, which is right, since the copy that booted owns the events.
 */
class Deactivator
{
    /** @param bool $network_wide Passed by core for a network deactivation. */
    public static function deactivate($network_wide = false): void
    {
        Cron_Registry::for_each_site((bool) $network_wide, [ Cron_Registry::class, 'clear_all' ]);
    }
}
