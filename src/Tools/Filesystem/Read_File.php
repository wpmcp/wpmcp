<?php

namespace WPMCP\Tools\Filesystem;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read a file inside the WordPress installation. Every path is confined to
 * ABSPATH by Filesystem_Guard::resolve_path(); reads never mutate anything,
 * so there is nothing to back up or roll back here.
 */
class Read_File
{
    public function handle(array $args): array
    {
        $abs = Filesystem_Guard::resolve_path((string) ($args['path'] ?? ''));
        if (is_wp_error($abs)) {
            throw new \RuntimeException($abs->get_error_message());
        }

        if (Filesystem_Guard::is_protected($abs)) {
            throw new \RuntimeException('This file is protected from reads.');
        }

        if (! is_file($abs)) {
            throw new \RuntimeException('File not found.');
        }

        $content = (string) file_get_contents($abs);
        $rel     = Filesystem_Guard::to_relative($abs);

        if (! Filesystem_Guard::is_utf8($content)) {
            return [
                'path'   => $rel,
                'size'   => strlen($content),
                'binary' => true,
            ];
        }

        return [
            'path'    => $rel,
            'size'    => strlen($content),
            'content' => $content,
        ];
    }
}
