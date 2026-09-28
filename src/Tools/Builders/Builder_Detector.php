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
 * `tree_json_string`), the same for Oxygen 6's `_oxygen_data`, Avada's
 * `fusion_builder_status` = 'active' flag, Thrive Architect's
 * `tcb_editor_enabled` flag or `tve_landing_page` template (checked before
 * block markers, since the plain-text copy Thrive keeps in post_content
 * preserves them), a classic Oxygen (4.x and earlier) layout row under
 * either key layout (`_ct_builder_json` / `ct_builder_json` holding a tree
 * with elements, or a non-empty `_ct_builder_shortcodes` /
 * `ct_builder_shortcodes`, Oxygen's own test for a page it renders; checked
 * before block markers, since Oxygen leaves post_content as it was),
 * Gutenberg's `<!-- wp: -->` block
 * comment markers in post_content, then a WPBakery `[vc_row]` or
 * `[vc_section]` shortcode in post_content (a WPBakery page saved with its
 * backend editor off) or an Avada `[fusion_builder_container]` (an Avada
 * page whose flag is off or was never written, as on imported content),
 * falling back to 'classic' when none match.
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

        if (self::has_engine_tree($post_id, '_breakdance_data')) {
            return 'breakdance';
        }

        // Oxygen 6 is the Breakdance engine under the `_oxygen_` prefix.
        if (self::has_engine_tree($post_id, '_oxygen_data')) {
            return 'oxygen';
        }

        if ('active' === get_post_meta($post_id, 'fusion_builder_status', true)) {
            return 'avada';
        }

        // A landing page built from a Thrive template may carry no flag.
        if (! empty(get_post_meta($post_id, 'tcb_editor_enabled', true)) || '' !== (string) get_post_meta($post_id, 'tve_landing_page', true)) {
            return 'thrive';
        }

        if (self::has_classic_oxygen_layout($post_id)) {
            return 'oxygen-classic';
        }

        $post = get_post($post_id);
        $content = $post ? (string) $post->post_content : '';

        if (false !== strpos($content, '<!-- wp:')) {
            return 'gutenberg';
        }

        if (preg_match('/\[vc_(?:row|section)[\s\]]/', $content)) {
            return 'wpbakery';
        }

        if (preg_match('/\[fusion_builder_container[\s\]]/', $content)) {
            return 'avada';
        }

        return 'classic';
    }

    /**
     * Whether a page holds a classic Oxygen layout Oxygen would render: a
     * tree with elements, or a shortcode copy (all a page saved before
     * Oxygen 4 has). Oxygen 4.8.3 moved these rows to `_ct_` keys; earlier
     * versions and unmigrated sites keep the `ct_` keys.
     */
    private static function has_classic_oxygen_layout(int $post_id): bool
    {
        foreach (['_ct_builder_json', 'ct_builder_json'] as $key) {
            $tree = json_decode((string) get_post_meta($post_id, $key, true), true);
            if (is_array($tree) && is_array($tree['children'] ?? null) && [] !== $tree['children']) {
                return true;
            }
        }
        foreach (['_ct_builder_shortcodes', 'ct_builder_shortcodes'] as $key) {
            $shortcodes = get_post_meta($post_id, $key, true);
            if (is_string($shortcodes) && '' !== trim($shortcodes)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a Breakdance engine data row (`_breakdance_data`, or
     * `_oxygen_data` for Oxygen 6) holds a tree the engine would render:
     * the same root check its own reader makes.
     */
    private static function has_engine_tree(int $post_id, string $key): bool
    {
        $raw = get_post_meta($post_id, $key, true);
        if (! is_string($raw) || '' === $raw) {
            return false;
        }

        $outer = json_decode($raw, true);
        $tree  = is_array($outer) && is_string($outer['tree_json_string'] ?? null) ? json_decode($outer['tree_json_string'], true) : null;
        $root  = is_array($tree) ? ($tree['root'] ?? null) : null;

        return is_array($root) && array_key_exists('id', $root) && array_key_exists('data', $root) && is_array($root['children'] ?? null);
    }
}
