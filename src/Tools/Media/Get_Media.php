<?php

namespace WPMCP\Tools\Media;

if (! defined('ABSPATH')) {
    exit;
}

class Get_Media
{
    public function handle(array $args): array
    {
        $media_id = (int) ($args['media_id'] ?? 0);
        $post     = $media_id ? get_post($media_id) : null;
        if (! $post) {
            throw new \InvalidArgumentException('Media not found');
        }
        if ('attachment' !== $post->post_type) {
            throw new \InvalidArgumentException('That ID is not a media attachment.');
        }

        $meta = wp_get_attachment_metadata($media_id);
        $meta = is_array($meta) ? $meta : [];

        $sizes = [];
        if (! empty($meta['sizes']) && is_array($meta['sizes'])) {
            foreach (array_keys($meta['sizes']) as $size) {
                $src = wp_get_attachment_image_src($media_id, $size);
                if (is_array($src)) {
                    $sizes[ $size ] = ['url' => (string) $src[0], 'width' => (int) $src[1], 'height' => (int) $src[2]];
                }
            }
        }

        return [
            'media_id'    => $media_id,
            'title'       => (string) $post->post_title,
            'slug'        => (string) $post->post_name,
            'url'         => (string) wp_get_attachment_url($media_id),
            'mime_type'   => (string) $post->post_mime_type,
            'alt'         => (string) get_post_meta($media_id, '_wp_attachment_image_alt', true),
            'caption'     => (string) $post->post_excerpt,
            'description' => (string) $post->post_content,
            'date'        => (string) $post->post_date,
            'post_parent' => (int) $post->post_parent,
            'width'       => isset($meta['width']) ? (int) $meta['width'] : 0,
            'height'      => isset($meta['height']) ? (int) $meta['height'] : 0,
            'sizes'       => $sizes,
            // Registered sizes not generated yet (e.g. added by a new theme);
            // resize-media without "sizes" fills them.
            'missing_sizes' => Image_Subsizes::missing($media_id),
            'metadata'    => $meta,
        ];
    }
}
