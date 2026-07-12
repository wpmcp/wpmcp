<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: return a page's parsed `_elementor_data` element tree (id,
 * elType, widgetType, settings, and nested elements for every node), read
 * straight from postmeta. Never mutates anything, so this is not routed
 * through the safety core.
 */
class Get_Elementor_Data
{
    public function handle(array $args)
    {
        $post_id = (int) ($args['post_id'] ?? 0);

        if ($post_id <= 0) {
            return new \WP_Error('missing_post_id', 'A post_id is required.');
        }

        return [
            'post_id'  => $post_id,
            'elements' => Elementor_Page_Data::get($post_id),
        ];
    }
}
