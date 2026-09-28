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
        if (! Content_Guard::is_agent_readable_post_type($post_type)) {
            // Answered as an empty result rather than an error: whether a
            // conversation exists, and for whom, is itself information.
            return ['posts' => [], 'total' => 0, 'pages' => 0, 'page' => $page];
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

        $query = new \WP_Query($query_args);
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
