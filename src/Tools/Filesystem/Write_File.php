<?php

namespace WPMCP\Tools\Filesystem;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Create or overwrite a file inside the WordPress install. Disabled by
 * default: sites must opt in via the wpmcp_enable_fs_writes filter. Requires
 * the edit_files capability and honors DISALLOW_FILE_EDIT. Refuses
 * wp-config.php/.htaccess. Records the write as an undoable operation
 * (File_Write_Operation): an existing file is backed up first, and
 * rollback-operation with the returned operation_id writes it back, or
 * removes the file when this call created it (issue #602).
 * A .php target is syntax-checked and site-checked first (Php_Edit_Guard).
 */
class Write_File
{
    public static function is_enabled(): bool
    {
        return (bool) apply_filters('wpmcp_enable_fs_writes', false);
    }

    public function handle(array $args): array
    {
        if (! self::is_enabled()) {
            throw new \RuntimeException('The write-file tool is disabled. Enable it with the wpmcp_enable_fs_writes filter.');
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
            throw new \RuntimeException('This file is protected from writes.');
        }

        // Defense in depth: resolve_path() already rejects a dangling
        // symlink leaf, but never write through a symlink here either,
        // in case it is ever reached with an already-resolved path.
        if (is_link($abs)) {
            throw new \RuntimeException('Refusing to write through a symlink.');
        }

        $content = (string) ($args['content'] ?? '');
        $existed = is_file($abs);

        $done = File_Write_Operation::run(
            'write-file',
            $abs,
            $args,
            static function (string $backup) use ($abs, $content, $args): array {
                if (Php_Edit_Guard::applies($abs)) {
                    $written = Php_Edit_Guard::write($abs, $content, ! empty($args['unchecked']), $backup);
                    return ['bytes' => $written['bytes'], 'php_check' => $written['php_check']];
                }
                if (! wp_mkdir_p(dirname($abs))) {
                    throw new \RuntimeException('Could not create the parent directory.');
                }
                $bytes = file_put_contents($abs, $content);
                if (false === $bytes) {
                    throw new \RuntimeException('Could not write the file (check permissions).');
                }
                return ['bytes' => $bytes, 'php_check' => null];
            }
        );
        $php_check = $done['result']['php_check'];

        $rel = Filesystem_Guard::to_relative($abs);
        Filesystem_Guard::log($existed ? 'overwrite' : 'create', $rel);

        return [
            'path'         => $rel,
            'bytes'        => (int) $done['result']['bytes'],
            'action'       => $existed ? 'overwritten' : 'created',
            'operation_id' => $done['operation_id'],
            'backup'       => '' !== $done['backup'] ? Filesystem_Guard::to_relative($done['backup']) : null,
            // A create is undone by removing the file, an overwrite by
            // writing the backup back: both through rollback-operation.
            'recoverable'  => true,
        ] + (null === $php_check ? [] : ['php_check' => $php_check]);
    }
}
