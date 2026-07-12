<?php

namespace WPMCP\Tools;

if (! defined('ABSPATH')) {
    exit;
}

class Get_Page
{
    public function handle(array $args): array
    {
        $post = get_post((int) ($args['id'] ?? 0));
        if (! $post) {
            throw new \InvalidArgumentException('Page not found');
        }
        return [
            'id'           => $post->ID,
            'title'        => $post->post_title,
            'content'      => $post->post_content,
            'is_elementor' => (bool) get_post_meta($post->ID, '_elementor_edit_mode', true),
        ];
    }
}
