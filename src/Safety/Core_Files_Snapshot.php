<?php

namespace WPMCP\Safety;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Undo point for the incident-response core file reinstall (issue #382).
 *
 * Before any core file is overwritten, the current bytes of every file that
 * exists are packed into ONE archive in the per-operation backup directory
 * (File_Backup::operation_dir()). An archive rather than loose copies for the
 * reason File_Backup::backup_directory() gives: the backup root sits under
 * uploads, and loose PHP files there would be requestable on a server that
 * ignores the .htaccess deny. Snapshot_Store::prune() deletes the directory
 * with its snapshot, so backups do not pile up.
 *
 * The snapshot data is { files: { relative path => existed before (bool) } }.
 * Restoring writes each existing file back from the archive and deletes the
 * ones the reinstall created, so the tree ends exactly as it was. Paths are
 * re-validated on restore and wp-content is never written.
 */
class Core_Files_Snapshot
{
    public const TYPE = 'core_files';

    private const ARCHIVE = 'core-files.zip';

    private static ?string $root_override = null;

    /** Point every read and write at a fixture root instead of ABSPATH. Tests only. */
    public static function set_root_for_tests(?string $root): void
    {
        self::$root_override = $root;
    }

    /** The WordPress root core files live under, with a trailing slash. */
    public static function root(): string
    {
        return trailingslashit(self::$root_override ?? ABSPATH);
    }

    /**
     * A core-relative path this feature may write: plain segments only, no
     * traversal, no dot files, and never anything under wp-content.
     */
    public static function is_core_path(string $path): bool
    {
        if ('' === $path || strlen($path) > 255) {
            return false;
        }
        $segments = explode('/', $path);
        if ('wp-content' === strtolower($segments[0])) {
            return false;
        }
        foreach ($segments as $segment) {
            if (1 !== preg_match('/^[A-Za-z0-9_][A-Za-z0-9._-]*$/', $segment)) {
                return false;
            }
        }
        return true;
    }

    /** The empty shape Safe_Mutation persists; the tool merges the file list in. */
    public static function capture(string $operation_id): array
    {
        return [
            'object_type' => self::TYPE,
            'object_id'   => $operation_id,
            'data'        => ['files' => []],
        ];
    }

    /**
     * Archive the current bytes of $relative_paths (files that exist under
     * root()) for $operation_id. True when every one was stored.
     *
     * @param string[] $relative_paths
     */
    public static function backup(string $operation_id, array $relative_paths): bool
    {
        if ([] === $relative_paths) {
            return true;
        }
        if (! class_exists('ZipArchive')) {
            return false;
        }
        $dir = File_Backup::operation_dir($operation_id);
        if (! wp_mkdir_p($dir)) {
            return false;
        }
        File_Backup::protect_dir($dir);

        $zip = new \ZipArchive();
        if (true !== $zip->open($dir . '/' . self::ARCHIVE, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            return false;
        }
        $root = self::root();
        foreach ($relative_paths as $relative) {
            $abs = $root . $relative;
            if (! self::is_core_path($relative) || is_link($abs) || ! is_file($abs) || ! $zip->addFile($abs, $relative)) {
                $zip->close();
                File_Backup::delete_backup_dir($operation_id);
                return false;
            }
        }
        return $zip->close();
    }

    /**
     * Put the tree back as it was before the reinstall.
     *
     * @return string[] Warnings for anything that could not be restored.
     */
    public static function restore(array $snapshot): array
    {
        $files        = (array) ($snapshot['data']['files'] ?? []);
        $operation_id = (string) ($snapshot['object_id'] ?? '');
        if ([] === $files) {
            return [];
        }

        $archive = File_Backup::operation_dir($operation_id) . '/' . self::ARCHIVE;
        $zip     = null;
        if (in_array(true, $files, true)) {
            $zip = class_exists('ZipArchive') ? new \ZipArchive() : null;
            if (null === $zip || ! is_file($archive) || true !== $zip->open($archive, \ZipArchive::RDONLY)) {
                return ['Core files were not rolled back: the backup of the replaced files is no longer available.'];
            }
        }

        $fs       = self::filesystem();
        $root     = self::root();
        $warnings = [];
        foreach ($files as $relative => $existed) {
            $relative = (string) $relative;
            $abs      = $root . $relative;
            if (! self::is_core_path($relative) || is_link($abs)) {
                $warnings[] = sprintf('%s was not restored: it is not a plain core file path.', $relative);
                continue;
            }
            if (! $existed) {
                if (is_file($abs) && ! $fs->delete($abs)) {
                    $warnings[] = sprintf('%s could not be removed.', $relative);
                }
                continue;
            }
            $bytes = $zip instanceof \ZipArchive ? $zip->getFromName($relative) : false;
            if (false === $bytes) {
                $warnings[] = sprintf('%s is missing from the backup.', $relative);
                continue;
            }
            wp_mkdir_p(dirname($abs));
            if (! $fs->put_contents($abs, $bytes, self::mode($fs, $abs))) {
                $warnings[] = sprintf('%s could not be written back.', $relative);
            }
        }
        if ($zip instanceof \ZipArchive) {
            $zip->close();
        }
        return $warnings;
    }

    /** Keep an existing file's permissions; new files get the site default. */
    public static function mode(\WP_Filesystem_Base $fs, string $abs): int
    {
        if (is_file($abs)) {
            return (int) octdec((string) $fs->getchmod($abs));
        }
        return defined('FS_CHMOD_FILE') ? (int) FS_CHMOD_FILE : 0644;
    }

    /**
     * The direct filesystem, like Rollback_Service's: a tool call cannot
     * prompt for FTP credentials, and these paths are local by definition.
     */
    public static function filesystem(): \WP_Filesystem_Direct
    {
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
        return new \WP_Filesystem_Direct(null);
    }
}
