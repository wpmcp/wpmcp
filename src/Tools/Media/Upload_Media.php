<?php

namespace WPMCP\Tools\Media;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * upload-media: add a file to the Media Library from one of three sources
 * (exactly one per call):
 *  - data: base64 bytes the client sends, for small files the agent holds;
 *  - url (issue #471): any allowed upload type fetched from a public https
 *    URL through Remote_Image_Guard's SSRF layers (shape, public host only,
 *    no redirects, declared and actual size capped at max_bytes());
 *  - grant (issue #471): a single-use, short-lived upload URL bound to the
 *    calling user (Upload_Grant), so large files never pass through the
 *    model. The bytes arrive at a REST route and come back through store().
 *
 * Every source ends in store(), so all of them get the same checks:
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

    /**
     * Where uploaded and downloaded bytes wait before they are verified.
     * Filterable so tests can prove nothing is left behind on a refusal.
     */
    public static function temp_dir(): string
    {
        return trailingslashit((string) apply_filters('wpmcp_upload_media_temp_dir', get_temp_dir()));
    }

    public function handle(array $args): array
    {
        $sources = array_keys(array_filter([
            'data'  => '' !== trim((string) ($args['data'] ?? '')),
            'url'   => '' !== trim((string) ($args['url'] ?? '')),
            'grant' => ! empty($args['grant']),
        ]));
        if (1 !== count($sources)) {
            throw new \InvalidArgumentException('Pass exactly one of "data" (base64), "url" or "grant": true.');
        }

        if (['url'] === $sources) {
            return $this->from_url(trim((string) $args['url']), $args);
        }

        $filename = $this->filename((string) ($args['filename'] ?? ''), (string) ($args['mime_type'] ?? ''));

        if (['grant'] === $sources) {
            return Upload_Grant::issue($filename, $args);
        }

        $bytes = $this->decode((string) ($args['data'] ?? ''));
        $tmp   = $this->temp_file('wpmcp-upload-media');
        if (false === file_put_contents($tmp, $bytes)) {
            wp_delete_file($tmp);
            throw new \RuntimeException('The upload could not be written to a temporary file.');
        }
        unset($bytes);

        return $this->store($tmp, $filename, $args, array_diff_key($args, ['data' => true]));
    }

    /**
     * Verify a file already on this server and add it to the Media Library:
     * size cap, byte sniffing and blocked types, then one 'media_import'
     * snapshot. The temp file is moved into uploads on success and deleted
     * on any failure, so callers never clean it up. $filename must already
     * have passed filename().
     *
     * @param array<string, mixed> $args   title, alt, caption, post_id, session_id.
     * @param array<string, mixed> $record what the history row stores as the call's arguments.
     * @return array{operation_id: string, media_id: int, url: string, file: string, mime_type: string}
     */
    public function store(string $tmp, string $filename, array $args, array $record): array
    {
        if (! function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        try {
            clearstatcache(true, $tmp);
            $size = is_file($tmp) ? (int) filesize($tmp) : 0;
            $max  = self::max_bytes();
            if ($size < 1) {
                throw new \InvalidArgumentException('The file is empty.');
            }
            if ($size > $max) {
                throw new \InvalidArgumentException(sprintf('The file is %d bytes, above the %d byte upload limit.', (int) $size, (int) $max));
            }
            // On multisite, core folds the remaining quota into max_bytes()
            // already; this names the reason when the space is all gone.
            if (is_multisite() && function_exists('upload_is_user_over_quota') && upload_is_user_over_quota(false)) {
                throw new \InvalidArgumentException('This site has used all of its upload space.');
            }

            [$filename] = $this->verify($tmp, $filename);

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
                // media_handle_sideload() inserts through wp_insert_attachment(), which unslashes.
                wp_slash($post_data)
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
            update_post_meta($media_id, '_wp_attachment_image_alt', wp_slash(sanitize_text_field((string) $args['alt'])));
        }

        $operation_id = Media_Import_Snapshot::record(
            'upload-media',
            $media_id,
            $record,
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

    /**
     * Import from a public https URL. Every check that needs no request runs
     * first: URL shape, public host, and the name's blocked extensions.
     */
    private function from_url(string $url, array $args): array
    {
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        Remote_Image_Guard::validate_url($url, [$host]);
        Remote_Image_Guard::assert_public_host($host);

        $raw = trim((string) ($args['filename'] ?? ''));
        if ('' === $raw) {
            // Decoded but NOT sanitized yet: sanitize_file_name() would turn
            // "shell.php.pdf" into "shell.php_.pdf" and hide the segment.
            $raw = rawurldecode(basename((string) wp_parse_url($url, PHP_URL_PATH)));
        }
        $filename = $this->filename($raw, (string) ($args['mime_type'] ?? ''), true);

        $tmp = Remote_Image_Guard::download($url, self::max_bytes(), self::temp_dir());

        return $this->store($tmp, $filename, $args, $args);
    }

    private function temp_file(string $prefix): string
    {
        if (! function_exists('wp_tempnam')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        return wp_tempnam($prefix, self::temp_dir());
    }

    /**
     * Reduce a client-supplied name to a safe basename with an allowed-looking
     * extension. Public so Upload_Grant re-checks names the same way.
     */
    public function filename(string $raw, string $mime_hint = '', bool $from_url = false): string
    {
        $raw = trim(str_replace('\\', '/', $raw));
        $raw = basename($raw);
        if ('' === $raw) {
            throw new \InvalidArgumentException($from_url ? 'The url has no file name; pass a "filename" with an extension.' : 'A "filename" is required.');
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
            $hint = sanitize_mime_type($mime_hint);
            $ext  = '' !== $hint ? (string) wp_get_default_extension_for_mime_type($hint) : '';
            if ('' === $ext) {
                throw new \InvalidArgumentException($from_url
                    ? 'The url names no file extension; pass a "filename" with one.'
                    : 'The "filename" needs an extension (or pass a "mime_type" hint to derive one).');
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

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- strict-mode decode of the file bytes the client uploads; the result is written to disk as data and type-checked, never executed.
        $bytes = base64_decode($data, true);
        if (false === $bytes || '' === $bytes) {
            throw new \InvalidArgumentException('The "data" payload is not valid base64.');
        }
        if (strlen($bytes) > $max) {
            throw new \InvalidArgumentException(sprintf('The file is %d bytes, above the %d byte upload limit.', (int) strlen($bytes), (int) $max));
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
            $info = wp_getimagesize($tmp);
            if (is_array($info) && (int) $info[0] * (int) $info[1] > self::MAX_PIXELS) {
                throw new \InvalidArgumentException('The image dimensions exceed the 50 megapixel limit.');
            }
        }

        return [$filename, $type];
    }
}
