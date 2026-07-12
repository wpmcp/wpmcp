<?php

namespace WPMCP\Tools\Filesystem;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Search file contents for a substring across a directory tree inside the
 * WordPress install. Read-only; path confined via Filesystem_Guard. Results
 * are bounded (default 200, max 500 matches) so a broad search cannot
 * exhaust memory or flood the caller.
 */
class Search_Files
{
    private const DEFAULT_MAX_RESULTS = 200;
    private const HARD_MAX_RESULTS    = 500;

    public function handle(array $args): array
    {
        $query = (string) ($args['query'] ?? '');
        if ('' === $query) {
            throw new \InvalidArgumentException('A search query is required.');
        }

        $abs = Filesystem_Guard::resolve_path((string) ($args['path'] ?? '.'));
        if (is_wp_error($abs)) {
            throw new \RuntimeException($abs->get_error_message());
        }

        if (! is_dir($abs)) {
            throw new \RuntimeException('Not a directory.');
        }

        $extensions = [];
        if (! empty($args['extensions']) && is_array($args['extensions'])) {
            $extensions = array_map('strtolower', array_map('strval', $args['extensions']));
        }

        $cap = min(
            self::HARD_MAX_RESULTS,
            max(1, isset($args['max_results']) ? (int) $args['max_results'] : self::DEFAULT_MAX_RESULTS)
        );

        $matches = [];
        $it      = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($it as $f) {
            if (! $f->isFile()) {
                continue;
            }
            if ($extensions && ! in_array(strtolower($f->getExtension()), $extensions, true)) {
                continue;
            }

            $content = (string) file_get_contents($f->getPathname());
            if (! Filesystem_Guard::is_utf8($content)) {
                continue;
            }

            $rel  = Filesystem_Guard::to_relative($f->getPathname());
            $line = 0;
            foreach (explode("\n", $content) as $text) {
                $line++;
                if (false !== strpos($text, $query)) {
                    $matches[] = [
                        'file' => $rel,
                        'line' => $line,
                        'text' => substr(trim($text), 0, 300),
                    ];
                    if (count($matches) >= $cap) {
                        return ['matches' => $matches, 'truncated' => true];
                    }
                }
            }
        }

        return ['matches' => $matches, 'truncated' => false];
    }
}
