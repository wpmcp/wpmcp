<?php

namespace WPMCP\Tools\Bridge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The default-off opt-in gate for the third-party ability bridge
 * (issue #194). Silently exposing every ability another plugin registered
 * would be a surprise, not a feature, so the whole bridge surface is closed
 * until a site explicitly opens it — the same constant-plus-filter pattern
 * as Wp_Cli_Guard::is_enabled().
 *
 * Opening the gate never widens access: a bridged call still runs the
 * target ability's own permission callback (there is no bypass path, filter
 * or setting — see Execute_Site_Ability), plus our own governance, rate
 * limiting and audit logging on the bridge shell itself.
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
