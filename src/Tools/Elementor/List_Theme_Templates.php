<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * List Elementor theme-builder templates (header, footer, single, archive,
 * ...), optionally filtered to one template_type, with each one's display
 * conditions. Read-only.
 */
class List_Theme_Templates
{
    public function handle(array $args)
    {
        $filter = (string) ($args['template_type'] ?? '');
        if ('' !== $filter && ! Elementor_Template_Data::is_theme_type($filter)) {
            return new \WP_Error(
                'invalid_theme_type',
                sprintf('"%s" is not a theme-builder location.', $filter)
            );
        }

        $types = '' !== $filter ? [sanitize_key($filter)] : Elementor_Template_Data::THEME_TYPES;

        $posts = get_posts([
            'post_type'      => Elementor_Template_Data::POST_TYPE,
            'post_status'    => ['publish', 'draft', 'private'],
            'posts_per_page' => 200,
            'orderby'        => 'title',
            'order'          => 'ASC',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded admin read: at most 200 rows over Elementor's template CPT, an inherently small set.
            'meta_query'     => [
                [
                    'key'     => '_elementor_template_type',
                    'value'   => $types,
                    'compare' => 'IN',
                ],
            ],
        ]);

        $templates = [];
        foreach ($posts as $post) {
            $templates[] = [
                'template_id'   => $post->ID,
                'title'         => get_the_title($post),
                'template_type' => (string) get_post_meta($post->ID, '_elementor_template_type', true),
                'conditions'    => Elementor_Template_Data::conditions($post->ID),
            ];
        }

        return ['templates' => $templates];
    }
}
