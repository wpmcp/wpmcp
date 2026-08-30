<?php

namespace WPMCP\Tools\Content;

if (! defined('ABSPATH')) {
    exit;
}

class Create_Post
{
    private const VALID_STATUSES = ['draft', 'publish', 'pending', 'private', 'future'];

    /**
     * NOT routed through Safe_Mutation: creating a brand new post cannot destroy
     * or overwrite any existing content, so there is nothing to snapshot/roll
     * back. update-post, delete-post (force), and set-post-terms mutate or
     * remove existing state and therefore ARE safe-wrapped.
     */
    public function handle(array $args): array
    {
        $post_type = sanitize_key((string) ($args['post_type'] ?? 'post'));
        if ('' === $post_type) {
            $post_type = 'post';
        }
        if (! Content_Guard::is_writable_post_type($post_type)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a writable post type.', esc_html($post_type)));
        }

        $status = sanitize_key((string) ($args['status'] ?? 'draft'));
        if (! in_array($status, self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException('Invalid status.');
        }

        if (isset($args['meta']) && is_array($args['meta'])) {
            $guard = Content_Guard::check_meta($args['meta']);
            if (true !== $guard) {
                throw new \InvalidArgumentException(esc_html($guard));
            }
        }

        $postarr = [
            'post_type'    => $post_type,
            'post_status'  => $status,
            'post_title'   => sanitize_text_field((string) ($args['title'] ?? '')),
            'post_content' => (string) ($args['content'] ?? ''),
            'post_excerpt' => (string) ($args['excerpt'] ?? ''),
        ];
        if (! empty($args['slug'])) {
            $postarr['post_name'] = sanitize_title((string) $args['slug']);
        }
        if (isset($args['parent'])) {
            $postarr['post_parent'] = (int) $args['parent'];
        }

        $post_id = wp_insert_post($postarr, true);
        if (is_wp_error($post_id)) {
            throw new \InvalidArgumentException(esc_html($post_id->get_error_message()));
        }
        $post_id = (int) $post_id;

        if (isset($args['terms']) && is_array($args['terms'])) {
            foreach ($args['terms'] as $taxonomy => $terms) {
                wp_set_object_terms($post_id, array_values((array) $terms), sanitize_key((string) $taxonomy), false);
            }
        }
        if (isset($args['meta']) && is_array($args['meta'])) {
            foreach ($args['meta'] as $key => $value) {
                update_post_meta($post_id, sanitize_key((string) $key), $value);
            }
        }

        return [
            'post_id'   => $post_id,
            'status'    => $status,
            'permalink' => (string) get_permalink($post_id),
        ];
    }
}
