<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- ABSPATH guard is an intentional side effect.
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional.
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional.

namespace WPMCP\Tools\Packages;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * What activate-plugin, deactivate-plugin and install-plugin's activate step
 * share so the ledger matches the site (issue #728).
 *
 * - locked(): one plugin activation or deactivation at a time per site.
 *   Each of them reads 'active_plugins', changes it and writes it back, so
 *   two at once lose one of the changes, while both report success and both
 *   leave a ledger row. The lock serialises them, and the option is read
 *   fresh once it is held, since a parallel request may have written it
 *   after this one loaded it. A call that cannot get the lock in time is
 *   refused before anything is snapshotted or written.
 * - probe(): the active plugin lists, the state Safe_Mutation compares to
 *   tell a failed write that changed nothing (its undo point is voided)
 *   from one that did.
 * - is_active(): the verify step, asked of the stored lists, so an
 *   activation that did not land is never reported as done.
 */
final class Plugin_State
{
    /** Seconds a call waits for another activation to finish. */
    public const LOCK_WAIT = 20;

    /** One lock per site's options table: GET_LOCK names are server-wide. */
    public static function lock_name(): string
    {
        global $wpdb;

        return 'wpmcp_plugins_' . substr(md5((defined('DB_NAME') ? DB_NAME : '') . '|' . $wpdb->options), 0, 16);
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function locked(callable $work)
    {
        global $wpdb;

        $name = self::lock_name();
        $wait = max(0, (int) apply_filters('wpmcp_plugin_lock_wait', self::LOCK_WAIT));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL named lock; no API exists.
        $held = '1' === (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, $wait));
        if (! $held) {
            throw new \RuntimeException('Another plugin activation or deactivation is still running on this site, so nothing was changed. Run this call again when it has finished.');
        }

        try {
            self::refresh();
            return $work();
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL named lock; no API exists.
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        }
    }

    /**
     * The active plugin lists as stored now.
     *
     * @return array{site: mixed, network: mixed}
     */
    public static function probe(): array
    {
        return [
            'site'    => get_option('active_plugins', []),
            'network' => is_multisite() ? get_site_option('active_sitewide_plugins', []) : null,
        ];
    }

    /** True when $plugin is in the stored list the write targets. */
    public static function is_active(string $plugin, bool $network_wide): bool
    {
        if ($network_wide) {
            return array_key_exists($plugin, (array) get_site_option('active_sitewide_plugins', []));
        }
        return in_array($plugin, (array) get_option('active_plugins', []), true);
    }

    /** Drop this request's cached copies, so the lists are read from the database. */
    private static function refresh(): void
    {
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('active_plugins', 'options');
        if (is_multisite()) {
            wp_cache_delete(get_current_network_id() . ':active_sitewide_plugins', 'site-options');
        }
    }
}
