<?php

namespace WPMCP\Tools\Media;

if (! defined('ABSPATH')) {
    exit;
}

class Sideload_Image
{
    /**
     * Pure creation, exempt from Safe_Mutation: sideloading downloads a new
     * file and inserts a brand new attachment. It never modifies or removes
     * any existing object, so there is nothing to snapshot or roll back.
     * update-media and delete-media DO mutate/remove existing attachments
     * and are safe-wrapped.
     */
    public function handle(array $args): array
    {
        $url = trim((string) ($args['url'] ?? ''));
        if ('' === $url) {
            throw new \InvalidArgumentException('A "url" is required.');
        }

        if (! function_exists('media_sideload_image')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $post_id     = (int) ($args['post_id'] ?? 0);
        $description = isset($args['description']) ? (string) $args['description'] : null;

        $media_id = media_sideload_image($url, $post_id, $description, 'id');
        if (is_wp_error($media_id)) {
            throw new \InvalidArgumentException($media_id->get_error_message());
        }
        $media_id = (int) $media_id;

        if (array_key_exists('alt', $args)) {
            update_post_meta($media_id, '_wp_attachment_image_alt', sanitize_text_field((string) $args['alt']));
        }

        return [
            'media_id' => $media_id,
            'url'      => (string) wp_get_attachment_url($media_id),
        ];
    }
}
