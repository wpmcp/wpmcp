<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: report which page builder authored a post (elementor / bricks /
 * divi / gutenberg / classic), by inspecting plain postmeta/post_content
 * markers via Builder_Detector. Never mutates anything, so this is not
 * routed through the safety core.
 */
class Detect_Builder
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

        return [
            'post_id' => $post_id,
            'builder' => Builder_Detector::detect($post_id),
        ];
    }
}
