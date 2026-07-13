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
    /** Test seam: forces wp_get_environment_type()'s return value. Null = live detection. */
    private static ?string $environment_override = null;

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

    /**
     * Test seam: force wp_get_environment_type()'s effective value so tests
     * do not depend on live server configuration, mirroring
     * Database_Guard::set_no_backslash_escapes_override(). Pass null to
     * resume live detection.
     */
    public static function set_environment_override(?string $environment): void
    {
        self::$environment_override = $environment;
    }

    /** Current environment type, honoring the test override when set. */
    private static function environment_type(): string
    {
        if (null !== self::$environment_override) {
            return self::$environment_override;
        }

        return function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';
    }

    /**
     * Whether the active environment permits wp-cli execution. Production is
     * refused unless a SEPARATE, explicit wpmcp_allow_wp_cli_on_production
     * filter/WPMCP_ALLOW_WP_CLI_ON_PRODUCTION constant is also set: enabling
     * wp-cli at all (is_enabled()) is not, by itself, enough to run it on a
     * live production site. Every other environment (development, staging,
     * local, and the "production" fallback WordPress itself uses when
     * wp_get_environment_type() is unavailable is intentionally treated as
     * production, i.e. refused by default) is allowed.
     */
    public static function is_allowed_on_environment(): bool
    {
        if ('production' !== self::environment_type()) {
            return true;
        }

        $default = defined('WPMCP_ALLOW_WP_CLI_ON_PRODUCTION') && WPMCP_ALLOW_WP_CLI_ON_PRODUCTION;

        return (bool) apply_filters('wpmcp_allow_wp_cli_on_production', $default);
    }
}
