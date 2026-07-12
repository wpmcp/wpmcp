<?php

namespace WPMCP\Safety;

if (! defined('ABSPATH')) {
    exit;
}

class Snapshot
{
    public static function capture(string $object_type, int $object_id): array
    {
        $post = get_post($object_id, ARRAY_A);
        return [
            'object_type' => $object_type,
            'object_id'   => $object_id,
            'data'        => [
                'post'  => $post ? [
                    'post_content' => $post['post_content'],
                    'post_title'   => $post['post_title'],
                    'post_status'  => $post['post_status'],
                ] : null,
                'meta'  => get_post_meta($object_id),
                'terms' => $post ? self::capture_terms($object_id, $post['post_type']) : [],
            ],
        ];
    }

    /** Map of taxonomy => term IDs currently assigned to the post, for terms rollback. */
    private static function capture_terms(int $post_id, string $post_type): array
    {
        $terms = [];
        foreach ((array) get_object_taxonomies($post_type) as $taxonomy) {
            $ids = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
            if (is_array($ids)) {
                $terms[ $taxonomy ] = $ids;
            }
        }
        return $terms;
    }

    public static function serialize(array $before): string
    {
        return gzencode(wp_json_encode($before));
    }

    public static function unserialize(string $blob): array
    {
        return json_decode(gzdecode($blob), true);
    }
}
