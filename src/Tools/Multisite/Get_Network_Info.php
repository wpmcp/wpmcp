<?php

namespace WPMCP\Tools\Multisite;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: network id/name/domain, total site count, and the main site's
 * blog id, via Multisite_Adapter::network_info(). Only registered when
 * is_multisite() is true (see Plugin.php); if called in a context where that
 * has changed since registration, Multisite_Adapter itself still returns a
 * WP_Error rather than a fatal, so this stays safe either way.
 */
class Get_Network_Info
{
    public function handle(array $args)
    {
        return Multisite_Adapter::network_info();
    }
}
