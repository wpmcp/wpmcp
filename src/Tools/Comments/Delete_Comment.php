<?php

namespace WPMCP\Tools\Comments;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Permanently delete a comment.
 *
 * Destructive and disabled by default: sites must opt in with
 * add_filter('wpmcp_enable_delete_comment', '__return_true') before this tool
 * will run at all, in addition to the caller passing confirm:true.
 *
 * The delete is routed through Safe_Mutation with object_type 'comment', so
 * the full comment row and commentmeta are snapshotted first. A rollback
 * resurrects the comment via wp_insert_comment(): its content, author, status,
 * dates, thread association and meta are restored, but WordPress has no
 * import_id for comments, so the resurrected comment receives a NEW ID rather
 * than reclaiming the original one.
 */
class Delete_Comment
{
    public static function is_enabled(): bool
    {
        return (bool) apply_filters('wpmcp_enable_delete_comment', false);
    }

    public function handle(array $args): array
    {
        if (! self::is_enabled()) {
            throw new \WPMCP\MCP\Opt_In_Required('The delete-comment tool is disabled.', 'wpmcp_enable_delete_comment');
        }

        if (true === ($args['empty_spam'] ?? null)) {
            return $this->empty_spam($args);
        }

        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0 || ! get_comment($id)) {
            throw new \InvalidArgumentException('Comment not found.');
        }

        // The ability gate is moderate_comments; deleting this comment also
        // needs edit_comment on it, the check wp-admin's own delete makes.
        if (! current_user_can('edit_comment', $id)) {
            throw new \RuntimeException('You do not have permission to delete comment ' . (int) $id . '.');
        }

        if (true !== ($args['confirm'] ?? null)) {
            throw new \WPMCP\MCP\Confirmation_Required('Deleting a comment is permanent. Pass confirm:true to proceed.');
        }

        $out = Safe_Mutation::run(
            [
                'object_type' => 'comment',
                'object_id'   => $id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'delete-comment',
                'args'        => $args,
            ],
            function () use ($id): void {
                if (! wp_delete_comment($id, true)) {
                    throw new \RuntimeException('Could not delete the comment.');
                }
            }
        );

        return [
            'operation_id'  => $out['operation_id'],
            'id'            => $id,
            'deleted'       => true,
            'id_preserved'  => false,
            'warning'       => 'Rollback restores the comment content, author, status and meta, but WordPress cannot reuse the original comment ID, so a resurrected comment gets a new ID.',
        ];
    }

    /**
     * Empty spam (issue #718): permanently delete up to 100 spam comments per
     * call, each snapshotted first through Safe_Mutation so every one can be
     * resurrected by rollback (with a new ID, as for a single delete).
     * Needs the same opt-in as delete-comment plus confirm:true.
     */
    private function empty_spam(array $args): array
    {
        if (true !== ($args['confirm'] ?? null)) {
            throw new \WPMCP\MCP\Confirmation_Required('Emptying spam permanently deletes spam comments. Pass confirm:true to proceed.');
        }

        $ids = get_comments([
            'status' => 'spam',
            'fields' => 'ids',
            'number' => 100,
            'orderby' => 'comment_ID',
            'order'  => 'ASC',
        ]);

        $operation_ids = [];
        $deleted       = [];
        foreach ((array) $ids as $id) {
            $id = (int) $id;
            if (! current_user_can('edit_comment', $id)) {
                continue;
            }
            $out = Safe_Mutation::run(
                [
                    'object_type' => 'comment',
                    'object_id'   => $id,
                    'session_id'  => (string) ($args['session_id'] ?? 'default'),
                    'tool_name'   => 'delete-comment',
                    'args'        => [ 'id' => $id, 'empty_spam' => true, 'confirm' => true ],
                ],
                function () use ($id): void {
                    if (! wp_delete_comment($id, true)) {
                        throw new \RuntimeException('Could not delete spam comment ' . (int) $id . '.');
                    }
                }
            );
            $operation_ids[] = $out['operation_id'];
            $deleted[]       = $id;
        }

        $remaining = (int) get_comments([ 'status' => 'spam', 'count' => true ]);

        return [
            'deleted_count' => count($deleted),
            'deleted_ids'   => $deleted,
            'operation_ids' => $operation_ids,
            'remaining'     => $remaining,
            'id_preserved'  => false,
        ];
    }
}
