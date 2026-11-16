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
     * A row for a LIST read, and for get-comment below manage_options: the
     * author email and IP are masked on the server by the shared
     * Privacy\Masking rule, because a list hands many commenters' data to the
     * model at once (issue #718 and the list masking audit). Name, content and
     * status stay readable for moderation.
     */
    public static function masked_row(\WP_Comment $comment): array
    {
        $row              = self::row($comment);
        $row['author_ip'] = (string) $comment->comment_author_IP;
        return Masking::keys($row, [ 'author_email', 'author_ip' ]);
    }

    /** The list form of a comment row; see masked_row(). */
    public static function list_row(\WP_Comment $comment): array
    {
        return self::masked_row($comment);
    }
}
