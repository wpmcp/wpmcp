<?php

namespace WPMCP\Tools\Content;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: core's preview URL (get_preview_post_link) for a draft, pending
 * or scheduled post the caller can edit, so an agent can hand the user a link
 * after editing one. Published posts are refused: their permalink is the
 * answer. Never touches Safe_Mutation.
 */
class Get_Preview_Link
{
    private const PREVIEWABLE = ['draft', 'pending', 'future'];

    public function handle(array $args): array
    {
        $post_id = (int) ($args['post_id'] ?? 0);
        $post    = $post_id > 0 ? get_post($post_id) : null;
        if (! $post || ! Content_Guard::is_agent_readable_post_type((string) $post->post_type)) {
            throw new \InvalidArgumentException('Post not found');
        }

        if (! current_user_can('edit_post', $post->ID)) {
            throw new \RuntimeException('You do not have permission to edit post ' . (int) $post->ID . '.');
        }

        $status = (string) $post->post_status;
        if (! in_array($status, self::PREVIEWABLE, true)) {
            throw new \InvalidArgumentException(
                'Post ' . (int) $post->ID . ' is ' . esc_html($status) . '; only draft, pending and scheduled posts have a preview link.'
            );
        }

        $url = get_preview_post_link($post);
        if (! is_string($url) || '' === $url) {
            throw new \InvalidArgumentException('Post type ' . esc_html((string) $post->post_type) . ' is not viewable, so it has no preview.');
        }

        return [
            'post_id'     => (int) $post->ID,
            'status'      => $status,
            'preview_url' => $url,
        ];
    }
}
