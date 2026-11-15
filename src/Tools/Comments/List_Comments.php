<?php

namespace WPMCP\Tools\Comments;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: list comments as safe summary rows, optionally filtered by post
 * and moderation status, with paging. The author email is masked
 * in the list (masked: true); name, url, body and status stay readable for
 * moderation, and get-comment returns the full email. It never touches
 * Safe_Mutation (reads have nothing to roll back).
 */
class List_Comments
{
    /** Accepted status filters mapped to get_comments() 'status' values. */
    private const STATUS_FILTERS = [
        'approve'    => 'approve',
        'approved'   => 'approve',
        'hold'       => 'hold',
        'unapproved' => 'hold',
        'spam'       => 'spam',
        'trash'      => 'trash',
        'all'        => 'all',
    ];

    public function handle(array $args): array
    {
        $per_page = max(1, min(100, (int) ($args['per_page'] ?? 20)));
        $page     = max(1, (int) ($args['page'] ?? 1));
        $post_id  = (int) ($args['post_id'] ?? 0);
        $status   = self::STATUS_FILTERS[ (string) ($args['status'] ?? 'all') ] ?? 'all';

        $query_args = [
            'status'  => $status,
            'number'  => $per_page,
            'paged'   => $page,
            'orderby' => 'comment_ID',
            'order'   => 'DESC',
        ];
        if ($post_id > 0) {
            $query_args['post_id'] = $post_id;
        }

        $comments = get_comments($query_args);

        // Total for the same filter, ignoring paging, so callers can page.
        $count_args           = $query_args;
        $count_args['count']  = true;
        unset($count_args['number'], $count_args['paged']);
        $total = (int) get_comments($count_args);

        $rows = [];
        foreach ((array) $comments as $comment) {
            $rows[] = Comment_View::list_row($comment);
        }

        return [
            'comments' => $rows,
            'total'    => $total,
            'page'     => $page,
            'post_id'  => $post_id,
            'masked'   => true,
        ];
    }
}
