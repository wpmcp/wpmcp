<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- ABSPATH guard is an intentional side effect.

namespace WPMCP;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Every store WP MCP owns, in one place (issue #480), and the opt-in that
 * deletes them all when the plugin is deleted.
 *
 * Uninstaller removes exactly what is listed here, so a new table, option,
 * transient, meta key or directory is cleaned up as soon as it uses a
 * registered name. tests/free/Uninstall/DataRegistryGuardTest walks src/ at
 * token level and fails on any store the plugin writes that this registry
 * does not cover.
 *
 * Stores are named by string, never by class, for the same reasons as
 * Cron_Registry: uninstall.php reads this file without an autoloader, and
 * the directory builds prune some of the owning classes. The guard test
 * pins each string to its owner's constant.
 *
 * Not listed, on purpose: posts of WP MCP's own post types (blocks,
 * templates, widgets, memory entries, chat conversations) and their post
 * meta. They are site content the owner made, and pages may still use them.
 */
final class Data_Registry
{
    /** The opt-in, stored network-wide (sitemeta on multisite, an option otherwise). */
    public const SETTING = 'wpmcp_uninstall_delete_data';

    /** Defined in wp-config.php, overrides SETTING in both directions. */
    public const CONSTANT = 'WPMCP_UNINSTALL_DELETE_DATA';

    /**
     * Tables, without the site's table prefix. Snapshot_Store, Redirect_Store,
     * Search_Index_Store.
     */
    public const TABLES = [
        'wpmcp_snapshots',
        'wpmcp_redirects',
        'wpmcp_search_index',
    ];

    /** Option names, transients and site transients stored as options included. */
    public const OPTION_PREFIXES = [
        'wpmcp_',
        '_transient_wpmcp_',
        '_transient_timeout_wpmcp_',
        '_site_transient_wpmcp_',
        '_site_transient_timeout_wpmcp_',
    ];

    public const USER_META_PREFIXES = [
        'wpmcp_',
        '_wpmcp_',
    ];

    /** Network options and site transients on multisite. */
    public const SITE_META_PREFIXES = [
        'wpmcp_',
        '_site_transient_wpmcp_',
        '_site_transient_timeout_wpmcp_',
    ];

    /**
     * Directories directly under each site's uploads directory: file
     * backups taken before a write (File_Backup), site backup archives and
     * staged migration uploads (Site_Backup_Dir), WXR exports (Export_Dir),
     * the content mirror (Content_Mirror) and filesystem tool backups
     * (Filesystem_Guard).
     */
    public const UPLOADS_DIRS = [
        '.wpmcp-backups',
        'wpmcp-site-backups',
        'wpmcp-exports',
        'wpmcp-mirror',
        'wpmcp-fs-backups',
    ];

    /**
     * Directories directly under wp-content, shared by every site of a
     * network: the restore staging directory (Restore_Files) and compiled
     * widget classes (Compiled_Widget_Manifest).
     */
    public const CONTENT_DIRS = [
        'wpmcp-restore',
        'wpmcp-widgets',
    ];

    /** @var array{0: bool, 1: mixed}|null */
    private static ?array $constant_for_tests = null;

    /** Whether deleting the plugin should delete all of its data. */
    public static function delete_on_uninstall(): bool
    {
        [ $defined, $value ] = self::constant();
        if ($defined) {
            return is_scalar($value) && true === filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return (bool) get_site_option(self::SETTING, false);
    }

    /** Whether the constant decides, so the stored setting cannot be changed. */
    public static function is_forced(): bool
    {
        return self::constant()[0];
    }

    public static function set_delete_on_uninstall(bool $delete): void
    {
        if ($delete) {
            update_site_option(self::SETTING, 1);
        } else {
            delete_site_option(self::SETTING);
        }
    }

    public static function owns_option(string $name): bool
    {
        return self::starts_with_any($name, self::OPTION_PREFIXES);
    }

    public static function owns_user_meta(string $key): bool
    {
        return self::starts_with_any($key, self::USER_META_PREFIXES);
    }

    public static function owns_site_meta(string $key): bool
    {
        return self::starts_with_any($key, self::SITE_META_PREFIXES);
    }

    /**
     * Test seam: behave as if the constant were (or were not) defined with
     * $value. A real constant cannot be undefined again within a run.
     */
    public static function set_constant_for_tests(bool $defined, $value = null): void
    {
        self::$constant_for_tests = [ $defined, $value ];
    }

    public static function reset_for_tests(): void
    {
        self::$constant_for_tests = null;
    }

    /** @return array{0: bool, 1: mixed} */
    private static function constant(): array
    {
        if (null !== self::$constant_for_tests) {
            return self::$constant_for_tests;
        }

        return defined(self::CONSTANT) ? [ true, constant(self::CONSTANT) ] : [ false, null ];
    }

    private static function starts_with_any(string $name, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }
        return false;
    }
}
