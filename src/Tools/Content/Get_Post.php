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
            if ('_' === substr((string) $key, 0, 1) || is_protected_meta((string) $key, 'post') || Code_Posts::is_code_meta((string) $post->post_type, (string) $key)) {
                continue;
            }
            $meta[ $key ] = 1 === count($values) ? maybe_unserialize($values[0]) : array_map('maybe_unserialize', $values);
        }

        $thumbnail_id = (int) get_post_thumbnail_id($post);

        // A password-protected post's content and excerpt go only to a
        // caller who can edit it, as in core's REST API; everyone else gets
        // the post with both withheld and `protected` set (issue #450).
        $withheld = Content_Guard::withholds_protected_content($post);

        // A theme layout's code is withheld with a note, as the layout ops
        // withhold it (issue #893).
        $code_body = Code_Posts::content_note($post);
        $code_note = $code_body ?? Code_Posts::meta_note($post);
        $hidden    = $withheld || null !== $code_body;

        $out = [
            'post_id'        => (int) $post->ID,
            'post_type'      => (string) $post->post_type,
            'title'          => (string) $post->post_title,
            'slug'           => (string) $post->post_name,
            'status'         => (string) $post->post_status,
            'content'        => $hidden ? '' : (string) $post->post_content,
            'excerpt'        => $hidden ? '' : (string) $post->post_excerpt,
            'protected'      => '' !== (string) $post->post_password,
            'date'           => (string) $post->post_date,
            'modified'       => (string) $post->post_modified,
            'parent'         => (int) $post->post_parent,
            'permalink'      => (string) get_permalink($post->ID),
            'terms'          => $terms,
            'meta'           => $meta,
            'featured_image' => $thumbnail_id ? ['id' => $thumbnail_id, 'url' => (string) wp_get_attachment_image_url($thumbnail_id, 'full')] : null,
            'is_elementor'   => 'builder' === get_post_meta($post->ID, '_elementor_edit_mode', true),
        ];
        if (null !== $code_note) {
            $out['code_withheld'] = true;
            $out['note']          = $code_note;
        }
        return $out;
    }
}
