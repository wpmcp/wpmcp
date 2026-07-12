<?php

namespace WPMCP\Tools\Comments;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Change a comment's moderation status: approve, unapprove, spam, trash or
 * untrash.
 *
 * Routed through Safe_Mutation with object_type 'comment', so the comment's
 * full row and commentmeta are snapshotted before the write and the status
 * change can be undone via rollback-operation. trash is WordPress's own
 * reversible trash, but it is still snapshotted so a single rollback path
 * covers every status transition uniformly.
 */
class Moderate_Comment
{
    /** Accepted actions mapped to the wp_set_comment_status() status string. */
    private const ACTIONS = [
        'approve'   => 'approve',
        'unapprove' => 'hold',
        'spam'      => 'spam',
        'trash'     => 'trash',
        'untrash'   => 'approve',
    ];

    public function handle(array $args): array
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            throw new \InvalidArgumentException('A comment id is required.');
        }

        $action = (string) ($args['status'] ?? '');
        if (! isset(self::ACTIONS[ $action ])) {
            throw new \InvalidArgumentException('Unknown status. Use approve, unapprove, spam, trash or untrash.');
        }

        if (! get_comment($id)) {
            throw new \RuntimeException('Comment not found.');
        }

        $out = Safe_Mutation::run(
            [
                'object_type' => 'comment',
                'object_id'   => $id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'moderate-comment',
                'args'        => $args,
            ],
            function () use ($id, $action): void {
                // 'untrash' has a dedicated core function; every other action
                // is a plain status set. Both restore the comment to a normal
                // state where needed and return false on failure.
                $result = ('untrash' === $action)
                    ? wp_untrash_comment($id)
                    : wp_set_comment_status($id, self::ACTIONS[ $action ]);
                if (! $result) {
                    throw new \RuntimeException('Could not change the comment status.');
                }
            }
        );

        $comment = get_comment($id);

        return [
            'id'           => $id,
            'status'       => $comment ? Comment_View::status((string) $comment->comment_approved) : null,
            'operation_id' => $out['operation_id'],
        ];
    }
}
