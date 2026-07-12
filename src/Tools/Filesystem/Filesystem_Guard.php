<?php

namespace WPMCP\Tools\Filesystem;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Security core for the filesystem tools: confines every path to the
 * WordPress install root (ABSPATH in production; a temp fixture root in
 * tests), refuses protected files, and backs up before destructive writes.
 *
 * resolve_path() is the one chokepoint that makes "inside the WordPress
 * install only" true, so every rejection case is exercised exhaustively by
 * FilesystemGuardTest, including a real symlink escaping the root.
 */
class Filesystem_Guard
{
    public const AUDIT_OPTION = 'wpmcp_fs_write_audit_log';
    public const AUDIT_MAX    = 100;
    public const BACKUP_DIR   = 'wpmcp-fs-backups';

    /**
     * Confine a path to $root (defaults to ABSPATH). Returns the canonical
     * absolute path, or a WP_Error when the path is invalid or escapes root.
     *
     * @return string|\WP_Error
     */
    public static function resolve_path(string $path, ?string $root = null)
    {
        $root = $root ?? ABSPATH;

        if ('' === $path || false !== strpos($path, "\0")) {
            return new \WP_Error('invalid_path', 'Invalid file path.');
        }

        $is_absolute = ('' !== $path) && (
            '/' === $path[0]
            || '\\' === $path[0]
            || 1 === preg_match('#^[A-Za-z]:[\\\\/]#', $path)
        );
        $candidate = $is_absolute
            ? $path
            : rtrim($root, '/\\') . DIRECTORY_SEPARATOR . ltrim($path, '/\\');

        $real = realpath($candidate);
        if (false === $real) {
            $parent = realpath(dirname($candidate));
            if (false === $parent) {
                return new \WP_Error('parent_missing', 'The target directory does not exist.');
            }
            $real = rtrim($parent, '/\\') . DIRECTORY_SEPARATOR . basename($candidate);
        }

        $root_real = realpath($root);
        if (false === $root_real) {
            $root_real = $root;
        }

        $real_n = rtrim($real, '/\\');
        $root_n = rtrim($root_real, '/\\');
        if ($real_n !== $root_n && 0 !== strpos($real_n, $root_n . DIRECTORY_SEPARATOR)) {
            return new \WP_Error('outside_root', 'Path is outside the WordPress installation.');
        }

        return $real;
    }

    /**
     * Whether a path is write/delete-protected (wp-config.php / .htaccess),
     * matched case-insensitively on the basename. Filterable so a site can
     * extend the protected list.
     */
    public static function is_protected(string $abs): bool
    {
        $base      = strtolower(basename($abs));
        $protected = ['wp-config.php', '.htaccess'];
        /** Filter the write/delete-protected basenames. */
        $protected = (array) apply_filters('wpmcp_fs_protected_paths', $protected, $abs);
        return in_array($base, array_map('strtolower', $protected), true);
    }

    /**
     * Pure: a sanitized, timestamped backup filename for a relative path.
     */
    public static function backup_name(string $rel, string $timestamp): string
    {
        $flat = str_replace(['/', '\\'], '-', ltrim($rel, '/\\'));
        $flat = preg_replace('/[^A-Za-z0-9._-]/', '-', $flat);
        return $timestamp . '-' . $flat;
    }

    /**
     * Pure: is $content valid UTF-8 text (vs binary)?
     */
    public static function is_utf8(string $content): bool
    {
        if ('' === $content) {
            return true;
        }
        if (false !== strpos($content, "\0")) {
            return false;
        }
        return (bool) preg_match('//u', $content);
    }

    /**
     * Pure: gate write/edit/delete on the edit_files capability and honor
     * DISALLOW_FILE_EDIT.
     *
     * @return true|\WP_Error
     */
    public static function check_writes(bool $can_edit_files, bool $disallow_file_edit)
    {
        if ($disallow_file_edit) {
            return new \WP_Error('file_edit_disabled', 'File editing is disabled on this site (DISALLOW_FILE_EDIT).');
        }
        if (! $can_edit_files) {
            return new \WP_Error('file_edit_disabled', 'You do not have permission to edit files.');
        }
        return true;
    }

    /**
     * Live wrapper: gate writes from the current request context.
     *
     * @return true|\WP_Error
     */
    public static function writes_allowed()
    {
        return self::check_writes(
            current_user_can('edit_files'),
            defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT
        );
    }

    /**
     * Path relative to $root (defaults to ABSPATH), for display/log/output.
     * $root is a parameter only so tests can point it at a fixture root.
     */
    public static function to_relative(string $abs, ?string $root = null): string
    {
        $root  = $root ?? ABSPATH;
        $root  = rtrim((string) realpath($root), '/\\');
        $abs_n = $abs;
        if ('' !== $root && 0 === strpos($abs_n, $root)) {
            $abs_n = ltrim(substr($abs_n, strlen($root)), '/\\');
        }
        return str_replace('\\', '/', $abs_n);
    }

    /**
     * Copy $abs to a timestamped backup file inside $backup_dir. Returns the
     * backup's absolute path, or '' if $abs does not exist yet (a create,
     * not an overwrite, has nothing to back up). $root is used only to
     * compute the relative name via backup_name()/to_relative().
     *
     * @return string|\WP_Error
     */
    public static function backup_to_dir(string $abs, string $backup_dir, string $root, string $timestamp)
    {
        if (! is_file($abs)) {
            return '';
        }
        $rel  = self::to_relative($abs, $root);
        $name = self::backup_name($rel, $timestamp);
        $dest = rtrim($backup_dir, '/\\') . DIRECTORY_SEPARATOR . $name;
        if (! copy($abs, $dest)) {
            return new \WP_Error('backup_failed', 'Could not back up the file before writing.');
        }
        return $dest;
    }

    /**
     * Restore a backup over its original target, making a write/edit/delete
     * recoverable. Returns whether the copy succeeded.
     */
    public static function restore(string $backup_abs, string $target_abs): bool
    {
        if (! is_file($backup_abs)) {
            return false;
        }
        return copy($backup_abs, $target_abs);
    }

    /**
     * Live wrapper: back up $abs (an already resolve_path()'d absolute path)
     * to a timestamped file under wp-content/uploads/wpmcp-fs-backups/,
     * creating that directory (and blocking direct web access to it) on
     * first use. Returns the backup path, '' if $abs does not exist yet, or
     * a WP_Error if the uploads directory or the copy itself is unavailable.
     *
     * @return string|\WP_Error
     */
    public static function backup(string $abs)
    {
        if (! is_file($abs)) {
            return '';
        }
        $uploads = wp_upload_dir();
        if (! empty($uploads['error'])) {
            return new \WP_Error('no_uploads', 'Uploads directory is unavailable for backups.');
        }
        $dir = trailingslashit($uploads['basedir']) . self::BACKUP_DIR;
        if (! wp_mkdir_p($dir)) {
            return new \WP_Error('backup_dir', 'Could not create the backup directory.');
        }
        if (! is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\n");
            @file_put_contents($dir . '/index.html', '');
        }
        return self::backup_to_dir($abs, $dir, ABSPATH, gmdate('Ymd-His'));
    }

    /**
     * Append a write/edit/delete to the capped audit log option.
     */
    public static function log(string $op, string $rel_path): void
    {
        $log = get_option(self::AUDIT_OPTION, []);
        if (! is_array($log)) {
            $log = [];
        }
        $log[] = [
            'op'   => $op,
            'path' => $rel_path,
            'user' => get_current_user_id(),
            'time' => time(),
        ];
        if (count($log) > self::AUDIT_MAX) {
            $log = array_slice($log, -self::AUDIT_MAX);
        }
        update_option(self::AUDIT_OPTION, $log, false);
    }
}
