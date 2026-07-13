<?php

namespace WPMCP\Tools\Diagnostics;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: report the debug-related constants (WP_DEBUG, WP_DEBUG_LOG,
 * WP_DEBUG_DISPLAY, SCRIPT_DEBUG, SAVEQUERIES) and, when logging is on, the
 * resolved debug.log path. No secrets are exposed, only booleans and a
 * filesystem path; reads have nothing to roll back, so this never touches
 * Safe_Mutation.
 */
class Get_Debug_Config
{
    public function handle(array $args): array
    {
        return [
            'WP_DEBUG'         => defined('WP_DEBUG') && WP_DEBUG,
            'WP_DEBUG_LOG'     => defined('WP_DEBUG_LOG') && WP_DEBUG_LOG,
            'WP_DEBUG_DISPLAY' => defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY,
            'SCRIPT_DEBUG'     => defined('SCRIPT_DEBUG') && SCRIPT_DEBUG,
            'SAVEQUERIES'      => defined('SAVEQUERIES') && SAVEQUERIES,
            'log_path'         => Debug_Log_Path::resolve(),
        ];
    }
}
