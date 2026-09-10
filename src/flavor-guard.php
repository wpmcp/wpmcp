<?php

/**
 * Flavor coexistence guard.
 *
 * The full plugin and every wp.org build (the free directory build and the
 * wpmcp-for-woocommerce vertical, plus any later sibling) are the same tree:
 * the same WPMCP_* constants, the same \WPMCP\ namespace, the same options,
 * custom tables, cron hooks and admin menu slugs. Per-flavor prefixing of
 * those names is not a fix. The collision is in the constants and the class
 * namespace, which no string rewrite reaches, and renaming persisted state
 * orphans the tables and options of any install that updates into the
 * renamed build. So exactly one build boots per request and the others stand
 * down.
 *
 * Each main file requires this BEFORE it defines the constants or registers
 * its Composer autoloader, because registering a second PSR-4 autoloader for
 * \WPMCP\ is itself part of the collision: class resolution then depends on
 * which vendor/ registered first, which for a pruned vertical build means
 * classes resolving to a tree that is missing files.
 *
 * Builds identify themselves with a `WPMCP Flavor:` line in the main file's
 * plugin header. The guard ranks by that id, never by file or directory name:
 * the full plugin and the directory build are both named wpmcp.php, and the
 * full plugin's directory is whatever the installer chose (wpmcp-pro/ under
 * the licensing SDK's premium slug, wpmcp-main/ from a GitHub zip, wpmcp/ in
 * a checkout), so no path-based rule can tell them apart.
 *
 * Deliberately not namespaced and not autoloaded: it has to run before any
 * autoloader exists.
 */

// Plugin Check's Direct_File_Access_Check only accepts the bare defined()
// test; an extra conjunct makes it report the file as unprotected. The test
// bootstrap runs inside the WP test lib, which defines ABSPATH, so the guard
// needs no test escape hatch (same as src/Plugin.php).
if (! defined('ABSPATH')) {
    exit;
}

if (! function_exists('wpmcp_flavor_rank')) {
    /**
     * Precedence of a build flavor: a higher rank boots, a lower one stands
     * down. The full plugin carries everything, the directory build carries
     * everything free, and a vertical carries a subset of that, so each is a
     * superset of the ones below it. An id this file does not know ranks
     * lowest, so a known build always wins against an unrecognised one, and
     * two builds of equal rank fall back to load order (see $already_loaded).
     *
     * @param string $flavor Flavor id from the main file's `WPMCP Flavor:` header.
     * @return int
     */
    function wpmcp_flavor_rank(string $flavor): int
    {
        $ranks = [
            'full'        => 3,
            'wporg'       => 2,
            'woocommerce' => 1,
        ];

        return $ranks[$flavor] ?? 0;
    }
}

if (! function_exists('wpmcp_flavor_of')) {
    /**
     * The flavor id a plugin main file declares, or '' when the file is
     * missing or is not a WP MCP build.
     *
     * A missing file is the stale-entry case: core's loader skips an
     * active_plugins entry whose file is gone but only prunes the entry when
     * the Plugins screen next runs validate_active_plugins(). Treating it as
     * absent here means a build never stands down for a plugin that cannot
     * boot.
     *
     * @param string $path Absolute path of the main file.
     * @return string
     */
    function wpmcp_flavor_of(string $path): string
    {
        if (! is_file($path)) {
            return '';
        }

        // get_file_data() is the same 8 KiB header read core uses for plugin
        // metadata; it is loaded (wp-includes/functions.php) long before any
        // plugin file runs.
        $data = get_file_data($path, ['flavor' => 'WPMCP Flavor']);

        return trim((string) ($data['flavor'] ?? ''));
    }
}

if (! function_exists('wpmcp_flavor_should_defer')) {
    /**
     * Whether the calling main file must stop before booting.
     *
     * Load order is deliberately not consulted for ranking. WordPress loads
     * active plugins in sorted basename order, and 'wpmcp-for-woocommerce/...'
     * sorts before 'wpmcp/wpmcp.php' ('-' is 0x2D, '/' is 0x2F), so the
     * vertical normally runs first and a bare defined('WPMCP_VERSION') check
     * in it would never fire. Reading the active plugin list and each
     * candidate's declared flavor gives the same answer whichever file runs
     * first.
     *
     * @param string $file           The calling main file (__FILE__).
     * @param string $flavor         The calling build's flavor id; must match
     *                               its `WPMCP Flavor:` header.
     * @param bool   $already_loaded Whether another copy already booted this
     *                               request; callers pass defined('WPMCP_VERSION').
     * @return bool True when the caller must return without booting.
     */
    function wpmcp_flavor_should_defer(string $file, string $flavor, bool $already_loaded): bool
    {
        // Some copy already defined the shared constants this request.
        // Continuing would redefine them (a PHP warning), register a second
        // \WPMCP\ autoloader and boot a second Plugin instance. This is also
        // the tie-breaker between two builds of the same rank: the one core
        // loaded first keeps the request.
        if ($already_loaded) {
            return true;
        }

        $self = plugin_basename($file);
        $rank = wpmcp_flavor_rank($flavor);

        $active = (array) get_option('active_plugins', []);

        if (function_exists('is_multisite') && is_multisite()) {
            $active = array_merge(
                $active,
                array_keys((array) get_site_option('active_sitewide_plugins', []))
            );
        }

        foreach ($active as $plugin) {
            $plugin = (string) $plugin;

            if ($plugin === $self) {
                continue;
            }

            // Every WP MCP main file is named wpmcp*.php. Filtering on that
            // before touching the filesystem keeps this to one header read
            // per sibling build rather than one per active plugin, and an
            // unrelated 'wpmcp-companion/wpmcp-companion.php' still has to
            // carry the header to count.
            if (0 !== strpos(basename($plugin), 'wpmcp')) {
                continue;
            }

            $other = wpmcp_flavor_of(WP_PLUGIN_DIR . '/' . $plugin);

            if ('' !== $other && wpmcp_flavor_rank($other) > $rank) {
                return true;
            }
        }

        return false;
    }
}
