<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- ABSPATH guard is an intentional side effect.
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional.

namespace WPMCP\Admin;

use WPMCP\Tools\Backup\Backup_Dir_Health;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Dashboard warning when the site-backup folder is downloadable (issue #846).
 *
 * Reads the stored result of Backup_Dir_Health::self_check() and never
 * requests anything itself, so an admin page load stays free of network
 * calls. Shown on the Dashboard only, to administrators, while the last
 * check for the folder in use found it served; it goes away once a later
 * check finds it protected or the folder moves.
 */
class Backup_Dir_Notice
{
    public static function register(): void
    {
        add_action('admin_notices', [self::class, 'render']);
    }

    public static function render(): void
    {
        if (! current_user_can(Management_Access::CAPABILITY)) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (null === $screen || ! in_array($screen->id, [ 'dashboard', 'dashboard-network' ], true)) {
            return;
        }

        $warning = Backup_Dir_Health::warning();
        if (null === $warning) {
            return;
        }

        printf('<div class="notice notice-error"><p>%s</p></div>', esc_html($warning));
    }
}
