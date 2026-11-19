<?php

namespace WPMCP\Tools\Security;

use WPMCP\Safety\Core_Files_Snapshot;
use WPMCP\Safety\File_Backup;
use WPMCP\Tools\Filesystem\Delete_File;
use WPMCP\Tools\Filesystem\File_Write_Operation;
use WPMCP\Tools\Filesystem\Filesystem_Guard;
use WPMCP\Tools\Filesystem\Php_Edit_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The quarantine behind incident-response quarantine-file, list-quarantine,
 * restore-quarantined-file and purge-quarantined-file (issue #844): the step
 * between "scan-security found it" and "delete-file removed it".
 *
 * Quarantining copies the file's bytes into DIR under uploads, under a
 * random id with a non-executable extension (no server runs or maps
 * .quarantined to PHP, and the id cannot be guessed), in a folder with a
 * deny .htaccess and an empty index.php, then removes the original as an
 * undoable operation (File_Write_Operation, the delete-file path), so
 * rollback-operation puts it back like any other file change. A .php file is
 * removed through Php_Edit_Guard: if taking it away breaks the front end or
 * admin, the removal is reverted and the call says so.
 *
 * Each entry keeps the original path, sha256, size, mtime, the finding that
 * led to it, who and when, in the OPTION list. The held copy outlives a
 * restore on purpose: a restore can itself be rolled back, and the copy is
 * what makes that safe. Only purge removes it, with confirm:true. An entry's
 * state is read live from disk: quarantined (nothing at the path), restored
 * (the same bytes are back) or replaced (other bytes are there now).
 *
 * Refused: wp-config.php and every other protected file (Filesystem_Guard),
 * symlinks, WP MCP's own files, the quarantine and backup folders, and
 * WordPress core files, which reinstall-core-files replaces from the official
 * package instead. A core file is one listed in the official checksums; a
 * path in wp-admin, wp-includes or the install root is checked against them,
 * and refused when they cannot be fetched.
 */
class File_Quarantine
{
    /** Directory directly under uploads (Data_Registry::UPLOADS_DIRS). */
    public const DIR = 'wpmcp-quarantine';

    /** The entries, keyed by id. Not autoloaded. */
    public const OPTION = 'wpmcp_quarantine';

    private const EXTENSION = '.quarantined';

    private const REASON_MAX = 200;

    /** @var callable(): array<string,string> */
    private $checksums;

    public function __construct(?callable $checksums = null)
    {
        $this->checksums = $checksums ?? static fn (): array => (new Integrity_Audit())->checksums();
    }

    /** Absolute path of the quarantine folder for the current site. */
    public static function directory(): string
    {
        $uploads = wp_upload_dir(null, false);
        return trailingslashit((string) $uploads['basedir']) . self::DIR;
    }

    /** Absolute path of an entry's held copy. */
    public static function stored_path(string $id): string
    {
        return self::directory() . '/' . $id . self::EXTENSION;
    }

    // -----------------------------------------------------------------
    // actions
    // -----------------------------------------------------------------

    public function quarantine(array $args): array
    {
        self::require_writes('quarantine-file');

        $abs = $this->resolve((string) ($args['path'] ?? ''));
        if (! is_file($abs)) {
            throw new \RuntimeException('File not found.');
        }
        $rel = Filesystem_Guard::to_relative($abs);
        $this->refuse_core_file($rel);

        $sha  = (string) hash_file('sha256', $abs);
        $size = (int) filesize($abs);
        $mtime = (int) filemtime($abs);
        $id    = wp_generate_uuid4();

        $dir = self::directory();
        if (! wp_mkdir_p($dir)) {
            throw new \RuntimeException('The quarantine folder could not be created, so nothing was changed.');
        }
        File_Backup::protect_dir($dir);
        $stored = self::stored_path($id);
        $fs     = Core_Files_Snapshot::filesystem();
        if (! $fs->copy($abs, $stored, true) || ! is_file($stored) || hash_file('sha256', $stored) !== $sha) {
            $fs->delete($stored);
            throw new \RuntimeException('The file could not be copied into quarantine, so nothing was changed.');
        }

        $unchecked = ! empty($args['unchecked']);
        try {
            $done = File_Write_Operation::run(
                'incident-response',
                $abs,
                $args,
                static function (string $backup) use ($abs, $unchecked): bool {
                    if (Php_Edit_Guard::applies($abs)) {
                        Php_Edit_Guard::write($abs, null, $unchecked, $backup);
                        return true;
                    }
                    wp_delete_file($abs);
                    if (is_file($abs)) {
                        throw new \RuntimeException('Could not remove the file (check permissions).');
                    }
                    return true;
                }
            );
        } catch (\Throwable $e) {
            if (! is_file($abs)) {
                // The original is gone even though the call failed: keep the
                // held copy and record it, so nothing is lost.
                $this->save_entry($id, $rel, $sha, $size, $mtime, $args, '');
            } else {
                $fs->delete($stored);
            }
            throw new \RuntimeException(esc_html($e->getMessage()));
        }

        $entry = $this->save_entry($id, $rel, $sha, $size, $mtime, $args, (string) $done['operation_id']);
        Filesystem_Guard::log('quarantine', $rel);

        return [
            'entry'        => $this->describe($entry),
            'operation_id' => $done['operation_id'],
            'recoverable'  => true,
            'note'         => 'Undo with rollback-operation or restore-quarantined-file; check the site still works.',
        ];
    }

    public function list(): array
    {
        $rows = [];
        foreach (self::entries() as $entry) {
            $rows[] = $this->describe($entry);
        }
        usort($rows, static fn (array $a, array $b): int => $b['quarantined_at'] <=> $a['quarantined_at']);
        return ['entries' => $rows, 'count' => count($rows)];
    }

    public function restore(array $args): array
    {
        self::require_writes('restore-quarantined-file');

        $entry  = $this->entry((string) ($args['id'] ?? ''));
        $stored = self::stored_path($entry['id']);
        $bytes  = is_file($stored) ? file_get_contents($stored) : false;
        if (false === $bytes || ! hash_equals($entry['sha256'], hash('sha256', $bytes))) {
            throw new \RuntimeException('The quarantined copy is missing or no longer matches its recorded hash, so nothing was restored.');
        }

        $abs = $this->resolve($entry['path']);
        if (is_file($abs)) {
            if (hash_equals($entry['sha256'], (string) hash_file('sha256', $abs))) {
                return ['id' => $entry['id'], 'path' => $entry['path'], 'restored' => true, 'changed' => false, 'operation_id' => null];
            }
            if (true !== ($args['overwrite'] ?? null)) {
                throw new \RuntimeException('Another file now exists at the original path; pass overwrite:true to replace it (undoable with rollback-operation).');
            }
        }

        $unchecked = ! empty($args['unchecked']);
        $done      = File_Write_Operation::run(
            'incident-response',
            $abs,
            $args,
            static function (string $backup) use ($abs, $bytes, $unchecked): bool {
                if (Php_Edit_Guard::applies($abs)) {
                    Php_Edit_Guard::write($abs, $bytes, $unchecked, $backup);
                    return true;
                }
                if (! wp_mkdir_p(dirname($abs)) || false === file_put_contents($abs, $bytes)) {
                    throw new \RuntimeException('Could not write the file back (check permissions).');
                }
                return true;
            }
        );
        Filesystem_Guard::log('restore-quarantined', $entry['path']);

        return [
            'id'           => $entry['id'],
            'path'         => $entry['path'],
            'restored'     => true,
            'changed'      => true,
            'operation_id' => $done['operation_id'],
            'recoverable'  => true,
            'note'         => 'The quarantined copy is kept until purge-quarantined-file.',
        ];
    }

    public function purge(array $args): array
    {
        $entry  = $this->entry((string) ($args['id'] ?? ''));
        $stored = self::stored_path($entry['id']);
        if (is_file($stored) && ! Core_Files_Snapshot::filesystem()->delete($stored)) {
            throw new \RuntimeException('The quarantined copy could not be deleted.');
        }

        $all = self::entries();
        unset($all[ $entry['id'] ]);
        update_option(self::OPTION, $all, false);
        Filesystem_Guard::log('purge-quarantined', $entry['path']);

        return ['id' => $entry['id'], 'path' => $entry['path'], 'purged' => true, 'recoverable' => false];
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    private static function require_writes(string $action): void
    {
        if (! Delete_File::is_enabled()) {
            throw new \WPMCP\MCP\Opt_In_Required(sprintf('The incident-response %s action is disabled.', esc_html($action)), 'wpmcp_enable_fs_writes');
        }
        $gate = Filesystem_Guard::writes_allowed();
        if (is_wp_error($gate)) {
            throw new \RuntimeException(esc_html($gate->get_error_message()));
        }
        if (! wp_is_file_mod_allowed('wpmcp_incident_response')) {
            throw new \RuntimeException('File changes are disabled on this site (DISALLOW_FILE_MODS).');
        }
    }

    /** Resolve a path inside the install and refuse what may never be moved. */
    private function resolve(string $path): string
    {
        $abs = Filesystem_Guard::resolve_path($path);
        if (is_wp_error($abs)) {
            throw new \RuntimeException(esc_html($abs->get_error_message()));
        }
        if (Filesystem_Guard::is_protected($abs)) {
            throw new \RuntimeException('This file is protected (wp-config.php, .htaccess or a site backup) and cannot be quarantined.');
        }
        if (is_link($abs)) {
            throw new \RuntimeException('Refusing to quarantine through a symlink.');
        }
        if (Php_Edit_Guard::is_own_file($abs)) {
            throw new \RuntimeException('WP MCP\'s own files cannot be quarantined.');
        }
        $uploads = trailingslashit(wp_normalize_path((string) wp_upload_dir(null, false)['basedir']));
        $normal  = wp_normalize_path($abs);
        foreach ([self::DIR, File_Backup::BACKUP_DIR, Filesystem_Guard::BACKUP_DIR] as $own) {
            if (0 === strpos($normal, $uploads . $own . '/')) {
                throw new \RuntimeException('Files in WP MCP\'s quarantine and backup folders cannot be quarantined.');
            }
        }
        return $abs;
    }

    /** Refuse a WordPress core file; wp-content is never core. */
    private function refuse_core_file(string $rel): void
    {
        $first = strtolower((string) strtok($rel, '/'));
        if ('wp-content' === $first) {
            return;
        }
        $in_core_area = false === strpos($rel, '/') || in_array($first, ['wp-admin', 'wp-includes'], true);
        if (! $in_core_area) {
            return;
        }
        $checksums = ($this->checksums)();
        if (! is_array($checksums) || [] === $checksums) {
            throw new \RuntimeException('The official core checksums could not be fetched, so this path in a core folder could not be confirmed as a non-core file; nothing was changed.');
        }
        if (isset($checksums[ $rel ])) {
            throw new \RuntimeException('This is a WordPress core file. Use incident-response reinstall-core-files to replace it from the official package instead.');
        }
    }

    /** @return array<string, array> */
    private static function entries(): array
    {
        $all = get_option(self::OPTION, []);
        return is_array($all) ? $all : [];
    }

    private function entry(string $id): array
    {
        $all = self::entries();
        if ('' === $id || ! isset($all[ $id ]) || ! wp_is_uuid($id)) {
            throw new \RuntimeException('No quarantine entry has that id; list-quarantine shows them.');
        }
        return $all[ $id ];
    }

    private function save_entry(string $id, string $rel, string $sha, int $size, int $mtime, array $args, string $operation_id): array
    {
        $reason = sanitize_text_field((string) ($args['reason'] ?? ''));
        $entry  = [
            'id'             => $id,
            'path'           => $rel,
            'sha256'         => $sha,
            'size'           => $size,
            'mtime'          => $mtime,
            'reason'         => function_exists('mb_substr') ? mb_substr($reason, 0, self::REASON_MAX) : substr($reason, 0, self::REASON_MAX),
            'quarantined_at' => time(),
            'user_id'        => get_current_user_id(),
            'operation_id'   => $operation_id,
        ];
        $all        = self::entries();
        $all[ $id ] = $entry;
        update_option(self::OPTION, $all, false);
        return $entry;
    }

    /** An entry with its live state. */
    private function describe(array $entry): array
    {
        $abs   = ABSPATH . $entry['path'];
        $state = 'quarantined';
        if (is_file($abs)) {
            $state = hash_equals((string) $entry['sha256'], (string) hash_file('sha256', $abs)) ? 'restored' : 'replaced';
        }
        return $entry + [
            'state'  => $state,
            'stored' => is_file(self::stored_path((string) $entry['id'])),
        ];
    }
}
