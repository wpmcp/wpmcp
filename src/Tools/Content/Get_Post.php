<?php

namespace WPMCP\Tools\Content;

if (! defined('ABSPATH')) {
    exit;
}

class Get_Post
{
    public function handle(array $args): array
    {
        $post_id = (int) ($args['post_id'] ?? 0);
        $post    = $post_id ? get_post($post_id) : null;
        if (! $post || ! Content_Guard::is_agent_readable_post_type((string) $post->post_type)) {
            // Same answer for a plugin-internal type as for a missing post:
            // the difference is itself information.
            throw new \InvalidArgumentException('Post not found');
        }

        $terms = [];
        foreach ((array) get_object_taxonomies($post->post_type) as $taxonomy) {
            $term_objects = get_the_terms($post, $taxonomy);
            if (is_array($term_objects)) {
                $terms[ $taxonomy ] = array_map(
                    fn($t) => ['term_id' => (int) $t->term_id, 'name' => (string) $t->name, 'slug' => (string) $t->slug],
                    $term_objects
                );
            }
        }

        $meta_raw = get_post_meta($post->ID);
        $meta     = [];
        foreach ((array) $meta_raw as $key => $values) {
            if ('_' === substr((string) $key, 0, 1) || is_protected_meta((string) $key, 'post')) {
                continue;
            }
            $meta[ $key ] = 1 === count($values) ? maybe_unserialize($values[0]) : array_map('maybe_unserialize', $values);
        }

        $thumbnail_id = (int) get_post_thumbnail_id($post);

        return [
            'post_id'        => (int) $post->ID,
            'post_type'      => (string) $post->post_type,
            'title'          => (string) $post->post_title,
            'slug'           => (string) $post->post_name,
            'status'         => (string) $post->post_status,
            'content'        => (string) $post->post_content,
            'excerpt'        => (string) $post->post_excerpt,
            'date'           => (string) $post->post_date,
            'modified'       => (string) $post->post_modified,
            'parent'         => (int) $post->post_parent,
            'permalink'      => (string) get_permalink($post->ID),
            'terms'          => $terms,
            'meta'           => $meta,
            'featured_image' => $thumbnail_id ? ['id' => $thumbnail_id, 'url' => (string) wp_get_attachment_image_url($thumbnail_id, 'full')] : null,
            'is_elementor'   => 'builder' === get_post_meta($post->ID, '_elementor_edit_mode', true),
        ];
    }
}
