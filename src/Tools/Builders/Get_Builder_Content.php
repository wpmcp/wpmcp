<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: return the raw builder structure for a post. Bricks returns
 * the decoded `_bricks_page_content_2` postmeta JSON as an array; Divi
 * returns the post_content shortcode string plus the use-builder flag;
 * WPBakery returns the shortcode string, its parsed element tree (the dotted
 * paths update-builder-content operations address), its two meta rows and
 * whether the plugin is loaded; Beaver Builder returns its node tree (the
 * node ids operations address), whether the draft holds unpublished edits
 * and whether the plugin is loaded; Breakdance and Oxygen 6 (the same
 * engine) return their node tree (the node ids operations address), the
 * next node id and whether the plugin is loaded.
 * Elementor/Gutenberg/classic posts are out of scope for this tool (use
 * get-elementor-data for Elementor) and return a WP_Error. Never mutates
 * anything, so this is not routed through the safety core.
 */
class Get_Builder_Content
{
    public function handle(array $args)
    {
        $post_id = (int) ($args['post_id'] ?? 0);

        if ($post_id <= 0) {
            return new \WP_Error('missing_post_id', 'A post_id is required.');
        }

        if (! get_post($post_id)) {
            return new \WP_Error('post_not_found', "No post found with id '{$post_id}'.");
        }

        $builder = Builder_Detector::detect($post_id);

        if ('bricks' === $builder) {
            return [
                'post_id' => $post_id,
                'builder' => 'bricks',
                'content' => Bricks_Content::get($post_id) ?? [],
            ];
        }

        if ('divi' === $builder) {
            return [
                'post_id'     => $post_id,
                'builder'     => 'divi',
                'content'     => Divi_Content::get_content($post_id),
                'use_builder' => Divi_Content::uses_builder($post_id),
            ];
        }

        if ('wpbakery' === $builder) {
            return WPBakery_Content::read($post_id);
        }

        if ('beaver-builder' === $builder) {
            return Beaver_Builder_Content::read($post_id);
        }

        if ('breakdance' === $builder || 'oxygen' === $builder) {
            return Breakdance_Content::read($post_id, $builder);
        }

        return new \WP_Error(
            'unsupported_builder',
            "get-builder-content only supports 'bricks', 'divi', 'wpbakery', 'beaver-builder', 'breakdance' and 'oxygen'; this post was detected as '{$builder}'."
        );
    }
}
