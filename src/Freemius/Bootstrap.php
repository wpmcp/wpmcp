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
            'has_premium_version' => true,
            'premium_slug'        => 'wpmcp-pro',
            'has_addons'          => false,
            'has_paid_plans'      => true,
            // Privacy-first defaults: wpmcp does not force telemetry opt-in.
            // anonymous_mode skips the Freemius connect/opt-in gate on activation,
            // matching our "no telemetry by default" positioning.
            'is_live'             => true,
            'anonymous_mode'      => true,
            'menu'                => [
                // Nest Freemius pages under the existing top-level wpmcp admin menu.
                'slug'    => 'wpmcp',
                'account' => true,
                'support' => false,
            ],
        ];
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

        wpmcp_fs();

        // Guard: only wire uninstall cleanup if our uninstaller exists.
        if (class_exists(\WPMCP\Uninstaller::class) && method_exists(\WPMCP\Uninstaller::class, 'uninstall')) {
            wpmcp_fs()->add_action('after_uninstall', [\WPMCP\Uninstaller::class, 'uninstall']);
        }
    }
}
