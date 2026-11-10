<?php

namespace WPMCP\Tools\Backup;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Builds a portable full-site archive: a zip containing a SQL dump of the
 * database (db.sql), a manifest describing the origin site, and, for a full
 * backup, the wp-content files the site needs to look like itself somewhere
 * else.
 *
 * The manifest is the part that makes this a migration format rather than a
 * pile of bytes. It records the origin site_url/home_url, table prefix,
 * WordPress/PHP versions and multisite flag, so a restore can refuse an
 * incompatible target and a migration knows exactly which URL to rewrite to
 * (see Url_Rewriter). Without it, "restore anywhere" is a guess.
 *
 * The database is dumped to a file first and added to the zip by path, never
 * assembled in memory: see Db_Dumper for why that constraint drives the
 * design.
 *
 * Excluded by construction: this plugin's own backup, export and snapshot
 * directories. A backup that archives previous backups grows quadratically
 * and, on the second run, tries to read the file it is currently writing.
 * Caches, VCS metadata and node_modules are excluded too: they are
 * regenerable, and on a real site they are most of the bytes.
 */
class Site_Archive_Builder
{
    /**
     * Directory names skipped anywhere in the tree. Matched on the directory
     * name itself, not a path fragment, so an unrelated user directory that
     * merely contains the word "cache" in a longer name is still archived.
     */
    public const EXCLUDED_DIRS = [
        Site_Backup_Dir::DIR_NAME,
        'wpmcp-exports',
        '.wpmcp-backups',
        'cache',
        'node_modules',
        '.git',
        '.svn',
        'upgrade',
        'upgrade-temp-backup',
        'wp-personal-data-exports',
        // Restore staging and the wp-content trees a files restore replaced
        // (Restore_Files::DIR_NAME): archiving them would nest every
        // previous site inside the next backup.
        'wpmcp-restore',
    ];

    /**
     * File extensions skipped OUTSIDE the uploads directory.
     *
     * Deliberately not applied inside uploads. These extensions identify
     * regenerable build output when they turn up beside a plugin or a cache,
     * but inside uploads the very same extensions are user content: a
     * WooCommerce store's downloadable products are .zip files in the Media
     * Library, and so are exports and backups a site owner deliberately
     * uploaded. Excluding them tree-wide meant a store migrated with every
     * downloadable product missing while file_count still reported success -
     * a loss discovered by the first customer who tried to download, not by
     * anyone checking the archive.
     */
    public const EXCLUDED_EXTENSIONS = ['log', 'zip', 'gz', 'tar', 'sql'];

    /** Extensions still skipped inside uploads. See should_archive(). */
    public const UPLOADS_EXCLUDED_EXTENSIONS = ['log'];

    /**
     * Whether one file belongs in the archive.
     *
     * Public because it is the rule that decides whether a user's data
     * survives a migration, which makes it worth asserting directly rather
     * than inferring from the contents of a built zip.
     */
    public static function should_archive(string $path): bool
    {
        $normalised = str_replace('\\', '/', $path);

        foreach (explode('/', trim(dirname($normalised), '/')) as $segment) {
            if (in_array($segment, self::EXCLUDED_DIRS, true)) {
                return false;
            }
        }

        $uploads  = wp_upload_dir();
        $basedir  = isset($uploads['basedir']) ? str_replace('\\', '/', (string) $uploads['basedir']) : '';
        $in_uploads = '' !== $basedir && str_starts_with($normalised, rtrim($basedir, '/') . '/');

        $extension = strtolower(pathinfo($normalised, PATHINFO_EXTENSION));

        if ($in_uploads) {
            // Inside uploads almost everything is user content, but logs are
            // not: they are machine-generated, regenerable, frequently the
            // largest thing in the directory (WooCommerce's wc-logs), and
            // they carry order and customer detail that has no business
            // being copied into an archive someone may hand to a host.
            return ! in_array($extension, self::UPLOADS_EXCLUDED_EXTENSIONS, true);
        }

        return ! in_array($extension, self::EXCLUDED_EXTENSIONS, true);
    }

    private Db_Dumper $dumper;

    public function __construct(?Db_Dumper $dumper = null)
    {
        $this->dumper = $dumper ?? new Db_Dumper();
    }

    /**
     * Build an archive.
     *
     * $scope selects what goes in: 'all' (database + wp-content), 'database'
     * (dump only), 'files' (wp-content only), or 'uploads' (the uploads
     * directory only). A database-only archive is the fast, small default
     * for pre-change safety; a full one is what you carry to another host.
     *
     * The archive is written to $target . PARTIAL_SUFFIX and renamed to
     * $target only once ZipArchive::close() has succeeded, so a .zip in the
     * backup directory is always a complete archive and a .part is never one
     * (issue #771). A request killed mid-build (max_execution_time, the
     * memory limit) skips every catch block, so the rename is the only
     * guarantee a half-written file cannot be mistaken for a backup; the
     * .part it leaves is removed by discard_partial() when the job is found
     * dead.
     *
     * $progress, when given, is called with a progress record (see
     * describe_progress()) as the build advances: once at the start (so the
     * partial path is known before any bytes are written), for every chunk
     * of the dump, for every file added and, while close() writes the
     * archive, at ZipArchive's progress rate. Run_Backup_Job uses it to
     * heartbeat the job; a callback that throws stops the build, which
     * cleans up and rethrows.
     *
     * @param callable(array):void|null $progress
     * @return array{
     *     file: string, size: int, scope: string, manifest: array,
     *     tables: array<string,int>, file_count: int
     * }
     */
    public function build(string $scope = 'all', ?string $target = null, ?callable $progress = null): array
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The PHP zip extension is required to build a site backup archive.');
        }

        $scope = in_array($scope, ['all', 'database', 'files', 'uploads'], true) ? $scope : 'all';

        $dir = Site_Backup_Dir::path();
        Site_Backup_Dir::protect($dir);

        $target ??= $this->archive_path($dir, $scope);
        $partial = $target . self::PARTIAL_SUFFIX;

        // The scratch path is decided BEFORE the dump runs, not returned from
        // it: a dump that throws part-way (a full disk is the realistic case)
        // has already created the file, and a path only known on success
        // would leak that partial .sql into the backup directory on every
        // failure.
        $sql_path = in_array($scope, ['all', 'database'], true)
            ? $dir . '/db-' . wp_generate_password(12, false) . '.sql'
            : null;

        $state = [
            'phase'        => 'start',
            'partial_file' => $partial,
            'scratch_file' => $sql_path,
            'dump_bytes'   => 0,
            'files_added'  => 0,
            'percent'      => 0,
        ];
        $notify = static function (array $changes) use (&$state, $progress): void {
            $state = array_merge($state, $changes);
            if (null !== $progress) {
                $progress($state);
            }
        };

        $zip = new \ZipArchive();
        if (true !== $zip->open($partial, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            throw new \RuntimeException('Could not open the backup archive for writing.');
        }

        $dump_result = ['tables' => [], 'blob_tables' => [], 'bytes' => 0];

        $closing = false;
        try {
            $notify([]);

            if (null !== $sql_path) {
                $notify(['phase' => 'dump']);
                $dump_result = $this->write_dump($sql_path, $notify);
                $zip->addFile($sql_path, 'db.sql');
            }

            $file_count = 0;
            if (in_array($scope, ['all', 'files', 'uploads'], true)) {
                $notify(['phase' => 'files']);
                $file_count = $this->add_files($zip, $scope, $notify);
            }

            $manifest = $this->manifest($scope, $dump_result, $file_count);
            $zip->addFromString(
                'manifest.json',
                (string) wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );

            // close() is where the bytes move: every addFile() above only
            // recorded a path. On a 1 GB wp-content it runs for minutes, so
            // the heartbeat has to keep coming from inside it. An exception
            // from the callback is held and rethrown after close() returns
            // rather than thrown through libzip.
            $notify(['phase' => 'finalise']);
            $pending = null;
            if (method_exists($zip, 'registerProgressCallback')) {
                $zip->registerProgressCallback(0.02, static function (float $rate) use ($notify, &$pending): void {
                    if (null !== $pending) {
                        return;
                    }
                    try {
                        $notify(['percent' => (int) round($rate * 100)]);
                    } catch (\Throwable $e) {
                        $pending = $e;
                    }
                });
            }

            // A failed close() still frees the archive, and a second close()
            // on it throws a ValueError that would skip the cleanup below and
            // hide the real error, so it is attempted once only.
            $closing = true;
            $closed  = $zip->close();
            if (null !== $pending) {
                throw $pending;
            }
            if (true !== $closed) {
                throw new \RuntimeException('The backup archive could not be finalised.');
            }

            // Only a complete archive carries the .zip name.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- WP_Filesystem is not loaded in a cron request; the rename is within the plugin's own backup directory.
            if (! rename($partial, $target)) {
                throw new \RuntimeException('The finished backup archive could not be moved into place.');
            }
        } catch (\Throwable $e) {
            // Leave no half-written archive behind: a truncated zip that
            // looks like a backup is more dangerous than an obvious failure,
            // because it is the file someone reaches for in an emergency.
            if (! $closing) {
                @$zip->close();
            }
            self::discard_partial($state);
            if (is_file($target)) {
                wp_delete_file($target);
            }
            throw $e;
        }

        // The dump file only ever existed to be added by path.
        if (null !== $sql_path && is_file($sql_path)) {
            wp_delete_file($sql_path);
        }

        clearstatcache(true, $target);

        return [
            'file'       => $target,
            'size'       => (int) filesize($target),
            'scope'      => $scope,
            'manifest'   => $manifest,
            'tables'     => $dump_result['tables'],
            'file_count' => $file_count,
        ];
    }

    /** Suffix of an archive still being written. See build(). */
    public const PARTIAL_SUFFIX = '.part';

    /**
     * Remove what an unfinished build left in the backup directory: the
     * partial archive, libzip's temporary output beside it (it writes
     * `<partial>.XXXXXX` during close() and renames on success) and the
     * scratch dump. $progress is the record build() reported, as stored on
     * the job; every path in it is checked to be inside the backup
     * directory, because the record comes out of an option and a tampered
     * one must not be able to delete anything else.
     */
    public static function discard_partial(array $progress): void
    {
        $root = realpath(Site_Backup_Dir::path());
        if (false === $root) {
            return;
        }
        $root = trailingslashit($root);

        $inside = static function (string $path) use ($root): bool {
            $dir = realpath(dirname($path));
            return false !== $dir && trailingslashit($dir) === $root;
        };

        foreach (['partial_file', 'scratch_file'] as $key) {
            $path = $progress[ $key ] ?? null;
            if (! is_string($path) || '' === $path || ! $inside($path)) {
                continue;
            }
            if (is_file($path)) {
                wp_delete_file($path);
            }
            if ('partial_file' === $key) {
                foreach (glob($path . '.??????') ?: [] as $temp) {
                    if (is_file($temp)) {
                        wp_delete_file($temp);
                    }
                }
            }
        }
    }

    /** One line saying how far a build got, for a job's error or status. */
    public static function describe_progress(array $progress): string
    {
        $phase = (string) ($progress['phase'] ?? 'start');

        switch ($phase) {
            case 'dump':
                return sprintf('dumping the database, %s written', size_format((int) ($progress['dump_bytes'] ?? 0)));
            case 'files':
                return sprintf('adding files, %d added', (int) ($progress['files_added'] ?? 0));
            case 'finalise':
                return sprintf(
                    'writing the archive, %d%% done, %d files',
                    (int) ($progress['percent'] ?? 0),
                    (int) ($progress['files_added'] ?? 0)
                );
            default:
                return 'starting';
        }
    }

    /** Bytes buffered in memory before an append to the scratch dump file. */
    public const FLUSH_BYTES = 1048576;

    /**
     * Stream the database dump to a scratch file next to the archive.
     *
     * Written with buffered file_put_contents(..., FILE_APPEND) rather than
     * an fopen/fwrite handle: WordPress.WP.AlternativeFunctions is promoted
     * to an error by the plugin directory's review ruleset and forbids the
     * handle functions, while explicitly excluding file_put_contents. The
     * 1MB buffer is what keeps that from meaning one filesystem call per
     * generated statement.
     *
     * @param callable(array):void $notify progress sink (see build())
     * @return array{tables: array<string,int>, blob_tables: string[], bytes: int}
     */
    private function write_dump(string $path, callable $notify): array
    {
        $buffer = '';
        $bytes  = 0;

        $flush = static function (string $chunk) use ($path): void {
            if ('' === $chunk) {
                return;
            }
            // A failed write mid-dump means a disk quota or a full volume.
            // Continuing would produce a SQL file that is syntactically valid
            // up to the truncation point and silently missing every row after
            // it, which is the worst possible failure mode for a backup.
            if (false === file_put_contents($path, $chunk, FILE_APPEND)) {
                throw new \RuntimeException('Writing the database dump failed (out of disk space?): ' . esc_html($path));
            }
        };

        // Start from an empty file: FILE_APPEND would otherwise extend a
        // stale scratch file left by an interrupted earlier run.
        if (false === file_put_contents($path, '')) {
            throw new \RuntimeException('Could not create a temporary file for the database dump.');
        }

        $result = $this->dumper->dump(
            static function (string $sql) use (&$buffer, &$bytes, $flush, $notify): void {
                $buffer .= $sql;
                $bytes  += strlen($sql);
                if (strlen($buffer) >= self::FLUSH_BYTES) {
                    $flush($buffer);
                    $buffer = '';
                }
                // Every statement, not every flush: a heartbeat that waited
                // for a megabyte would be silent through a slow table.
                $notify(['dump_bytes' => $bytes]);
            }
        );

        $flush($buffer);

        return $result;
    }

    /**
     * Add the site's files to the archive under a wp-content/ prefix, and
     * return how many were added.
     *
     * @param callable(array):void $notify progress sink (see build())
     */
    private function add_files(\ZipArchive $zip, string $scope, callable $notify): int
    {
        $uploads = wp_upload_dir();
        $root    = 'uploads' === $scope
            ? (string) $uploads['basedir']
            : (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : ABSPATH . 'wp-content');

        $root = rtrim((string) $root, '/\\');
        if ('' === $root || ! is_dir($root)) {
            return 0;
        }

        // The archive path stays relative to wp-content even for an
        // uploads-only backup, so both scopes restore through one code path.
        $prefix = 'uploads' === $scope
            ? 'wp-content/' . basename($root)
            : 'wp-content';

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $current): bool {
                    if ($current->isDir()) {
                        return ! in_array($current->getFilename(), self::EXCLUDED_DIRS, true);
                    }
                    return self::should_archive($current->getPathname());
                }
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        $count = 0;
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile() || $file->isLink()) {
                // Symlinks are skipped rather than followed: following them
                // can walk straight out of wp-content (or into a loop) and
                // pull unrelated server files into an archive that the user
                // may hand to someone else.
                continue;
            }

            $absolute = $file->getPathname();
            $relative = ltrim(substr($absolute, strlen($root)), '/\\');

            if (! $file->isReadable()) {
                continue;
            }

            if ($zip->addFile($absolute, $prefix . '/' . str_replace('\\', '/', $relative))) {
                $count++;
                $notify(['files_added' => $count]);
            }
        }

        return $count;
    }

    /**
     * The origin description a restore or migration reads before touching
     * anything.
     */
    private function manifest(string $scope, array $dump_result, int $file_count): array
    {
        global $wpdb, $wp_version;

        return [
            'format'        => 'wpmcp-site-backup',
            'format_version' => 1,
            'created_at'    => gmdate('c'),
            'scope'         => $scope,
            'site'          => [
                'site_url'     => get_site_url(),
                'home_url'     => get_home_url(),
                'table_prefix' => $wpdb->prefix,
                'base_prefix'  => $wpdb->base_prefix,
                'multisite'    => is_multisite(),
                'charset'      => $wpdb->charset,
                'locale'       => get_locale(),
            ],
            'versions'      => [
                'wordpress' => $wp_version ?? '',
                'php'       => PHP_VERSION,
                'plugin'    => defined('WPMCP_VERSION') ? WPMCP_VERSION : '0.0.0',
            ],
            'database'      => [
                'tables'      => $dump_result['tables'],
                'row_count'   => array_sum($dump_result['tables']),
                'blob_tables' => $dump_result['blob_tables'],
                'bytes'       => $dump_result['bytes'],
                // "%" in values is written as itself (see Db_Dumper); older
                // archives lack this key and may hold wpdb's placeholder.
                'percent'     => 'literal',
            ],
            'files'         => [
                'count' => $file_count,
            ],
        ];
    }

    /**
     * A dated archive name with a random suffix. The suffix is not
     * decoration: on a server that ignores the directory's .htaccess, a
     * predictable name like backup-2026-08-13.zip is directly fetchable by
     * anyone who guesses it, and the archive contains every password hash on
     * the site.
     */
    private function archive_path(string $dir, string $scope): string
    {
        return $dir . '/wpmcp-' . $scope . '-' . gmdate('Ymd-His') . '-' . wp_generate_password(8, false) . '.zip';
    }
}
