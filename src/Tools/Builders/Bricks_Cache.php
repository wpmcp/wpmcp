<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Bricks theme presence, the editor mode row and the one place wpmcp
 * refreshes what Bricks derives from a page's layout (issue #914).
 *
 * Bricks' own save (includes/ajax.php) writes the layout row, sets
 * `_bricks_editor_mode` and then calls wp_update_post(), whose save_post hook
 * (includes/assets/files.php) rebuilds the page's `post-<id>.min.css` when CSS
 * loading is set to external files. A page is rendered by Bricks only while
 * its editor mode is not `wordpress` (Helpers::render_with_bricks). A raw
 * meta write does none of this, so the write path and the rollback path both
 * call here.
 *
 * Lives apart from the content helpers because the always-loaded rollback
 * path calls it, and every build flavor ships this namespace. Keep it free of
 * references to sibling paid classes.
 */
final class Bricks_Cache
{
    public const EDITOR_MODE_KEY = '_bricks_editor_mode';

    public const TEMPLATE_TYPE_KEY = '_bricks_template_type';

    public const CONTENT_KEY = '_bricks_page_content_2';

    public const HEADER_KEY = '_bricks_page_header_2';

    public const FOOTER_KEY = '_bricks_page_footer_2';

    public const TEMPLATE_POST_TYPE = 'bricks_template';

    /** Whether the Bricks theme is loaded on this request. */
    public static function plugin_active(): bool
    {
        return (bool) apply_filters('wpmcp_bricks_active', defined('BRICKS_VERSION'));
    }

    /** The layout area a post holds: content, header or footer (Bricks Templates::get_template_type). */
    public static function area(int $post_id): string
    {
        $type = (string) get_post_meta($post_id, self::TEMPLATE_TYPE_KEY, true);

        return in_array($type, ['header', 'footer'], true) ? $type : 'content';
    }

    /** The meta row that holds an area's elements. */
    public static function area_key(string $area): string
    {
        if ('header' === $area) {
            return self::HEADER_KEY;
        }

        return 'footer' === $area ? self::FOOTER_KEY : self::CONTENT_KEY;
    }

    /**
     * The editor mode Bricks' save gives a page: `bricks` for a header or
     * footer template and for any page whose content row is not empty, else
     * `wordpress` (ajax.php, "Always set editor mode").
     */
    public static function editor_mode(int $post_id): string
    {
        if ('content' !== self::area($post_id)) {
            return 'bricks';
        }

        $content = get_post_meta($post_id, self::CONTENT_KEY, true);

        return is_array($content) && [] !== $content ? 'bricks' : 'wordpress';
    }

    /** Store the editor mode Bricks' own save would. */
    public static function sync_editor_mode(int $post_id): void
    {
        update_post_meta($post_id, self::EDITOR_MODE_KEY, self::editor_mode($post_id));
    }

    /**
     * Whether Bricks can edit this post: a Bricks template, a post type
     * enabled in the Bricks settings (page by default), or a post that
     * already carries Bricks data.
     */
    public static function is_editable(int $post_id): bool
    {
        $post = get_post($post_id);
        if (! $post || 'revision' === $post->post_type) {
            return false;
        }

        if (self::TEMPLATE_POST_TYPE === $post->post_type) {
            return true;
        }

        foreach ([self::CONTENT_KEY, self::HEADER_KEY, self::FOOTER_KEY, self::EDITOR_MODE_KEY] as $key) {
            if (metadata_exists('post', $post_id, $key)) {
                return true;
            }
        }

        return in_array($post->post_type, self::post_types(), true);
    }

    /** @return string[] the post types the Bricks settings enable (default: page) */
    public static function post_types(): array
    {
        $settings = get_option('bricks_global_settings', []);
        $types    = is_array($settings) && isset($settings['postTypes']) && is_array($settings['postTypes']) ? $settings['postTypes'] : ['page'];

        return array_values(array_filter(array_map('strval', $types)));
    }

    /**
     * Rebuild what Bricks derives from the page's layout, the way its
     * save_post hook does: the page's CSS file (a no-op inside Bricks unless
     * CSS loading is set to external files) and the cached template list. A
     * no-op when Bricks is not loaded: nothing serves those files then. Bricks
     * loads Assets_Files only when CSS loading is set to external files
     * (Assets::__construct), so its absence means inline CSS: nothing to rebuild.
     */
    public static function regenerate(int $post_id): void
    {
        if ($post_id <= 0 || ! self::plugin_active()) {
            return;
        }

        $post = get_post($post_id);
        if (! $post || 'revision' === $post->post_type) {
            return;
        }

        try {
            if (self::TEMPLATE_POST_TYPE === $post->post_type) {
                // Bricks Templates::flush_templates_cache().
                wp_cache_set('last_changed', (string) microtime(), 'bricks_' . self::TEMPLATE_POST_TYPE);
            }

            if ('wordpress' === get_post_meta($post_id, self::EDITOR_MODE_KEY, true) || ! class_exists('Bricks\\Assets_Files')) {
                return;
            }

            $area     = self::area($post_id);
            $elements = get_post_meta($post_id, self::area_key($area), true);

            \Bricks\Assets_Files::generate_post_css_file($post_id, $area, is_array($elements) ? $elements : []);
        } catch (\Throwable $e) {
            // CSS regeneration must never fail the write it follows.
            unset($e);
        }
    }
}
