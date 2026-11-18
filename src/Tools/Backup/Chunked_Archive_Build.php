<?php

namespace WPMCP\Tools\Backup;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * A site archive built in steps (issue #783), so a large site's full
 * backup finishes on a host whose PHP time limit is far shorter than the
 * build. Site_Archive_Builder writes the whole zip in one request, and its
 * final ZipArchive::close() alone runs for minutes on a 1 GB wp-content.
 *
 * The build is a small state machine whose state is plain data, saved on
 * the backup job between requests (Run_Backup_Job runs one step per cron
 * request and schedules the next):
 *
 * - dump: Db_Dumper::step() appends to the scratch db.sql, a batch of rows
 *   at a time, every statement whole where a step stops.
 * - scan: the file tree is walked a directory at a time; what to archive
 *   goes to a list file (one JSON [path, archive name] per line) and the
 *   directories still to visit to a queue file.
 * - files: list entries are added to the partial archive in batches, each
 *   batch written by its own close(). libzip writes a close() to a temp
 *   file and renames it over the archive, so a close() that is canceled
 *   (it would run past the hard deadline) or killed leaves the archive as
 *   the last step saved it; the batch is retried smaller.
 * - finalise: manifest.json is added and the archive is renamed to .zip.
 *
 * The archive is the one Site_Archive_Builder makes (db.sql, manifest.json
 * with format_version 1, wp-content/...), so restores read both alike.
 *
 * Every step records how many bytes of the dump and list it committed. A
 * step that was killed may have appended more; recover() cuts the files
 * back before the retry, so nothing is written twice.
 */
class Chunked_Archive_Build
{
    /** Bytes of the list file read per batch. */
    private const LIST_READ_BYTES = 262144;

    /** Bytes copied per read when a scratch file is cut back. */
    private const COPY_BYTES = 8388608;

    /** Files and bytes in the first batch, before the build has timed one. */
    public const FIRST_BATCH_FILES = 500;
    public const FIRST_BATCH_BYTES = 33554432;

    /** Canceled closes of the finishing write before the build gives up. */
    private const MAX_FINALISE_CANCELS = 3;

    private Db_Dumper $dumper;

    private Site_Archive_Builder $builder;

    public function __construct(?Db_Dumper $dumper = null)
    {
        $this->dumper  = $dumper ?? new Db_Dumper();
        $this->builder = new Site_Archive_Builder($this->dumper);
    }

    /**
     * The state of a build that has not started. Paths are decided here, so
     * the first progress report already names every file cleanup may need
     * to remove.
     *
     * @return array<string,mixed>
     */
    public function start(string $scope): array
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The PHP zip extension is required to build a site backup archive.');
        }

        $scope = in_array($scope, ['all', 'database', 'files', 'uploads'], true) ? $scope : 'all';

        $dir = Site_Backup_Dir::path();
        Site_Backup_Dir::protect($dir);

        $target  = $this->builder->archive_path($dir, $scope);
        $partial = $target . Site_Archive_Builder::PARTIAL_SUFFIX;
        $token   = wp_generate_password(12, false);
        $with_db = in_array($scope, ['all', 'database'], true);

        $uploads = wp_upload_dir();
        $root    = 'uploads' === $scope
            ? (string) $uploads['basedir']
            : (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : ABSPATH . 'wp-content');
        $root    = rtrim($root, '/\\');

        return [
            'scope'          => $scope,
            'phase'          => $with_db ? 'dump' : 'scan',
            'target'         => $target,
            'partial'        => $partial,
            'sql'            => $with_db ? $dir . '/db-' . $token . '.sql' : null,
            'list'           => $dir . '/wpmcp-list-' . $token . '.jsonl',
            'dirs'           => $dir . '/wpmcp-dirs-' . $token . '.json',
            'with_files'     => 'database' !== $scope,
            'list_committed' => 0,
            'steps'          => 0,
            'attempts'       => 0,
            'step_running'   => false,
            'dump'           => $with_db ? array_merge($this->dumper->begin(), ['committed' => 0]) : null,
            'scan'           => [
                'root'    => $root,
                // The archive path stays relative to wp-content even for an
                // uploads-only backup, so both scopes restore through one
                // code path.
                'prefix'  => 'uploads' === $scope ? 'wp-content/' . basename($root) : 'wp-content',
                'started' => false,
                'found'   => 0,
            ],
            'files'          => [
                'offset'      => 0,
                'added'       => 0,
                'batch_files' => null,
                'batch_bytes' => null,
                'store'       => false,
            ],
            'finalise'       => ['cancels' => 0],
            'files_total'    => null,
            'result'         => null,
        ];
    }

    /**
     * Advance the build by one request's worth: units of work (a batch of
     * rows, a directory, a batch of files, the finish) until the soft
     * budget in $limits is spent. One unit always runs. $notify receives
     * the progress record (see progress()) after each unit, and inside a
     * long close() at ZipArchive's progress rate; a $notify that throws
     * stops the step (the caller then removes what the build wrote).
     *
     * $limits: seconds (soft budget), hard_seconds (null, or when a close()
     * still running is canceled), batch_files and batch_bytes (caps on one
     * batch), dump_rows (rows per SELECT).
     *
     * @param callable(array):void $notify
     * @return array<string,mixed> the state to save; phase 'done' carries
     *         the artifact in 'result'
     */
    public function step(array $build, array $limits, callable $notify): array
    {
        $start = microtime(true);
        $soft  = $start + max(0.0, (float) ($limits['seconds'] ?? 0));
        $hard  = isset($limits['hard_seconds']) ? $start + max(0.0, (float) $limits['hard_seconds']) : null;

        $yield = static fn(): bool => microtime(true) >= $soft;

        $build['steps'] = (int) ($build['steps'] ?? 0) + 1;

        do {
            $build['yield_now'] = false;
            switch ($build['phase']) {
                case 'dump':
                    $build = $this->step_dump($build, $limits, $yield, $notify);
                    break;
                case 'scan':
                    $build = $this->step_scan($build, $yield);
                    break;
                case 'files':
                    $build = $this->step_files($build, $limits, $hard, $notify);
                    break;
                case 'finalise':
                    $build = $this->step_finalise($build, $hard, $notify);
                    break;
                default:
                    $build['yield_now'] = true;
            }
            $notify(self::progress($build));
        } while ('done' !== $build['phase'] && empty($build['yield_now']) && ! $yield());

        unset($build['yield_now']);

        return $build;
    }

    /**
     * Put the scratch files back to what the last saved step committed,
     * after a step that was killed part-way: libzip's temp output goes, and
     * dump or list bytes written past the committed length are cut off.
     */
    public static function recover(array $build): array
    {
        Site_Archive_Builder::discard_zip_temp((string) $build['partial']);

        if (is_array($build['dump'] ?? null) && null !== $build['sql'] && is_file((string) $build['sql'])) {
            self::cut((string) $build['sql'], (int) ($build['dump']['committed'] ?? 0));
        }
        if (is_file((string) $build['list'])) {
            self::cut((string) $build['list'], (int) ($build['list_committed'] ?? 0));
        }

        return $build;
    }

    /**
     * The progress record saved on the job and shown by get-backup-status.
     * Its file keys are the ones Site_Archive_Builder::discard_partial()
     * removes, so the stale sweep and a cancel clean up a chunked build too.
     */
    public static function progress(array $build): array
    {
        $phase = (string) ($build['phase'] ?? 'start');
        $dump  = is_array($build['dump'] ?? null) ? $build['dump'] : null;
        $files = (array) ($build['files'] ?? []);

        $progress = [
            'phase'        => 'done' === $phase ? 'finalise' : $phase,
            'partial_file' => $build['partial'] ?? null,
            'scratch_file' => $build['sql'] ?? null,
            'list_file'    => $build['list'] ?? null,
            'dirs_file'    => $build['dirs'] ?? null,
            'steps'        => (int) ($build['steps'] ?? 0),
            'dump_bytes'   => null !== $dump ? (int) ($dump['bytes'] ?? 0) : 0,
            'files_added'  => (int) ($files['added'] ?? 0),
            'percent'      => self::percent($build),
        ];
        if (null !== $dump) {
            $progress['tables_done']  = min((int) $dump['index'], count((array) $dump['tables']));
            $progress['tables_total'] = count((array) $dump['tables']);
        }
        if ('scan' === $phase) {
            $progress['files_total'] = (int) ($build['scan']['found'] ?? 0);
        } elseif (null !== ($build['files_total'] ?? null)) {
            $progress['files_total'] = (int) $build['files_total'];
        }
        $progress['summary'] = Site_Archive_Builder::describe_progress($progress);

        return $progress;
    }

    /** A rough overall percentage: the dump, then the files, then the finish. */
    private static function percent(array $build): int
    {
        $phase     = (string) ($build['phase'] ?? '');
        $has_files = ! empty($build['with_files']);
        $dump_end  = is_array($build['dump'] ?? null) ? ($has_files ? 30 : 80) : 0;

        switch ($phase) {
            case 'dump':
                $total = max(1, count((array) $build['dump']['tables']));
                return (int) floor($dump_end * min(1, (int) $build['dump']['index'] / $total));
            case 'scan':
                return $dump_end + 2;
            case 'files':
                $committed = max(1, (int) ($build['list_committed'] ?? 0));
                $share     = min(1, (int) ($build['files']['offset'] ?? 0) / $committed);
                return (int) floor($dump_end + 5 + (90 - $dump_end) * $share);
            case 'finalise':
                return 97;
            case 'done':
                return 100;
            default:
                return 0;
        }
    }

    private function step_dump(array $build, array $limits, callable $yield, callable $notify): array
    {
        $path = (string) $build['sql'];
        $dump = $build['dump'];

        if (0 === (int) $dump['committed'] && ! is_file($path)) {
            self::write($path, '', false);
        }

        $buffer = '';
        $write  = static function (string $sql) use (&$buffer, $path, $notify, $build): void {
            $buffer .= $sql;
            if (strlen($buffer) >= Site_Archive_Builder::FLUSH_BYTES) {
                self::write($path, $buffer, true);
                $buffer = '';
                $notify(self::progress($build));
            }
        };

        $dump = $this->dumper->step($dump, $write, $yield, max(1, (int) ($limits['dump_rows'] ?? Db_Dumper::BATCH)));
        self::write($path, $buffer, true);

        clearstatcache(true, $path);
        $dump['committed'] = (int) filesize($path);
        $build['dump']     = $dump;

        if (! empty($dump['done'])) {
            $build = $this->begin_list($build);
            $build['phase'] = $build['with_files'] ? 'scan' : 'files';
            if (! $build['with_files']) {
                $build['files_total'] = 0;
            }
        }

        return $build;
    }

    /** Start the list file: db.sql goes in first, as its own batch. */
    private function begin_list(array $build): array
    {
        $head = null !== $build['sql'] ? (string) wp_json_encode([(string) $build['sql'], 'db.sql']) . "\n" : '';
        self::write((string) $build['list'], $head, false);
        $build['list_committed'] = strlen($head);
        $build['scan']['started'] = true;

        $root = (string) $build['scan']['root'];
        self::save_dirs((string) $build['dirs'], '' !== $root && is_dir($root) ? [$root] : []);

        return $build;
    }

    private function step_scan(array $build, callable $yield): array
    {
        if (empty($build['scan']['started'])) {
            $build = $this->begin_list($build);
        }

        $root   = (string) $build['scan']['root'];
        $prefix = (string) $build['scan']['prefix'];
        $stack  = self::load_dirs((string) $build['dirs']);
        $lines  = '';
        $found  = (int) $build['scan']['found'];

        while ([] !== $stack) {
            $dir     = (string) array_pop($stack);
            $entries = is_readable($dir) ? scandir($dir) : false;
            if (false === $entries) {
                throw new \RuntimeException(sprintf('Could not read the directory %s while listing files to back up.', esc_html($dir)));
            }

            $subdirs = [];
            foreach ($entries as $entry) {
                if ('.' === $entry || '..' === $entry) {
                    continue;
                }
                $path = $dir . '/' . $entry;
                // Symlinks are skipped rather than followed: following them
                // can walk straight out of wp-content (or into a loop) and
                // pull unrelated server files into an archive that the user
                // may hand to someone else.
                if (is_link($path)) {
                    continue;
                }
                if (is_dir($path)) {
                    if (! in_array($entry, Site_Archive_Builder::EXCLUDED_DIRS, true)) {
                        $subdirs[] = $path;
                    }
                    continue;
                }
                if (! is_file($path) || ! is_readable($path) || ! Site_Archive_Builder::should_archive($path)) {
                    continue;
                }
                $relative = str_replace('\\', '/', ltrim(substr($path, strlen($root)), '/\\'));
                $lines   .= (string) wp_json_encode([$path, $prefix . '/' . $relative]) . "\n";
                $found++;
            }
            foreach (array_reverse($subdirs) as $subdir) {
                $stack[] = $subdir;
            }

            if ($yield()) {
                break;
            }
        }

        self::write((string) $build['list'], $lines, true);
        $build['list_committed'] += strlen($lines);
        $build['scan']['found']   = $found;

        if ([] === $stack) {
            if (is_file((string) $build['dirs'])) {
                wp_delete_file((string) $build['dirs']);
            }
            $build['phase']       = 'files';
            $build['files_total'] = $found;
        } else {
            self::save_dirs((string) $build['dirs'], $stack);
        }

        return $build;
    }

    private function step_files(array $build, array $limits, ?float $hard, callable $notify): array
    {
        $files     = $build['files'];
        $committed = (int) $build['list_committed'];
        $offset    = (int) $files['offset'];

        if ($offset >= $committed) {
            $build['phase'] = 'finalise';
            return $build;
        }

        $max_files = max(1, min((int) ($limits['batch_files'] ?? PHP_INT_MAX), (int) ($files['batch_files'] ?? self::FIRST_BATCH_FILES)));
        $max_bytes = max(1, min((int) ($limits['batch_bytes'] ?? PHP_INT_MAX), (int) ($files['batch_bytes'] ?? self::FIRST_BATCH_BYTES)));

        $chunk = (string) file_get_contents((string) $build['list'], false, null, $offset, min(self::LIST_READ_BYTES, $committed - $offset));

        $entries  = [];
        $consumed = 0;
        $bytes    = 0;
        while (count($entries) < $max_files && ([] === $entries || $bytes < $max_bytes)) {
            $end = strpos($chunk, "\n", $consumed);
            if (false === $end) {
                break;
            }
            $line     = substr($chunk, $consumed, $end - $consumed);
            $consumed = $end + 1;
            $entry    = json_decode($line, true);
            if (! is_array($entry) || ! isset($entry[0], $entry[1])) {
                throw new \RuntimeException('The backup file list is damaged; start the backup again.');
            }
            $entries[] = [(string) $entry[0], (string) $entry[1]];
            $bytes    += is_file((string) $entry[0]) ? (int) filesize((string) $entry[0]) : 0;
        }
        if ([] === $entries) {
            throw new \RuntimeException('The backup file list is damaged; start the backup again.');
        }

        $zip = $this->open((string) $build['partial']);

        $added = 0;
        foreach ($entries as [$absolute, $name]) {
            if (! is_file($absolute) || ! $zip->addFile($absolute, $name)) {
                continue;
            }
            if (! empty($files['store'])) {
                $zip->setCompressionName($name, \ZipArchive::CM_STORE);
            }
            if ('db.sql' !== $name) {
                $added++;
            }
        }

        $began  = microtime(true);
        $closed = $this->close($zip, $hard, static function (int $percent) use ($notify, $build): void {
            $progress            = self::progress($build);
            $progress['percent'] = max($progress['percent'], $percent);
            $notify($progress);
        });
        $took = microtime(true) - $began;

        if (! $closed) {
            // The archive is what the last step left. Retry smaller: half
            // the batch, and a single file is tried once more uncompressed
            // (a raw copy) before the build gives up on it.
            Site_Archive_Builder::discard_zip_temp((string) $build['partial']);
            if (count($entries) <= 1) {
                if (! empty($files['store'])) {
                    throw new \RuntimeException(sprintf(
                        'The file %s could not be added to the backup within the PHP time limit of one request, even uncompressed. Raise max_execution_time for WP-Cron requests, or move the file out of wp-content, and start the backup again.',
                        esc_html($entries[0][1])
                    ));
                }
                $files['store'] = true;
            } else {
                $files['batch_files'] = max(1, intdiv(count($entries), 2));
                $files['batch_bytes'] = max(1, intdiv(max(1, $bytes), 2));
            }
            $build['files']     = $files;
            $build['yield_now'] = true;
            return $build;
        }

        $files['offset'] = $offset + $consumed;
        $files['added']  = (int) $files['added'] + $added;
        $files['store']  = false;

        // Size the next batch to the time this one took: aim for half the
        // soft budget, never more than double or less than half at once.
        $target               = max(0.5, (float) ($limits['seconds'] ?? 0) / 2);
        $factor               = max(0.5, min(2.0, $target / max(0.01, $took)));
        $files['batch_files'] = max(1, (int) round(count($entries) * $factor));
        $files['batch_bytes'] = max(1048576, (int) round(max($bytes, 1) * $factor));

        $build['files'] = $files;
        if ($files['offset'] >= $committed) {
            $build['phase'] = 'finalise';
        }

        return $build;
    }

    private function step_finalise(array $build, ?float $hard, callable $notify): array
    {
        $dump     = is_array($build['dump'] ?? null) ? Db_Dumper::result($build['dump']) : ['tables' => [], 'blob_tables' => [], 'bytes' => 0];
        $count    = (int) $build['files']['added'];
        $manifest = $this->builder->manifest((string) $build['scope'], $dump, $count);
        $partial  = (string) $build['partial'];
        $target   = (string) $build['target'];

        $zip = $this->open($partial);
        $zip->addFromString('manifest.json', (string) wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $closed = $this->close($zip, $hard, static function () use ($notify, $build): void {
            $notify(self::progress($build));
        });
        if (! $closed) {
            Site_Archive_Builder::discard_zip_temp($partial);
            $build['finalise']['cancels'] = (int) $build['finalise']['cancels'] + 1;
            if ($build['finalise']['cancels'] > self::MAX_FINALISE_CANCELS) {
                throw new \RuntimeException('The finished backup archive could not be written within the PHP time limit of one request. Raise max_execution_time for WP-Cron requests and start the backup again.');
            }
            $build['yield_now'] = true;
            return $build;
        }

        // Only a complete archive carries the .zip name.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- WP_Filesystem is not loaded in a cron request; the rename is within the plugin's own backup directory.
        if (! rename($partial, $target)) {
            throw new \RuntimeException('The finished backup archive could not be moved into place.');
        }

        foreach (['sql', 'list', 'dirs'] as $key) {
            $path = $build[ $key ] ?? null;
            if (is_string($path) && '' !== $path && is_file($path)) {
                wp_delete_file($path);
            }
        }

        clearstatcache(true, $target);
        $build['phase']  = 'done';
        $build['result'] = [
            'file'       => $target,
            'size'       => (int) filesize($target),
            'scope'      => (string) $build['scope'],
            'manifest'   => $manifest,
            'tables'     => $dump['tables'],
            'file_count' => $count,
        ];

        return $build;
    }

    private function open(string $partial): \ZipArchive
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($partial, \ZipArchive::CREATE)) {
            throw new \RuntimeException('Could not open the backup archive for writing.');
        }
        return $zip;
    }

    /**
     * close(), canceled when it would run past $hard: libzip then drops its
     * temp output and the archive on disk stays as it was. A deadline that
     * has already passed cancels before any byte is written. Throws on a
     * failed close() that was not canceled. Returns false when canceled.
     *
     * @param callable(int):void $progress percent of this close done
     */
    private function close(\ZipArchive $zip, ?float $hard, callable $progress): bool
    {
        if (null !== $hard && microtime(true) >= $hard) {
            $zip->unchangeAll();
            $zip->close();
            return false;
        }

        $pending  = null;
        $canceled = false;
        if (method_exists($zip, 'registerProgressCallback')) {
            $zip->registerProgressCallback(0.02, static function (float $rate) use ($progress, &$pending): void {
                if (null !== $pending) {
                    return;
                }
                try {
                    $progress((int) round($rate * 100));
                } catch (\Throwable $e) {
                    $pending = $e;
                }
            });
        }
        if (method_exists($zip, 'registerCancelCallback')) {
            $zip->registerCancelCallback(static function () use ($hard, &$pending, &$canceled): int {
                // A heartbeat that found the job canceled stops the write
                // too, rather than finishing a batch nobody wants.
                if (null !== $pending || (null !== $hard && microtime(true) >= $hard)) {
                    $canceled = true;
                    return 1;
                }
                return 0;
            });
        }

        // A failed close() still frees the archive; it is attempted once.
        // A canceled one warns "Operation cancelled", which is the outcome
        // asked for, not an error worth a log line: warnings from this one
        // call are held back, and a failure is reported by the throw below.
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- scoped to one close() and restored at once.
        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            $closed = $zip->close();
        } finally {
            restore_error_handler();
        }
        if (null !== $pending) {
            throw $pending;
        }
        if ($canceled) {
            return false;
        }
        if (true !== $closed) {
            throw new \RuntimeException('The backup archive could not be written (out of disk space?).');
        }

        return true;
    }

    /** Write or append to a scratch file, failing loudly (a full disk). */
    private static function write(string $path, string $data, bool $append): void
    {
        if ($append && '' === $data) {
            return;
        }
        if (false === file_put_contents($path, $data, $append ? FILE_APPEND : 0)) {
            throw new \RuntimeException('Writing a backup scratch file failed (out of disk space?): ' . esc_html(basename($path)));
        }
    }

    /** The directory queue of the scan phase. */
    private static function load_dirs(string $path): array
    {
        $stack = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
        if (! is_array($stack)) {
            throw new \RuntimeException('The backup directory queue is damaged; start the backup again.');
        }
        return array_values(array_map('strval', $stack));
    }

    /** Written beside and renamed over, so a killed write leaves the old queue. */
    private static function save_dirs(string $path, array $stack): void
    {
        $temp = $path . '.next';
        self::write($temp, (string) wp_json_encode(array_values($stack)), false);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- WP_Filesystem is not loaded in a cron request; the rename is within the plugin's own backup directory.
        if (! rename($temp, $path)) {
            throw new \RuntimeException('Saving the backup directory queue failed.');
        }
    }

    /**
     * Cut $path back to $length bytes. There is no ftruncate() without a
     * file handle, which the plugin directory's rules forbid, so the kept
     * part is copied beside the file in bounded reads and renamed over it.
     */
    private static function cut(string $path, int $length): void
    {
        clearstatcache(true, $path);
        $size = (int) filesize($path);
        if ($size === $length) {
            return;
        }
        if ($size < $length) {
            throw new \RuntimeException('A backup scratch file is shorter than its last saved step; start the backup again.');
        }

        $temp = $path . '.cut';
        self::write($temp, '', false);
        for ($at = 0; $at < $length; $at += self::COPY_BYTES) {
            $part = file_get_contents($path, false, null, $at, min(self::COPY_BYTES, $length - $at));
            if (false === $part) {
                throw new \RuntimeException('Reading a backup scratch file failed.');
            }
            self::write($temp, $part, true);
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- WP_Filesystem is not loaded in a cron request; the rename is within the plugin's own backup directory.
        if (! rename($temp, $path)) {
            throw new \RuntimeException('Cutting a backup scratch file back failed.');
        }
    }
}
