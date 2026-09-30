<?php

namespace WPMCP\Governance;

use WPMCP\Identity\Identity_Context;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Audit, and optionally govern, ability calls that do not come through the
 * wpmcp endpoint (issue #412): core's REST run route, another MCP server on
 * the site, WP-CLI, or plain PHP from another plugin, for third-party
 * abilities as well as ours.
 *
 * Built on core's execution hooks (WordPress 7.1): wp_pre_execute_ability
 * opens a frame (or refuses), and the first of wp_ability_permission_result,
 * wp_ability_validate_input, wp_ability_execute_result,
 * wp_ability_validate_output or wp_after_execute_ability that settles the
 * outcome closes it with one audit row. On 6.9, which has only the
 * before/after actions, successful calls are logged and nothing is refused.
 *
 * Modes (one option, default off):
 *  - off:     nothing is hooked in effect; every call behaves exactly as if
 *             wpmcp were not installed.
 *  - audit:   one row per call: ability, identity, outcome (reason
 *             "site:<owner>[:<error code>]"), source and duration. Inputs
 *             and outputs are never stored, like every other row.
 *  - enforce: audit, plus a call to an ability an admin disabled BY NAME
 *             (a stored ability toggle) is refused on every entry point.
 *             Domain and operation toggles and the enable filters are not
 *             applied here: they are written for MCP agents, and applying
 *             them site-wide would take down other plugins' own abilities.
 *
 * Stands down entirely for wpmcp's own abilities (their permission callback
 * already applies every governance layer and writes the audit row, with its
 * source) and for any call made inside the wpmcp endpoint (the bridge logs
 * those itself), so no call is logged twice.
 */
class Site_Wide_Governance
{
    public const OPTION = 'wpmcp_governance_site_wide';
    public const MODES  = ['off', 'audit', 'enforce'];

    /** @var array<int, array{name: string, source: string, started: float}> */
    private static array $frames = [];

    public static function mode(): string
    {
        $mode = get_option(self::OPTION, 'off');

        return in_array($mode, self::MODES, true) ? $mode : 'off';
    }

    public static function set_mode(string $mode): void
    {
        update_option(self::OPTION, in_array($mode, self::MODES, true) ? $mode : 'off');
    }

    public static function register(): void
    {
        // Last, so enforcement has the final word over any earlier
        // short-circuit (a cache must not serve a disabled ability).
        add_filter('wp_pre_execute_ability', [self::class, 'pre_execute'], PHP_INT_MAX, 4);
        add_filter('wp_ability_permission_result', [self::class, 'permission_result'], PHP_INT_MAX, 2);
        add_filter('wp_ability_validate_input', [self::class, 'validate_input'], PHP_INT_MAX, 3);
        add_filter('wp_ability_execute_result', [self::class, 'execute_result'], PHP_INT_MAX, 2);
        add_filter('wp_ability_validate_output', [self::class, 'validate_output'], PHP_INT_MAX, 3);
        add_action('wp_before_execute_ability', [self::class, 'before_execute'], PHP_INT_MIN, 1);
        add_action('wp_after_execute_ability', [self::class, 'after_execute'], PHP_INT_MAX, 1);
    }

    /**
     * wp_pre_execute_ability. Returns $pre unchanged unless enforcement
     * refuses the call.
     *
     * @param mixed $pre  Core's sentinel, or an earlier filter's short-circuit.
     * @param mixed $name The ability name.
     * @return mixed
     */
    public static function pre_execute($pre, $name = '')
    {
        $name = (string) $name;
        if (! self::watches($name)) {
            return $pre;
        }

        if ('enforce' === self::mode() && self::disabled_by_name($name)) {
            self::record($name, Call_Source::current(), false, 'governance:ability_toggle', 0);
            return new \WP_Error(
                'wpmcp_governance_denied',
                sprintf('"%s" is disabled on this site by wpmcp governance.', $name),
                ['status' => 403]
            );
        }

        if (! self::is_sentinel($pre)) {
            // An earlier filter answered for the ability (a cache, a mock);
            // the call is over, so it is one row now.
            self::record($name, Call_Source::current(), ! is_wp_error($pre), is_wp_error($pre) ? (string) $pre->get_error_code() : 'short-circuit', 0);
            return $pre;
        }

        self::open($name);
        return $pre;
    }

    /**
     * @param mixed $permission The permission result.
     * @param mixed $name       The ability name.
     * @return mixed
     */
    public static function permission_result($permission, $name = '')
    {
        if (true !== $permission) {
            $name = (string) $name;
            if (self::has_frame($name)) {
                self::close($name, false, 'ability_invalid_permissions');
            } elseif (self::watches($name) && Call_Source::REST === Call_Source::current()) {
                // Core's run route checks permission BEFORE execute(), and a
                // refusal there never reaches execute(). An allowed check is
                // not logged here: execute() logs it.
                self::record($name, Call_Source::REST, false, 'ability_invalid_permissions', 0);
            }
        }

        return $permission;
    }

    /**
     * @param mixed $validity True, or a WP_Error / false when invalid.
     * @param mixed $input    Unused.
     * @param mixed $name     The ability name.
     * @return mixed
     */
    public static function validate_input($validity, $input = null, $name = '')
    {
        unset($input);
        if (self::is_invalid($validity) && self::has_frame((string) $name)) {
            self::close((string) $name, false, 'ability_invalid_input');
        }

        return $validity;
    }

    /**
     * @param mixed $result The execute callback's result.
     * @param mixed $name   The ability name.
     * @return mixed
     */
    public static function execute_result($result, $name = '')
    {
        if (is_wp_error($result) && self::has_frame((string) $name)) {
            self::close((string) $name, false, (string) $result->get_error_code());
        }

        return $result;
    }

    /**
     * @param mixed $validity True, or a WP_Error / false when invalid.
     * @param mixed $output   Unused.
     * @param mixed $name     The ability name.
     * @return mixed
     */
    public static function validate_output($validity, $output = null, $name = '')
    {
        unset($output);
        if (self::is_invalid($validity) && self::has_frame((string) $name)) {
            self::close((string) $name, false, 'ability_invalid_output');
        }

        return $validity;
    }

    /**
     * wp_before_execute_ability. On 7.1 the frame is already open; on 6.9,
     * which has no pre filter, this is where it opens.
     *
     * @param mixed $name The ability name.
     */
    public static function before_execute($name): void
    {
        $name = (string) $name;
        if (! class_exists('WP_Filter_Sentinel') && self::watches($name)) {
            self::open($name);
        }
    }

    /** @param mixed $name The ability name. */
    public static function after_execute($name): void
    {
        if (self::has_frame((string) $name)) {
            self::close((string) $name, true, '');
        }
    }

    /**
     * Whether this call is one the site-wide layer handles: the setting is
     * on, the ability is not ours, and the call is not inside our endpoint.
     */
    private static function watches(string $name): bool
    {
        return '' !== $name
            && ! str_starts_with($name, 'wpmcp/')
            && 'off' !== self::mode()
            && Call_Source::MCP !== Call_Source::current();
    }

    private static function disabled_by_name(string $name): bool
    {
        $toggles = Governance::ability_toggles();

        return array_key_exists($name, $toggles) && false === $toggles[ $name ];
    }

    /** @param mixed $pre */
    private static function is_sentinel($pre): bool
    {
        return class_exists('WP_Filter_Sentinel') && $pre instanceof \WP_Filter_Sentinel;
    }

    /** @param mixed $validity */
    private static function is_invalid($validity): bool
    {
        return false === $validity || (is_wp_error($validity) && $validity->has_errors());
    }

    private static function open(string $name): void
    {
        self::$frames[] = [
            'name'    => $name,
            'source'  => Call_Source::current(),
            'started' => microtime(true),
        ];
    }

    private static function has_frame(string $name): bool
    {
        return null !== self::find($name);
    }

    private static function find(string $name): ?int
    {
        for ($i = count(self::$frames) - 1; $i >= 0; $i--) {
            if ($name === self::$frames[ $i ]['name']) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Close the innermost frame for $name with one audit row. Frames above
     * it can only be calls that ended without a closing hook (a nested call
     * whose input normalization failed), so they are discarded with it.
     */
    private static function close(string $name, bool $allowed, string $outcome): void
    {
        $index = self::find($name);
        if (null === $index) {
            return;
        }

        $frame        = self::$frames[ $index ];
        self::$frames = array_slice(self::$frames, 0, $index);

        $duration = (int) round((microtime(true) - $frame['started']) * 1000);
        self::record($name, $frame['source'], $allowed, $outcome, $duration);
    }

    /**
     * One audit row. The reason carries the owner (the namespace prefix, the
     * same attribution the bridge uses) and the outcome; never the input or
     * the output. Wrapped like every other audit write: logging must never
     * break the call it observes.
     */
    private static function record(string $name, string $source, bool $allowed, string $outcome, int $duration_ms): void
    {
        try {
            $slash  = strpos($name, '/');
            $owner  = false === $slash ? $name : substr($name, 0, $slash);
            $reason = 'site:' . $owner . ('' === $outcome ? '' : ':' . $outcome);

            Governance_Audit_Log::record(
                $name,
                Identity_Context::current() ?? 'none',
                $allowed,
                $reason,
                ['source' => $source, 'duration_ms' => $duration_ms]
            );
        } catch (\Throwable $e) {
            unset($e);
        }
    }

    /** Test seam: drop any frame a test left open. */
    public static function reset_for_tests(): void
    {
        self::$frames = [];
    }
}
