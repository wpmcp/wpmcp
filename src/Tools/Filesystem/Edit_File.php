<?php

namespace WPMCP\Tools\Filesystem;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Replace an exact string in a file (must match exactly once unless
 * replace_all). Disabled by default: sites must opt in via the
 * wpmcp_enable_fs_writes filter. Requires the edit_files capability and
 * honors DISALLOW_FILE_EDIT. Refuses wp-config.php/.htaccess. Records the
 * edit as an undoable operation (File_Write_Operation): the original is
 * backed up first and rollback-operation with the returned operation_id
 * writes it back byte for byte (issue #602).
 * A .php target is syntax-checked and site-checked first (Php_Edit_Guard).
 */
class Edit_File
{
    public static function is_enabled(): bool
    {
        return (bool) apply_filters('wpmcp_enable_fs_writes', false);
    }

    public function handle(array $args): array
    {
        if (! self::is_enabled()) {
            throw new \WPMCP\MCP\Opt_In_Required('The edit-file tool is disabled.', 'wpmcp_enable_fs_writes');
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

        if (! is_file($abs)) {
            throw new \RuntimeException('File not found.');
        }

        $content = (string) file_get_contents($abs);
        if (! Filesystem_Guard::is_utf8($content)) {
            throw new \RuntimeException('Cannot edit a binary file.');
        }
        // A copy of wp-config.php under any name (issue #606): its "found" and
        // "not found" answers would otherwise be an oracle for the secrets.
        if (Filesystem_Guard::holds_wp_secrets($content)) {
            throw new \RuntimeException('This file is protected from writes.');
        }

        $old = (string) ($args['old_string'] ?? '');
        $new = (string) ($args['new_string'] ?? '');
        if ('' === $old) {
            throw new \InvalidArgumentException('old_string must not be empty.');
        }

        $count = substr_count($content, $old);
        if (0 === $count) {
            throw new \RuntimeException('old_string was not found in the file.');
        }

        $replace_all = ! empty($args['replace_all']);
        if ($count > 1 && ! $replace_all) {
            throw new \RuntimeException('old_string matched multiple times; pass replace_all or make it unique.');
        }

        if ($replace_all) {
            $updated = str_replace($old, $new, $content);
        } else {
            $pos     = strpos($content, $old);
            $updated = substr($content, 0, $pos) . $new . substr($content, $pos + strlen($old));
        }

        $done = File_Write_Operation::run(
            'edit-file',
            $abs,
            $args,
            static function (string $backup) use ($abs, $updated, $args): ?string {
                if (Php_Edit_Guard::applies($abs)) {
                    return Php_Edit_Guard::write($abs, $updated, ! empty($args['unchecked']), $backup)['php_check'];
                }
                if (false === file_put_contents($abs, $updated)) {
                    throw new \RuntimeException('Could not write the file (check permissions).');
                }
                return null;
            }
        );
        $php_check = $done['result'];

        $rel = Filesystem_Guard::to_relative($abs);
        Filesystem_Guard::log('edit', $rel);

        return [
            'path'         => $rel,
            'replacements' => $replace_all ? $count : 1,
            'operation_id' => $done['operation_id'],
            'backup'       => Filesystem_Guard::to_relative($done['backup']),
            'recoverable'  => true,
        ] + (null === $php_check ? [] : ['php_check' => $php_check]);
    }
}
