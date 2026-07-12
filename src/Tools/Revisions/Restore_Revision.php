<?php

namespace WPMCP\Tools\Revisions;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

class Restore_Revision
{
    public function handle(array $args): array
    {
        $post_id     = (int) ($args['post_id'] ?? 0);
        $post        = $post_id ? get_post($post_id) : null;
        $revision_id = (int) ($args['revision_id'] ?? 0);
        $revision    = $revision_id ? get_post($revision_id) : null;

        if (! $post || ! $revision || 'revision' !== $revision->post_type || (int) $revision->post_parent !== $post_id) {
            throw new \InvalidArgumentException('Revision not found for that post');
        }

        // wp_restore_post_revision() only ever mutates the PARENT post
        // (title/content/excerpt/etc.), never the revision record itself, so
        // this restore is snapshotted and made undoable through the parent
        // post's own object_type='post' Safe_Mutation path, reusing the
        // existing post rollback machinery rather than adding a new one.
        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $post_id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'restore-revision',
                'args'        => $args,
            ],
            function () use ($revision_id) {
                return wp_restore_post_revision($revision_id);
            }
        );

        return ['operation_id' => $out['operation_id'], 'post_id' => $post_id, 'revision_id' => $revision_id];
    }
}
