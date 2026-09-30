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
 * `_oxygen_`), so every method here takes the builder slug. Breakdance 1.x
 * stores the same rows without the leading underscore (its set_meta() hands
 * the name straight to update_post_meta()), so for Breakdance the prefix is
 * read off the post: whichever data row it has, else the one the loaded
 * Breakdance writes (see prefix()).
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
    /** Breakdance 2 and later, like Oxygen 6, write the underscored rows. */
    private const PREFIXED = '_breakdance_';

    /** Breakdance 1.x writes the same rows without the underscore. */
    private const UNPREFIXED = 'breakdance_';

    /**
     * The meta prefix a builder's rows use on a post. For Breakdance that is
     * the prefix of the data row the post already has, so a page is always
     * read and written in its own row and never gains a second one; a post
     * without one (or $post_id 0) gets the prefix the loaded Breakdance
     * writes.
     */
    public static function prefix(string $builder, int $post_id = 0): string
    {
        if ('oxygen' === $builder) {
            return '_oxygen_';
        }

        $preferred = self::breakdance_prefix();
        if ($post_id > 0) {
            $other = self::PREFIXED === $preferred ? self::UNPREFIXED : self::PREFIXED;
            foreach ([$preferred, $other] as $prefix) {
                if (metadata_exists('post', $post_id, $prefix . 'data')) {
                    return $prefix;
                }
            }
        }

        return $preferred;
    }

    /**
     * The prefix the loaded Breakdance writes a new page's rows under: none
     * before 2.0, the underscore from then on and whenever Breakdance is not
     * loaded. Filterable through wpmcp_breakdance_meta_prefix.
     */
    private static function breakdance_prefix(): string
    {
        $version = defined('__BREAKDANCE_VERSION') ? (string) constant('__BREAKDANCE_VERSION') : '';
        $legacy  = '' !== $version && version_compare($version, '2.0', '<');
        $prefix  = apply_filters('wpmcp_breakdance_meta_prefix', $legacy ? self::UNPREFIXED : self::PREFIXED);

        return self::UNPREFIXED === $prefix ? self::UNPREFIXED : self::PREFIXED;
    }

    /** The data row of a builder's page (see prefix()). */
    public static function data_key(string $builder, int $post_id = 0): string
    {
        return self::prefix($builder, $post_id) . 'data';
    }

    /** @return string[] every data row a builder's page may use */
    public static function data_keys(string $builder): array
    {
        return 'oxygen' === $builder ? ['_oxygen_data'] : [self::PREFIXED . 'data', self::UNPREFIXED . 'data'];
    }

    /** @return string[] the generated cache rows for a builder's page */
    public static function cache_keys(string $builder, int $post_id = 0): array
    {
        $prefix = self::prefix($builder, $post_id);

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
