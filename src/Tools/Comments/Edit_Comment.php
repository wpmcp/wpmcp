<?php

namespace WPMCP\Tools\Comments;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Edit a comment's content and/or author fields (name, email, url).
 *
 * Routed through Safe_Mutation with object_type 'comment', so the comment's
 * full row and commentmeta are snapshotted before the write and the edit can
 * be undone via rollback-operation. Only the allowlisted fields below are ever
 * changed; moderation status is left to Moderate_Comment.
 */
class Edit_Comment
{
    /** Allowlisted inputs mapped to their wp_update_comment() column keys. */
    private const EDITABLE_FIELDS = [
        'content'      => 'comment_content',
        'author'       => 'comment_author',
        'author_email' => 'comment_author_email',
        'author_url'   => 'comment_author_url',
    ];

    public function handle(array $args): array
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            throw new \InvalidArgumentException('A comment id is required.');
        }

        if (! get_comment($id)) {
            throw new \RuntimeException('Comment not found.');
        }

        // The ability gate is moderate_comments; editing this comment also
        // needs edit_comment on it, which core maps to edit_post on its post.
        if (! current_user_can('edit_comment', $id)) {
            throw new \RuntimeException('You do not have permission to edit comment ' . (int) $id . '.');
        }

        $changes = $this->collect_changes($args);
        if ([] === $changes) {
            return ['id' => $id, 'updated' => []];
        }

        $out = Safe_Mutation::run(
            [
                'object_type' => 'comment',
                'object_id'   => $id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'edit-comment',
                'args'        => $args,
            ],
            function () use ($id, $changes): void {
                $result = wp_update_comment(array_merge(['comment_ID' => $id], $changes));
                if (false === $result || is_wp_error($result)) {
                    throw new \RuntimeException('Could not update the comment.');
                }
            }
        );

        return [
            'id'           => $id,
            'updated'      => array_keys(array_intersect_key($args, self::EDITABLE_FIELDS)),
            'operation_id' => $out['operation_id'],
        ];
    }

    /**
     * Build the wp_update_comment() column map from allowlisted inputs only.
     *
     * @return array<string,string>
     */
    private function collect_changes(array $args): array
    {
        $changes = [];
        foreach (self::EDITABLE_FIELDS as $input => $column) {
            if (! array_key_exists($input, $args)) {
                continue;
            }
            $value = (string) $args[ $input ];
            if ('comment_author_email' === $column) {
                $changes[ $column ] = sanitize_email($value);
            } elseif ('comment_author_url' === $column) {
                $changes[ $column ] = esc_url_raw($value);
            } elseif ('comment_content' === $column) {
                $changes[ $column ] = wp_kses_post($value);
            } else {
                $changes[ $column ] = sanitize_text_field($value);
            }
        }
        return $changes;
    }
}
