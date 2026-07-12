<?php

namespace WPMCP\Freemius;

if (! defined('ABSPATH')) {
    exit;
}

class Bootstrap
{
    /**
     * fs_dynamic_init() configuration array, per Freemius SDK conventions.
     *
     * Kept as a pure static method (no SDK dependency) so it is testable
     * without requiring vendor/freemius/start.php to be present.
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

    public static function init(): void
    {
        if (function_exists('wpmcp_fs')) {
            return;
        }

        // Guarded: only when SDK present (skipped in dev/CI without vendor/freemius).
        if (! file_exists(WPMCP_DIR . 'vendor/freemius/start.php')) {
            return;
        }

        require_once WPMCP_DIR . 'vendor/freemius/start.php';

        // phpcs:ignore WordPress -- fs_dynamic_init and wpmcp_fs are Freemius SDK conventions.
        function wpmcp_fs()
        {
            global $wpmcp_fs;

            if (! isset($wpmcp_fs)) {
                $wpmcp_fs = fs_dynamic_init(Bootstrap::config());
            }

            return $wpmcp_fs;
        }

        wpmcp_fs();

        // Guard: only wire uninstall cleanup if our uninstaller exists.
        if (class_exists(\WPMCP\Uninstaller::class) && method_exists(\WPMCP\Uninstaller::class, 'uninstall')) {
            wpmcp_fs()->add_action('after_uninstall', [\WPMCP\Uninstaller::class, 'uninstall']);
        }
    }
}
