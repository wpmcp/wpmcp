<?php

namespace WPMCP\Tools\Media;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * upload-media: add a file to the Media Library from base64 bytes the client
 * sends, for agents that hold the file itself rather than a URL to it.
 *
 * Nothing the client says about the file is trusted:
 *  - the payload must be strict base64 (an optional data: URI prefix is
 *    accepted and discarded) and fit under max_bytes(), checked on the
 *    encoded length BEFORE decoding so an oversized payload is never
 *    materialised twice in memory;
 *  - the filename is reduced to a sanitized basename, and any executable or
 *    active-content extension anywhere in it is refused outright (SVG has its
 *    own sanitizing tool, upload-svg);
 *  - the type is sniffed from the bytes by wp_check_filetype_and_ext()
 *    against the current user's allowed upload types. The mime_type argument
 *    is only a hint used to supply a missing extension, and the result of
 *    that hint is verified like any other name;
 *  - anything claiming to be an image must decode as one.
 *
 * The created attachment is recorded as a 'media_import' snapshot, so rolling
 * the operation back deletes the attachment and its files again.
 */
class Upload_Media
{
    /** Extensions that can execute or carry active content in a browser or on the server. */
    private const BLOCKED_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phps', 'phar',
        'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'exe', 'com', 'bat', 'cmd', 'msi', 'dll', 'so',
        'js', 'mjs', 'jsp', 'asp', 'aspx', 'htm', 'html', 'xhtml', 'shtml', 'svg', 'svgz',
        'xml', 'htaccess', 'swf',
    ];

    /** Sniffed types that are refused even when a site filter allows them. */
    private const BLOCKED_TYPES = [
        'text/html', 'application/xhtml+xml', 'image/svg+xml', 'application/javascript',
        'text/javascript', 'application/x-php', 'text/x-php', 'application/x-httpd-php',
        'application/x-msdownload', 'application/x-sh', 'application/x-shockwave-flash',
    ];

    /** Decompression-bomb guard, matching Remote_Image_Guard. */
    private const MAX_PIXELS = 50_000_000;

    /** Byte cap: the site's own upload limit by default, filterable. */
    public static function max_bytes(): int
    {
        return max(1, (int) apply_filters('wpmcp_upload_media_max_bytes', (int) wp_max_upload_size()));
    }

    public function handle(array $args): array
    {
        $filename = $this->filename($args);
        $bytes    = $this->decode((string) ($args['data'] ?? ''));

        if (! function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $tmp = wp_tempnam('wpmcp-upload-media');
        try {
            if (false === file_put_contents($tmp, $bytes)) {
                throw new \RuntimeException('The upload could not be written to a temporary file.');
            }
            unset($bytes);

            [$filename, $mime] = $this->verify($tmp, $filename);

            $post_data = [];
            $title     = trim((string) ($args['title'] ?? ''));
            if ('' !== $title) {
                $post_data['post_title'] = sanitize_text_field($title);
            }
            if (array_key_exists('caption', $args)) {
                $post_data['post_excerpt'] = sanitize_text_field((string) $args['caption']);
            }

            $media_id = media_handle_sideload(
                ['name' => $filename, 'tmp_name' => $tmp],
                (int) ($args['post_id'] ?? 0),
                null,
                $post_data
            );
            if (is_wp_error($media_id)) {
                throw new \RuntimeException('The file could not be added to the Media Library: ' . esc_html($media_id->get_error_message()));
            }
            $media_id = (int) $media_id;
        } finally {
            if (is_file($tmp)) {
                wp_delete_file($tmp);
            }
        }

        if (array_key_exists('alt', $args)) {
            update_post_meta($media_id, '_wp_attachment_image_alt', sanitize_text_field((string) $args['alt']));
        }

        $operation_id = Media_Import_Snapshot::record(
            'upload-media',
            $media_id,
            array_diff_key($args, ['data' => true]),
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

    private function filename(array $args): string
    {
        $raw = trim(str_replace('\\', '/', (string) ($args['filename'] ?? '')));
        $raw = basename($raw);
        if ('' === $raw) {
            throw new \InvalidArgumentException('A "filename" is required.');
        }

        // Check every dot segment, so "shell.php.jpg" is refused rather than
        // relying on the server never executing a middle extension.
        $segments = array_slice(explode('.', strtolower($raw)), 1);
        foreach ($segments as $segment) {
            if (in_array(trim($segment), self::BLOCKED_EXTENSIONS, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Files with a .%s extension cannot be uploaded%s.',
                    esc_html(trim($segment)),
                    in_array(trim($segment), ['svg', 'svgz'], true) ? ' here; use upload-svg, which sanitizes SVG markup' : ''
                ));
            }
        }

        $name = sanitize_file_name($raw);
        if ('' === $name || str_starts_with($name, '.')) {
            $name = 'upload';
        }

        if ('' === (string) pathinfo($name, PATHINFO_EXTENSION)) {
            $hint = sanitize_mime_type((string) ($args['mime_type'] ?? ''));
            $ext  = '' !== $hint ? (string) wp_get_default_extension_for_mime_type($hint) : '';
            if ('' === $ext) {
                throw new \InvalidArgumentException('The "filename" needs an extension (or pass a "mime_type" hint to derive one).');
            }
            $name .= '.' . $ext;
        }

        if (strlen($name) > 120) {
            $ext  = (string) pathinfo($name, PATHINFO_EXTENSION);
            $name = substr((string) pathinfo($name, PATHINFO_FILENAME), 0, 100) . '.' . $ext;
        }

        return $name;
    }

    private function decode(string $data): string
    {
        $data = (string) preg_replace('/^data:[^,]*;base64,/i', '', trim($data));
        $data = (string) preg_replace('/\s+/', '', $data);
        if ('' === $data) {
            throw new \InvalidArgumentException('A non-empty base64 "data" payload is required.');
        }

        $max = self::max_bytes();
        // Decoded size is at most 3/4 of the encoded length.
        $estimate = intdiv(strlen($data) * 3, 4) - substr_count(substr($data, -2), '=');
        if ($estimate > $max) {
            throw new \InvalidArgumentException(sprintf('The file is about %d bytes, above the %d byte upload limit.', (int) $estimate, (int) $max));
        }

        $bytes = base64_decode($data, true);
        if (false === $bytes || '' === $bytes) {
            throw new \InvalidArgumentException('The "data" payload is not valid base64.');
        }
        if (strlen($bytes) > $max) {
            throw new \InvalidArgumentException(sprintf('The file is %d bytes, above the %d byte upload limit.', strlen($bytes), (int) $max));
        }

        return $bytes;
    }

    /**
     * Sniff the type from the bytes and return [filename, mime].
     *
     * @return array{0: string, 1: string}
     */
    private function verify(string $tmp, string $filename): array
    {
        $check = wp_check_filetype_and_ext($tmp, $filename);
        $type  = (string) ($check['type'] ?? '');
        $ext   = (string) ($check['ext'] ?? '');
        if ('' === $type || '' === $ext) {
            throw new \InvalidArgumentException('The file content does not match an allowed upload type for its name.');
        }
        if (! empty($check['proper_filename'])) {
            $filename = (string) $check['proper_filename'];
        }

        $blocked = in_array(strtolower($type), self::BLOCKED_TYPES, true)
            || in_array(strtolower($ext), self::BLOCKED_EXTENSIONS, true);
        if ($blocked) {
            throw new \InvalidArgumentException('That file type cannot be uploaded.');
        }

        if (str_starts_with($type, 'image/')) {
            if (! wp_get_image_mime($tmp)) {
                throw new \InvalidArgumentException('The file is named as an image but its bytes are not a valid image.');
            }
            $info = @getimagesize($tmp); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
            if (is_array($info) && (int) $info[0] * (int) $info[1] > self::MAX_PIXELS) {
                throw new \InvalidArgumentException('The image dimensions exceed the 50 megapixel limit.');
            }
        }

        return [$filename, $type];
    }
}
