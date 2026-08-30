<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * List the Elementor Custom Code snippets stored on this site
 * (`elementor_snippet` posts), with their location, priority, and code.
 * Read-only.
 */
class List_Code_Snippets
{
    public function handle(array $args): array
    {
        $posts = get_posts([
            'post_type'      => 'elementor_snippet',
            'post_status'    => ['publish', 'draft', 'private'],
            'posts_per_page' => 200,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);

        $snippets = [];
        foreach ($posts as $post) {
            $snippets[] = [
                'snippet_id' => $post->ID,
                'title'      => get_the_title($post),
                'status'     => $post->post_status,
                'location'   => (string) get_post_meta($post->ID, '_elementor_location', true),
                'priority'   => (int) get_post_meta($post->ID, '_elementor_priority', true),
                'code'       => (string) get_post_meta($post->ID, '_elementor_code', true),
            ];
        }

        return ['snippets' => $snippets];
    }
}
