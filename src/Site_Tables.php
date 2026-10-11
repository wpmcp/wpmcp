<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- ABSPATH guard is an intentional side effect.
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional.
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional.

namespace WPMCP;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Creates WP MCP's per-site tables on every site that needs them (issue #527).
 *
 * Each table uses the site's table prefix, so on a network every site needs
 * its own copy. Three paths make sure it has one:
 *
 * - activation, for every site of the network on a network activation;
 * - wp_initialize_site, for a site created while the plugin is network active;
 * - maybe_install(), on admin_init and rest_api_init, for any site still
 *   missing them (an upgrade, or a site that predates this class). It reads
 *   one autoloaded option, so a current site pays no query for it.
 *
 * The tables are the ones Data_Registry::TABLES lists; installers() maps each
 * to its owner by class name, as a string because the vertical builds prune
 * some owners from the zip. A pruned owner's table is skipped.
 */
final class Site_Tables
{
    /**
     * Schema version of the whole set. Bump it whenever any table's
     * definition changes, so every site runs the installers once more.
     */
    public const VERSION = '1';

    /** Per-site option recording the VERSION a site's tables were built at. */
    public const VERSION_OPTION = 'wpmcp_site_tables_version';

    /** After core's own wp_initialize_site (10), which creates the site's core tables. */
    public const INITIALIZE_PRIORITY = 20;

    /** @return array<string, string> Table name (without prefix) => owner class with a static install(). */
    public static function installers(): array
    {
        return [
            'wpmcp_snapshots'    => '\\WPMCP\\Safety\\Snapshot_Store',
            'wpmcp_redirects'    => '\\WPMCP\\Tools\\Redirects\\Redirect_Store',
            'wpmcp_search_index' => '\\WPMCP\\Tools\\Search\\Search_Index_Store',
        ];
    }

    public static function register(): void
    {
        add_action('admin_init', [self::class, 'maybe_install']);
        add_action('rest_api_init', [self::class, 'maybe_install'], 1);
        add_action('wp_initialize_site', [self::class, 'initialize_site'], self::INITIALIZE_PRIORITY);
    }

    /** Create the tables on the current site, or on every site when $network_wide. */
    public static function install_all(bool $network_wide): void
    {
        Cron_Registry::for_each_site($network_wide, [self::class, 'install']);
    }

    /** Create or upgrade every table on the current site and record the version. */
    public static function install(): void
    {
        foreach (Data_Registry::TABLES as $table) {
            $owner = self::installers()[ $table ] ?? null;
            if (null !== $owner && class_exists($owner)) {
                call_user_func([$owner, 'install']);
            }
        }
        update_option(self::VERSION_OPTION, self::VERSION, true);
    }

    /** Install on the current site unless it is already at VERSION. */
    public static function maybe_install(): void
    {
        if (self::VERSION === (string) get_option(self::VERSION_OPTION, '')) {
            return;
        }
        self::install();
    }

    /**
     * A new site of a network the plugin is active on. A site of a network
     * where the plugin is only activated per site gets its tables when it
     * is activated there.
     *
     * @param \WP_Site $site
     */
    public static function initialize_site($site): void
    {
        if (! self::is_network_active() || ! is_object($site) || empty($site->blog_id)) {
            return;
        }
        switch_to_blog((int) $site->blog_id);
        try {
            self::install();
        } finally {
            restore_current_blog();
        }
    }

    private static function is_network_active(): bool
    {
        if (! is_multisite() || ! defined('WPMCP_FILE')) {
            return false;
        }
        $active = (array) get_site_option('active_sitewide_plugins', []);

        return isset($active[ plugin_basename((string) constant('WPMCP_FILE')) ]);
    }
}
