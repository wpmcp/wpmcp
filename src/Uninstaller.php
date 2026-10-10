<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- ABSPATH guard is an intentional side effect.

namespace WPMCP;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Uninstall (issues #468, #480): leave no plugin cron event on any site and,
 * when the owner opted in, none of the plugin's data either.
 *
 * Two entry points, one per kind of build, because WordPress runs a plugin's
 * uninstall.php INSTEAD of a registered uninstall hook:
 *
 *  - the directory builds (wp.org, WooCommerce) ship uninstall.php, which
 *    loads this file, Cron_Registry and Data_Registry directly (no
 *    autoloader) and calls uninstall();
 *  - the self-hosted build ships no uninstall.php, so the licensing SDK's
 *    own uninstall hook still runs, and that build's licensing bootstrap
 *    attaches uninstall() to the SDK's after_uninstall action.
 *
 * So this class may only use WordPress core, Cron_Registry and Data_Registry.
 *
 * Data is kept by default. With Data_Registry::delete_on_uninstall() on, the
 * stores Data_Registry lists are removed on every site of a network:
 * tables, options and transients, user meta, network options, the
 * Application Passwords the Connection screen issued, and WP MCP's own
 * directories. Directory removal is confined to the registered names
 * directly under the resolved uploads or wp-content directory and never
 * follows a symlink: a link is removed as a link, its target is left alone.
 */
class Uninstaller
{
    /** The Connection screen's ledger of issued Application Passwords (Connection_Page::OPTION). */
    private const APP_PASSWORDS_OPTION = 'wpmcp_connection_passwords';

    private static ?string $content_dir_for_tests = null;

    public static function uninstall(): void
    {
        // Read before anything is deleted: the setting is one of the stores.
        $delete = Data_Registry::delete_on_uninstall();

        Cron_Registry::for_each_site(true, [ Cron_Registry::class, 'clear_all' ]);

        if ($delete) {
            Cron_Registry::for_each_site(true, [ self::class, 'delete_site_data' ]);
            self::delete_shared_data();
        }
    }

    /** Everything WP MCP keeps for the current site. */
    public static function delete_site_data(): void
    {
        global $wpdb;

        self::revoke_application_passwords();

        foreach (Data_Registry::TABLES as $table) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall with "delete all data" on: drops WP MCP's own tables, named in Data_Registry and bound with %i.
            $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . $table));
        }

        foreach (self::matching($wpdb->options, 'option_name', Data_Registry::OPTION_PREFIXES) as $option) {
            delete_option($option);
        }

        $uploads = wp_upload_dir(null, false);
        if (empty($uploads['error']) && ! empty($uploads['basedir'])) {
            foreach (Data_Registry::UPLOADS_DIRS as $dir) {
                self::delete_owned_dir((string) $uploads['basedir'], $dir);
            }
        }

        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
    }

    /** What every site of a network shares: user meta, network options, wp-content directories. */
    public static function delete_shared_data(): void
    {
        global $wpdb;

        foreach (self::matching($wpdb->usermeta, 'meta_key', Data_Registry::USER_META_PREFIXES) as $key) {
            delete_metadata('user', 0, $key, '', true);
        }

        if (is_multisite()) {
            foreach (self::matching($wpdb->sitemeta, 'meta_key', Data_Registry::SITE_META_PREFIXES, 'site_id') as [ $network_id, $key ]) {
                delete_network_option((int) $network_id, $key);
            }
        }

        $content = self::$content_dir_for_tests ?? (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : ABSPATH . 'wp-content');
        foreach (Data_Registry::CONTENT_DIRS as $dir) {
            self::delete_owned_dir($content, $dir);
        }
    }

    /**
     * Remove $base/$name and everything in it, when $name is a registered
     * WP MCP directory sitting directly in $base. A symlink, at the top or
     * anywhere inside, is unlinked and never followed. Returns whether the
     * directory was there and is now gone.
     */
    public static function delete_owned_dir(string $base, string $name): bool
    {
        $registered = array_merge(Data_Registry::UPLOADS_DIRS, Data_Registry::CONTENT_DIRS);
        if (! in_array($name, $registered, true)) {
            return false;
        }

        $real_base = realpath($base);
        if (false === $real_base || ! is_dir($real_base)) {
            return false;
        }

        $path = $real_base . DIRECTORY_SEPARATOR . $name;
        if (is_link($path)) {
            return self::fs()->delete($path, false, 'f');
        }
        if (! is_dir($path) || realpath($path) !== $path) {
            return false;
        }

        self::delete_tree($path);

        return ! file_exists($path);
    }

    /** Test seam: the wp-content directory uninstall cleans. */
    public static function set_content_dir_for_tests(?string $dir): void
    {
        self::$content_dir_for_tests = $dir;
    }

    /** Revoke every Application Password the Connection screen issued on this site. */
    private static function revoke_application_passwords(): void
    {
        if (! class_exists('WP_Application_Passwords')) {
            return;
        }
        foreach ((array) get_option(self::APP_PASSWORDS_OPTION, []) as $record) {
            if (is_array($record) && ! empty($record['uuid']) && ! empty($record['user_id'])) {
                \WP_Application_Passwords::delete_application_password((int) $record['user_id'], (string) $record['uuid']);
            }
        }
    }

    /**
     * Distinct values of $column in $table that start with any of $prefixes,
     * or [$extra, value] pairs when $extra names a second column.
     */
    private static function matching(string $table, string $column, array $prefixes, string $extra = ''): array
    {
        global $wpdb;

        $where = implode(' OR ', array_fill(0, count($prefixes), '%i LIKE %s'));
        $args  = [];
        foreach ($prefixes as $prefix) {
            $args[] = $column;
            $args[] = $wpdb->esc_like($prefix) . '%';
        }

        if ('' === $extra) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Uninstall: lists WP MCP's own keys by registered prefix; $where is only %i/%s placeholders bound below.
            return array_values(array_unique((array) $wpdb->get_col($wpdb->prepare("SELECT DISTINCT %i FROM %i WHERE $where", $column, $table, ...$args))));
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Uninstall: lists WP MCP's own keys by registered prefix; $where is only %i/%s placeholders bound below.
        return (array) $wpdb->get_results($wpdb->prepare("SELECT DISTINCT %i, %i FROM %i WHERE $where", $extra, $column, $table, ...$args), ARRAY_N);
    }

    /** Depth-first removal that unlinks symlinks instead of descending into them. */
    private static function delete_tree(string $dir): void
    {
        $fs = self::fs();
        foreach (array_diff((array) scandir($dir), [ '.', '..' ]) as $entry) {
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_link($path) || ! is_dir($path)) {
                $fs->delete($path, false, 'f');
            } else {
                self::delete_tree($path);
            }
        }
        $fs->rmdir($dir, false);
    }

    /**
     * The direct filesystem transport: Plugin Check rejects unlink() and
     * rmdir(), and uninstall cannot prompt for FTP credentials.
     */
    private static function fs(): \WP_Filesystem_Direct
    {
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

        return new \WP_Filesystem_Direct(null);
    }
}
