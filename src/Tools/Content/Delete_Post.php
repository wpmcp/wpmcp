<?php

namespace WPMCP\Tools\Content;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

class Delete_Post
{
    /**
     * Trash-delete (the default) is NOT routed through Safe_Mutation: WordPress's
     * own trash already makes it reversible, so a redundant snapshot buys us
     * nothing. Force-delete permanently destroys the post, so that branch IS
     * safe-wrapped for a rollback-able snapshot.
     */
    public function handle(array $args): array
    {
        $post_id = (int) ($args['post_id'] ?? 0);
        $post    = $post_id ? get_post($post_id) : null;
        if (! $post) {
            throw new \InvalidArgumentException('Post not found');
        }

        $force = ! empty($args['force']);

        if (! $force) {
            wp_trash_post($post_id);
            return ['post_id' => $post_id, 'deleted' => 'trashed'];
        }

        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $post_id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'delete-post',
                'args'        => $args,
            ],
            function () use ($post_id) {
                wp_delete_post($post_id, true);
                return true;
            }
        );

        return ['operation_id' => $out['operation_id'], 'post_id' => $post_id, 'deleted' => 'deleted'];
    }
}
