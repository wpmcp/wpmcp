<?php

namespace WPMCP\Tools\Content;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

class Delete_Post
{
    /**
     * The permanent force-delete path is destructive and disabled by default,
     * matching every newer delete tool (Delete_Media, Delete_Comment, etc.):
     * sites must opt in with add_filter('wpmcp_enable_delete_post',
     * '__return_true') before a force-delete will run at all, in addition to
     * the caller passing confirm:true. The default trash path is left ungated:
     * WordPress's own trash already makes it reversible.
     */
    public static function is_force_enabled(): bool
    {
        return (bool) apply_filters('wpmcp_enable_delete_post', false);
    }

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

        if (! self::is_force_enabled()) {
            throw new \RuntimeException('Permanent (force) delete-post is disabled. Enable it with the wpmcp_enable_delete_post filter.');
        }
        if (true !== ($args['confirm'] ?? null)) {
            throw new \InvalidArgumentException('Permanently deleting a post is not reversible for the physical record. Pass confirm:true to proceed.');
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
