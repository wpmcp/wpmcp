<?php

namespace WPMCP\Tools\Media;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * list-media (issue #64): enumerate the Media Library with type/date/search
 * filters and paging. Read-only; complements get-media (single attachment).
 */
class List_Media
{
    public function handle(array $args): array
    {
        $per_page = min(100, max(1, (int) ($args['per_page'] ?? 20)));
        $page     = max(1, (int) ($args['page'] ?? 1));

        $query_args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => ['date' => 'DESC', 'ID' => 'DESC'],
            'no_found_rows'  => false,
        ];

        // 'image' matches every image/* subtype; 'image/png' matches exactly.
        // missing_sizes:true (issue #716) reports, per image, the registered
        // sizes it lacks; it narrows the listing to images by default.
        $missing_sizes = true === ($args['missing_sizes'] ?? null);

        $type = trim((string) ($args['type'] ?? ($missing_sizes ? 'image' : '')));
        if ('' !== $type) {
            $query_args['post_mime_type'] = $type;
        }

        $search = trim((string) ($args['search'] ?? ''));
        if ('' !== $search) {
            $query_args['s'] = $search;
        }

        $date_query = [];
        if (! empty($args['after'])) {
            $date_query['after'] = (string) $args['after'];
        }
        if (! empty($args['before'])) {
            $date_query['before'] = (string) $args['before'];
        }
        if ($date_query) {
            $date_query['inclusive']  = true;
            $query_args['date_query'] = [ $date_query ];
        }

        $query = new \WP_Query($query_args);

        $items        = [];
        $with_missing = 0;
        foreach ($query->posts as $post) {
            $meta = wp_get_attachment_metadata($post->ID);
            $meta = is_array($meta) ? $meta : [];
            $item = [
                'media_id'    => (int) $post->ID,
                'title'       => (string) $post->post_title,
                'url'         => (string) wp_get_attachment_url($post->ID),
                'mime_type'   => (string) $post->post_mime_type,
                'alt'         => (string) get_post_meta($post->ID, '_wp_attachment_image_alt', true),
                'date'        => (string) $post->post_date,
                'post_parent' => (int) $post->post_parent,
                'width'       => isset($meta['width']) ? (int) $meta['width'] : 0,
                'height'      => isset($meta['height']) ? (int) $meta['height'] : 0,
            ];
            if ($missing_sizes) {
                $item['missing_sizes'] = Image_Subsizes::missing((int) $post->ID);
                $with_missing         += $item['missing_sizes'] ? 1 : 0;
            }
            $items[] = $item;
        }

        $out = [
            'items'    => $items,
            'total'    => (int) $query->found_posts,
            'pages'    => max(1, (int) $query->max_num_pages),
            'page'     => $page,
            'per_page' => $per_page,
        ];
        if ($missing_sizes) {
            // Count for this page only; page through to cover the library.
            $out['with_missing_sizes'] = $with_missing;
        }
        return $out;
    }
}
