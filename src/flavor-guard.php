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
 *
 * Ships byte-identical in every build. scripts/build-woo-release.sh excludes
 * this file from its text-domain rewrite (the 'wpmcp' basename prefix below
 * matches the rewrite's pattern) and fails the build if the staged copy
 * differs from this one, so keep user-facing strings out of here: the
 * stand-down notice belongs in each main file, in that build's own domain.
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
            // The verticals each carry one ecosystem's slice and neither is
            // a superset of the other, so they tie and the one core loads
            // first keeps the request (each main file's notice says so).
            'woocommerce' => 1,
            'elementor'   => 1,
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

if (! function_exists('wpmcp_other_build_installed')) {
    /**
     * Whether another WP MCP build is installed on this site, active or not:
     * any plugin outside $plugin_dir whose main file carries a `WPMCP Flavor:`
     * header.
     *
     * The uninstall paths ask this first. Every build shares the same options,
     * custom tables and cron hooks, so deleting one build (say the Elementor
     * flavor next to the full plugin) must not clear the crons or drop the
     * data the build that stays depends on. Unlike wpmcp_flavor_should_defer()
     * it reads every installed plugin's header, not only wpmcp*.php ones: the
     * verticals' main files are named after their slug, and an uninstall is
     * rare enough that one header read per plugin costs nothing.
     *
     * @param string $plugin_dir Absolute directory of the build being removed.
     * @return bool
     */
    function wpmcp_other_build_installed(string $plugin_dir): bool
    {
        if (! function_exists('get_plugins')) {
            $admin = ABSPATH . 'wp-admin/includes/plugin.php';
            if (! is_file($admin)) {
                return false;
            }
            require_once $admin;
        }

        $self = basename(rtrim(str_replace('\\', '/', $plugin_dir), '/'));

        foreach (array_keys((array) get_plugins()) as $plugin) {
            $plugin = (string) $plugin;
            if (false === strpos($plugin, '/') || dirname($plugin) === $self) {
                continue;
            }
            if ('' !== wpmcp_flavor_of(WP_PLUGIN_DIR . '/' . $plugin)) {
                return true;
            }
        }

        return false;
    }
}

if (! function_exists('wpmcp_other_build_active')) {
    /**
     * Whether another WP MCP build is active on this site or network-wide:
     * an active plugin outside $plugin_dir whose main file carries a
     * `WPMCP Flavor:` header.
     *
     * Deactivation asks this first. Deactivating the build that booted (say
     * the full plugin next to an active Safe MCP for Elementor) hands the
     * site to the other one on the next request, and that one runs on the
     * same recurring cron events and background jobs, so they must stay.
     *
     * @param string $plugin_dir Absolute directory of the build being deactivated.
     * @return bool
     */
    function wpmcp_other_build_active(string $plugin_dir): bool
    {
        $self   = basename(rtrim(str_replace('\\', '/', $plugin_dir), '/'));
        $active = (array) get_option('active_plugins', []);

        if (function_exists('is_multisite') && is_multisite()) {
            $active = array_merge(
                $active,
                array_keys((array) get_site_option('active_sitewide_plugins', []))
            );
        }

        foreach ($active as $plugin) {
            $plugin = (string) $plugin;
            if (false === strpos($plugin, '/') || dirname($plugin) === $self) {
                continue;
            }
            if ('' !== wpmcp_flavor_of(WP_PLUGIN_DIR . '/' . $plugin)) {
                return true;
            }
        }

        return false;
    }
}
