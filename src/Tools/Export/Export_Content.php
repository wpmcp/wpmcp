<?php

namespace WPMCP\Tools\Export;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Generate a WordPress eXtended RSS (WXR) export of site content using the
 * native WordPress exporter (wp-admin/includes/export.php's export_wp()).
 * export_wp() sends its own Content-Type/Content-Disposition headers and
 * echoes the XML directly (it was written for a browser download, not for
 * returning a string), so the headers are suppressed and the echoed output
 * is captured via ob_start(). This does not mutate the site: it only reads
 * posts/terms/comments and writes a new file under uploads.
 *
 * export_wp() declares several helper functions (wxr_cdata(), etc.) inside
 * its own body with no function_exists() guard, so WordPress core itself
 * only supports calling it once per PHP process; a second call fatals with
 * "cannot redeclare function". A real WordPress request is one process per
 * request, so this is normally invisible, but a long-lived process (WP-CLI,
 * a persistent worker) could hit it. Track that with a static flag and fail
 * with a clear, actionable message instead of letting the raw fatal take
 * down the whole process.
 */
class Export_Content
{
    private static bool $has_run = false;

    public function handle(array $args): array
    {
        if (self::$has_run) {
            throw new \RuntimeException('export-content can only run once per PHP process: WordPress\'s own export_wp() cannot be safely called twice in the same process. Run this tool again in a fresh request.');
        }

        if (! function_exists('export_wp')) {
            require_once ABSPATH . 'wp-admin/includes/export.php';
        }
        self::$has_run = true;

        $export_args = ['content' => 'all'];
        if (! empty($args['content'])) {
            $export_args['content'] = sanitize_key((string) $args['content']);
        }
        if (! empty($args['author'])) {
            $export_args['author'] = (int) $args['author'];
        }
        if (! empty($args['start_date'])) {
            $export_args['start_date'] = (string) $args['start_date'];
        }
        if (! empty($args['end_date'])) {
            $export_args['end_date'] = (string) $args['end_date'];
        }
        if (! empty($args['status'])) {
            $export_args['status'] = sanitize_key((string) $args['status']);
        }

        ob_start();
        // export_wp() unconditionally calls header(); suppress the resulting
        // "headers already sent" notice rather than letting it leak into the
        // captured buffer or a test's output. Guaranteed restoration via try/finally.
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Scoped warning suppression around export_wp() headers notice with guaranteed restore.
        $suppress = set_error_handler(static function () {
            return true;
        }, E_WARNING);
        try {
            export_wp($export_args);
        } finally {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Restore original error handler unconditionally on every exit path.
            set_error_handler($suppress);
        }
        $xml = (string) ob_get_clean();

        $dir = Export_Dir::path();
        Export_Dir::protect($dir);

        $filename = 'wpmcp-export-' . gmdate('Y-m-d-His') . '-' . substr(wp_generate_uuid4(), 0, 8) . '.xml';
        $path     = trailingslashit($dir) . $filename;
        file_put_contents($path, $xml);

        return [
            'file'       => $path,
            'size'       => filesize($path),
            'item_count' => substr_count($xml, '<item>'),
        ];
    }
}
