<?php

namespace WPMCP\Tools\Filesystem;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Replace an exact string in a file (must match exactly once unless
 * replace_all). Disabled by default: sites must opt in via the
 * wpmcp_enable_fs_writes filter. Requires the edit_files capability and
 * honors DISALLOW_FILE_EDIT. Refuses wp-config.php/.htaccess. Backs up the
 * original file before editing it, so the change is genuinely recoverable.
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
            throw new \RuntimeException('The edit-file tool is disabled. Enable it with the wpmcp_enable_fs_writes filter.');
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

        $backup = Filesystem_Guard::backup($abs);
        if (is_wp_error($backup)) {
            throw new \RuntimeException(esc_html($backup->get_error_message()));
        }

        if ($replace_all) {
            $updated = str_replace($old, $new, $content);
        } else {
            $pos     = strpos($content, $old);
            $updated = substr($content, 0, $pos) . $new . substr($content, $pos + strlen($old));
        }

        if (false === file_put_contents($abs, $updated)) {
            throw new \RuntimeException('Could not write the file (check permissions).');
        }

        $rel = Filesystem_Guard::to_relative($abs);
        Filesystem_Guard::log('edit', $rel);

        return [
            'path'         => $rel,
            'replacements' => $replace_all ? $count : 1,
            'backup'       => $backup ? Filesystem_Guard::to_relative($backup) : null,
            'recoverable'  => '' !== $backup,
        ];
    }
}
