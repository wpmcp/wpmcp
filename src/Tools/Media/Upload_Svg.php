<?php

namespace WPMCP\Tools\Media;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * upload-svg (issue #64): add an SVG to the Media Library from raw markup or
 * from an allowlisted URL, behind the bundled fail-closed Svg_Sanitizer.
 * What lands on disk is ALWAYS the sanitizer's output, never the raw input.
 * The created attachment is recorded as a 'media_import' snapshot, so
 * rolling the operation back deletes it (and its file) again.
 */
class Upload_Svg
{
    private const MAX_SVG_BYTES = 2 * 1024 * 1024;

    public function handle(array $args): array
    {
        $markup = (string) ($args['markup'] ?? '');
        $url    = trim((string) ($args['url'] ?? ''));

        if ('' === trim($markup) && '' === $url) {
            throw new \InvalidArgumentException('Provide either raw "markup" or an allowlisted "url" for the SVG.');
        }

        $filename = 'image.svg';
        if ('' === trim($markup)) {
            Remote_Image_Guard::validate_url($url);
            $tmp = Remote_Image_Guard::download($url);
            try {
                if ((int) filesize($tmp) > self::MAX_SVG_BYTES) {
                    throw new \RuntimeException('The fetched SVG exceeds the 2 MB SVG size limit.');
                }
                $markup = (string) file_get_contents($tmp);
            } finally {
                wp_delete_file($tmp);
            }
            $filename = Remote_Image_Guard::safe_filename($url, 'image.svg');
        }

        // Fail closed: throws on anything with script/external-fetch ability.
        $sanitized = Svg_Sanitizer::sanitize($markup);

        $title = trim((string) ($args['title'] ?? ''));
        if ('' !== $title) {
            $filename = sanitize_file_name($title);
        }
        if (! str_ends_with(strtolower($filename), '.svg')) {
            $filename = (string) (pathinfo($filename, PATHINFO_FILENAME) ?: 'image') . '.svg';
        }

        // WordPress refuses SVG uploads by default (they are active content).
        // That refusal is exactly why this tool exists: the sanitizer has
        // already run and only ITS output is written, so svg is permitted for
        // this one controlled write and immediately revoked again.
        $allow_svg = static function (array $mimes): array {
            $mimes['svg'] = 'image/svg+xml';
            return $mimes;
        };
        add_filter('upload_mimes', $allow_svg);
        try {
            $upload = wp_upload_bits($filename, null, $sanitized);
        } finally {
            remove_filter('upload_mimes', $allow_svg);
        }
        if (! empty($upload['error'])) {
            throw new \RuntimeException('The SVG could not be written to the uploads directory: ' . esc_html((string) $upload['error']));
        }

        $post_id  = (int) ($args['post_id'] ?? 0);
        $media_id = wp_insert_attachment(
            [
                'post_mime_type' => 'image/svg+xml',
                'post_title'     => '' !== $title ? $title : (string) pathinfo($filename, PATHINFO_FILENAME),
                'post_status'    => 'inherit',
            ],
            $upload['file'],
            $post_id,
            true
        );
        if (is_wp_error($media_id)) {
            wp_delete_file($upload['file']);
            throw new \RuntimeException('The SVG attachment could not be created: ' . esc_html($media_id->get_error_message()));
        }
        $media_id = (int) $media_id;

        if (! empty($args['alt'])) {
            update_post_meta($media_id, '_wp_attachment_image_alt', sanitize_text_field((string) $args['alt']));
        }

        $operation_id = Media_Import_Snapshot::record(
            'upload-svg',
            $media_id,
            $args,
            (string) ($args['session_id'] ?? 'default')
        );

        return [
            'operation_id' => $operation_id,
            'media_id'     => $media_id,
            'url'          => (string) wp_get_attachment_url($media_id),
        ];
    }
}
