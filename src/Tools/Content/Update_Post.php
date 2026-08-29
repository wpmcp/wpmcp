<?php

namespace WPMCP\Tools\Content;

use WPMCP\Safety\Safe_Mutation;
use WPMCP\Tools\Redirects\Redirect_Store;
use WPMCP\Tools\Redirects\Redirect_Suggestions;

if (! defined('ABSPATH')) {
    exit;
}

class Update_Post
{
    private const VALID_STATUSES = ['draft', 'publish', 'pending', 'private', 'future'];

    /**
     * When this edit moved a PUBLISHED post to a new URL, queue (and return) a
     * suggestion that the old URL be redirected to the new one.
     *
     * SUGGESTION ONLY: renaming a slug must not silently change site-wide
     * routing. The old URL is a real address people and search engines already
     * hold, and deciding what happens to it is a routing decision, not a
     * content edit, so it takes an explicit create-redirect call (or a human
     * clicking Create on the Redirects screen, which calls the same tool).
     *
     * Only publish -> publish moves qualify: an unpublished post had no public
     * URL to lose, and a draft being published is gaining one.
     *
     * @return array<string,mixed>|null
     */
    private function suggest_redirect(int $post_id, string $old_url, string $old_status): ?array
    {
        if ('publish' !== $old_status || '' === $old_url) {
            return null;
        }
        if ('publish' !== get_post_status($post_id)) {
            return null;
        }

        $new_url = get_permalink($post_id);
        $new_url = is_string($new_url) ? $new_url : '';
        if ('' === $new_url) {
            return null;
        }

        $old_path = Redirect_Store::normalize_path($old_url);
        $new_path = Redirect_Store::normalize_path($new_url);
        if ($old_path === $new_path) {
            return null;
        }

        return Redirect_Suggestions::propose(
            $old_path,
            Redirect_Suggestions::REASON_SLUG_CHANGED,
            $post_id,
            sprintf('The published URL moved from %s to %s.', $old_path, $new_path)
        );
    }

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
                throw new \InvalidArgumentException(esc_html($guard));
            }
        }
        if (isset($args['status']) && ! in_array($args['status'], self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException('Invalid status.');
        }

        $old_status = (string) $post->post_status;
        $old_url    = 'publish' === $old_status ? (string) get_permalink($post_id) : '';

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

        $result     = ['operation_id' => $out['operation_id'], 'post_id' => $post_id];
        $suggestion = $this->suggest_redirect($post_id, $old_url, $old_status);
        if (null !== $suggestion) {
            $result['suggested_redirect'] = $suggestion;
        }

        return $result;
    }
}
