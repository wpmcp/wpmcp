<?php

namespace WPMCP\Tools\Media;

use WPMCP\Safety\{File_Backup, Safe_Mutation};

if (! defined('ABSPATH')) {
    exit;
}

/**
 * resize-media (issue #64): regenerate the specified REGISTERED image sizes
 * for an attachment from its original file and report the resulting files.
 * Routed through Safe_Mutation with the attachment as snapshot target
 * (its _wp_attachment_metadata rides in the post-meta capture) plus a
 * physical-file backup, so the operation is restorable like delete-media.
 */
class Resize_Media
{
    public function handle(array $args): array
    {
        $media_id = (int) ($args['media_id'] ?? 0);
        $post     = $media_id ? get_post($media_id) : null;
        if (! $post || 'attachment' !== $post->post_type) {
            throw new \InvalidArgumentException('Media not found');
        }
        if (! wp_attachment_is_image($media_id)) {
            throw new \InvalidArgumentException('resize-media only works on image attachments.');
        }

        $sizes = array_values(array_filter(array_map('strval', (array) ($args['sizes'] ?? []))));
        if (empty($sizes)) {
            throw new \InvalidArgumentException('Pass at least one registered size name in "sizes".');
        }

        $registered = wp_get_registered_image_subsizes();
        foreach ($sizes as $size) {
            if (! isset($registered[ $size ])) {
                throw new \InvalidArgumentException(sprintf(
                    '"%s" is not a registered image size. Registered sizes: %s.',
                    esc_html($size),
                    esc_html(implode(', ', array_keys($registered)))
                ));
            }
        }

        $file = (string) get_attached_file($media_id);
        if ('' === $file || ! is_file($file)) {
            throw new \RuntimeException('The attachment\'s original file is missing on disk.');
        }

        if (! function_exists('image_make_intermediate_size')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        // Back up the current physical files BEFORE regeneration overwrites
        // any of them, mirroring Delete_Media's pre-mutation backup.
        $operation_id = wp_generate_uuid4();
        $manifest     = File_Backup::backup($operation_id, File_Backup::collect_attachment_files($media_id));

        $out = Safe_Mutation::run(
            [
                'object_type'         => 'post',
                'object_id'           => $media_id,
                'session_id'          => (string) ($args['session_id'] ?? 'default'),
                'tool_name'           => 'resize-media',
                'args'                => $args,
                'operation_id'        => $operation_id,
                'extra_snapshot_data' => $manifest ? ['files' => ['operation_id' => $operation_id, 'manifest' => $manifest]] : [],
            ],
            function () use ($media_id, $file, $sizes, $registered): array {
                $meta = wp_get_attachment_metadata($media_id);
                $meta = is_array($meta) ? $meta : [];

                $base_url = dirname((string) wp_get_attachment_url($media_id));
                $result   = [];
                foreach ($sizes as $size) {
                    $spec    = $registered[ $size ];
                    $resized = image_make_intermediate_size($file, (int) $spec['width'], (int) $spec['height'], (bool) $spec['crop']);
                    if (! is_array($resized)) {
                        throw new \RuntimeException(sprintf(
                            'Size "%s" could not be generated (the original may be smaller than the requested dimensions).',
                            esc_html($size)
                        ));
                    }
                    $meta['sizes'][ $size ] = $resized;
                    $result[ $size ]        = [
                        'file'   => (string) $resized['file'],
                        'width'  => (int) $resized['width'],
                        'height' => (int) $resized['height'],
                        'url'    => $base_url . '/' . $resized['file'],
                    ];
                }

                wp_update_attachment_metadata($media_id, $meta);
                return $result;
            }
        );

        return [
            'operation_id' => $out['operation_id'],
            'media_id'     => $media_id,
            'sizes'        => $out['result'],
        ];
    }
}
