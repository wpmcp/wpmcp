<?php

namespace WPMCP\Freemius;

if (! defined('ABSPATH')) {
    exit;
}

class Bootstrap
{
    /**
     * Test seam: forced SDK start.php path. Subject to the same existence
     * check as the real candidates, so pointing it at a missing file
     * simulates an SDK-less install. Guarded by WPMCP_TESTING.
     */
    private static ?string $sdk_path_override = null;

    /**
     * Test seam: forced Freemius instance (object), forced-absent (false),
     * or unset (null = use the real accessor). Guarded by WPMCP_TESTING.
     */
    private static object|false|null $fs_override = null;

    public static function set_sdk_path_for_tests(?string $path): void
    {
        if (! defined('WPMCP_TESTING') || ! WPMCP_TESTING) {
            return;
        }
        self::$sdk_path_override = $path;
    }

    public static function set_fs_for_tests(object|false|null $fs): void
    {
        if (! defined('WPMCP_TESTING') || ! WPMCP_TESTING) {
            return;
        }
        self::$fs_override = $fs;
    }

    /**
     * fs_dynamic_init() configuration array, per Freemius SDK conventions.
     *
     * Kept as a pure static method (no SDK dependency) so it is testable
     * without requiring the Freemius SDK to be present.
     *
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        return [
            'id'                  => WPMCP_FS_ID,
            'slug'                => 'wpmcp',
            'type'                => 'plugin',
            'public_key'          => WPMCP_FS_PUBLIC_KEY,
            'is_premium'          => false,
            // false only in a build that upgrades in place (see
            // upgrades_in_place()): the SDK then treats the installed folder
            // as the whole product, so it adds the "Activate License" link,
            // never tells a buyer to download and upload another zip, and
            // never instantiates its updater for this folder.
            'has_premium_version' => ! self::upgrades_in_place(),
            'premium_slug'        => 'wpmcp-pro',
            'has_addons'          => false,
            'has_paid_plans'      => true,
            // wp.org guideline 7: consent must be an explicit, default-off
            // opt-in. anonymous_mode would suppress the SDK's stock opt-in
            // screen and decide on the user's behalf, so it stays false and
            // the SDK ships its default-off connect screen. Nothing is
            // transmitted before the user affirmatively opts in.
            //
            // Scope note: the wp.org directory build carries no SDK at all
            // (scripts/flavors/wporg/strip.php removes src/Freemius and
            // build-wporg-release.sh composer-removes freemius/wordpress-sdk,
            // with build gates that fail on either surviving). This file only
            // governs the self-hosted zip, where the same consent posture is
            // applied deliberately rather than because a guideline forces it.
            'is_live'             => true,
            'anonymous_mode'      => false,
            // Pinned rather than left to the SDK's own default: the Skip link
            // on the connect screen is what makes the opt-in declinable, and
            // readme.txt documents it, so the value it depends on is stated
            // here instead of inherited.
            'enable_anonymous'    => true,
            'menu'                => [
                // Nest Freemius pages under the existing top-level wpmcp admin menu.
                'slug'    => 'wpmcp',
                'account' => true,
                'support' => false,
                // Required because the slug above IS the plugin's own
                // top-level menu (Plugin::register_admin_menu). In activation
                // mode the SDK's override_plugin_menu_with_activation() calls
                // FS_Admin_Menu_Manager::remove_menu_item(), which does
                // remove_all_actions() on the top-level hook plus
                // remove_all_submenu_items(). Freemius hooks admin_menu at
                // WP_FS__LOWEST_PRIORITY, after our priority-10 registration,
                // so the takeover always wins. override_exact confines it to
                // the activation URL. That URL is admin.php?page=wpmcp, which
                // is the History page (the top-level entry and its first
                // submenu), so History is the one screen the connect page
                // replaces until the opt-in is answered or skipped. Audit Log,
                // Handshake, Connection, Abilities, Redirects, Skills and
                // Memory stay reachable from every admin screen meanwhile.
                'override_exact' => true,
            ],
        ];
    }

    /**
     * The vertical builds that carry the licensing SDK and unlock WP MCP Pro
     * inside their own folder (Safe MCP for Elementor). They are not the
     * zip deployed to the licensing SDK's server, so that server's package
     * must never be offered as their update or as a download to install.
     */
    private const IN_PLACE_FLAVORS = ['elementor'];

    /**
     * Whether this build upgrades in place: its main file defines a
     * WPMCP_FLAVOR listed in IN_PLACE_FLAVORS. Read from the constant, not
     * Plugin::flavor(), because scripts/lib/inline-fs-config.php evaluates
     * config() with only this file loaded.
     */
    public static function upgrades_in_place(): bool
    {
        return defined('WPMCP_FLAVOR') && in_array(WPMCP_FLAVOR, self::IN_PLACE_FLAVORS, true);
    }

    /**
     * True when live Freemius credentials are wired into the plugin file.
     * With placeholders (0 / '') the whole integration stays inert.
     */
    public static function credentials_present(): bool
    {
        return defined('WPMCP_FS_ID') && WPMCP_FS_ID > 0
            && defined('WPMCP_FS_PUBLIC_KEY') && '' !== WPMCP_FS_PUBLIC_KEY;
    }

    /**
     * Resolve the SDK entry point, preferring the composer-vendored location
     * (freemius/wordpress-sdk) with the original manually-vendored path kept
     * as a fallback. Null when no SDK is installed (dev/CI safety).
     */
    public static function locate_sdk(): ?string
    {
        if (null !== self::$sdk_path_override) {
            return file_exists(self::$sdk_path_override) ? self::$sdk_path_override : null;
        }

        $candidates = [
            WPMCP_DIR . 'vendor/freemius/wordpress-sdk/start.php',
            WPMCP_DIR . 'vendor/freemius/start.php',
        ];
        foreach ($candidates as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Single guard for init(): live credentials AND an installed SDK.
     */
    public static function should_load(): bool
    {
        return self::credentials_present() && null !== self::locate_sdk();
    }

    /**
     * The Freemius instance for this plugin, or null when the SDK is not
     * loaded. This is the accessor the rest of the codebase (Pro\Gate)
     * uses; the global wpmcp_fs() stays as the SDK-conventional alias.
     */
    public static function fs(): ?object
    {
        if (null !== self::$fs_override) {
            return false === self::$fs_override ? null : self::$fs_override;
        }

        return function_exists('wpmcp_fs') ? wpmcp_fs() : null;
    }

    /**
     * Point the licensing SDK's cached main file for this product at the
     * build that is booting.
     *
     * The SDK caches one main file per product id (fs_accounts:
     * id_slug_type_path_map[id].path, and the plugin_main_file entry of the
     * product's storage) and keeps it for as long as that file exists. Every
     * WP MCP build that carries the SDK (the full plugin, Safe MCP for
     * Elementor) is the same product, so once one of them ran, the other
     * inherited its file: its row links landed on the wrong plugin, its
     * activation, deactivation and uninstall hooks were registered for the
     * other file, and its premium basename was computed from the other
     * file's name, which also hid a wpmcp-pro/ install from the SDK's
     * updater. Only one build boots per request (src/flavor-guard.php), so
     * the one that boots claims the cache. A no-op when the cache already
     * names this file; inert when the SDK's classes are not loaded.
     */
    private static function claim_main_file(): void
    {
        $sdk_ready = defined('WPMCP_FILE') && defined('WP_FS__ACCOUNTS_OPTION_NAME')
            && class_exists('FS_Options') && class_exists('FS_Storage');
        if (! $sdk_ready) {
            return;
        }

        $mine = plugin_basename(WPMCP_FILE);

        $accounts = \FS_Options::instance(WP_FS__ACCOUNTS_OPTION_NAME, true);
        $map      = $accounts->get_option('id_slug_type_path_map', []);
        if (is_array($map) && isset($map[ WPMCP_FS_ID ]['path']) && $map[ WPMCP_FS_ID ]['path'] !== $mine) {
            $map[ WPMCP_FS_ID ]['path'] = $mine;
            $accounts->set_option('id_slug_type_path_map', $map, true);
        }

        $storage = \FS_Storage::instance('plugin', (string) self::config()['slug']);
        $main    = $storage->plugin_main_file;
        if (is_object($main) && ! empty($main->path) && $main->path !== $mine) {
            $main->path                = $mine;
            $storage->plugin_main_file = $main;
        }
    }

    public static function init(): void
    {
        // Exactly once: the global accessor existing means the SDK is live.
        if (function_exists('wpmcp_fs')) {
            return;
        }

        // Inert without live credentials or without the SDK on disk
        // (dev/CI checkouts without vendor/, uninstall on stripped installs).
        // The resolved path is captured once so the require below can never
        // race a re-resolution to null.
        $start = self::credentials_present() ? self::locate_sdk() : null;
        if (null === $start) {
            return;
        }

        // The composer files-autoload includes start.php the moment
        // vendor/autoload.php loads. If that happened before WordPress was
        // up (test harness, WP-CLI package context), start.php hit its
        // ABSPATH guard and no-oped while still being marked as included,
        // so require_once would silently skip it here. A plain require,
        // guarded on the function the SDK defines, is both idempotent and
        // correct: when start.php already ran for real, the guard skips.
        if (! function_exists('fs_dynamic_init')) {
            require $start;
        }

        // Fail inert, never fatal: if the SDK deferred to another copy or
        // still did not expose its entry point, skip licensing entirely.
        if (! function_exists('fs_dynamic_init')) {
            return;
        }

        // The accessor must live in the GLOBAL namespace (Freemius SDK
        // convention), so it is declared in a dedicated non-namespaced file.
        require_once __DIR__ . '/wpmcp-fs.php';

        // Before the instance exists: a staging copy resolves as a temporary
        // duplicate and keeps Pro without using a licence site.
        Staging_Copy::boot();

        // Before the instance exists: the SDK must bind to THIS build's main
        // file, not to a sibling build that ran on an earlier request.
        self::claim_main_file();

        wpmcp_fs();

        // Pro history depth: decided once WordPress is up, because the
        // licence state is only known after the SDK has initialised.
        add_action('init', [\WPMCP\Pro\Snapshot_History::class, 'register']);

        // Pin the connect-screen icon to a shipped asset (scripts/build-release.sh
        // stages assets/ and gates on this path). Left unset, the SDK's
        // get_local_icon_url() finds no local icon, and on localhost installs
        // falls through to fetch_remote_icon_url() (and plugins_api) while
        // rendering the opt-in screen: an outbound request made before the user
        // has consented to anything, which is exactly what readme.txt says does
        // not happen. Returning a path short-circuits that whole branch.
        wpmcp_fs()->add_filter('plugin_icon', static fn () => WPMCP_DIR . 'assets/wpmcp-icon.svg');

        // Guard: only wire uninstall cleanup if our uninstaller exists.
        if (class_exists(\WPMCP\Uninstaller::class) && method_exists(\WPMCP\Uninstaller::class, 'uninstall')) {
            wpmcp_fs()->add_action('after_uninstall', [\WPMCP\Uninstaller::class, 'uninstall']);
        }
    }
}
