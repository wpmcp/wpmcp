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
            throw new \RuntimeException('The delete-comment tool is disabled. Enable it with the wpmcp_enable_delete_comment filter.');
        }

        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0 || ! get_comment($id)) {
            throw new \InvalidArgumentException('Comment not found.');
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
}
