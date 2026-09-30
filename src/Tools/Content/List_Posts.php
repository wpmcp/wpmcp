<?php

namespace WPMCP\Tools\Content;

if (! defined('ABSPATH')) {
    exit;
}

class List_Posts
{
    private const VALID_STATUSES = ['publish', 'future', 'draft', 'pending', 'private', 'trash', 'any'];
    private const VALID_ORDERBY  = ['date', 'modified', 'title', 'menu_order', 'ID'];

    public function handle(array $args): array
    {
        $per_page = max(1, min(100, (int) ($args['per_page'] ?? 20)));
        $page     = max(1, (int) ($args['page'] ?? 1));
        $orderby  = in_array($args['orderby'] ?? '', self::VALID_ORDERBY, true) ? $args['orderby'] : 'date';
        $order    = (isset($args['order']) && 'ASC' === strtoupper((string) $args['order'])) ? 'ASC' : 'DESC';

        $post_type = isset($args['post_type']) ? sanitize_key((string) $args['post_type']) : 'post';
        if ('' === $post_type) {
            $post_type = 'post';
        }
        $empty = ['posts' => [], 'total' => 0, 'pages' => 0, 'page' => $page];
        if ('any' === $post_type) {
            // WP_Query's 'any' is every type not excluded from search, which
            // can include a plugin's non-public records. Narrow it to the
            // types this caller may read (issue #446).
            $post_type = array_values(array_filter(
                array_map('strval', get_post_types(['exclude_from_search' => false], 'names')),
                [Content_Guard::class, 'can_read_post_type']
            ));
            if ([] === $post_type) {
                return $empty;
            }
        } elseif (! Content_Guard::can_read_post_type($post_type)) {
            // Answered as an empty result rather than an error: whether a
            // private record exists, and for whom, is itself information.
            // The permission check refuses the call before it gets here;
            // this holds when the handler is reached some other way.
            return $empty;
        }
        $status_in = isset($args['status']) ? (string) $args['status'] : 'any';
        $status    = in_array($status_in, self::VALID_STATUSES, true) ? $status_in : 'any';

        $query_args = [
            'post_type'      => $post_type,
            'post_status'    => $status,
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => $orderby,
            'order'          => $order,
        ];
        if (! empty($args['search'])) {
            $query_args['s'] = sanitize_text_field((string) $args['search']);
        }
        if (! empty($args['author'])) {
            $query_args['author'] = (int) $args['author'];
        }
        if (isset($args['parent'])) {
            $query_args['post_parent'] = (int) $args['parent'];
        }

        // WP_Query applies no read permission to an explicit status or to
        // 'any', so another user's drafts and private posts would be listed
        // to a Contributor. Keep the rows to what core's read_post allows
        // (issue #448); in SQL, so total and pages stay exact.
        $where = Content_Guard::readable_posts_where((array) $post_type, $GLOBALS['wpdb']->posts);
        $query = new \WP_Query();
        $scope = static function ($sql, $q) use (&$query, $where) {
            return $q === $query ? $sql . $where : $sql;
        };
        if ('' !== $where) {
            add_filter('posts_where', $scope, 10, 2);
        }
        try {
            $query->query($query_args);
        } finally {
            remove_filter('posts_where', $scope, 10);
        }
        $rows  = [];
        foreach ($query->posts as $p) {
            $rows[] = [
                'post_id'      => (int) $p->ID,
                'post_type'    => (string) $p->post_type,
                'title'        => (string) $p->post_title,
                'slug'         => (string) $p->post_name,
                'status'       => (string) $p->post_status,
                'date'         => (string) $p->post_date,
                'modified'     => (string) $p->post_modified,
                'author_id'    => (int) $p->post_author,
                'permalink'    => (string) get_permalink((int) $p->ID),
                'is_elementor' => 'builder' === get_post_meta((int) $p->ID, '_elementor_edit_mode', true),
            ];
        }

        return [
            'posts' => $rows,
            'total' => (int) $query->found_posts,
            'pages' => (int) $query->max_num_pages,
            'page'  => $page,
        ];
    }
}
