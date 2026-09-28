<?php

namespace WPMCP\Tools\Comments;

use WPMCP\Safety\Comment_Creation_Snapshot;
use WPMCP\Tools\Content\Content_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Post a comment on a post as the current user (issue #284). Also the shared
 * writer behind reply-to-comment.
 *
 * The rules follow core's own admin reply: the caller must be able to edit
 * the post, and a draft, pending or trashed post takes no comments. The
 * status is 'approved' or 'unapproved'; approved requires moderate_comments,
 * and the default is approved for a moderator and unapproved otherwise.
 * Content goes through wp_filter_comment(), so the caller's own kses rules
 * apply exactly as they would in wp-admin.
 *
 * NOT routed through Safe_Mutation (a new comment has no prior state). Once
 * the comment exists a 'comment_create' row is written under the caller's
 * session_id (Comment_Creation_Snapshot); rollback-operation and
 * rollback-session undo it by moving the comment to the trash.
 */
class Create_Comment
{
    private const STATUSES = ['approved' => 1, 'unapproved' => 0];

    public function handle(array $args): array
    {
        return $this->insert($args, (int) ($args['post_id'] ?? 0), 0, 'create-comment');
    }

    /**
     * @param int $parent_id 0 for a top-level comment.
     */
    public function insert(array $args, int $post_id, int $parent_id, string $tool_name): array
    {
        $content = trim((string) ($args['content'] ?? ''));
        if ('' === $content) {
            throw new \InvalidArgumentException('Comment content is required.');
        }

        $post = $post_id > 0 ? get_post($post_id) : null;
        if (! $post || ! Content_Guard::is_agent_readable_post_type((string) $post->post_type)) {
            throw new \InvalidArgumentException('Post not found');
        }
        if (in_array($post->post_status, ['draft', 'pending', 'trash', 'auto-draft'], true)) {
            throw new \InvalidArgumentException('Post ' . (int) $post->ID . ' is ' . esc_html($post->post_status) . ' and cannot take comments.');
        }
        if (! current_user_can('edit_post', $post->ID)) {
            throw new \RuntimeException('You do not have permission to edit post ' . (int) $post->ID . '.');
        }

        $moderator = current_user_can('moderate_comments');
        $status    = (string) ($args['status'] ?? ($moderator ? 'approved' : 'unapproved'));
        if (! isset(self::STATUSES[ $status ])) {
            throw new \InvalidArgumentException('Unknown status. Use approved or unapproved.');
        }
        if ('approved' === $status && ! $moderator) {
            throw new \RuntimeException('Posting an approved comment requires the moderate_comments capability.');
        }

        $user = wp_get_current_user();
        if (! $user->exists()) {
            throw new \RuntimeException('Comments are posted as the current user, and there is none.');
        }

        $data = wp_filter_comment([
            'comment_post_ID'      => (int) $post->ID,
            'comment_parent'       => $parent_id,
            'comment_content'      => $content,
            'comment_type'         => 'comment',
            'user_id'              => (int) $user->ID,
            'comment_author'       => (string) $user->display_name,
            'comment_author_email' => (string) $user->user_email,
            'comment_author_url'   => (string) $user->user_url,
            'comment_author_IP'    => '',
            'comment_agent'        => '',
        ]);
        $data['comment_approved'] = self::STATUSES[ $status ];

        $comment_id = wp_insert_comment(wp_slash($data));
        if (! $comment_id) {
            throw new \RuntimeException('Could not create the comment.');
        }

        $operation_id = Comment_Creation_Snapshot::record($tool_name, (int) $comment_id, $args, (string) ($args['session_id'] ?? 'default'));

        $comment = get_comment($comment_id);

        return Comment_View::row($comment) + ['operation_id' => $operation_id];
    }
}
