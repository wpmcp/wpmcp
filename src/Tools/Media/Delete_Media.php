<?php

namespace WPMCP\Tools\Media;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

class Delete_Media
{
    /**
     * Destructive and disabled by default: sites must opt in with
     * add_filter('wpmcp_enable_delete_media', '__return_true') before this
     * tool will run at all, in addition to the caller passing confirm:true.
     */
    public static function is_enabled(): bool
    {
        return (bool) apply_filters('wpmcp_enable_delete_media', false);
    }

    public function handle(array $args): array
    {
        if (! self::is_enabled()) {
            throw new \RuntimeException('The delete-media tool is disabled. Enable it with the wpmcp_enable_delete_media filter.');
        }

        $media_id = (int) ($args['media_id'] ?? 0);
        $post     = $media_id ? get_post($media_id) : null;
        if (! $post) {
            throw new \InvalidArgumentException('Media not found');
        }
        if ('attachment' !== $post->post_type) {
            throw new \InvalidArgumentException('That ID is not a media attachment.');
        }
        if (true !== ($args['confirm'] ?? null)) {
            throw new \InvalidArgumentException('Deleting media is permanent. Pass confirm:true to proceed.');
        }

        $force = ! empty($args['force']);

        // WordPress only trashes attachments (reversible via its own trash,
        // like Delete_Post's default path) when MEDIA_TRASH is defined
        // truthy AND force wasn't requested. Most sites never define that
        // constant, so without it this "default" delete is a real permanent
        // delete, not a reversible trash. Snapshot it via Safe_Mutation
        // whenever it isn't genuinely covered by WordPress's own trash;
        // only skip the snapshot when native trash already makes it
        // reversible for free.
        $covered_by_native_trash = ! $force && defined('MEDIA_TRASH') && MEDIA_TRASH;

        if ($covered_by_native_trash) {
            wp_delete_attachment($media_id, false);
            return ['media_id' => $media_id, 'deleted' => 'trashed', 'files_recoverable' => true];
        }

        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $media_id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'delete-media',
                'args'        => $args,
            ],
            function () use ($media_id, $force) {
                wp_delete_attachment($media_id, $force);
                return true;
            }
        );

        // This path is irreversible for the physical file: Safe_Mutation's
        // rollback restores the DB record (post, meta, terms) but cannot
        // restore bytes already unlinked from disk. See issue #24.
        return [
            'operation_id'      => $out['operation_id'],
            'media_id'          => $media_id,
            'deleted'           => 'deleted',
            'files_recoverable' => false,
            'warning'           => 'Rollback restores the media record but not the physical file(s); the file is permanently deleted (see issue #24).',
        ];
    }
}
