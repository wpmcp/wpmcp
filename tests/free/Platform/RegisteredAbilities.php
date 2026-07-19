<?php

namespace WPMCP\Tests\Free\Platform;

use WPMCP\MCP\Ability;
use WPMCP\MCP\Registrar;
use WPMCP\Plugin;
use WPMCP\Pro\Gate;

/**
 * Enumerates every ability the plugin's real registration path produces,
 * including pro-tier abilities (the pro gate is forced open for the duration
 * of the enumeration only).
 *
 * Plugin::boot() registers abilities once into the shared Registrar with the
 * Gate in its default (free) state, so the shared instance never contains the
 * pro tier under test. This helper temporarily swaps a fresh Registrar into
 * the Plugin singleton, replays Plugin::register_abilities() against it with
 * Gate::set_pro_for_tests(true), captures the result, and restores both the
 * original Registrar and the Gate — the live registry is untouched because
 * Registrar only calls wp_register_ability() inside a real
 * wp_abilities_api_init action window.
 */
final class RegisteredAbilities
{
    /** @return Ability[] every ability, keyed by nothing, in registration order. */
    public static function all(): array
    {
        $plugin = Plugin::instance();
        $prop   = new \ReflectionProperty(Plugin::class, 'registrar');
        // NOTE: no setAccessible() call — a no-op since PHP 8.1 and
        // deprecated in PHP 8.5.
        $original = $prop->getValue($plugin);

        $prop->setValue($plugin, new Registrar());
        Gate::set_pro_for_tests(true);
        try {
            $plugin->register_abilities();
            return $plugin->registrar()->all();
        } finally {
            Gate::set_pro_for_tests(null);
            $prop->setValue($plugin, $original);
        }
    }

    /** @return array<string,string> ability name => tier, sorted by name. */
    public static function manifest_map(): array
    {
        $map = [];
        foreach (self::all() as $ability) {
            $map[ $ability->name ] = $ability->tier;
        }
        ksort($map);
        return $map;
    }
}
