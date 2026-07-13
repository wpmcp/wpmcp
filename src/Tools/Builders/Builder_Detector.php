<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Classify which page builder authored a post, by inspecting the same
 * plain-storage markers Detect_Builder, Get_Builder_Content, and
 * Update_Builder_Content read/write directly: postmeta flags and, for
 * Gutenberg vs classic, the post_content itself. No Bricks or Divi plugin
 * class is required to be loaded for this to work, since all of it lives
 * in ordinary WordPress storage.
 *
 * Checked in priority order: Elementor's `_elementor_edit_mode` flag,
 * Bricks' `_bricks_page_content_2` postmeta, Divi's `_et_pb_use_builder`
 * flag, Gutenberg's `<!-- wp: -->` block comment markers in post_content,
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

        $post = get_post($post_id);
        $content = $post ? (string) $post->post_content : '';

        if (false !== strpos($content, '<!-- wp:')) {
            return 'gutenberg';
        }

        return 'classic';
    }
}
