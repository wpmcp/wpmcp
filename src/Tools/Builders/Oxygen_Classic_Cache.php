<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Classic Oxygen (4.x and earlier) presence, its layout rows, and the one
 * place wpmcp refreshes a page's generated CSS.
 *
 * Lives apart from the content helpers because the always-loaded rollback
 * path calls it, and every build flavor ships this namespace.
 *
 * Oxygen renders each page's CSS into uploads/oxygen/css/<post id>.css and
 * records it in the page's entry of the oxygen_vsb_css_files_state option
 * (the file's path and URL, or a flag saying the page has no CSS). Its own
 * save rebuilds both through oxygen_vsb_cache_page_css(), and while a page
 * has no entry it serves the page's CSS from the tree on each request
 * instead. Any other change to the tree (a raw write, a snapshot restore)
 * must do the same or the page keeps the old styles. So refresh() first
 * drops the page's entry and file, which is what Oxygen itself does when a
 * page's tree is emptied or the page is deleted, and then, when Oxygen is
 * loaded, rebuilds them through Oxygen. The file removed is always the one
 * Oxygen names after the page under uploads, never a path read from the
 * option.
 *
 * The entry is dropped whether or not Oxygen is loaded, since the option
 * outlives it: a stale entry would be served as soon as it is active again.
 */
final class Oxygen_Classic_Cache
{
    public const STATE_OPTION = 'oxygen_vsb_css_files_state';

    /** Every layout row, under both key layouts (Oxygen 4.8.3+ prefixes them with an underscore). */
    public const LAYOUT_KEYS = ['_ct_builder_json', '_ct_builder_shortcodes', 'ct_builder_json', 'ct_builder_shortcodes'];

    /** Whether classic Oxygen is loaded on this request. */
    public static function plugin_active(): bool
    {
        return (bool) apply_filters('wpmcp_oxygen_classic_active', defined('CT_VERSION'));
    }

    /** The loaded Oxygen version, or '' when it is not loaded or does not say. */
    public static function version(): string
    {
        return self::plugin_active() && defined('CT_VERSION') ? (string) constant('CT_VERSION') : '';
    }

    public static function refresh(int $post_id): void
    {
        if ($post_id <= 0) {
            return;
        }

        self::invalidate($post_id);
        self::regenerate($post_id);
    }

    private static function invalidate(int $post_id): void
    {
        $state = get_option(self::STATE_OPTION);
        if (is_array($state) && array_key_exists($post_id, $state)) {
            unset($state[ $post_id ]);
            update_option(self::STATE_OPTION, $state);
        }

        $file = trailingslashit(wp_upload_dir()['basedir']) . 'oxygen/css/' . $post_id . '.css';
        if (file_exists($file)) {
            wp_delete_file($file);
        }
    }

    /** Rebuild the page's CSS through Oxygen itself, as its save does. */
    private static function regenerate(int $post_id): void
    {
        if (! self::plugin_active() || ! function_exists('oxygen_vsb_cache_page_css')) {
            return;
        }

        // Oxygen reads the page settings for the CSS from this global,
        // which its save sets before generating.
        global $oxy_ajax_post_id;
        $previous         = $oxy_ajax_post_id;
        $oxy_ajax_post_id = $post_id; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Oxygen's own global, set the way its save sets it.
        try {
            oxygen_vsb_cache_page_css($post_id);
        } catch (\Throwable $e) {
            // Cache regeneration must never fail the write it follows; with
            // no entry, Oxygen serves the page's CSS from its tree.
            unset($e);
        } finally {
            $oxy_ajax_post_id = $previous; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Oxygen's own global, put back as it was.
        }
    }
}
