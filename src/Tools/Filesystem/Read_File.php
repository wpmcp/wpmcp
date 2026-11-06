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
        Filesystem_Guard::assert_reads_allowed();

        $abs = Filesystem_Guard::resolve_path((string) ($args['path'] ?? ''));
        if (is_wp_error($abs)) {
            throw new \RuntimeException(esc_html($abs->get_error_message()));
        }

        if (Filesystem_Guard::is_protected($abs)) {
            throw new \RuntimeException('This file is protected from reads.');
        }

        // A file backup asked for by the name it had before the upgrade
        // renamed it to .bak (issues #622, #730): follow the same recorded
        // mapping restore() follows, and name the new path in the response.
        $renamed_from = null;
        if (! is_file($abs)) {
            $renamed = Legacy_Backup_Migration::renamed_path($abs);
            if (null === $renamed || Filesystem_Guard::is_protected($renamed)) {
                throw new \RuntimeException('File not found.');
            }
            $renamed_from = Filesystem_Guard::to_relative($abs);
            $abs          = $renamed;
        }

        $content = (string) file_get_contents($abs);
        $rel     = Filesystem_Guard::to_relative($abs);

        // A copy of wp-config.php under any name (issue #606).
        if (Filesystem_Guard::holds_wp_secrets($content)) {
            throw new \RuntimeException('This file is protected from reads.');
        }

        $result = ['path' => $rel, 'size' => strlen($content)];
        if (null !== $renamed_from) {
            $result['renamed_from'] = $renamed_from;
        }

        if (! Filesystem_Guard::is_utf8($content)) {
            $result['binary'] = true;
            return $result;
        }

        $result['content'] = $content;
        return $result;
    }
}
