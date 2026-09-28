<?php

namespace WPMCP\Tools\Comments;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Reply to a comment as the current user (issue #284): a create-comment on
 * the parent's post with comment_parent set, under the same rules, status
 * handling and creation row. Spam or trashed comments take no replies.
 */
class Reply_To_Comment
{
    public function handle(array $args): array
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            throw new \InvalidArgumentException('A comment id is required.');
        }

        $parent = get_comment($id);
        if (! $parent) {
            throw new \RuntimeException('Comment not found.');
        }
        if (in_array((string) $parent->comment_approved, ['spam', 'trash', 'post-trashed'], true)) {
            throw new \InvalidArgumentException('Comment ' . (int) $id . ' is ' . esc_html((string) $parent->comment_approved) . ' and cannot be replied to.');
        }

        return (new Create_Comment())->insert($args, (int) $parent->comment_post_ID, $id, 'reply-to-comment');
    }
}
