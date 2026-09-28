<?php

namespace WPMCP\Tools\SEO;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Set one or more SEO fields (title, description, focus keyword, canonical,
 * noindex, nofollow) on a post via SEO_Adapter::update_meta(), translated to
 * the active plugin's postmeta keys.
 *
 * These are ordinary postmeta, so the write routes through Safe_Mutation
 * with the existing object_type 'post' and the post id: the existing post
 * snapshot already captures the full postmeta map (including the active
 * plugin's SEO keys) before the mutation, and a rollback-operation restores
 * it exactly, through the same engine that already covers posts. No
 * SEO-specific snapshot logic is needed.
 */
class Update_SEO_Meta
{
    private const FIELDS = ['title', 'description', 'focus_keyword', 'canonical', 'noindex', 'nofollow'];

    public function handle(array $args): array
    {
        $post_id = (int) ($args['post_id'] ?? 0);

        // edit_posts on the ability says the caller edits posts somewhere,
        // not that they may rewrite this one: re-check the specific post.
        Post_Access::assert_editable($post_id);

        $fields = array_intersect_key($args, array_flip(self::FIELDS));
        if ([] === $fields) {
            throw new \InvalidArgumentException('At least one SEO field is required.');
        }

        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $post_id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'update-seo-meta',
                'args'        => $args,
            ],
            function () use ($post_id, $fields): void {
                SEO_Adapter::update_meta($post_id, $fields);
            }
        );

        return array_merge(
            ['post_id' => $post_id],
            SEO_Adapter::get_meta($post_id),
            ['operation_id' => $out['operation_id'], 'recoverable' => true]
        );
    }
}
