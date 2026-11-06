<?php

namespace WPMCP\Tools\Filesystem;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Delete a file inside the WordPress install. Disabled by default: sites
 * must opt in via the wpmcp_enable_fs_writes filter. Requires the
 * edit_files capability and honors DISALLOW_FILE_EDIT. Always requires
 * confirm:true. Refuses wp-config.php/.htaccess. Records the deletion as an
 * undoable operation (File_Write_Operation): the file is backed up first and
 * rollback-operation with the returned operation_id puts it back byte for
 * byte (issue #602).
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
            throw new \WPMCP\MCP\Opt_In_Required('The delete-file tool is disabled.', 'wpmcp_enable_fs_writes');
        }

        if (true !== ($args['confirm'] ?? null)) {
            throw new \WPMCP\MCP\Confirmation_Required('Deleting a file requires confirm:true.');
        }

        $gate = Filesystem_Guard::writes_allowed();
        if (is_wp_error($gate)) {
            throw new \RuntimeException(esc_html($gate->get_error_message()));
        }

        $abs = Filesystem_Guard::resolve_path((string) ($args['path'] ?? ''));
        if (is_wp_error($abs)) {
            throw new \RuntimeException(esc_html($abs->get_error_message()));
        }

        if (Filesystem_Guard::is_protected($abs)) {
            throw new \RuntimeException('This file is protected from deletion.');
        }

        if (! is_file($abs)) {
            throw new \RuntimeException('File not found.');
        }

        $rel = Filesystem_Guard::to_relative($abs);

        $done = File_Write_Operation::run(
            'delete-file',
            $abs,
            $args,
            static function () use ($abs): bool {
                wp_delete_file($abs);
                if (is_file($abs)) {
                    throw new \RuntimeException('Could not delete the file (check permissions).');
                }
                return true;
            }
        );

        Filesystem_Guard::log('delete', $rel);

        return [
            'path'         => $rel,
            'deleted'      => true,
            'operation_id' => $done['operation_id'],
            'backup'       => Filesystem_Guard::to_relative($done['backup']),
            'recoverable'  => true,
        ];
    }
}
