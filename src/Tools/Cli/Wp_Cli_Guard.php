<?php

namespace WPMCP\Tools\Cli;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Security core for the guarded wp-cli executor (issue #44). Every guard the
 * run-wp-cli tool relies on lives here as a pure, independently testable
 * check: enable/disable, environment refusal, subcommand allowlisting,
 * shell-metacharacter rejection, and binary resolution. Run_Wp_Cli composes
 * these checks and is the only caller that ever actually spawns a process.
 *
 * Mirrors Database_Guard's shape: static methods returning true|WP_Error (or
 * bool for simple predicates), so callers convert a WP_Error into a thrown
 * exception the same way Query::handle() does with is_read_only_sql().
 */
class Wp_Cli_Guard
{
    /**
     * Whether wp-cli execution is enabled at all. Two opt-in seams, either
     * sufficient: the WPMCP_ALLOW_WP_CLI constant (for wp-config.php) and the
     * wpmcp_allow_wp_cli filter (programmatic control, also what tests use).
     * Default (neither set) is OFF, matching OAuth_Config::is_enabled().
     */
    public static function is_enabled(): bool
    {
        $default = defined('WPMCP_ALLOW_WP_CLI') && WPMCP_ALLOW_WP_CLI;

        return (bool) apply_filters('wpmcp_allow_wp_cli', $default);
    }
}
