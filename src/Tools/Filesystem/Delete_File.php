<?php

namespace WPMCP\Tools\Filesystem;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Delete a file inside the WordPress install. Disabled by default: sites
 * must opt in via the wpmcp_enable_fs_writes filter. Requires the
 * edit_files capability and honors DISALLOW_FILE_EDIT. Always requires
 * confirm:true. Refuses wp-config.php/.htaccess. Backs up the file before
 * deleting it, so the deletion is genuinely recoverable via
 * Filesystem_Guard::restore().
 */
class Delete_File
{
    public static function is_enabled(): bool
    {
        return (bool) apply_filters('wpmcp_enable_fs_writes', false);
    }

    public function handle(array $args): array
    {
        if (! self::is_enabled()) {
            throw new \RuntimeException('The delete-file tool is disabled. Enable it with the wpmcp_enable_fs_writes filter.');
        }

        if (true !== ($args['confirm'] ?? null)) {
            throw new \InvalidArgumentException('Deleting a file requires confirm:true.');
        }

        $gate = Filesystem_Guard::writes_allowed();
        if (is_wp_error($gate)) {
            throw new \RuntimeException($gate->get_error_message());
        }

        $abs = Filesystem_Guard::resolve_path((string) ($args['path'] ?? ''));
        if (is_wp_error($abs)) {
            throw new \RuntimeException($abs->get_error_message());
        }

        if (Filesystem_Guard::is_protected($abs)) {
            throw new \RuntimeException('This file is protected from deletion.');
        }

        if (! is_file($abs)) {
            throw new \RuntimeException('File not found.');
        }

        $backup = Filesystem_Guard::backup($abs);
        if (is_wp_error($backup)) {
            throw new \RuntimeException($backup->get_error_message());
        }

        $rel = Filesystem_Guard::to_relative($abs);

        if (! unlink($abs)) {
            throw new \RuntimeException('Could not delete the file (check permissions).');
        }

        Filesystem_Guard::log('delete', $rel);

        return [
            'path'        => $rel,
            'deleted'     => true,
            'backup'      => $backup ? Filesystem_Guard::to_relative($backup) : null,
            'recoverable' => '' !== $backup,
        ];
    }
}
