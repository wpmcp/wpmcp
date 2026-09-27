<?php

namespace WPMCP\Tools\Filesystem;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Create or overwrite a file inside the WordPress install. Disabled by
 * default: sites must opt in via the wpmcp_enable_fs_writes filter. Requires
 * the edit_files capability and honors DISALLOW_FILE_EDIT. Refuses
 * wp-config.php/.htaccess. Backs up an existing file before overwriting it,
 * so the change is genuinely recoverable (see Filesystem_Guard::restore()).
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

        $backup = Filesystem_Guard::backup($abs);
        if (is_wp_error($backup)) {
            throw new \RuntimeException(esc_html($backup->get_error_message()));
        }

        if (! wp_mkdir_p(dirname($abs))) {
            throw new \RuntimeException('Could not create the parent directory.');
        }

        $bytes = file_put_contents($abs, $content);
        if (false === $bytes) {
            throw new \RuntimeException('Could not write the file (check permissions).');
        }

        $rel = Filesystem_Guard::to_relative($abs);
        Filesystem_Guard::log($existed ? 'overwrite' : 'create', $rel);

        return [
            'path'        => $rel,
            'bytes'       => (int) $bytes,
            'action'      => $existed ? 'overwritten' : 'created',
            'backup'      => $backup ? Filesystem_Guard::to_relative($backup) : null,
            'recoverable' => '' !== $backup,
        ];
    }
}
