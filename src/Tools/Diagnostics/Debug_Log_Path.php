<?php

namespace WPMCP\Tools\Diagnostics;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Resolve the WordPress debug log path, shared by Get_Debug_Config (report
 * the path) and Get_Debug_Log (read its tail).
 *
 * WP_DEBUG_LOG can be boolean (true = default WP_CONTENT_DIR/debug.log) or a
 * string (a custom path, since WP 5.1). Only a string value is treated as a
 * custom path; anything else falls back to the default location.
 */
class Debug_Log_Path
{
    /**
     * Return the resolved debug log path when logging is enabled, or null
     * when WP_DEBUG_LOG is not truthy.
     */
    public static function resolve(): ?string
    {
        if (! defined('WP_DEBUG_LOG') || ! WP_DEBUG_LOG) {
            return null;
        }

        if (is_string(WP_DEBUG_LOG) && '' !== WP_DEBUG_LOG) {
            return WP_DEBUG_LOG;
        }

        return WP_CONTENT_DIR . '/debug.log';
    }
}
