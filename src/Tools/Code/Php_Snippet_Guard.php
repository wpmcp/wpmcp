<?php

namespace WPMCP\Tools\Code;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Security core for the guarded PHP snippet executor (issue #45). This is
 * the single most dangerous feature in the plugin: running arbitrary PHP is
 * remote code execution by definition, and it cannot be sandboxed in-process
 * or made undoable. Every guard Run_Php_Snippet relies on lives here as a
 * pure, independently testable check, mirroring Wp_Cli_Guard's shape:
 * enable/disable and environment refusal live here; Run_Php_Snippet composes
 * these checks and is the only caller that ever actually evaluates a
 * snippet.
 *
 * This tool is the ONE explicit escape hatch outside the snapshot/rollback
 * safety model: its effects are not captured and not undoable, and enabling
 * it grants RCE to anyone who can call it with manage_options. The product's
 * "AI physically can't wreck your site" promise holds only because this is
 * default-off, dev-only, and must be deliberately enabled.
 */
class Php_Snippet_Guard
{
    /** Test seam: forces wp_get_environment_type()'s return value. Null = live detection. */
    private static ?string $environment_override = null;

    /**
     * Whether PHP snippet execution is enabled at all. Two opt-in seams,
     * either sufficient: the WPMCP_ALLOW_PHP_EXEC constant (for
     * wp-config.php) and the wpmcp_allow_php_exec filter (programmatic
     * control, also what tests use). Default (neither set) is OFF, matching
     * Wp_Cli_Guard::is_enabled() and OAuth_Config::is_enabled(). A disabled
     * install can never eval anything: this is the first and most important
     * guard.
     */
    public static function is_enabled(): bool
    {
        $default = defined('WPMCP_ALLOW_PHP_EXEC') && WPMCP_ALLOW_PHP_EXEC;

        return (bool) apply_filters('wpmcp_allow_php_exec', $default);
    }

    /**
     * The execution gate chain, in order, as ONE shared refusal. Both
     * surfaces that can put a snippet on the path to running call this:
     * Run_Php_Snippet::guard() before it evaluates anything, and
     * Activate_Php_Snippet before it marks a stored snippet active. It
     * lives here rather than being re-typed at each call site so a third
     * gate added to this chain applies to every such surface at once,
     * which is the drift the split between "execution" and
     * "exec-adjacent" would otherwise invite.
     *
     * Deliberately NOT included: Php_Snippet_Validator. The validator is a
     * usability speed-bump that an authorized caller can trivially evade,
     * and its message differs per surface; the two checks here are the
     * real gate.
     */
    public static function assert_execution_allowed(): void
    {
        if (! self::is_enabled()) {
            throw new \RuntimeException(
                'PHP execution is disabled. Enable it with the WPMCP_ALLOW_PHP_EXEC constant or the wpmcp_allow_php_exec filter. This grants remote code execution to any manage_options caller; only enable it on a development or staging environment you control.'
            );
        }

        if (! self::is_allowed_on_environment()) {
            throw new \RuntimeException(
                'PHP execution is refused on this environment. Production and any unrecognized/unknown environment are refused by default (fail closed); set WPMCP_ALLOW_PHP_EXEC_ON_PRODUCTION or the wpmcp_allow_php_exec_on_production filter to override.'
            );
        }
    }

    /**
     * Test seam: force wp_get_environment_type()'s effective value so tests
     * do not depend on live server configuration, mirroring
     * Wp_Cli_Guard::set_environment_override(). Pass null to resume live
     * detection.
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

        return function_exists('wp_get_environment_type') ? wp_get_environment_type() : '';
    }

    /**
     * Whether the active environment permits PHP snippet execution.
     * FAIL CLOSED: only the three explicit non-production values below
     * proceed without an override. Every other value, including an unknown
     * or empty string (wp_get_environment_type() unavailable or
     * misconfigured) and the literal 'production', is refused unless a
     * SEPARATE, explicit wpmcp_allow_php_exec_on_production
     * filter/WPMCP_ALLOW_PHP_EXEC_ON_PRODUCTION constant is also set.
     * Enabling snippet execution at all (is_enabled()) is not, by itself,
     * enough to run it anywhere the environment can't be positively
     * confirmed safe: RCE has no safe "unknown" default.
     */
    public static function is_allowed_on_environment(): bool
    {
        $safe_environments = ['local', 'development', 'staging'];

        if (in_array(self::environment_type(), $safe_environments, true)) {
            return true;
        }

        $default = defined('WPMCP_ALLOW_PHP_EXEC_ON_PRODUCTION') && WPMCP_ALLOW_PHP_EXEC_ON_PRODUCTION;

        return (bool) apply_filters('wpmcp_allow_php_exec_on_production', $default);
    }
}
