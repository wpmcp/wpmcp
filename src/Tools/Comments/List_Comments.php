<?php

namespace WPMCP\Tools\Comments;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: list comments as safe summary rows, optionally filtered by post
 * and moderation status, with paging. Commenter emails and IPs are masked in
 * list rows by the shared Privacy\Masking rule (masked: true, issues #718 and
 * the list masking audit); name, url, body and status stay readable for
 * moderation, and get-comment returns them in full only at manage_options.
 *
 * view:'akismet' returns Akismet's status (active, whether a key is
 * configured, never the key itself), its strictness and spam counters from
 * its options, plus the site's spam and pending comment counts.
 * It never touches Safe_Mutation (reads have nothing to roll back).
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
        if ('akismet' === ($args['view'] ?? '')) {
            return self::akismet_status();
        }

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

    /**
     * Akismet status from its own options. The API key is reported only as
     * configured yes/no; it never leaves this method.
     */
    public static function akismet_status(): array
    {
        // Filterable like every pack's presence check, so tests never stub the real class.
        $active = (bool) apply_filters('wpmcp_akismet_active', defined('AKISMET_VERSION') || class_exists('Akismet'));
        $key    = defined('WPCOM_API_KEY') ? (string) constant('WPCOM_API_KEY') : (string) get_option('wordpress_api_key', '');
        $counts = wp_count_comments();

        return [
            'akismet' => [
                'active'            => $active,
                'version'           => defined('AKISMET_VERSION') ? (string) constant('AKISMET_VERSION') : null,
                'key_configured'    => '' !== trim($key),
                'strict'            => '1' === (string) get_option('akismet_strictness', '0'),
                'spam_caught_total' => (int) get_option('akismet_spam_count', 0),
            ],
            'counts'  => [
                'spam'    => (int) ($counts->spam ?? 0),
                'pending' => (int) ($counts->moderated ?? 0),
            ],
        ];
    }
}
