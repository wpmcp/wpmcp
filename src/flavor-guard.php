<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- bootstrap guard: the ABSPATH check and the function declaration share the file by design.

/**
 * Flavor coexistence guard.
 *
 * The full plugin and every wp.org vertical build (wpmcp-for-woocommerce and
 * any later sibling) are the same tree: the same WPMCP_* constants, the same
 * \WPMCP\ namespace, the same options, custom tables, cron hooks and admin
 * menu slugs. Per-flavor prefixing of those names is not a fix. The collision
 * is in the constants and the class namespace, which no string rewrite
 * reaches, and renaming persisted state orphans the tables and options of any
 * install that updates into the renamed build. So exactly one build boots per
 * request and the others stand down.
 *
 * Each main file requires this BEFORE it defines the constants or registers
 * its Composer autoloader, because registering a second PSR-4 autoloader for
 * \WPMCP\ is itself part of the collision: class resolution then depends on
 * which vendor/ registered first, which for a pruned vertical build means
 * classes resolving to a tree that is missing files.
 *
 * Deliberately not namespaced and not autoloaded: it has to run before any
 * autoloader exists.
 */

if (! defined('ABSPATH') && ! defined('WPMCP_TESTING')) {
    exit;
}

if (! function_exists('wpmcp_flavor_should_defer')) {
    /**
     * Whether the calling main file must stop before booting.
     *
     * Load order is deliberately not consulted. WordPress loads active plugins
     * in sorted basename order, and 'wpmcp-for-woocommerce/...' sorts before
     * 'wpmcp/wpmcp.php' ('-' is 0x2D, '/' is 0x2F), so the vertical normally
     * runs first and a bare defined('WPMCP_VERSION') check in it would never
     * fire. Reading the active plugin list gives the same answer whichever
     * file runs first.
     *
     * @param string   $self           Basename of the calling main file, e.g. 'wpmcp.php'.
     * @param string[] $outranked_by   Main-file basenames that win against $self.
     * @param bool     $already_loaded Whether another copy already booted this
     *                                 request; callers pass defined('WPMCP_VERSION').
     * @return bool True when the caller must return without booting.
     */
    function wpmcp_flavor_should_defer(string $self, array $outranked_by, bool $already_loaded): bool
    {
        // Some copy already defined the shared constants this request.
        // Continuing would redefine them (a PHP warning), register a second
        // \WPMCP\ autoloader and boot a second Plugin instance.
        if ($already_loaded) {
            return true;
        }

        $winners = [];
        foreach ($outranked_by as $basename) {
            if ($basename !== $self) {
                $winners[$basename] = true;
            }
        }

        if ([] === $winners) {
            return false;
        }

        $active = (array) get_option('active_plugins', []);

        if (function_exists('is_multisite') && is_multisite()) {
            $active = array_merge(
                $active,
                array_keys((array) get_site_option('active_sitewide_plugins', []))
            );
        }

        foreach ($active as $plugin) {
            // Compare the main-file basename, not a substring of the path: an
            // unrelated 'wpmcp-companion/wpmcp-companion.php' must not take a
            // flavor offline.
            if (isset($winners[basename((string) $plugin)])) {
                return true;
            }
        }

        return false;
    }
}
