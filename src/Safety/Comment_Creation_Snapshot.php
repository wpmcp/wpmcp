<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- ABSPATH guard is an intentional side effect.
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional (matches the rest of WPMCP\Safety).
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional (matches the rest of WPMCP\Safety).

namespace WPMCP\Safety;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The ledger row for a comment an agent CREATED (create-comment,
 * reply-to-comment; issue #284). The comment counterpart of
 * Post_Creation_Snapshot: there is no prior state to capture, so the row
 * records what was created, and Rollback_Service undoes it by moving the
 * comment to the trash (never a hard delete, so it can be restored).
 *
 * comment_post_ID and comment_date_gmt are captured for the identity check
 * the rollback makes, so it never trashes a different comment that has since
 * reclaimed the id.
 *
 * The row is written AFTER the comment exists. If it cannot be written, the
 * comment this call just created is removed again and the error is
 * rethrown: a creation is never left behind without its undo point.
 */
class Comment_Creation_Snapshot
{
    public const OBJECT_TYPE = 'comment_create';

    /**
     * @return string The operation id of the written row.
     * @throws Mutation_Failed When the row could not be written.
     */
    public static function record(string $tool_name, int $comment_id, array $args, string $session_id): string
    {
        $comment = get_comment($comment_id);
        if (! $comment) {
            throw new \InvalidArgumentException('A creation row needs the created comment.');
        }

        $operation_id = wp_generate_uuid4();
        try {
            Snapshot_Store::save(
                $operation_id,
                '' === $session_id ? 'default' : $session_id,
                [
                    'object_type' => self::OBJECT_TYPE,
                    'object_id'   => $comment_id,
                    'data'        => [
                        'comment_post_ID'  => (int) $comment->comment_post_ID,
                        'comment_date_gmt' => (string) $comment->comment_date_gmt,
                    ],
                ],
                $tool_name,
                hash('sha256', (string) wp_json_encode($args))
            );
        } catch (\Throwable $e) {
            // No undo point, no creation. Created by this same call a moment
            // ago, so nothing else can reference it yet.
            wp_delete_comment($comment_id, true);
            throw $e;
        }
        Operation_Context::note($operation_id);
        Snapshot_Store::prune();

        return $operation_id;
    }
}
