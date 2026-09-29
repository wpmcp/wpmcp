<?php

namespace WPMCP\Tools\Security;

use WPMCP\Safety\Core_Files_Snapshot;
use WPMCP\Safety\File_Backup;
use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Reinstalls the core files the integrity audit flags (incident-response
 * reinstall-core-files, issue #382).
 *
 * The flagged set is recomputed here with Integrity_Audit::diff() against the
 * official checksums (fetched through wp_safe_remote_get(), like the audit),
 * so only files the audit would report as modified or missing are touched.
 * Replacement bytes come from the official no-content package for the running
 * version, downloaded with core's download_url() (itself wp_safe_remote_get()),
 * and each file is written only when its packaged bytes match the official
 * checksum; a mismatch is skipped and reported, never written. Nothing under
 * wp-content is ever read from the package or written.
 *
 * The current bytes of every file about to be replaced are archived first
 * (Core_Files_Snapshot) and the write runs through Safe_Mutation, so the
 * reinstall is undone by rollback-operation like any other write.
 */
class Core_File_Repair
{
    private const PACKAGE_URL     = 'https://downloads.wordpress.org/release/wordpress-%s-no-content.zip';
    private const PACKAGE_TIMEOUT = 300;
    private const PACKAGE_PREFIX  = 'wordpress/';

    /** @var callable(): array<string,string> */
    private $checksums;

    /** @var callable(string): string */
    private $package;

    /** Whether the package file is this class's own download, to delete afterwards. */
    private bool $owns_package;

    public function __construct(?callable $checksums = null, ?callable $package = null)
    {
        $this->checksums    = $checksums ?? static fn (): array => (new Integrity_Audit())->checksums();
        $this->owns_package = null === $package;
        $this->package      = $package ?? [self::class, 'download_package'];
    }

    /** Point reads and writes at a fixture root instead of ABSPATH. Tests only. */
    public static function set_root_for_tests(?string $root): void
    {
        Core_Files_Snapshot::set_root_for_tests($root);
    }

    /**
     * Repair the flagged core files, or only those in $paths.
     *
     * @param string[] $paths Core-relative paths; empty means every flagged file.
     * @param array    $args  The tool call's arguments, for the snapshot.
     * @return array{ repaired: string[], skipped: array<int, array{path: string, reason: string}>, operation_id: ?string, recoverable: bool }
     */
    public function repair(array $paths, array $args): array
    {
        $checksums = ($this->checksums)();
        if (! is_array($checksums) || [] === $checksums) {
            throw new \RuntimeException('The official core checksums could not be fetched (offline or api.wordpress.org unreachable), so no core file was changed.');
        }

        $flagged = $this->flagged($checksums);
        $skipped = [];
        $targets = $flagged;
        if ([] !== $paths) {
            $targets = [];
            foreach ($paths as $path) {
                $path = (string) $path;
                if (! Core_Files_Snapshot::is_core_path($path)) {
                    $skipped[] = ['path' => $path, 'reason' => 'not_a_core_path'];
                } elseif (! isset($flagged[ $path ])) {
                    $skipped[] = ['path' => $path, 'reason' => 'not_flagged'];
                } else {
                    $targets[ $path ] = $flagged[ $path ];
                }
            }
        }

        if ([] === $targets) {
            return ['repaired' => [], 'skipped' => $skipped, 'operation_id' => null, 'recoverable' => true];
        }

        $verified = $this->verified_bytes($targets, $skipped);
        if ([] === $verified) {
            return ['repaired' => [], 'skipped' => $skipped, 'operation_id' => null, 'recoverable' => true];
        }

        $root    = Core_Files_Snapshot::root();
        $existed = [];
        foreach (array_keys($verified) as $path) {
            $existed[ $path ] = is_file($root . $path);
        }

        $operation_id = wp_generate_uuid4();
        if (! Core_Files_Snapshot::backup($operation_id, array_keys(array_filter($existed)))) {
            throw new \RuntimeException('The core files about to be replaced could not be backed up, so none was changed.');
        }

        $started = false;
        try {
            $out = Safe_Mutation::run(
                [
                    'object_type'         => Core_Files_Snapshot::TYPE,
                    'object_id'           => $operation_id,
                    'session_id'          => (string) ($args['session_id'] ?? 'default'),
                    'tool_name'           => 'incident-response',
                    'args'                => $args,
                    'operation_id'        => $operation_id,
                    'extra_snapshot_data' => ['files' => $existed],
                ],
                function () use ($verified, $root, &$started): bool {
                    $started = true;
                    return self::write($verified, $root);
                },
                static fn ($result): bool => true === $result
            );
        } catch (\Throwable $e) {
            if (! $started) {
                File_Backup::delete_backup_dir($operation_id);
                throw new \RuntimeException('The undo point for the core files could not be saved, so none was changed.');
            }
            throw new \RuntimeException('Writing the core files failed and every file was put back as it was.');
        }

        return [
            'repaired'     => array_keys($verified),
            'skipped'      => $skipped,
            'operation_id' => $out['operation_id'],
            'recoverable'  => true,
        ];
    }

    /**
     * Core files the audit reports as modified or missing, path => md5.
     * wp-content and anything that is not a plain core path never qualify.
     *
     * @param array<string,string> $checksums
     * @return array<string,string>
     */
    private function flagged(array $checksums): array
    {
        $core = [];
        foreach ($checksums as $path => $md5) {
            if (is_string($md5) && Core_Files_Snapshot::is_core_path((string) $path)) {
                $core[ (string) $path ] = $md5;
            }
        }

        $root   = Core_Files_Snapshot::root();
        $hasher = static function (string $relative) use ($root): ?string {
            $abs = $root . $relative;
            if (! is_file($abs)) {
                return null;
            }
            $md5 = md5_file($abs);
            return false === $md5 ? null : $md5;
        };

        $flagged = [];
        foreach ((new Integrity_Audit())->diff($core, $hasher) as $finding) {
            if (in_array($finding['id'] ?? '', ['integrity_modified', 'integrity_missing'], true)) {
                $path             = (string) $finding['value'];
                $flagged[ $path ] = $core[ $path ];
            }
        }
        ksort($flagged);
        return $flagged;
    }

    /**
     * Packaged bytes for each target whose md5 matches the official checksum.
     *
     * @param array<string,string> $targets path => expected md5
     * @param array                $skipped appended to for every file left alone
     * @return array<string,string> path => bytes
     */
    private function verified_bytes(array $targets, array &$skipped): array
    {
        global $wp_version;
        $zip_path = (string) ($this->package)((string) $wp_version);

        try {
            $zip = class_exists('ZipArchive') ? new \ZipArchive() : null;
            if ('' === $zip_path || ! is_file($zip_path) || null === $zip || true !== $zip->open($zip_path, \ZipArchive::RDONLY)) {
                throw new \RuntimeException(sprintf(
                    'The official WordPress %s package could not be downloaded or opened, so no core file was changed.',
                    esc_html((string) $wp_version)
                ));
            }

            $verified = [];
            $root     = Core_Files_Snapshot::root();
            foreach ($targets as $path => $expected) {
                $bytes = $zip->getFromName(self::PACKAGE_PREFIX . $path);
                if (false === $bytes) {
                    $skipped[] = ['path' => $path, 'reason' => 'not_in_package'];
                } elseif (! hash_equals(strtolower($expected), md5($bytes))) {
                    $skipped[] = ['path' => $path, 'reason' => 'package_checksum_mismatch'];
                } elseif (is_link($root . $path)) {
                    $skipped[] = ['path' => $path, 'reason' => 'symlink'];
                } else {
                    $verified[ $path ] = $bytes;
                }
            }
            $zip->close();
            return $verified;
        } finally {
            if ($this->owns_package && '' !== $zip_path && is_file($zip_path)) {
                wp_delete_file($zip_path);
            }
        }
    }

    /** @param array<string,string> $verified path => bytes */
    private static function write(array $verified, string $root): bool
    {
        $fs = Core_Files_Snapshot::filesystem();
        foreach ($verified as $path => $bytes) {
            $abs = $root . $path;
            if (! wp_mkdir_p(dirname($abs)) || ! $fs->put_contents($abs, $bytes, Core_Files_Snapshot::mode($fs, $abs))) {
                return false;
            }
        }
        return true;
    }

    /**
     * Download the official no-content package for $version to a temp file.
     * Development builds have no published package, so they get ''.
     */
    public static function download_package(string $version): string
    {
        if (1 !== preg_match('/^\d+\.\d+(\.\d+)?$/', $version)) {
            return '';
        }
        if (! function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $file = download_url(sprintf(self::PACKAGE_URL, $version), self::PACKAGE_TIMEOUT);
        return is_wp_error($file) ? '' : (string) $file;
    }
}
