<?php

namespace WPMCP\Tools\SEO;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The one per-post read gate the SEO tool group applies before returning any
 * of a post's own content.
 *
 * The SEO abilities are gated on `edit_posts`, which is a surface-level
 * capability: it says the caller edits posts somewhere on the site, not that
 * they may read this particular post. Every SEO read returns post-derived
 * strings (title, excerpt, curated description, dates, author), so each one
 * has to re-check the specific post, the same way Search_Content re-checks
 * `read_post` per result.
 *
 * Two conditions refuse:
 *
 * - Not published and the caller cannot `read_post` it: drafts, private posts
 *   and pending revisions of other authors.
 * - Password protected and the caller cannot `edit_post` it. A protected post
 *   is published, so the status check alone lets it through, and the SEO
 *   reads take the raw `post_excerpt` and the SEO meta description rather
 *   than the display helpers, so they bypass the blanking
 *   `post_password_required()` normally provides. Editors of the post see it
 *   because they can read it in wp-admin anyway.
 *
 * Kept in one place because the check was being copy-pasted per tool, where
 * one tool drifting (as Get_SEO_Meta had, with no check at all) makes the
 * group's surface inconsistent: the same draft readable through one ability
 * and refused through its sibling.
 */
class Post_Access
{
    /**
     * The post, if the current user may read it through an SEO tool.
     *
     * @throws \InvalidArgumentException when the id is absent or no such post.
     * @throws \RuntimeException         when the caller may not read it.
     */
    public static function assert_readable(int $post_id): \WP_Post
    {
        if ($post_id <= 0) {
            throw new \InvalidArgumentException('A post id is required.');
        }

        $post = get_post($post_id);
        if (! $post instanceof \WP_Post) {
            throw new \InvalidArgumentException('Post not found: ' . (int) $post_id);
        }

        if ('publish' !== $post->post_status && ! current_user_can('read_post', $post_id)) {
            throw new \RuntimeException(
                'You do not have permission to read post ' . (int) $post_id . '.'
            );
        }

        if ('' !== (string) $post->post_password && ! current_user_can('edit_post', $post_id)) {
            throw new \RuntimeException(
                'Post ' . (int) $post_id . ' is password protected.'
            );
        }

        return $post;
    }
}
