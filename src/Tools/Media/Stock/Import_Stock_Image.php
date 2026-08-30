<?php

namespace WPMCP\Tools\Media\Stock;

use WPMCP\Tools\Media\Media_Import_Snapshot;
use WPMCP\Tools\Media\Remote_Image_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * import-stock-image (issue #64): sideload one stock search result into the
 * Media Library. Every remote byte passes Remote_Image_Guard (https-only,
 * pre-request host allowlist, no redirects, declared+actual size caps, real
 * image-content verification, sanitized filenames). Attribution and license
 * metadata from the search result are persisted on the attachment, and the
 * creation is recorded as a 'media_import' snapshot: rolling the operation
 * back deletes the imported attachment and its files again.
 */
class Import_Stock_Image
{
    public const META_KEYS = [
        'provider'    => '_wpmcp_stock_provider',
        'attribution' => '_wpmcp_stock_attribution',
        'license'     => '_wpmcp_stock_license',
        'license_url' => '_wpmcp_stock_license_url',
        'source_url'  => '_wpmcp_stock_source_url',
    ];

    public function handle(array $args): array
    {
        $url = trim((string) ($args['image_url'] ?? ''));
        if ('' === $url) {
            throw new \InvalidArgumentException('An "image_url" (from a search-stock-images result) is required.');
        }

        // Layer 1: shape + allowlist, BEFORE any request leaves the site.
        Remote_Image_Guard::validate_url($url);

        // Layers 2-3: guarded transport (no redirects, size caps).
        $tmp = Remote_Image_Guard::download($url);

        try {
            // Layers 4-5: the bytes must BE an allowed image; name sanitized.
            $filename = Remote_Image_Guard::safe_filename($url, 'stock-image');
            $filename = Remote_Image_Guard::assert_image($tmp, $filename);

            if (! function_exists('media_handle_sideload')) {
                require_once ABSPATH . 'wp-admin/includes/media.php';
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';
            }

            $post_id  = (int) ($args['post_id'] ?? 0);
            $media_id = media_handle_sideload(['name' => $filename, 'tmp_name' => $tmp], $post_id);
            if (is_wp_error($media_id)) {
                throw new \RuntimeException(esc_html('The image could not be added to the Media Library: ' . $media_id->get_error_message()));
            }
            $media_id = (int) $media_id;
        } catch (\Throwable $e) {
            if (is_file($tmp)) {
                wp_delete_file($tmp);
            }
            throw $e;
        }

        if (! empty($args['title'])) {
            wp_update_post(['ID' => $media_id, 'post_title' => sanitize_text_field((string) $args['title'])]);
        }
        if (! empty($args['alt'])) {
            update_post_meta($media_id, '_wp_attachment_image_alt', sanitize_text_field((string) $args['alt']));
        }

        // Persist provider attribution/license where the source supplied it.
        foreach (self::META_KEYS as $arg => $meta_key) {
            $value = trim((string) ($args[ $arg ] ?? ''));
            if ('' === $value) {
                continue;
            }
            if (in_array($arg, ['license_url', 'source_url'], true)) {
                $value = esc_url_raw($value);
            } elseif ('provider' === $arg) {
                $value = sanitize_key($value);
            } else {
                $value = sanitize_text_field($value);
            }
            update_post_meta($media_id, $meta_key, $value);
        }

        $operation_id = Media_Import_Snapshot::record(
            'import-stock-image',
            $media_id,
            $args,
            (string) ($args['session_id'] ?? 'default')
        );

        return [
            'operation_id' => $operation_id,
            'media_id'     => $media_id,
            'url'          => (string) wp_get_attachment_url($media_id),
            'file'         => basename((string) get_attached_file($media_id)),
            'mime_type'    => (string) get_post_mime_type($media_id),
        ];
    }
}
