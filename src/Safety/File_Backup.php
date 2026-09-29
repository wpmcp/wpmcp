<?php

namespace WPMCP\Safety;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Backs up an attachment's physical files (the main file plus every
 * intermediate size, and the pre-scale original when one exists) before an
 * irreversible delete, so Rollback_Service can put the bytes back on disk
 * after the DB record is resurrected. See issue #24.
 */
class File_Backup
{
    public const BACKUP_DIR = '.wpmcp-backups';

    /** File name of a whole-directory backup inside an operation's backup dir. */
    public const PACKAGE_ARCHIVE = 'package.zip';

    /**
     * Absolute path to the per-operation backup directory under uploads,
     * e.g. wp-content/uploads/.wpmcp-backups/&lt;operation_id&gt;/. Does not
     * create the directory; callers that need it to exist use backup().
     */
    public static function operation_dir(string $operation_id): string
    {
        $uploads = wp_upload_dir();
        return trailingslashit($uploads['basedir']) . self::BACKUP_DIR . '/' . $operation_id;
    }

    /**
     * Copy every path in $abs_paths into a fresh per-operation backup
     * directory, protected from direct web access. Returns a manifest
     * mapping each original absolute path to the filename it was stored
     * under inside that directory, for later restore(). Paths that fail to
     * copy are simply omitted from the manifest rather than aborting the
     * whole backup: a partial recovery is better than none.
     */
    public static function backup(string $operation_id, array $abs_paths): array
    {
        if (empty($abs_paths)) {
            return [];
        }

        $dir = self::operation_dir($operation_id);
        if (! wp_mkdir_p($dir)) {
            return [];
        }
        self::protect_dir($dir);

        $manifest = [];
        $used     = [];
        foreach ($abs_paths as $original) {
            if (! is_file($original)) {
                continue;
            }
            $stored = self::unique_name(basename($original), $used);
            if (copy($original, $dir . '/' . $stored)) {
                $manifest[ $original ] = $stored;
                $used[ $stored ]       = true;
            }
        }

        return $manifest;
    }

    /**
     * A basename is not guaranteed unique across an attachment's files
     * (unlikely, but a size file could theoretically collide with another
     * path's basename), so collisions get a numeric suffix rather than
     * silently overwriting an already-stored backup.
     */
    private static function unique_name(string $basename, array $used): string
    {
        if (! isset($used[ $basename ])) {
            return $basename;
        }
        $i = 2;
        while (isset($used[ "{$i}-{$basename}" ])) {
            $i++;
        }
        return "{$i}-{$basename}";
    }

    /**
     * Copy every backed-up file in $manifest (original absolute path =>
     * stored filename, as returned by backup()) back to its original path,
     * recreating any directory that no longer exists. Missing backup files
     * are skipped rather than aborting the whole restore, so a partial
     * manifest still recovers what it can.
     */
    public static function restore(string $operation_id, array $manifest): void
    {
        if (empty($manifest)) {
            return;
        }

        $dir = self::operation_dir($operation_id);
        foreach ($manifest as $original => $stored) {
            $source = $dir . '/' . $stored;
            if (! is_file($source)) {
                continue;
            }
            wp_mkdir_p(dirname($original));
            copy($source, $original);
        }
    }

    /**
     * Back up a whole directory (a plugin or theme about to be replaced by
     * install-package-from-zip, issue #282) as ONE archive in the
     * per-operation backup directory.
     *
     * An archive rather than a copied tree on purpose: the backup root sits
     * under uploads, and loose copies of a plugin's PHP files there would be
     * directly requestable on any server that ignores the .htaccess deny.
     * Symlinks inside the directory are skipped rather than followed. Returns
     * false, leaving nothing behind, when the archive could not be written;
     * an empty directory has nothing to archive and also returns false, so
     * callers record that case themselves.
     */
    public static function backup_directory(string $operation_id, string $source_dir): bool
    {
        if (! class_exists('ZipArchive') || ! is_dir($source_dir) || is_link($source_dir)) {
            return false;
        }

        $dir = self::operation_dir($operation_id);
        if (! wp_mkdir_p($dir)) {
            return false;
        }
        self::protect_dir($dir);

        $zip  = new \ZipArchive();
        $path = $dir . '/' . self::PACKAGE_ARCHIVE;
        if (true !== $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            self::delete_backup_dir($operation_id);
            return false;
        }

        $base  = strlen(trailingslashit($source_dir));
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source_dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($items as $item) {
            if (! $item instanceof \SplFileInfo || $item->isLink()) {
                continue;
            }
            $relative = substr($item->getPathname(), $base);
            $ok       = $item->isDir() ? $zip->addEmptyDir($relative) : $zip->addFile($item->getPathname(), $relative);
            if (! $ok) {
                $zip->close();
                self::delete_backup_dir($operation_id);
                return false;
            }
        }

        if (! $zip->close() || ! is_file($path)) {
            self::delete_backup_dir($operation_id);
            return false;
        }
        return true;
    }

    /** Whether $operation_id holds a directory backup written by backup_directory(). */
    public static function has_directory_backup(string $operation_id): bool
    {
        return is_file(self::operation_dir($operation_id) . '/' . self::PACKAGE_ARCHIVE);
    }

    /**
     * Unpack a backup_directory() archive into $target_dir, which the caller
     * has already emptied. The archive is this plugin's own, written from a
     * directory on this server, so it is extracted as it stands.
     */
    public static function restore_directory(string $operation_id, string $target_dir): bool
    {
        $path = self::operation_dir($operation_id) . '/' . self::PACKAGE_ARCHIVE;
        if (! class_exists('ZipArchive') || ! is_file($path) || ! wp_mkdir_p($target_dir)) {
            return false;
        }

        $zip = new \ZipArchive();
        if (true !== $zip->open($path, \ZipArchive::RDONLY)) {
            return false;
        }
        $ok = $zip->extractTo($target_dir);
        $zip->close();
        return $ok;
    }

    /**
     * Delete a per-operation backup directory and everything in it. Called
     * when a snapshot is pruned (Snapshot_Store::prune()), so backups do
     * not accumulate forever. A no-op if the directory does not exist.
     */
    public static function delete_backup_dir(string $operation_id): void
    {
        $dir = self::operation_dir($operation_id);
        if (! is_dir($dir)) {
            return;
        }
        foreach (array_diff((array) scandir($dir), ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            if (is_file($path)) {
                wp_delete_file($path);
            }
        }
        // Recursive, so nothing left inside can keep the directory alive.
        self::filesystem()->rmdir($dir, true);
    }

    /**
     * The WP_Filesystem instance, initialised on first use.
     *
     * Plugin Check promotes WordPress.WP.AlternativeFunctions to an error, so
     * directory removal has to go through WP_Filesystem rather than rmdir().
     * Booted with the direct method: this only ever touches the plugin's own
     * backup directory under wp-content/uploads, and prompting for FTP
     * credentials from a tool call is not an option.
     */
    private static function filesystem(): \WP_Filesystem_Base
    {
        global $wp_filesystem;
        if (! $wp_filesystem instanceof \WP_Filesystem_Base) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        return $wp_filesystem;
    }

    /** Block direct web access to a backup directory (deny + empty index). Also used by Core_Files_Snapshot. */
    public static function protect_dir(string $dir): void
    {
        if (! is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\n");
        }
        if (! is_file($dir . '/index.php')) {
            @file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
        }
    }

    /**
     * Every absolute file path belonging to an attachment: the main file,
     * each registered intermediate size from wp_get_attachment_metadata(),
     * and the pre-"-scaled" original when WordPress downsized the upload on
     * import. Paths are deduplicated and any size entry whose file is
     * already missing from disk is skipped (nothing to back up).
     */
    public static function collect_attachment_files(int $attachment_id): array
    {
        $main = get_attached_file($attachment_id);
        if (! $main) {
            return [];
        }

        $dir   = trailingslashit(dirname($main));
        $paths = [$main];

        $meta = wp_get_attachment_metadata($attachment_id);
        if (is_array($meta) && ! empty($meta['sizes']) && is_array($meta['sizes'])) {
            foreach ($meta['sizes'] as $size) {
                if (! empty($size['file'])) {
                    $paths[] = $dir . $size['file'];
                }
            }
        }

        // WordPress may have downsized a large original on upload, keeping
        // the pre-scale original alongside it (e.g. "photo-scaled.jpg" is
        // the attached file, "photo.jpg" is the untouched original).
        if (is_array($meta) && ! empty($meta['original_image'])) {
            $paths[] = $dir . $meta['original_image'];
        }

        $paths = array_values(array_unique($paths));

        return array_values(array_filter($paths, 'is_file'));
    }
}
