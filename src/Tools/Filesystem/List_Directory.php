<?php

namespace WPMCP\Tools\Filesystem;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * List entries (files/dirs with size + mtime) of a directory inside the
 * WordPress install. Read-only; path confined via Filesystem_Guard.
 */
class List_Directory
{
    private const MAX_DEPTH   = 5;
    private const MAX_ENTRIES = 2000;

    public function handle(array $args): array
    {
        $abs = Filesystem_Guard::resolve_path((string) ($args['path'] ?? '.'));
        if (is_wp_error($abs)) {
            throw new \RuntimeException($abs->get_error_message());
        }

        if (! is_dir($abs)) {
            throw new \RuntimeException('Not a directory.');
        }

        $recursive = ! empty($args['recursive']);
        $entries   = [];

        if ($recursive) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            $it->setMaxDepth(self::MAX_DEPTH);
            foreach ($it as $f) {
                $entries[] = $this->entry($f->getPathname());
                if (count($entries) >= self::MAX_ENTRIES) {
                    break;
                }
            }
        } else {
            foreach (scandir($abs) as $name) {
                if ('.' === $name || '..' === $name) {
                    continue;
                }
                $entries[] = $this->entry($abs . DIRECTORY_SEPARATOR . $name);
            }
        }

        return [
            'path'    => Filesystem_Guard::to_relative($abs),
            'entries' => $entries,
        ];
    }

    private function entry(string $abs): array
    {
        return [
            'name'  => basename($abs),
            'path'  => Filesystem_Guard::to_relative($abs),
            'type'  => is_dir($abs) ? 'dir' : 'file',
            'size'  => is_file($abs) ? (int) filesize($abs) : 0,
            'mtime' => (int) filemtime($abs),
        ];
    }
}
