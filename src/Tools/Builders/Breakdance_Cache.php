<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Breakdance presence, and the one place wpmcp refreshes a page's generated
 * CSS cache.
 *
 * Lives apart from the content helpers because the always-loaded rollback
 * path calls it, and every build flavor ships this namespace.
 *
 * Breakdance renders each page's CSS into files under uploads and records
 * them, with the page's asset dependencies, in two postmeta rows
 * (`_breakdance_css_file_paths_cache` and `_breakdance_dependency_cache`).
 * Its own save rebuilds both through
 * \Breakdance\Render\generateCacheForPost(), and its front end rebuilds them
 * whenever either row is missing. Any other change to `_breakdance_data`
 * (a raw write, a snapshot restore) must do the same or the page keeps the
 * old styles.
 *
 * A no-op when Breakdance is not loaded: nothing serves those files then,
 * and the writer drops the stale rows so they are rebuilt once it is.
 */
final class Breakdance_Cache
{
    public const CSS_META_KEY        = '_breakdance_css_file_paths_cache';
    public const DEPENDENCY_META_KEY = '_breakdance_dependency_cache';

    /** Whether the Breakdance plugin is loaded on this request. */
    public static function plugin_active(): bool
    {
        return (bool) apply_filters('wpmcp_breakdance_active', defined('__BREAKDANCE_VERSION'));
    }

    /** Rebuild the page's CSS and dependency cache through Breakdance itself. */
    public static function regenerate(int $post_id): void
    {
        if ($post_id <= 0 || ! self::plugin_active() || ! function_exists('Breakdance\\Render\\generateCacheForPost')) {
            return;
        }

        try {
            \Breakdance\Render\generateCacheForPost($post_id);
        } catch (\Throwable $e) {
            // Cache regeneration must never fail the write it follows.
            unset($e);
        }
    }
}
