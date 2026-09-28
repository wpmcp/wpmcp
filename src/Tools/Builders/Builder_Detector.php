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
 * Builder's `_fl_builder_enabled` flag, Gutenberg's
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
}
