<?php

namespace WPMCP\Tools\Diagnostics;

use WPMCP\Tools\Filesystem\Filesystem_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: return a bounded tail of the WordPress debug log.
 *
 * Defaults to WP_CONTENT_DIR/debug.log, or Debug_Log_Path::resolve() when
 * WP_DEBUG_LOG is a custom string path. An explicit 'path' argument is
 * accepted (e.g. to read a rotated log) but is always confined to
 * WP_CONTENT_DIR via Filesystem_Guard::resolve_path(), the same path-escape
 * guard the filesystem tools use, so no traversal (../, an absolute path
 * outside WP_CONTENT_DIR, a symlink escape) can reach a file elsewhere on
 * disk.
 *
 * BOUNDED: never reads the whole file. Reads at most the last MAX_BYTES
 * bytes from the end of the file, then trims that chunk down to at most
 * MAX_LINES lines, so a multi-gigabyte log cannot exhaust memory and a
 * request cannot be used to exfiltrate more than a small tail.
 */
class Get_Debug_Log
{
    public const MAX_LINES = 200;
    public const MAX_BYTES = 64 * 1024;

    public function handle(array $args): array
    {
        $requested = isset($args['path']) ? (string) $args['path'] : null;
        $default   = Debug_Log_Path::resolve() ?? (WP_CONTENT_DIR . '/debug.log');
        $target    = $requested ?? $default;

        $abs = Filesystem_Guard::resolve_path($target, WP_CONTENT_DIR);
        if (is_wp_error($abs)) {
            throw new \RuntimeException(esc_html($abs->get_error_message()));
        }

        if (! is_file($abs)) {
            return [
                'path'    => $abs,
                'exists'  => false,
                'content' => '',
            ];
        }

        $lines = isset($args['lines']) ? max(1, (int) $args['lines']) : self::MAX_LINES;
        $lines = min($lines, self::MAX_LINES);

        $content = $this->read_tail($abs, self::MAX_BYTES);
        $content = $this->last_n_lines($content, $lines);

        return [
            'path'    => $abs,
            'exists'  => true,
            'content' => $content,
        ];
    }

    /**
     * Read at most $max_bytes from the end of $abs, without loading the
     * whole file into memory first.
     */
    private function read_tail(string $abs, int $max_bytes): string
    {
        $size = (int) filesize($abs);
        if ($size <= $max_bytes) {
            return (string) file_get_contents($abs);
        }

        // file_get_contents() with an offset reads the tail without loading
        // the whole file. It is one of the three names the Plugin Check
        // review ruleset explicitly excludes from
        // WordPress.WP.AlternativeFunctions, unlike fopen/fread/fclose.
        $content = file_get_contents($abs, false, null, $size - $max_bytes, $max_bytes);

        return false === $content ? '' : $content;
    }

    /**
     * Keep only the last $n lines of $content. Since $content may already
     * have been truncated mid-line by the byte cap, the first (possibly
     * partial) line is dropped along with the rest once more than $n lines
     * remain.
     */
    private function last_n_lines(string $content, int $n): string
    {
        if ('' === $content) {
            return '';
        }

        $trailing_newline = "\n" === substr($content, -1);
        $lines            = explode("\n", $trailing_newline ? rtrim($content, "\n") : $content);

        if (count($lines) > $n) {
            $lines = array_slice($lines, -$n);
        }

        $result = implode("\n", $lines);
        return $trailing_newline ? $result . "\n" : $result;
    }
}
