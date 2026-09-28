<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Classify which page builder authored a post, by inspecting the same
 * plain-storage markers the builder read/write handlers in this namespace
 * use directly: postmeta flags and, for Gutenberg vs classic, the
 * post_content itself. No Bricks or Divi plugin class is required to be
 * loaded for this to work, since all of it lives in ordinary WordPress
 * storage.
 *
 * Deliberately names no sibling handler class. The wp.org build sweeps this
 * namespace by reference and treats a mention in a comment as a reference,
 * so naming the paid handlers here would keep them in that zip.
 *
 * Checked in priority order: Elementor's `_elementor_edit_mode` flag,
 * Bricks' `_bricks_page_content_2` postmeta, Divi's `_et_pb_use_builder`
 * flag, WPBakery's `_wpb_vc_js_status` = 'true' editor flag, Beaver
 * Builder's `_fl_builder_enabled` flag, a Breakdance `_breakdance_data`
 * row holding a valid tree (a root with id, data and children inside its
 * `tree_json_string`), Gutenberg's
 * `<!-- wp: -->` block comment markers in post_content, then a
 * WPBakery `[vc_row]` or `[vc_section]` shortcode in post_content (a
 * WPBakery page saved with its backend editor off), falling back to
 * 'classic' when none match.
 */
class Builder_Detector
{
    public static function detect(int $post_id): string
    {
        if ('builder' === get_post_meta($post_id, '_elementor_edit_mode', true)) {
            return 'elementor';
        }

        $bricks_data = get_post_meta($post_id, '_bricks_page_content_2', true);
        if (! empty($bricks_data)) {
            return 'bricks';
        }

        if ('on' === get_post_meta($post_id, '_et_pb_use_builder', true)) {
            return 'divi';
        }

        if ('true' === get_post_meta($post_id, '_wpb_vc_js_status', true)) {
            return 'wpbakery';
        }

        // Beaver Builder stores true, which postmeta keeps as '1'.
        if ('1' === (string) get_post_meta($post_id, '_fl_builder_enabled', true)) {
            return 'beaver-builder';
        }

        if (self::has_breakdance_tree($post_id)) {
            return 'breakdance';
        }

        $post = get_post($post_id);
        $content = $post ? (string) $post->post_content : '';

        if (false !== strpos($content, '<!-- wp:')) {
            return 'gutenberg';
        }

        if (preg_match('/\[vc_(?:row|section)[\s\]]/', $content)) {
            return 'wpbakery';
        }

        return 'classic';
    }

    /**
     * Whether `_breakdance_data` holds a tree Breakdance would render: the
     * same root check its own reader makes.
     */
    private static function has_breakdance_tree(int $post_id): bool
    {
        $raw = get_post_meta($post_id, '_breakdance_data', true);
        if (! is_string($raw) || '' === $raw) {
            return false;
        }

        $outer = json_decode($raw, true);
        $tree  = is_array($outer) && is_string($outer['tree_json_string'] ?? null) ? json_decode($outer['tree_json_string'], true) : null;
        $root  = is_array($tree) ? ($tree['root'] ?? null) : null;

        return is_array($root) && array_key_exists('id', $root) && array_key_exists('data', $root) && is_array($root['children'] ?? null);
    }
}
