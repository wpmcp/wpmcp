<?php

namespace WPMCP\Tools\Content;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

class Update_Post
{
    private const VALID_STATUSES = ['draft', 'publish', 'pending', 'private', 'future'];

    public function handle(array $args): array
    {
        $post_id = (int) ($args['post_id'] ?? 0);
        $post    = $post_id ? get_post($post_id) : null;
        if (! $post) {
            throw new \InvalidArgumentException('Post not found');
        }
        if (! Content_Guard::is_writable_post_type((string) $post->post_type)) {
            throw new \InvalidArgumentException('That post type is not writable here.');
        }
        if (isset($args['meta']) && is_array($args['meta'])) {
            $guard = Content_Guard::check_meta($args['meta']);
            if (true !== $guard) {
                throw new \InvalidArgumentException($guard);
            }
        }
        if (isset($args['status']) && ! in_array($args['status'], self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException('Invalid status.');
        }

        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $post_id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'update-post',
                'args'        => $args,
            ],
            function () use ($post_id, $args) {
                $postarr = ['ID' => $post_id];
                if (array_key_exists('title', $args)) {
                    $postarr['post_title'] = sanitize_text_field((string) $args['title']);
                }
                if (array_key_exists('content', $args)) {
                    $postarr['post_content'] = (string) $args['content'];
                }
                if (array_key_exists('excerpt', $args)) {
                    $postarr['post_excerpt'] = (string) $args['excerpt'];
                }
                if (! empty($args['slug'])) {
                    $postarr['post_name'] = sanitize_title((string) $args['slug']);
                }
                if (isset($args['parent'])) {
                    $postarr['post_parent'] = (int) $args['parent'];
                }
                if (! empty($args['status'])) {
                    $postarr['post_status'] = sanitize_key((string) $args['status']);
                }
                wp_update_post($postarr);

                if (isset($args['terms']) && is_array($args['terms'])) {
                    $append = isset($args['terms_mode']) && 'append' === $args['terms_mode'];
                    foreach ($args['terms'] as $taxonomy => $terms) {
                        wp_set_object_terms($post_id, array_values((array) $terms), sanitize_key((string) $taxonomy), $append);
                    }
                }
                if (isset($args['meta']) && is_array($args['meta'])) {
                    foreach ($args['meta'] as $key => $value) {
                        update_post_meta($post_id, sanitize_key((string) $key), $value);
                    }
                }
                if (array_key_exists('featured_image', $args)) {
                    $featured_image = $args['featured_image'];
                    if (null === $featured_image) {
                        delete_post_thumbnail($post_id);
                    } elseif (is_array($featured_image) && ! empty($featured_image['id'])) {
                        set_post_thumbnail($post_id, (int) $featured_image['id']);
                    }
                }

                return true;
            }
        );

        return ['operation_id' => $out['operation_id'], 'post_id' => $post_id];
    }
}
