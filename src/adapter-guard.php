<?php

/**
 * MCP Adapter coexistence guard (issue #386).
 *
 * WP MCP bundles the WordPress MCP Adapter (wordpress/mcp-adapter) and the
 * schema package it depends on (wordpress/php-mcp-schema) through Composer.
 * The adapter is also a canonical plugin of its own, and other plugins
 * bundle it too. Adapter 0.7.0 deprecates bundling in favour of the
 * canonical plugin and changes enough internals (schema records instead of
 * DTOs, a new schema package major) that two copies must never be mixed in
 * one request: half the classes from one copy and half from another is a
 * fatal waiting for the first request that crosses the seam.
 *
 * Composer registers its class loader with prepend=true, so a bundled copy
 * loaded after the canonical plugin silently shadows every adapter class the
 * canonical plugin has not loaded yet. This guard removes that shadowing:
 *
 *  1. Our Composer loader is moved to the END of the autoload stack. Any
 *     other copy of the adapter (the canonical plugin, or another plugin's
 *     bundle managed by its version-picking autoloader) resolves first, and
 *     ours is only the fallback when nothing else provides the classes.
 *  2. The shared namespaces (WP\MCP\, WP\McpSchema\) are not served from our
 *     bundle before plugins_loaded. The canonical plugin checks whether
 *     another copy is already loaded while plugins are still loading; if our
 *     bundle answered that check, the canonical plugin would stand down in
 *     favour of our older copy and show an admin error. Nothing in WP MCP
 *     touches adapter classes before plugins_loaded (Server::register defers
 *     its boot to plugins_loaded), so the fallback is still in place the
 *     moment we need it.
 *
 * Our own \WPMCP\ classes and every other dependency are unaffected: no other
 * plugin provides them, so their position in the stack changes nothing.
 *
 * Deliberately not namespaced and not autoloaded, like src/flavor-guard.php:
 * it operates on the autoloader itself. Every main file requires it right
 * after vendor/autoload.php.
 */

// Plugin Check's Direct_File_Access_Check only accepts the bare defined() test.
if (! defined('ABSPATH')) {
    exit;
}

if (! function_exists('wpmcp_is_shared_adapter_class')) {
    /**
     * Whether a class belongs to a namespace another plugin may also provide.
     *
     * @param string $class Fully qualified class name, no leading backslash.
     * @return bool
     */
    function wpmcp_is_shared_adapter_class(string $class): bool
    {
        return 0 === strpos($class, 'WP\\MCP\\') || 0 === strpos($class, 'WP\\McpSchema\\');
    }
}

if (! function_exists('wpmcp_prefer_shared_mcp_adapter')) {
    /**
     * Re-registers this build's Composer loader as a gated, last-resort
     * loader. Idempotent: a second call finds no prepended loader to move.
     *
     * @param string $vendor_dir Absolute path of this build's vendor/ directory.
     * @return bool True when the loader was moved, false when it was not found.
     */
    function wpmcp_prefer_shared_mcp_adapter(string $vendor_dir): bool
    {
        if (! class_exists('Composer\\Autoload\\ClassLoader', false)) {
            return false;
        }

        $loaders = \Composer\Autoload\ClassLoader::getRegisteredLoaders();
        $loader  = $loaders[ $vendor_dir ] ?? null;
        if (null === $loader) {
            // The registry is keyed by the path Composer computed, which can
            // differ from ours by a symlink.
            $real = realpath($vendor_dir);
            foreach ($loaders as $dir => $candidate) {
                if (false !== $real && realpath((string) $dir) === $real) {
                    $loader = $candidate;
                    break;
                }
            }
        }
        if (! $loader instanceof \Composer\Autoload\ClassLoader) {
            return false;
        }

        $callback = [ $loader, 'loadClass' ];
        if (! in_array($callback, (array) spl_autoload_functions(), true)) {
            // Already moved (the gated wrapper below is registered instead).
            return false;
        }

        // spl_autoload_unregister, not ClassLoader::unregister(): the latter
        // also drops the loader from Composer's registry, which
        // Composer\InstalledVersions reads to find installed.php.
        spl_autoload_unregister($callback);

        spl_autoload_register(
            static function (string $class) use ($loader): void {
                if (
                    wpmcp_is_shared_adapter_class($class)
                    && (! function_exists('did_action') || 0 === did_action('plugins_loaded'))
                ) {
                    return;
                }

                $loader->loadClass($class);
            },
            true,
            false
        );

        return true;
    }
}
