<?php

namespace WPMCP\Safety;

use WPMCP\Tools\Filesystem\Filesystem_Guard;
use WPMCP\Tools\Filesystem\Php_Edit_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Undo point for one write-file, edit-file or delete-file call (issue #602).
 *
 * Before the write, the file's current bytes (when it exists) are copied to
 * BACKUP_FILE in the per-operation backup directory
 * (File_Backup::operation_dir()), never under the original name: a copy of a
 * .php file kept as .php under uploads would run on a server that ignores
 * the .htaccess deny. Snapshot_Store::prune() deletes that directory with its
 * snapshot, so the backup lives exactly as long as the undo point.
 *
 * The snapshot data is { path: install-relative path, existed: bool,
 * unchecked: bool }. Restoring writes the backup back byte for byte, or
 * removes the file when the write created it. The path is resolved again
 * through Filesystem_Guard on restore, so it is still confined to the
 * install and protected files are still refused. A .php restore goes
 * through Php_Edit_Guard like a .php write: the bytes must parse, the site
 * is checked before and after, and a restore that breaks the site is
 * reverted. A write made with unchecked:true is restored unchecked too.
 */
class File_Write_Snapshot
{
    public const TYPE = 'file_write';

    /** The pre-write bytes, inside the operation's backup directory. */
    public const BACKUP_FILE = 'before.bak';

    /** The current bytes, kept while a .php restore runs so a fatal can be reverted. */
    private const REVERT_FILE = 'revert.bak';

    /** Whether the most recent restore() put the file back. */
    private static bool $last_restored = true;

    /** The empty shape Safe_Mutation persists; the write merges its data in. */
    public static function capture(string $operation_id): array
    {
        return [
            'object_type' => self::TYPE,
            'object_id'   => $operation_id,
            'data'        => ['path' => '', 'existed' => false, 'unchecked' => false],
        ];
    }

    /** Absolute path of an operation's backup copy. */
    public static function backup_path(string $operation_id): string
    {
        return File_Backup::operation_dir($operation_id) . '/' . self::BACKUP_FILE;
    }

    /**
     * Copy $abs into the operation's backup directory. Returns the backup's
     * absolute path, '' when $abs does not exist (a create has nothing to
     * back up), or null when the copy failed.
     */
    public static function backup(string $operation_id, string $abs): ?string
    {
        if (! is_file($abs)) {
            return '';
        }
        $dir = File_Backup::operation_dir($operation_id);
        if (! wp_mkdir_p($dir)) {
            return null;
        }
        File_Backup::protect_dir($dir);
        $dest = self::backup_path($operation_id);
        if (! Core_Files_Snapshot::filesystem()->copy($abs, $dest, true) || ! is_file($dest)) {
            return null;
        }
        return $dest;
    }

    /** Whether the most recent restore() put the file back. Read by Rollback_Service. */
    public static function last_restored(): bool
    {
        return self::$last_restored;
    }

    /**
     * Put the file back as it was before the write.
     *
     * @return string[] Warnings; non-empty when the file was not restored.
     */
    public static function restore(array $snapshot): array
    {
        self::$last_restored = false;
        $data         = (array) ($snapshot['data'] ?? []);
        $rel          = (string) ($data['path'] ?? '');
        $existed      = ! empty($data['existed']);
        $unchecked    = ! empty($data['unchecked']);
        $operation_id = (string) ($snapshot['object_id'] ?? '');
        $backup       = self::backup_path($operation_id);
        $backup_rel   = Filesystem_Guard::to_relative($backup);

        $abs = '' === $rel ? new \WP_Error('invalid_path', 'no path was recorded') : Filesystem_Guard::resolve_path($rel);
        if (is_wp_error($abs)) {
            return [sprintf('%s was not restored: %s', $rel, $abs->get_error_message())];
        }
        if (Filesystem_Guard::is_protected($abs) || is_link($abs)) {
            return [sprintf('%s was not restored: it is a protected file or a symlink.', $rel)];
        }

        $bytes = null;
        if ($existed) {
            $bytes = is_file($backup) ? file_get_contents($backup) : false;
            if (false === $bytes) {
                return [sprintf('%s was not restored: its backup is no longer available.', $rel)];
            }
        }

        if (Php_Edit_Guard::applies($abs)) {
            return self::restore_php($abs, $rel, $bytes, $unchecked, $operation_id, $backup_rel);
        }

        $fs = Core_Files_Snapshot::filesystem();
        if (null === $bytes) {
            if (is_file($abs) && ! $fs->delete($abs)) {
                return [sprintf('%s could not be removed.', $rel)];
            }
            self::$last_restored = true;
            return [];
        }
        wp_mkdir_p(dirname($abs));
        if (! $fs->put_contents($abs, $bytes, Core_Files_Snapshot::mode($fs, $abs))) {
            return [sprintf('%s could not be written back; its backup is %s.', $rel, $backup_rel)];
        }
        self::$last_restored = true;
        return [];
    }

    /**
     * A .php restore: Php_Edit_Guard writes the backup bytes back (or
     * removes a created file) with the syntax check, the lock and the site
     * check, and reverts the restore when the site breaks.
     *
     * @return string[]
     */
    private static function restore_php(string $abs, string $rel, ?string $bytes, bool $unchecked, string $operation_id, string $backup_rel): array
    {
        $revert = '';
        if (is_file($abs)) {
            $dir    = File_Backup::operation_dir($operation_id);
            $revert = $dir . '/' . self::REVERT_FILE;
            if (wp_mkdir_p($dir)) {
                File_Backup::protect_dir($dir);
            }
            if (! Core_Files_Snapshot::filesystem()->copy($abs, $revert, true)) {
                return [sprintf('%s was not restored: its current version could not be backed up first.', $rel)];
            }
        }
        try {
            Php_Edit_Guard::write($abs, $bytes, $unchecked, $revert);
        } catch (\RuntimeException $e) {
            $manual = null === $bytes
                ? sprintf('delete %s by hand', $rel)
                : sprintf('copy %s over %s by hand', $backup_rel, $rel);
            return [sprintf('%s was not restored: %s To restore it anyway, %s.', $rel, $e->getMessage(), $manual)];
        } finally {
            if ('' !== $revert && is_file($revert)) {
                Core_Files_Snapshot::filesystem()->delete($revert);
            }
        }
        self::$last_restored = true;
        return [];
    }
}
