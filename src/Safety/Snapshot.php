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
                'post' => $post ? [
                    'post_content' => $post['post_content'],
                    'post_title'   => $post['post_title'],
                    'post_status'  => $post['post_status'],
                ] : null,
                'meta' => get_post_meta($object_id),
            ],
        ];
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
