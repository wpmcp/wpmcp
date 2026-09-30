<?php

namespace WPMCP\Tools\Media;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

class Update_Media
{
    /**
     * Attachments are posts of type 'attachment', so Safe_Mutation's generic
     * 'post' object_type snapshots and restores them the same way it does
     * regular posts: full row + meta (including _wp_attachment_image_alt and
     * _wp_attachment_metadata) + terms.
     */
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

        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $media_id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'update-media',
                'args'        => $args,
            ],
            function () use ($media_id, $args): array {
                $updated = [];
                $postarr = ['ID' => $media_id];

                if (array_key_exists('title', $args)) {
                    $postarr['post_title'] = sanitize_text_field((string) $args['title']);
                    $updated[]             = 'title';
                }
                if (array_key_exists('caption', $args)) {
                    $postarr['post_excerpt'] = sanitize_text_field((string) $args['caption']);
                    $updated[]               = 'caption';
                }
                if (array_key_exists('description', $args)) {
                    $postarr['post_content'] = (string) $args['description'];
                    $updated[]               = 'description';
                }
                if (count($postarr) > 1) {
                    wp_update_post(wp_slash($postarr));
                }
                if (array_key_exists('alt', $args)) {
                    update_post_meta($media_id, '_wp_attachment_image_alt', wp_slash(sanitize_text_field((string) $args['alt'])));
                    $updated[] = 'alt';
                }

                return $updated;
            }
        );

        return ['operation_id' => $out['operation_id'], 'media_id' => $media_id, 'updated' => $out['result']];
    }
}
