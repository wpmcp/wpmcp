<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Beaver Builder presence, and the one place wpmcp clears its per-layout
 * asset cache.
 *
 * Lives apart from the content helpers because the always-loaded rollback
 * path calls it, and every build flavor ships this namespace.
 *
 * Beaver Builder renders each layout's CSS and JS into files under
 * uploads/bb-plugin/cache/ ({post_id}-layout.css and friends) and rebuilds
 * them only when they are missing. Its own publish deletes them through
 * FLBuilderModel::delete_all_asset_cache() and, for a saved row or module
 * template, the cache of every page that uses it; any other change to the
 * layout meta (a raw write, a snapshot restore) must do the same or the
 * page keeps the old styles.
 *
 * A no-op when Beaver Builder is not loaded: nothing serves those files, and
 * they are rebuilt from the stored layout once it is.
 */
final class Beaver_Builder_Cache
{
    /** Whether the Beaver Builder plugin is loaded on this request. */
    public static function plugin_active(): bool
    {
        return (bool) apply_filters('wpmcp_beaver_builder_active', defined('FL_BUILDER_VERSION'));
    }

    public static function clear(int $post_id): void
    {
        if ($post_id <= 0 || ! self::plugin_active() || ! class_exists('\\FLBuilderModel')) {
            return;
        }

        try {
            if (method_exists('\\FLBuilderModel', 'delete_all_asset_cache')) {
                \FLBuilderModel::delete_all_asset_cache($post_id);
            }
            if (method_exists('\\FLBuilderModel', 'delete_node_template_asset_cache')) {
                \FLBuilderModel::delete_node_template_asset_cache($post_id);
            }
        } catch (\Throwable $e) {
            // Cache clearing must never fail the write it follows.
            unset($e);
        }
    }
}
