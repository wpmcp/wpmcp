<?php

namespace WPMCP\Tools\Comments;

use WPMCP\Privacy\Masking;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Shared read-side formatting for the comment tools. Turns a WP_Comment into
 * a stable summary row and translates WordPress's raw comment_approved column
 * (which mixes booleans and status strings: '1', '0', 'spam', 'trash',
 * 'post-trashed') into a friendly, predictable status label.
 */
class Comment_View
{
    public static function status(string $comment_approved): string
    {
        switch ($comment_approved) {
            case '1':
                return 'approved';
            case '0':
                return 'unapproved';
            default:
                // 'spam', 'trash', 'post-trashed' pass through as-is.
                return $comment_approved;
        }
    }

    public static function row(\WP_Comment $comment): array
    {
        return [
            'id'           => (int) $comment->comment_ID,
            'post_id'      => (int) $comment->comment_post_ID,
            'parent'       => (int) $comment->comment_parent,
            'author'       => (string) $comment->comment_author,
            'author_email' => (string) $comment->comment_author_email,
            'author_url'   => (string) $comment->comment_author_url,
            'content'      => (string) $comment->comment_content,
            'status'       => self::status((string) $comment->comment_approved),
            'date'         => (string) $comment->comment_date,
        ];
    }

    /**
     * A row for a LIST read: the author email is masked on the server
     * (Privacy\Masking), because a list hands many commenters' emails to the
     * model at once. Name, content and status stay readable for moderation.
     * A single get-comment keeps the full email.
     */
    public static function list_row(\WP_Comment $comment): array
    {
        return Masking::keys(self::row($comment), [ 'author_email' ]);
    }
}
