<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Breakdance engine presence, and the one place wpmcp refreshes a page's
 * generated CSS cache. The engine ships as two products: Breakdance itself
 * and Oxygen 6, which is the same code run with BREAKDANCE_MODE 'oxygen'.
 * Both keep a page's rows under their own meta prefix (`_breakdance_` or
 * `_oxygen_`), so every method here takes the builder slug.
 *
 * Lives apart from the content helpers because the always-loaded rollback
 * path calls it, and every build flavor ships this namespace.
 *
 * The engine renders each page's CSS into files under uploads and records
 * them, with the page's asset dependencies, in two postmeta rows
 * (`<prefix>css_file_paths_cache` and `<prefix>dependency_cache`). Its own
 * save rebuilds both through \Breakdance\Render\generateCacheForPost(), and
 * its front end rebuilds them whenever either row is missing. Any other
 * change to the data row (a raw write, a snapshot restore) must do the same
 * or the page keeps the old styles.
 *
 * A no-op when that product is not loaded: nothing serves those files then,
 * and the writer drops the stale rows so they are rebuilt once it is.
 */
final class Breakdance_Cache
{
    /** The meta prefix a builder slug stores its rows under. */
    public static function prefix(string $builder): string
    {
        return 'oxygen' === $builder ? '_oxygen_' : '_breakdance_';
    }

    /** @return string[] the generated cache rows for a builder */
    public static function cache_keys(string $builder): array
    {
        $prefix = self::prefix($builder);

        return [$prefix . 'css_file_paths_cache', $prefix . 'dependency_cache'];
    }

    /** Whether that product of the engine is loaded on this request. */
    public static function plugin_active(string $builder = 'breakdance'): bool
    {
        $oxygen = defined('BREAKDANCE_MODE') && 'oxygen' === constant('BREAKDANCE_MODE');

        if ('oxygen' === $builder) {
            return (bool) apply_filters('wpmcp_oxygen_active', defined('__BREAKDANCE_VERSION') && $oxygen);
        }

        return (bool) apply_filters('wpmcp_breakdance_active', defined('__BREAKDANCE_VERSION') && ! $oxygen);
    }

    /** Rebuild the page's CSS and dependency cache through the engine itself. */
    public static function regenerate(int $post_id, string $builder = 'breakdance'): void
    {
        if ($post_id <= 0 || ! self::plugin_active($builder) || ! function_exists('Breakdance\\Render\\generateCacheForPost')) {
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
