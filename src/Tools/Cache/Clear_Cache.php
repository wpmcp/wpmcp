<?php

namespace WPMCP\Tools\Cache;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Flush WordPress caches: the object cache, transients, OPcache (when
 * available and enabled), and any detected page-cache plugin.
 *
 * NOT routed through Safe_Mutation and does NOT touch the safety core: clearing
 * a cache has no meaningful before-image to restore (the data is regenerated on
 * demand), and the operation is safe and idempotent, so snapshotting/rollback
 * would be meaningless here. This mirrors how Delete_Menu documents operations
 * the post/option safety engine does not model, rather than inventing a
 * rollback that cannot exist.
 *
 * Every third-party page-cache integration is guarded by
 * function_exists()/defined() (via Page_Cache_Detector) so the tool never fatals
 * when a plugin is absent, and only invokes a plugin's clear API when detected.
 */
class Clear_Cache
{
    private Page_Cache_Detector $detector;

    public function __construct(?Page_Cache_Detector $detector = null)
    {
        $this->detector = $detector ?? new Page_Cache_Detector();
    }

    public function handle(array $args): array
    {
        return [
            'object_cache' => $this->flush_object_cache(),
            'transients'   => $this->delete_transients(),
            'opcache'      => $this->reset_opcache(),
            'page_cache'   => $this->clear_page_cache(),
        ];
    }

    /**
     * @return array{flushed: bool}
     */
    private function flush_object_cache(): array
    {
        return ['flushed' => (bool) wp_cache_flush()];
    }

    /**
     * Delete all transients (both the value and its timeout row), covering
     * expired and unexpired alike. Deleting all of them is intentional: the
     * point of a cache flush is to force regeneration of everything, and
     * transients are by definition regenerable. Site transients are handled
     * separately from per-site transients.
     *
     * @return array{deleted: int}
     */
    private function delete_transients(): array
    {
        global $wpdb;

        $deleted = 0;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WP has no API to enumerate transients; caching a cache-flush enumeration would defeat the flush.
        $names = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options}
             WHERE option_name LIKE '\_transient\_%'
             AND option_name NOT LIKE '\_transient\_timeout\_%'"
        );
        foreach ((array) $names as $name) {
            $key = substr($name, strlen('_transient_'));
            if (delete_transient($key)) {
                $deleted++;
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WP has no API to enumerate site transients; caching a cache-flush enumeration would defeat the flush.
        $site_names = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options}
             WHERE option_name LIKE '\_site\_transient\_%'
             AND option_name NOT LIKE '\_site\_transient\_timeout\_%'"
        );
        foreach ((array) $site_names as $name) {
            $key = substr($name, strlen('_site_transient_'));
            if (delete_site_transient($key)) {
                $deleted++;
            }
        }

        return ['deleted' => $deleted];
    }

    /**
     * Reset OPcache only when the extension is available and enabled. Guarded by
     * function_exists() and ini_get('opcache.enable') so it is a no-op (rather
     * than a fatal) on hosts without OPcache.
     *
     * @return array{available: bool, reset: bool}
     */
    private function reset_opcache(): array
    {
        $available = function_exists('opcache_reset');
        $reset     = false;

        if ($available && (bool) ini_get('opcache.enable')) {
            $reset = (bool) opcache_reset();
        }

        return [
            'available' => $available,
            'reset'     => $reset,
        ];
    }

    /**
     * @return array{detected: bool, plugins: array<string, string>}
     */
    private function clear_page_cache(): array
    {
        $detected = $this->detector->detect();
        $cleared  = $this->detector->clear_detected();

        return [
            'detected' => $detected['detected'],
            'plugins'  => $cleared,
        ];
    }
}
