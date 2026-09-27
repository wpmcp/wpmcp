<?php

namespace WPMCP\Tools\Backup;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Restore a site in place from a site-backup archive produced by
 * Site_Archive_Builder (issue #190, phase 1).
 *
 * This is the one tool in the plugin that can destroy a site, so it is
 * built gate-first: everything that can refuse does so before anything
 * writes. dry_run defaults to TRUE on purpose: an agent that fires this
 * ability from a vague instruction hits a compatibility report, not a
 * restore. The archive reference is resolved through Archive_Locator, so
 * the same containment rules apply as for reading or deleting an archive.
 *
 * Order of a real restore, and why:
 *
 *  1. Compatibility gate on manifest.json (format, format_version, scope,
 *     table prefix, multisite): refusals throw, warnings are reported.
 *  2. db.sql is extracted to a scratch file (a corrupt or truncated entry
 *     fails here), its size is checked against the manifest, and every
 *     statement is parsed and held to Sql_Import_Policy. A dump that ends
 *     mid-statement is refused here, not discovered half-way through.
 *  3. With include_files, wp-content is extracted to a staging directory.
 *  4. A database-scope safety archive of the current site is taken. If it
 *     fails, the restore does not start. Its job id is in every result
 *     from here on, including failures.
 *  5. Maintenance mode (Maintenance_Guard's option) goes on and stays on,
 *     re-asserted as soon as the dump has replaced the options table.
 *  6. The dump is imported statement by statement. On the first failure
 *     the import stops, the failing statement is reported, and the safety
 *     archive is imported to put the site back as it was.
 *  7. The acting user's credentials are written back (Restore_Session), the
 *     backup job history is kept (it describes files on disk, which the
 *     database restore does not touch), and the staged wp-content is
 *     swapped in.
 *  8. Maintenance mode is released whatever happened.
 *
 * Steps 1 to 3 are also what a dry run performs, so the report a user sees
 * first is the same verdict the real restore acts on.
 *
 * Not routed through Safe_Mutation: a whole-database replace is outside
 * the per-object model Snapshot_Store captures, so a snapshot could not
 * undo it. The safety archive (step 4) is this tool's undo, and it is
 * taken unconditionally.
 */
class Restore_Site_Backup
{
    /**
     * The newest archive format this build knows how to restore. An archive
     * with a newer format_version is refused: it may contain structures
     * this importer does not understand, and guessing is how sites die.
     */
    private const MAX_FORMAT_VERSION = 1;

    /**
     * Archive scopes that carry a database dump. A files-only or
     * uploads-only archive has nothing this tool can restore in phase 1.
     */
    private const RESTORABLE_SCOPES = ['all', 'database'];

    /** Rewrite the progress file after this many statements. */
    private const STATE_EVERY = 200;

    /** Table names listed in a warning before it is summarised. */
    private const LIST_LIMIT = 20;

    /** @var callable(array): array|null */
    private $safety_producer;

    private ?Restore_Files $files;

    /**
     * @param callable(array): array|null $safety_producer How the pre-restore
     *        safety archive is produced; defaults to Run_Backup_Job's own
     *        producer, which builds a database-scope Site_Archive_Builder
     *        archive. Injectable for the same reason Run_Backup_Job's is.
     * @param Restore_Files|null $files The wp-content swapper; defaults to
     *        one rooted at WP_CONTENT_DIR.
     */
    public function __construct(?callable $safety_producer = null, ?Restore_Files $files = null)
    {
        $this->safety_producer = $safety_producer;
        $this->files           = $files;
    }

    public function handle(array $args): array
    {
        $dry_run          = ! isset($args['dry_run']) || (bool) $args['dry_run'];
        $include_files    = ! empty($args['include_files']);
        $preserve_session = ! isset($args['preserve_session']) || (bool) $args['preserve_session'];

        $archive  = Archive_Locator::resolve($args);
        $manifest = Archive_Locator::read_manifest($archive);

        $report = $this->compatibility_report($archive, $manifest, $include_files);

        $interrupted = self::interrupted_restore();
        if (null !== $interrupted) {
            $report['warnings'][] = sprintf(
                'An earlier restore stopped without finishing (started %s, from %s, last at statement %d on table %s). Its pre-restore safety archive is backup job %d; restoring that job puts the site back as it was before that attempt. Maintenance mode may still be on (disable-maintenance turns it off).',
                esc_html((string) ($interrupted['started_at'] ?? 'unknown')),
                esc_html((string) ($interrupted['archive'] ?? 'unknown')),
                (int) ($interrupted['statement'] ?? 0),
                esc_html((string) ($interrupted['table'] ?? 'unknown')),
                (int) ($interrupted['safety_job_id'] ?? 0)
            );
        }

        $sql_path = null;
        $scan     = null;

        try {
            if (empty($report['refusals'])) {
                try {
                    [$sql_path, $scan] = $this->prepare_dump($archive, $manifest);
                } catch (\RuntimeException $e) {
                    $report['refusals'][] = $e->getMessage();
                }
            }

            if ($include_files && empty($report['refusals'])) {
                try {
                    self::check_file_entries($archive);
                } catch (\RuntimeException $e) {
                    $report['refusals'][] = $e->getMessage();
                }
            }

            if (null !== $scan) {
                $report['warnings'] = array_merge($report['warnings'], self::scan_warnings($scan));
            }

            if ($dry_run) {
                return [
                    'dry_run'       => true,
                    'file'          => $archive,
                    'compatible'    => empty($report['refusals']),
                    'refusals'      => $report['refusals'],
                    'warnings'      => $report['warnings'],
                    'scope'         => (string) ($manifest['scope'] ?? ''),
                    'include_files' => $include_files,
                    'statements'    => null !== $scan ? $scan['statements'] : null,
                    'tables'        => null !== $scan ? count($scan['tables']) : null,
                    'manifest'      => $manifest,
                ];
            }

            if (! empty($report['refusals'])) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- compatibility_report(), prepare_dump() and Sql_Import_Policy escape every operand they interpolate into a refusal; escaping the joined text again would entity-encode the plugin's own quotes.
                throw new \RuntimeException('Restore refused: ' . implode(' ', $report['refusals']));
            }

            return $this->execute($archive, (string) $sql_path, (array) $scan, $include_files, $preserve_session, $report['warnings']);
        } finally {
            if (null !== $sql_path) {
                self::remove_scratch($sql_path);
            }
        }
    }

    /**
     * Steps 3 to 8 of the class docblock. Only reached once the gate and
     * the dump scan have passed.
     */
    private function execute(string $archive, string $sql_path, array $scan, bool $include_files, bool $preserve_session, array $warnings): array
    {
        if (! self::acquire_lock()) {
            throw new \RuntimeException('Restore refused: another restore is already running on this site.');
        }

        $maintenance = new Restore_Maintenance();
        $files       = $include_files ? ($this->files ?? new Restore_Files()) : null;
        $staged      = null;
        $session_sql = null;
        $safety      = null;

        try {
            self::extend_limits();

            if (null !== $files) {
                try {
                    $staged = $files->stage($archive);
                } catch (\RuntimeException $e) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Restore_Files escapes the entry names it interpolates.
                    throw new \RuntimeException('Restore refused: ' . $e->getMessage());
                }
            }

            $safety = $this->take_safety_archive();

            try {
                $captured    = $preserve_session ? Restore_Session::capture(get_current_user_id()) : null;
                $jobs        = get_option(Backup_Job_Store::OPTION, null);
                $session_sql = self::session_variables();

                $state = [
                    'started_at'    => gmdate('c'),
                    'archive'       => basename($archive),
                    'safety_job_id' => $safety['job_id'],
                ];
                self::write_state($state + ['statement' => 0, 'table' => '']);

                $maintenance->enter();

                $import   = $this->import_dump($sql_path, $scan, $maintenance, $state);
                $rollback = null;

                if (null !== $import['failure']) {
                    $rollback = $this->roll_back($safety, $maintenance, ['phase' => 'rollback'] + $state);
                }

                self::restore_session_variables($session_sql);
                wp_cache_flush();
                self::keep_job_history($jobs);

                if (null === $import['failure']) {
                    $session = $preserve_session
                        ? Restore_Session::reapply($captured)
                        : [
                            'preserved'        => false,
                            'relogin_required' => true,
                            'reason'           => 'preserve_session was false, so every session now comes from the backup; sign in again.',
                        ];
                } else {
                    $session = [
                        'preserved'        => ! empty($rollback['restored']),
                        'relogin_required' => empty($rollback['restored']),
                        'reason'           => ! empty($rollback['restored'])
                            ? 'The restore was rolled back to the pre-restore state, so existing sessions are unchanged.'
                            : 'The restore failed and the rollback did not complete; you may need to sign in again after restoring the safety archive.',
                    ];
                }

                $files_result = null;
                if (null !== $files && null !== $staged) {
                    if (null !== $import['failure']) {
                        $files_result = ['restored' => false, 'reason' => 'The database restore failed, so wp-content was not swapped.'];
                    } else {
                        try {
                            $files_result = ['restored' => true] + $files->swap($staged);
                        } catch (\RuntimeException $e) {
                            $files_result = ['restored' => false, 'error' => $e->getMessage()];
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Something unexpected after the safety archive exists: the
                // caller needs its job id more than anything else.
                throw new \RuntimeException(sprintf(
                    'The restore stopped unexpectedly: %s. The pre-restore safety archive is backup job %d; restore it to put the site back.',
                    esc_html($e->getMessage()),
                    (int) $safety['job_id']
                ), 0, $e);
            }
        } finally {
            if (null !== $session_sql) {
                self::restore_session_variables($session_sql);
            }
            $maintenance->leave();
            if (null !== $files && null !== $staged) {
                $files->discard($staged);
            }
            if (null !== $safety) {
                self::clear_state();
            }
            self::release_lock();
        }

        $restored = null === $import['failure'];
        if (null !== $scan['placeholder']) {
            $warnings[] = 'This archive was written by an older version that stored "%" in values as a placeholder token; the token was converted back to "%" during the import.';
        }

        if ($restored && null !== $files_result && empty($files_result['restored'])) {
            $status = 'database_restored_files_failed';
        } elseif ($restored) {
            $status = 'restored';
        } elseif (! empty($rollback['restored'])) {
            $status = 'failed_rolled_back';
        } else {
            $status = 'failed';
        }

        return [
            'dry_run'        => false,
            'status'         => $status,
            'restored'       => $restored,
            'file'           => $archive,
            'warnings'       => $warnings,
            'safety_archive' => $safety,
            'import'         => [
                'statements_total'    => (int) $scan['statements'],
                'statements_executed' => (int) $import['executed'],
                'tables'              => count($scan['tables']),
            ],
            'failure'        => $import['failure'],
            'rollback'       => $rollback,
            'session'        => $session,
            'files'          => $files_result,
            'maintenance'    => ['enabled_during_restore' => true, 'released' => ! $maintenance->is_active()],
        ];
    }

    /**
     * Import a dump with the progress file and the maintenance re-assert
     * wired in.
     *
     * @return array{executed: int, failure: ?array}
     */
    private function import_dump(string $sql_path, array $scan, Restore_Maintenance $maintenance, array $state): array
    {
        global $wpdb;

        $options  = (string) $wpdb->options;
        $previous = null;
        $count    = 0;

        $after = static function (array $statement, array $class) use (&$previous, &$count, $options, $maintenance, $state): void {
            $table = $class['table'];
            $count++;

            if (null !== $table && $previous === $options && $table !== $options) {
                $maintenance->after_options_imported();
            }

            if (null !== $table && ($table !== $previous || 0 === $count % self::STATE_EVERY)) {
                self::write_state($state + [
                    'statement' => (int) $statement['index'],
                    'table'     => $table,
                ]);
            }

            if (null !== $table) {
                $previous = $table;
            }

            /**
             * Fires after each statement of a restore has executed.
             *
             * @param array $statement {sql, offset, index}
             * @param array $class     {kind, table}
             */
            do_action('wpmcp_restore_statement_executed', $statement, $class);
        };

        $result = (new Sql_Importer($sql_path, $this->policy_for(null)))->import($scan['placeholder'], $after);

        // The options table was the last one in the dump: nothing followed
        // it to trigger the re-assert, but the row still changed.
        if ($previous === $options) {
            $maintenance->after_options_imported();
        }

        return $result;
    }

    /**
     * Put the site back from the safety archive after a failed import.
     *
     * @return array{attempted: bool, restored: bool, failure?: ?array, error?: string}
     */
    private function roll_back(array $safety, Restore_Maintenance $maintenance, array $state): array
    {
        $sql_path = null;

        try {
            $manifest         = Archive_Locator::read_manifest((string) $safety['file']);
            [$sql_path, $scan] = $this->prepare_dump((string) $safety['file'], $manifest);
            $import           = $this->import_dump($sql_path, $scan, $maintenance, $state);

            return [
                'attempted' => true,
                'restored'  => null === $import['failure'],
                'failure'   => $import['failure'],
            ];
        } catch (\Throwable $e) {
            return [
                'attempted' => true,
                'restored'  => false,
                'error'     => $e->getMessage(),
            ];
        } finally {
            if (null !== $sql_path) {
                self::remove_scratch($sql_path);
            }
        }
    }

    /**
     * Step 4: a database-scope archive of the site as it is now, produced
     * synchronously through the ordinary backup job machinery so it shows
     * up in list-backup-jobs and can be restored like any other.
     *
     * @return array{job_id: int, file: string}
     * @throws \RuntimeException When the archive could not be produced.
     */
    private function take_safety_archive(): array
    {
        $job = Backup_Job_Store::create('database', 'database');
        $id  = (int) $job['id'];
        Backup_Job_Store::update($id, ['purpose' => 'pre-restore safety archive']);

        (new Run_Backup_Job($this->safety_producer))->handle($id);

        $done = Backup_Job_Store::get($id);
        $file = is_array($done) ? (string) ($done['result']['file'] ?? '') : '';

        if (! is_array($done) || 'completed' !== $done['status'] || '' === $file || ! is_file($file)) {
            $error = is_array($done) && ! empty($done['error']) ? (string) $done['error'] : 'no archive file was produced';
            throw new \RuntimeException(sprintf(
                'Restore refused: the pre-restore safety archive (backup job %d) failed, so nothing was changed: %s',
                (int) $id,
                esc_html($error)
            ));
        }

        return ['job_id' => $id, 'file' => $file];
    }

    /**
     * Step 2: extract db.sql to a scratch file and validate all of it.
     *
     * @return array{0: string, 1: array} The scratch path and the scan result.
     * @throws \RuntimeException
     */
    private function prepare_dump(string $archive, array $manifest): array
    {
        $root = Site_Backup_Dir::path();
        Site_Backup_Dir::protect($root);

        $dir = $root . '/restore-' . wp_generate_password(12, false);
        if (! wp_mkdir_p($dir)) {
            throw new \RuntimeException('Could not create a scratch directory to read the dump.');
        }
        $path = $dir . '/db.sql';

        try {
            $zip = new \ZipArchive();
            if (true !== $zip->open($archive)) {
                throw new \RuntimeException('That archive could not be opened; it may be truncated.');
            }
            // extractTo() streams the entry to disk inside libzip and checks
            // its CRC, so a damaged dump fails here without ever being held
            // in PHP memory (getFromName() on a large dump would be).
            $extracted = $zip->extractTo($dir, 'db.sql');
            $zip->close();

            if (true !== $extracted || ! is_file($path)) {
                throw new \RuntimeException('db.sql could not be extracted from the archive; it is corrupt or truncated.');
            }

            $expected = $manifest['database']['bytes'] ?? null;
            clearstatcache(true, $path);
            $actual = (int) filesize($path);
            if (is_int($expected) && $expected > 0 && $actual !== $expected) {
                throw new \RuntimeException(sprintf(
                    'db.sql is %d bytes but the manifest recorded %d; the dump is truncated or was edited.',
                    $actual,
                    $expected
                ));
            }

            $tables = isset($manifest['database']['tables']) && is_array($manifest['database']['tables']) && [] !== $manifest['database']['tables']
                ? array_map('strval', array_keys($manifest['database']['tables']))
                : null;

            $scan = (new Sql_Importer($path, $this->policy_for($tables)))->scan(self::max_packet());
        } catch (\Throwable $e) {
            self::remove_scratch($path);
            throw $e;
        }

        $scan['manifest_tables'] = $tables;

        return [$path, $scan];
    }

    /**
     * The statement policy for this site. The table list is only known
     * during the scan; the import re-checks prefix and statement shape,
     * which is what guards against the scratch file changing in between.
     */
    private function policy_for(?array $tables): Sql_Import_Policy
    {
        return new Sql_Import_Policy(self::site_prefix(), $tables);
    }

    /** @return string[] Warnings derived from the dump scan. */
    private static function scan_warnings(array $scan): array
    {
        $in_dump   = array_fill_keys($scan['tables'], true);
        $untouched = array_values(array_filter(
            (new Db_Dumper())->tables(),
            static fn(string $table): bool => ! isset($in_dump[ $table ])
        ));

        if ([] === $untouched) {
            return [];
        }

        $shown = array_slice($untouched, 0, self::LIST_LIMIT);
        $more  = count($untouched) - count($shown);

        return [sprintf(
            '%d table(s) on this site are not in the archive and will be left exactly as they are (typically tables created by plugins installed after the backup): %s%s.',
            count($untouched),
            esc_html(implode(', ', $shown)),
            $more > 0 ? sprintf(' and %d more', $more) : ''
        )];
    }

    /** @throws \RuntimeException On an unsafe or missing wp-content tree. */
    private static function check_file_entries(string $archive): void
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($archive)) {
            throw new \RuntimeException('That archive could not be opened; it may be truncated.');
        }

        try {
            if ([] === Restore_Files::entries($zip)) {
                throw new \RuntimeException('include_files was requested but the archive holds no wp-content files.');
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * The backup job history describes archives on disk, and the database
     * restore does not touch the disk. Letting the dump's copy of the job
     * option win would forget every archive made since the backup, the
     * safety archive of this very restore included.
     */
    private static function keep_job_history($jobs): void
    {
        if (is_array($jobs)) {
            update_option(Backup_Job_Store::OPTION, $jobs);
        }
    }

    private static function site_prefix(): string
    {
        global $wpdb;

        return is_multisite() ? (string) $wpdb->base_prefix : (string) $wpdb->prefix;
    }

    private static function max_packet(): int
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads a server variable; there is no API for it and it must be live.
        return (int) $wpdb->get_var('SELECT @@SESSION.max_allowed_packet');
    }

    /**
     * The session variables the dump preamble changes. A failed import
     * never reaches the footer that resets them, and the rest of this
     * request (the rollback, the option writes) must not run with foreign
     * key checks off or a different sql_mode.
     *
     * @return array{sql_mode: string, foreign_key_checks: int, unique_checks: int}
     */
    private static function session_variables(): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads connection session variables; no API exists and they must be live.
        $row = $wpdb->get_row('SELECT @@SESSION.sql_mode AS sql_mode, @@SESSION.foreign_key_checks AS fk, @@SESSION.unique_checks AS uc', ARRAY_A);

        return [
            'sql_mode'           => (string) ($row['sql_mode'] ?? ''),
            'foreign_key_checks' => (int) ($row['fk'] ?? 1),
            'unique_checks'      => (int) ($row['uc'] ?? 1),
        ];
    }

    private static function restore_session_variables(array $vars): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- restores the connection session variables captured before the import; every value is bound.
        $wpdb->query($wpdb->prepare(
            'SET SESSION sql_mode = %s, SESSION foreign_key_checks = %d, SESSION unique_checks = %d',
            $vars['sql_mode'],
            $vars['foreign_key_checks'],
            $vars['unique_checks']
        ));

        if (isset($wpdb->dbh) && $wpdb->dbh) {
            $wpdb->set_charset($wpdb->dbh);
        }
    }

    /**
     * A MySQL named lock, not an option: the options table is one of the
     * things being replaced, and a named lock is released by the server if
     * this request dies, so a crashed restore can never wedge the next one.
     */
    private static function acquire_lock(): bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL named lock; no API exists.
        return '1' === (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', self::lock_name()));
    }

    private static function release_lock(): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL named lock; no API exists.
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::lock_name()));
    }

    private static function lock_is_free(): bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL named lock; no API exists.
        return '1' === (string) $wpdb->get_var($wpdb->prepare('SELECT IS_FREE_LOCK(%s)', self::lock_name()));
    }

    /** One lock per install, not per server: GET_LOCK names are server-wide. */
    private static function lock_name(): string
    {
        global $wpdb;

        return 'wpmcp_restore_' . substr(md5((defined('DB_NAME') ? DB_NAME : '') . '|' . $wpdb->base_prefix), 0, 16);
    }

    /**
     * A restore is one long request, and a client that disconnects part-way
     * through the import is the worst possible moment for PHP to stop.
     * max_execution_time is deliberately left alone (the directory review
     * flags set_time_limit()); a restore that outlives it is reported by
     * the progress file on the next call, with its safety archive.
     */
    private static function extend_limits(): void
    {
        if (function_exists('ignore_user_abort')) {
            ignore_user_abort(true);
        }
    }

    /**
     * The progress file lives on disk next to the archives, not in the
     * database: the database is what is being replaced. If PHP dies
     * mid-import, the next call finds it and reports where the restore
     * stopped and which safety archive to restore.
     */
    private static function state_path(): string
    {
        return Site_Backup_Dir::path() . '/restore-in-progress.json';
    }

    private static function write_state(array $state): void
    {
        file_put_contents(self::state_path(), (string) wp_json_encode($state));
    }

    private static function clear_state(): void
    {
        if (is_file(self::state_path())) {
            wp_delete_file(self::state_path());
        }
    }

    /** The progress file of a restore that died, or null. */
    private static function interrupted_restore(): ?array
    {
        $path = self::state_path();
        if (! is_file($path) || ! self::lock_is_free()) {
            return null;
        }

        $state = json_decode((string) file_get_contents($path), true);

        return is_array($state) ? $state : ['started_at' => 'unknown'];
    }

    private static function remove_scratch(string $sql_path): void
    {
        if (is_file($sql_path)) {
            wp_delete_file($sql_path);
        }

        $dir = dirname($sql_path);
        if (is_dir($dir) && str_starts_with(basename($dir), 'restore-')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
            (new \WP_Filesystem_Direct(null))->rmdir($dir, true);
        }
    }

    /**
     * Compare the archive against the target site. Refusals are conditions
     * under which a restore would corrupt the target or cannot be carried
     * out at all (wrong prefix, unknown or newer format, a manifest missing
     * the fields the gate depends on, an archive without a database dump);
     * warnings are survivable but worth surfacing (WordPress downgrade,
     * BLOB tables that round-tripped through escaped string literals).
     *
     * Absent fields are refusals, not defaults: a manifest that does not say
     * which prefix it was taken from is not evidence that it matches.
     *
     * @return array{refusals: string[], warnings: string[]}
     */
    private function compatibility_report(string $archive, array $manifest, bool $include_files): array
    {
        global $wpdb, $wp_version;

        $refusals = [];
        $warnings = [];

        if ('wpmcp-site-backup' !== (string) ($manifest['format'] ?? '')) {
            $refusals[] = 'This archive was not produced by this plugin (unknown manifest format).';
        }

        $format_version = $manifest['format_version'] ?? null;
        if (! is_int($format_version) || $format_version < 1) {
            $refusals[] = 'The manifest has no usable format_version; refusing to guess which archive layout this is.';
        } elseif ($format_version > self::MAX_FORMAT_VERSION) {
            $refusals[] = sprintf(
                'Archive format_version %d is newer than the latest this build understands (%d); update the plugin before restoring.',
                $format_version,
                self::MAX_FORMAT_VERSION
            );
        }

        $scope = (string) ($manifest['scope'] ?? '');
        if (! in_array($scope, self::RESTORABLE_SCOPES, true)) {
            $refusals[] = sprintf(
                'The archive scope is "%s", which carries no database dump; only "all" or "database" archives can be restored.',
                esc_html($scope)
            );
        } elseif (! self::zip_has_entry($archive, 'db.sql')) {
            $refusals[] = 'The archive has no db.sql entry although its manifest claims a database scope; it may be truncated or hand-edited.';
        }

        if ($include_files && 'all' !== $scope) {
            $refusals[] = sprintf(
                'include_files was requested but the archive scope is "%s", which carries no wp-content.',
                esc_html($scope)
            );
        }

        if (! isset($manifest['site']['table_prefix']) || '' === (string) $manifest['site']['table_prefix']) {
            $refusals[] = 'The manifest does not record a table_prefix; refusing to guess whether it matches this site.';
        } else {
            $archive_prefix = (string) $manifest['site']['table_prefix'];
            if ($archive_prefix !== $wpdb->prefix) {
                $refusals[] = sprintf(
                    'Table prefix mismatch: the archive uses "%s" but this site uses "%s". A same-site restore cannot change the prefix.',
                    esc_html($archive_prefix),
                    esc_html($wpdb->prefix)
                );
            }
        }

        if (! isset($manifest['site']['multisite'])) {
            $refusals[] = 'The manifest does not record whether the source was multisite; refusing to guess.';
        } else {
            $archive_multisite = (bool) $manifest['site']['multisite'];
            if ($archive_multisite !== is_multisite()) {
                $refusals[] = sprintf(
                    'Multisite mismatch: the archive is from a %s install but this site is %s.',
                    $archive_multisite ? 'multisite' : 'single-site',
                    is_multisite() ? 'multisite' : 'single-site'
                );
            }
        }

        $archive_wp = (string) ($manifest['versions']['wordpress'] ?? '');
        $target_wp  = (string) ($wp_version ?? '');
        if ('' !== $archive_wp && '' !== $target_wp && version_compare($target_wp, $archive_wp, '<')) {
            $warnings[] = sprintf(
                'The archive was taken on WordPress %s but this site runs %s; restoring is a database downgrade and core may re-run migrations.',
                esc_html($archive_wp),
                esc_html($target_wp)
            );
        }

        $blob_tables = (array) ($manifest['database']['blob_tables'] ?? []);
        if (! empty($blob_tables)) {
            $warnings[] = sprintf(
                'These tables hold BLOB columns that round-tripped through escaped string literals and may need spot-checking after restore: %s.',
                esc_html(implode(', ', array_map('strval', $blob_tables)))
            );
        }

        return [
            'refusals' => $refusals,
            'warnings' => $warnings,
        ];
    }

    /**
     * Whether the archive contains an entry with exactly this name, without
     * extracting anything. The manifest was already read, so the zip is
     * known to open; a failure here is treated as the entry being absent.
     */
    private static function zip_has_entry(string $archive, string $name): bool
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($archive)) {
            return false;
        }

        $found = false !== $zip->locateName($name);
        $zip->close();

        return $found;
    }
}
