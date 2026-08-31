<?php

namespace WPMCP\Tools\Media;

use WPMCP\Safety\Snapshot_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * History row for a tool that CREATED a new attachment (import-stock-image,
 * upload-svg). Mirrors Build_Page's 'page_build' pattern: there is no prior
 * state to capture, so the snapshot records WHAT WAS CREATED and
 * Rollback_Service::apply_media_import_snapshot() undoes it by force-deleting
 * the attachment (and, via wp_delete_attachment, its files on disk).
 * post_date_gmt is captured for the same identity check every other restore
 * path uses: rollback must never delete a different post that has since
 * reclaimed the id.
 */
class Media_Import_Snapshot
{
    public static function record(string $tool_name, int $media_id, array $args, string $session_id): string
    {
        $operation_id = wp_generate_uuid4();
        $post         = get_post($media_id);

        Snapshot_Store::save(
            $operation_id,
            $session_id,
            [
                'object_type' => 'media_import',
                'object_id'   => $media_id,
                'data'        => [
                    'media_id'      => $media_id,
                    'post_date_gmt' => $post ? $post->post_date_gmt : null,
                ],
            ],
            $tool_name,
            hash('sha256', (string) wp_json_encode($args))
        );
        Snapshot_Store::prune();

        return $operation_id;
    }
}
