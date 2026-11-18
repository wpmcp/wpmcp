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
 *
 * Issue #716: without "sizes" it fills the registered sizes the attachment
 * lacks (see Image_Subsizes), and media_ids runs that over a bounded batch,
 * one snapshotted operation per attachment. Rollback also deletes the size
 * files the operation created, so an undo leaves no orphans on disk.
 */
class Resize_Media
{
    /** Attachments per media_ids call; page through list-media for more. */
    public const MAX_BATCH = 20;

    public function handle(array $args): array
    {
        if (! array_key_exists('media_ids', $args)) {
            return $this->resize_one($args);
        }
        if (isset($args['media_id'])) {
            throw new \InvalidArgumentException('Pass either media_id or media_ids, not both.');
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $args['media_ids']))));
        if (! $ids) {
            throw new \InvalidArgumentException('media_ids must list at least one attachment ID.');
        }
        if (count($ids) > self::MAX_BATCH) {
            throw new \InvalidArgumentException(sprintf(
                'media_ids takes at most %d attachments per call; split the rest into further calls.',
                (int) self::MAX_BATCH
            ));
        }

        $items       = [];
        $regenerated = 0;
        $failed      = 0;
        foreach ($ids as $id) {
            $one = $args;
            unset($one['media_ids']);
            $one['media_id'] = $id;
            try {
                $item         = $this->resize_one($one);
                $regenerated += $item['sizes'] ? 1 : 0;
            } catch (\Throwable $e) {
                $item = ['media_id' => $id, 'error' => $e->getMessage()];
                ++$failed;
            }
            $items[] = $item;
        }

        return ['items' => $items, 'regenerated' => $regenerated, 'failed' => $failed];
    }

    private function resize_one(array $args): array
    {
        $media_id = (int) ($args['media_id'] ?? 0);
        $post     = $media_id ? get_post($media_id) : null;
        if (! $post || 'attachment' !== $post->post_type) {
            throw new \InvalidArgumentException('Media not found');
        }
        if (! wp_attachment_is_image($media_id)) {
            throw new \InvalidArgumentException('resize-media only works on image attachments.');
        }

        if (! isset($args['sizes'])) {
            $sizes = Image_Subsizes::missing($media_id);
            if (! $sizes) {
                return [
                    'operation_id'      => null,
                    'media_id'          => $media_id,
                    'sizes'             => [],
                    'files_recoverable' => true,
                    'message'           => 'No registered image size is missing; nothing was changed.',
                ];
            }
        } else {
            $sizes = array_values(array_filter(array_map('strval', (array) $args['sizes'])));
        }
        if (empty($sizes)) {
            throw new \InvalidArgumentException('Pass at least one registered size name in "sizes", or omit "sizes" to fill the missing ones.');
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
        $files        = File_Backup::collect_attachment_files($media_id);
        $manifest     = File_Backup::backup($operation_id, $files);

        // A file the backup could not copy cannot be put back by rollback
        // once regeneration overwrites it. Refuse unless the caller accepts
        // that explicitly, and never report such a resize as recoverable.
        $unrecoverable = array_values(array_map('wp_basename', array_diff($files, array_keys($manifest))));
        if ($unrecoverable && true !== ($args['allow_unrecoverable_files'] ?? null)) {
            File_Backup::delete_backup_dir($operation_id);
            throw new \RuntimeException(sprintf(
                'Nothing was resized: these files could not be backed up, so rollback could not restore them: %s. Pass allow_unrecoverable_files:true to resize anyway.',
                esc_html(implode(', ', $unrecoverable))
            ));
        }

        $created = self::files_to_create($file, $sizes, $registered);

        $out = Safe_Mutation::run(
            [
                'object_type'         => 'post',
                'object_id'           => $media_id,
                'session_id'          => (string) ($args['session_id'] ?? 'default'),
                'tool_name'           => 'resize-media',
                'args'                => $args,
                'operation_id'        => $operation_id,
                'extra_snapshot_data' => $manifest ? ['files' => ['operation_id' => $operation_id, 'manifest' => $manifest, 'created' => $created]] : [],
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

        $result = [
            'operation_id'      => $out['operation_id'],
            'media_id'          => $media_id,
            'sizes'             => $out['result'],
            'files_recoverable' => ! $unrecoverable,
        ];
        if ($unrecoverable) {
            $result['unrecoverable_files'] = $unrecoverable;
            $result['warning']             = 'Rollback restores the attachment metadata, but not the files listed in unrecoverable_files.';
        }
        return $result;
    }

    /**
     * Size files this regeneration will write that do not exist yet, so a
     * rollback can delete them (File_Backup::remove_created). Predicted from
     * core's own naming ({name}-{w}x{h}.{ext}, with the dimensions
     * image_resize_dimensions() yields); an output-format mapping such as
     * JPEG to WebP adds that extension as a candidate. Files already on disk
     * are left out: they are either backed up or not this operation's.
     *
     * @param string[] $sizes
     * @return string[]
     */
    private static function files_to_create(string $file, array $sizes, array $registered): array
    {
        $dims = wp_getimagesize($file);
        if (! is_array($dims) || empty($dims[0]) || empty($dims[1])) {
            return [];
        }
        $info = pathinfo($file);
        $exts = [ (string) ($info['extension'] ?? '') ];
        $mime = (string) ($dims['mime'] ?? '');
        if ('' !== $mime) {
            $map = (array) wp_get_image_editor_output_format($file, $mime);
            if (! empty($map[ $mime ])) {
                $mapped = (string) wp_get_default_extension_for_mime_type((string) $map[ $mime ]);
                if ('' !== $mapped) {
                    $exts[] = $mapped;
                }
            }
        }

        $created = [];
        foreach ($sizes as $size) {
            $spec   = $registered[ $size ];
            $resize = image_resize_dimensions((int) $dims[0], (int) $dims[1], (int) $spec['width'], (int) $spec['height'], $spec['crop']);
            if (! is_array($resize)) {
                continue;
            }
            foreach (array_unique(array_filter($exts)) as $ext) {
                $path = $info['dirname'] . '/' . $info['filename'] . '-' . (int) $resize[4] . 'x' . (int) $resize[5] . '.' . $ext;
                if (! file_exists($path) && ! in_array($path, $created, true)) {
                    $created[] = $path;
                }
            }
        }
        return $created;
    }
}
