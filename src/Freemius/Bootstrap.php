<?php

namespace WPMCP\Freemius;

if (! defined('ABSPATH')) {
    exit;
}

class Bootstrap
{
    public static function init(): void
    {
        if (function_exists('wpmcp_fs')) {
            return;
        }
        // Guarded: only when SDK present (skipped in unit tests).
        if (! file_exists(WPMCP_DIR . 'vendor/freemius/start.php')) {
            return;
        }
        require_once WPMCP_DIR . 'vendor/freemius/start.php';
        // fs_dynamic_init(...) config: id/slug/public_key filled at registration on freemius.com.
    }
}
