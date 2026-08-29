<?php

namespace WPMCP\Tools\Media;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * SSRF/abuse guard for every remote media fetch introduced by issue #64
 * (stock-image import, SVG-from-URL). Defense in depth, each layer
 * independent of the others:
 *
 *  1. URL shape: https only, no credentials, no custom port, and the host
 *     must match a static allowlist of known stock/CDN hosts (filterable via
 *     'wpmcp_remote_media_allowed_hosts') — checked BEFORE any request, with
 *     label-boundary suffix matching so "images.pexels.com.evil.example"
 *     never matches "images.pexels.com".
 *  2. Transport: wp_safe_remote_get (WordPress's own unsafe-URL rejection on
 *     top of ours) with redirection disabled — any 3xx is a hard failure, so
 *     an allowlisted host can never bounce the fetch to an internal address
 *     (SSRF via redirect chain).
 *  3. Size: the declared Content-Length is checked against the cap before
 *     accepting the body, and the actual bytes on disk are re-checked after
 *     the download in case the header lied. Cap filterable via
 *     'wpmcp_remote_media_max_bytes' (default 15 MB).
 *  4. Content: callers importing raster images run assert_image(), which
 *     requires the actual bytes to parse as a real image of an allowed type
 *     (getimagesize + wp_check_filetype_and_ext) — a polyglot file served
 *     with an image name/mime is rejected on its bytes, not its label.
 *  5. Filenames: derived from the URL path only, url-decoded, run through
 *     sanitize_file_name(), query strings discarded, length-capped.
 */
class Remote_Image_Guard
{
    public const DEFAULT_ALLOWED_HOSTS = [
        'images.pexels.com',
        'images.unsplash.com',
        'plus.unsplash.com',
        'upload.wikimedia.org',
        'staticflickr.com',
    ];

    public const DEFAULT_MAX_BYTES = 15 * 1024 * 1024;

    private const ALLOWED_IMAGE_TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP];

    /** @throws \InvalidArgumentException when the URL fails any pre-request check. */
    public static function validate_url(string $url): void
    {
        $parts = wp_parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            throw new \InvalidArgumentException('The remote URL could not be parsed.');
        }
        if ('https' !== strtolower((string) ($parts['scheme'] ?? ''))) {
            throw new \InvalidArgumentException('Only https:// remote media URLs are allowed.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('Remote media URLs must not embed credentials.');
        }
        if (isset($parts['port']) && 443 !== (int) $parts['port']) {
            throw new \InvalidArgumentException('Remote media URLs must use the default https port.');
        }

        $host = strtolower((string) $parts['host']);
        foreach (self::allowed_hosts() as $allowed) {
            $allowed = strtolower(trim((string) $allowed));
            if ('' === $allowed) {
                continue;
            }
            // Exact match, or a subdomain match on a label boundary. A plain
            // suffix check would let "pexels.com.evil.example" through.
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return;
            }
        }

        throw new \InvalidArgumentException(sprintf(
            'Host "%s" is not on the allowed remote media host list. Extend it with the wpmcp_remote_media_allowed_hosts filter if this source is trusted.',
            esc_html($host)
        ));
    }

    /** @return string[] */
    public static function allowed_hosts(): array
    {
        return (array) apply_filters('wpmcp_remote_media_allowed_hosts', self::DEFAULT_ALLOWED_HOSTS);
    }

    public static function max_bytes(): int
    {
        return max(1, (int) apply_filters('wpmcp_remote_media_max_bytes', self::DEFAULT_MAX_BYTES));
    }

    /**
     * Download an allowlist-validated URL to a temp file with redirects
     * disabled and both declared and actual size enforced. Returns the temp
     * file path; the caller owns (and must clean up) the file.
     *
     * @throws \RuntimeException on any transport, redirect, or size failure.
     */
    public static function download(string $url): string
    {
        if (! function_exists('wp_tempnam')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $max = self::max_bytes();
        $tmp = wp_tempnam('wpmcp-remote-media');

        $response = wp_safe_remote_get($url, [
            'timeout'             => 30,
            'redirection'         => 0,
            'stream'              => true,
            'filename'            => $tmp,
            'limit_response_size' => $max + 1,
        ]);

        try {
            if (is_wp_error($response)) {
                throw new \RuntimeException('The remote media download failed: ' . $response->get_error_message());
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code >= 300 && $code < 400) {
                throw new \RuntimeException('The remote media server answered with a redirect; redirects are never followed for media downloads.');
            }
            if (200 !== $code) {
                throw new \RuntimeException(sprintf('The remote media server answered HTTP %d.', $code));
            }

            $declared = wp_remote_retrieve_header($response, 'content-length');
            if ('' !== (string) $declared && (int) $declared > $max) {
                throw new \RuntimeException(sprintf('The remote file declares %d bytes, above the %d byte limit.', (int) $declared, $max));
            }

            clearstatcache(true, $tmp);
            $actual = is_file($tmp) ? (int) filesize($tmp) : 0;
            if ($actual > $max) {
                throw new \RuntimeException(sprintf('The downloaded file is %d bytes, above the %d byte limit.', $actual, $max));
            }
            if ($actual < 1) {
                throw new \RuntimeException('The remote media download produced an empty file.');
            }
        } catch (\RuntimeException $e) {
            if (is_file($tmp)) {
                wp_delete_file($tmp);
            }
            throw $e;
        }

        return $tmp;
    }

    /**
     * Sanitized filename derived from the URL path ONLY: the query string is
     * discarded entirely, percent-encoding decoded, and the result run
     * through sanitize_file_name() and length-capped.
     */
    public static function safe_filename(string $url, string $fallback = 'remote-media'): string
    {
        $path = (string) (wp_parse_url($url)['path'] ?? '');
        $name = sanitize_file_name(rawurldecode(basename($path)));
        if ('' === $name || str_starts_with($name, '.')) {
            $name = $fallback;
        }
        if (strlen($name) > 80) {
            $ext  = pathinfo($name, PATHINFO_EXTENSION);
            $name = substr(pathinfo($name, PATHINFO_FILENAME), 0, 70) . ('' !== $ext ? '.' . $ext : '');
        }
        return $name;
    }

    /**
     * Require the downloaded bytes to be a real raster image of an allowed
     * type. Returns the filename to sideload under (corrected to match the
     * detected type, so a mislabeled-but-genuine image cannot keep a lying
     * extension).
     *
     * @throws \RuntimeException when the bytes are not an acceptable image.
     */
    public static function assert_image(string $tmp_file, string $filename): string
    {
        $info = @getimagesize($tmp_file); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
        if (! is_array($info) || ! in_array((int) $info[2], self::ALLOWED_IMAGE_TYPES, true)) {
            throw new \RuntimeException('The downloaded file is not an allowed image type (jpeg, png, gif, webp).');
        }

        // Decompression-bomb guard: the byte cap alone cannot stop a small
        // file that decodes to an enormous pixel grid (WordPress fully
        // decodes it to build subsizes). 50 megapixels comfortably covers
        // real photography.
        if ((int) $info[0] * (int) $info[1] > 50_000_000) {
            throw new \RuntimeException('The image dimensions exceed the 50 megapixel limit.');
        }

        $expected_ext = str_replace('jpeg', 'jpg', (string) image_type_to_extension((int) $info[2], false));
        $current_ext  = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        $equivalent   = ['jpg' => ['jpg', 'jpeg'], 'png' => ['png'], 'gif' => ['gif'], 'webp' => ['webp']];
        if (! in_array($current_ext, $equivalent[ $expected_ext ] ?? [ $expected_ext ], true)) {
            $filename = pathinfo($filename, PATHINFO_FILENAME) . '.' . $expected_ext;
        }

        $check = wp_check_filetype_and_ext($tmp_file, $filename);
        if (empty($check['type']) || ! str_starts_with((string) $check['type'], 'image/')) {
            throw new \RuntimeException('The downloaded file failed WordPress filetype verification.');
        }
        if (! empty($check['proper_filename'])) {
            $filename = (string) $check['proper_filename'];
        }

        return $filename;
    }
}
