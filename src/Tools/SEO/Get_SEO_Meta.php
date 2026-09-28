<?php

namespace WPMCP\Tools\SEO;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: return a post's SEO title, meta description, focus keyword,
 * canonical URL, and robots flags (noindex/nofollow) via the active SEO
 * plugin's postmeta keys, through SEO_Adapter. Reads have nothing to roll
 * back, so this never touches Safe_Mutation.
 *
 * The description this returns is post content, so the read goes through
 * Post_Access like the rest of the SEO group: edit_posts alone does not
 * entitle a caller to another author's draft or to a protected post.
 */
class Get_SEO_Meta
{
    public function handle(array $args): array
    {
        $post_id = (int) ($args['post_id'] ?? 0);

        Post_Access::assert_readable($post_id);

        return array_merge(['post_id' => $post_id], SEO_Adapter::get_meta($post_id));
    }
}
