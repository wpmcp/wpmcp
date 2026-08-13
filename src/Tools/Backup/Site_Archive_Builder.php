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
    ];

    /** File extensions skipped anywhere in the tree. */
    public const EXCLUDED_EXTENSIONS = ['log', 'zip', 'gz', 'tar', 'sql'];

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
     * @return array{
     *     file: string, size: int, scope: string, manifest: array,
     *     tables: array<string,int>, file_count: int
     * }
     */
    public function build(string $scope = 'all', ?string $target = null): array
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The PHP zip extension is required to build a site backup archive.');
        }

        $scope = in_array($scope, ['all', 'database', 'files', 'uploads'], true) ? $scope : 'all';

        $dir = Site_Backup_Dir::path();
        Site_Backup_Dir::protect($dir);

        $target ??= $this->archive_path($dir, $scope);

        $zip = new \ZipArchive();
        if (true !== $zip->open($target, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            throw new \RuntimeException('Could not open the backup archive for writing.');
        }

        $dump_result = ['tables' => [], 'blob_tables' => [], 'bytes' => 0];

        // The scratch path is decided BEFORE the dump runs, not returned from
        // it: a dump that throws part-way (a full disk is the realistic case)
        // has already created the file, and a path only known on success
        // would leak that partial .sql into the backup directory on every
        // failure.
        $sql_path = in_array($scope, ['all', 'database'], true)
            ? $dir . '/db-' . wp_generate_password(12, false) . '.sql'
            : null;

        try {
            if (null !== $sql_path) {
                $dump_result = $this->write_dump($sql_path);
                $zip->addFile($sql_path, 'db.sql');
            }

            $file_count = 0;
            if (in_array($scope, ['all', 'files', 'uploads'], true)) {
                $file_count = $this->add_files($zip, $scope);
            }

            $manifest = $this->manifest($scope, $dump_result, $file_count);
            $zip->addFromString(
                'manifest.json',
                (string) wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );

            if (true !== $zip->close()) {
                throw new \RuntimeException('The backup archive could not be finalised.');
            }
        } catch (\Throwable $e) {
            // Leave no half-written archive behind: a truncated zip that
            // looks like a backup is more dangerous than an obvious failure,
            // because it is the file someone reaches for in an emergency.
            @$zip->close();
            if (is_file($target)) {
                wp_delete_file($target);
            }
            if (null !== $sql_path && is_file($sql_path)) {
                wp_delete_file($sql_path);
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
     * @return array{tables: array<string,int>, blob_tables: string[], bytes: int}
     */
    private function write_dump(string $path): array
    {
        $buffer = '';

        $flush = static function (string $chunk) use ($path): void {
            if ('' === $chunk) {
                return;
            }
            // A failed write mid-dump means a disk quota or a full volume.
            // Continuing would produce a SQL file that is syntactically valid
            // up to the truncation point and silently missing every row after
            // it, which is the worst possible failure mode for a backup.
            if (false === file_put_contents($path, $chunk, FILE_APPEND)) {
                throw new \RuntimeException('Writing the database dump failed (out of disk space?): ' . $path);
            }
        };

        // Start from an empty file: FILE_APPEND would otherwise extend a
        // stale scratch file left by an interrupted earlier run.
        if (false === file_put_contents($path, '')) {
            throw new \RuntimeException('Could not create a temporary file for the database dump.');
        }

        $result = $this->dumper->dump(
            static function (string $sql) use (&$buffer, $flush): void {
                $buffer .= $sql;
                if (strlen($buffer) >= self::FLUSH_BYTES) {
                    $flush($buffer);
                    $buffer = '';
                }
            }
        );

        $flush($buffer);

        return $result;
    }

    /**
     * Add the site's files to the archive under a wp-content/ prefix, and
     * return how many were added.
     */
    private function add_files(\ZipArchive $zip, string $scope): int
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
                    return ! in_array(
                        strtolower($current->getExtension()),
                        self::EXCLUDED_EXTENSIONS,
                        true
                    );
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
