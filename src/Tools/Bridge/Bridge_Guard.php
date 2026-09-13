<?php

namespace WPMCP\Tools\Bridge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The default-off opt-in gate for the third-party ability bridge
 * (issue #194). Silently exposing every ability another plugin registered
 * would be a surprise, not a feature, so the whole bridge surface is closed
 * until a site explicitly opens it: the same constant-plus-filter pattern
 * as Wp_Cli_Guard::is_enabled(), and listed in Opt_In_Gates for the same
 * reason (the ability grid must never appear to open a gate only code can).
 *
 * Opening the gate never widens access:
 *
 *  - a bridged call still runs the target ability's own permission callback
 *    (there is no bypass path, filter or setting; see Execute_Site_Ability),
 *    plus our own governance, rate limiting and audit logging on the bridge
 *    shell itself;
 *  - only abilities the owning plugin marked show_in_rest are reachable.
 *    Registration is not exposure: core defaults show_in_rest to false and
 *    refuses to list or run such an ability over REST/MCP, so the bridge
 *    honours exactly the same flag (is_bridgeable()). An ability kept
 *    internal by its author is treated as if it did not exist here, in
 *    every tool, so hidden names are not enumerable through the bridge.
 */
class Bridge_Guard
{
    /**
     * Whether the site has opted in to bridging third-party abilities.
     * Default (neither the constant nor the filter set) is OFF.
     */
    public static function is_enabled(): bool
    {
        $default = defined('WPMCP_ENABLE_ABILITY_BRIDGE') && WPMCP_ENABLE_ABILITY_BRIDGE;

        return (bool) apply_filters('wpmcp_enable_ability_bridge', $default);
    }

    /** The WP_Error every bridge tool returns while the gate is closed. */
    public static function disabled_error(): \WP_Error
    {
        return new \WP_Error(
            'wpmcp_bridge_disabled',
            'The third-party ability bridge is disabled on this site (default). Opt in with define(\'WPMCP_ENABLE_ABILITY_BRIDGE\', true) or the wpmcp_enable_ability_bridge filter.'
        );
    }

    /**
     * Whether $name refers to a FOREIGN ability the bridge may touch.
     * Everything in our own namespace is refused: our abilities already have
     * call-tool, and refusing them here keeps the two surfaces disjoint
     * (and keeps the meta-tools and the bridge tools themselves out).
     */
    public static function is_foreign(string $name): bool
    {
        return '' !== $name && ! str_starts_with($name, 'wpmcp/');
    }

    /**
     * Whether a live ability is one the bridge may expose at all: the owning
     * plugin opted it into external clients with meta.show_in_rest, the flag
     * core's own abilities list and run controllers gate on. A hard,
     * unfilterable precondition applied by every bridge tool (listing,
     * schema read and execution agree), so a plugin's internal-only
     * abilities, the ones most likely to carry a lax permission callback
     * because the author assumed in-process callers, never become reachable
     * over HTTP through wpmcp.
     *
     * @param mixed $ability A WP_Ability (or anything else, which is refused).
     */
    public static function is_bridgeable($ability): bool
    {
        return is_object($ability)
            && method_exists($ability, 'get_meta_item')
            && true === $ability->get_meta_item('show_in_rest');
    }

    /**
     * The WP_Error for a name the bridge cannot see. Deliberately the same
     * code and message whether $name is unregistered or registered but not
     * exposed, so probing the bridge by name reveals nothing about hidden
     * abilities.
     */
    public static function unknown_error(string $name): \WP_Error
    {
        return new \WP_Error(
            'wpmcp_bridge_unknown',
            sprintf('No ability named "%s" is registered on this site. Use wpmcp/list-site-abilities to discover names.', $name)
        );
    }

    /**
     * Resolve a foreign name to the live, exposed WP_Ability the bridge may
     * read or execute, or the WP_Error the caller should return unchanged.
     * Callers have already checked the gate and is_foreign().
     *
     * @return \WP_Ability|\WP_Error
     */
    public static function lookup(string $name)
    {
        if (! function_exists('wp_get_ability') || ! function_exists('wp_has_ability')) {
            return new \WP_Error('wpmcp_bridge_unavailable', 'The Abilities API is not available on this site.');
        }

        $ability = wp_has_ability($name) ? wp_get_ability($name) : null;
        if (null === $ability || ! self::is_bridgeable($ability)) {
            return self::unknown_error($name);
        }

        return $ability;
    }

    /**
     * The plugin-ish owner of a foreign ability, derived from its namespace
     * prefix ("yoast/analyze-page" => "yoast"). Best-effort attribution for
     * listings and the audit log.
     */
    public static function owner_of(string $name): string
    {
        $slash = strpos($name, '/');

        return false === $slash ? $name : substr($name, 0, $slash);
    }
}
