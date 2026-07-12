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
        $root_real = realpath($abs);

        if ($recursive) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $abs,
                    \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS
                ),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            // Never descend into a symlinked directory.
            $it->setFlags($it->getFlags() & ~\FilesystemIterator::FOLLOW_SYMLINKS);
            $it->setMaxDepth(self::MAX_DEPTH);
            foreach ($it as $f) {
                if ($f->isLink() || ! $this->is_contained($f->getPathname(), $root_real)) {
                    continue;
                }
                if (Filesystem_Guard::is_protected($f->getPathname())) {
                    continue;
                }
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
                $child = $abs . DIRECTORY_SEPARATOR . $name;
                if (is_link($child) || ! $this->is_contained($child, $root_real)) {
                    continue;
                }
                if (Filesystem_Guard::is_protected($child)) {
                    continue;
                }
                $entries[] = $this->entry($child);
            }
        }

        return [
            'path'    => Filesystem_Guard::to_relative($abs),
            'entries' => $entries,
        ];
    }

    /**
     * Re-validate that $abs's canonical form is still within $root_real
     * before it is listed. A symlink deeper in a directory chain can yield
     * an outside real path even when the leaf itself is not a link, so
     * per-entry containment is checked in addition to skipping symlinks.
     */
    private function is_contained(string $abs, string|false $root_real): bool
    {
        $real = realpath($abs);
        if (false === $real || false === $root_real) {
            return false;
        }
        $real_n = rtrim($real, '/\\');
        $root_n = rtrim($root_real, '/\\');
        return $real_n === $root_n || 0 === strpos($real_n, $root_n . DIRECTORY_SEPARATOR);
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
