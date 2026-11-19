<?php

namespace WPMCP\Tools\Backup;

use WPMCP\Tools\Export\Export_Content;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The WP-Cron executor for a queued backup job. Trigger_Backup schedules a
 * single event on self::HOOK with the job id as its only argument;
 * Plugin::boot() hooks self::HOOK to [new Run_Backup_Job(), 'handle'] so
 * WordPress invokes it on the next cron run.
 *
 * Produces the backup artifact via the existing Export_Content tool (a WXR
 * export), reusing the Snapshot/Export machinery rather than inventing a new
 * artifact format. Any exception from that call (including export_wp()'s
 * documented "only once per PHP process" limitation, see Export_Content's
 * docblock) is caught and recorded as a failed job with the error message,
 * rather than left running or allowed to fatal the cron request: a status
 * of "running" forever, with no way to observe what went wrong, would be
 * worse than an honest "failed".
 *
 * An unknown job id (e.g. the job-store option was cleared after the event
 * was scheduled) is a silent no-op: there is no job record to flip, and
 * WP-Cron has no return value to report back to, so surfacing an error here
 * would have nowhere useful to go.
 *
 * The artifact-producing step is a constructor-injected callable, defaulting
 * to self::default_producer(). This is not merely a test convenience:
 * Export_Content's underlying export_wp() can only be safely called once per
 * PHP process, ever (a real WordPress core limitation, see Export_Content's
 * docblock), a budget already shared with ExportContentTest. Injecting the
 * producer lets tests verify this executor's own status-transition and
 * artifact-recording behavior (what this class is actually responsible for)
 * without contending over that unresettable, process-wide resource; the
 * default callable itself is exercised in production exactly as written.
 *
 * The producer receives the whole job record, so the artifact format follows
 * the job's requested type: a 'content' job produces the WXR export this
 * tool has always produced, while 'full', 'database', 'files' and 'uploads'
 * produce a portable site archive (Site_Archive_Builder). Routing here
 * rather than at schedule time means the type recorded on the job is the
 * single source of truth for what was actually backed up.
 *
 * The producer's second argument is a progress callback (issue #771). The
 * catch below only sees an exception; a request that PHP ends for
 * max_execution_time or memory_limit skips it, and before this change such
 * a job stayed "running" with updated_at frozen at the claim. The callback
 * writes the producer's progress to the job every HEARTBEAT_SECONDS
 * (Backup_Job_Store::heartbeat), so a job that stops reporting is found
 * dead by the store's stale sweep within STALE_HEARTBEAT_AFTER and its
 * partial archive is removed; the heartbeat also notices a cancel from
 * another request and stops the build by throwing Backup_Job_Canceled.
 *
 * Chunked builds (issue #783). On a host with a PHP time limit the WP-Cron
 * hook runs handle_scheduled(), which builds an archive job in steps
 * (Chunked_Archive_Build): each request does work until a soft budget is
 * spent, saves the build on the job and schedules the next step. Before
 * its work a step arms a watchdog event, so a step the host kills anyway
 * is taken over once it has been silent STEP_IDLE_SECONDS, from the last
 * saved state; MAX_STEP_ATTEMPTS killed steps in a row fail the job. With
 * no time limit (max_execution_time 0) the archive is still built in one
 * request, as before. Filters: wpmcp_backup_chunked (bool, job) and
 * wpmcp_backup_step_limits (see step_limits()). handle() stays the
 * one-request build for callers that need the archive at once (the
 * safety archive a restore takes).
 */
class Run_Backup_Job
{
    public const HOOK = 'wpmcp_run_backup_job';

    /** Job types that produce a site archive rather than a WXR export. */
    public const ARCHIVE_TYPES = ['full', 'database', 'files', 'uploads'];

    /**
     * Seconds a step marked running may stay silent before another delivery
     * of the event takes it over as dead. A live step heartbeats at least
     * every Backup_Job_Store::HEARTBEAT_SECONDS, including inside close().
     */
    public const STEP_IDLE_SECONDS = 90;

    /** Steps in a row the host may kill before the job is failed. */
    public const MAX_STEP_ATTEMPTS = 3;

    /** @var callable(array, callable): array */
    private $producer;

    /** Whether the producer is the default one, the only one that can chunk. */
    private bool $default_producer;

    public function __construct(?callable $producer = null)
    {
        $this->default_producer = null === $producer;
        $this->producer         = $producer ?? self::default_producer();
    }

    /**
     * Whether a job is built in steps: an archive job on a host with a PHP
     * time limit. Filter wpmcp_backup_chunked to force it either way.
     */
    public static function chunked(array $job): bool
    {
        if (null === self::archive_scope($job)) {
            return false;
        }

        return (bool) apply_filters('wpmcp_backup_chunked', (int) ini_get('max_execution_time') > 0, $job);
    }

    /**
     * How much one request may do. seconds: the soft budget, after which no
     * new unit of work starts; hard_seconds: when a close() still writing
     * is canceled (null for none), both from the start of the step and
     * within the request's own time limit, counted from the request start;
     * batch_files and batch_bytes cap one batch of files; dump_rows is the
     * rows per SELECT. Filter: wpmcp_backup_step_limits (limits, job).
     *
     * @return array{seconds: float, hard_seconds: ?float, batch_files: int, batch_bytes: int, dump_rows: int}
     */
    public static function step_limits(array $job): array
    {
        $limit   = (int) ini_get('max_execution_time');
        $elapsed = isset($_SERVER['REQUEST_TIME_FLOAT']) ? max(0.0, microtime(true) - (float) $_SERVER['REQUEST_TIME_FLOAT']) : 0.0;

        $hard = $limit > 0 ? max(1.0, $limit * 0.8 - $elapsed) : null;
        $soft = $limit > 0 ? max(1.0, min(60.0, $limit * 0.4)) : 60.0;
        if (null !== $hard) {
            $soft = min($soft, $hard / 2);
        }

        $limits = (array) apply_filters('wpmcp_backup_step_limits', [
            'seconds'      => $soft,
            'hard_seconds' => $hard,
            'batch_files'  => 20000,
            'batch_bytes'  => 1073741824,
            'dump_rows'    => Db_Dumper::BATCH,
        ], $job);

        return [
            'seconds'      => max(0.0, (float) ($limits['seconds'] ?? $soft)),
            'hard_seconds' => isset($limits['hard_seconds']) ? max(0.0, (float) $limits['hard_seconds']) : null,
            'batch_files'  => max(1, (int) ($limits['batch_files'] ?? 20000)),
            'batch_bytes'  => max(1, (int) ($limits['batch_bytes'] ?? 1073741824)),
            'dump_rows'    => max(1, (int) ($limits['dump_rows'] ?? Db_Dumper::BATCH)),
        ];
    }

    /**
     * Route a job to its artifact producer by type.
     *
     * An unrecognised type falls back to the WXR export rather than erroring:
     * the type field predates this routing and older queued jobs (and any
     * caller passing something novel) must keep producing the artifact they
     * always did.
     *
     * @return callable(array, callable): array
     */
    public static function default_producer(): callable
    {
        return static function (array $job, ?callable $progress = null): array {
            $scope = self::archive_scope($job);

            if (null !== $scope) {
                return (new Site_Archive_Builder())->build($scope, null, $progress);
            }

            return (new Export_Content())->handle([]);
        };
    }

    /**
     * The progress callback handed to the producer: writes the progress to
     * the job at most every HEARTBEAT_SECONDS (the first report and a phase
     * change go through at once, so the partial archive's path is on the
     * record before a byte is written), and stops the build when the job is
     * no longer the worker's to finish.
     *
     * @return callable(array):void
     */
    private static function heartbeat(int $job_id): callable
    {
        $last_at    = null;
        $last_phase = null;

        return static function (array $progress) use ($job_id, &$last_at, &$last_phase): void {
            $now   = Backup_Job_Store::now();
            $phase = $progress['phase'] ?? null;
            if (null !== $last_at && $phase === $last_phase && $now - $last_at < Backup_Job_Store::HEARTBEAT_SECONDS) {
                return;
            }
            $last_at    = $now;
            $last_phase = $phase;

            if (null === Backup_Job_Store::heartbeat($job_id, $progress)) {
                throw new Backup_Job_Canceled(sprintf('Backup job %d was canceled while it was running.', (int) $job_id));
            }
        };
    }

    /**
     * What a cron request may do for a long build without the directory
     * review's objections: keep running after the loopback client that
     * spawned WP-Cron goes away, and use the memory WordPress allows an
     * administrative task (WP_MAX_MEMORY_LIMIT; filter
     * wpmcp_backup_memory_limit). max_execution_time is left alone, which
     * is why the heartbeat exists.
     */
    private static function extend_limits(): void
    {
        if (function_exists('ignore_user_abort')) {
            ignore_user_abort(true);
        }
        wp_raise_memory_limit('wpmcp_backup');
    }

    /**
     * The archive scope a job maps to, or null when the job should produce a
     * WXR export instead.
     *
     * A 'full' job honours an explicitly narrowed scope (so scope is not
     * silently ignored when someone sets it), while the narrower types carry
     * their scope in the type itself. 'all' is the job store's default and
     * means "whatever this type implies" rather than a deliberate choice,
     * which is why it does not override the type.
     */
    public static function archive_scope(array $job): ?string
    {
        $type = (string) ($job['type'] ?? 'full');

        if (! in_array($type, self::ARCHIVE_TYPES, true)) {
            return null;
        }

        if ('full' !== $type) {
            return $type;
        }

        $scope = (string) ($job['scope'] ?? 'all');

        return in_array($scope, ['database', 'files', 'uploads'], true) ? $scope : 'all';
    }

    /**
     * Why a job's recorded owner may no longer run it, or null when it may
     * or the job records no owner (a schedule's job is vetted against its
     * creator by Backup_Schedule::job_refusal() instead).
     */
    private static function owner_refusal(array $job): ?string
    {
        if (! array_key_exists('user_id', $job)) {
            return null;
        }

        $capability = Network_Scope::capability();
        $owner      = (int) $job['user_id'];
        if ($owner > 0 && user_can($owner, $capability)) {
            return null;
        }

        return sprintf('Canceled because the user who queued this backup no longer has %s.', $capability);
    }

    /**
     * The WP-Cron entry point (Plugin::boot() hooks it to self::HOOK). A
     * job that is not chunked runs through handle() in this request; a
     * chunked one starts or continues its build by one step.
     */
    public function handle_scheduled(int $job_id): void
    {
        $job = Backup_Job_Store::get($job_id);
        if (null === $job || Restore_Site_Backup::JOB_TYPE === ($job['type'] ?? null)) {
            return;
        }

        $build = $job['build'] ?? null;
        if (! is_array($build)) {
            if ('queued' === ($job['status'] ?? null) && $this->default_producer && self::chunked($job)) {
                $claimed = $this->vet_and_claim($job_id, $job);
                if (null !== $claimed) {
                    $this->run_step($job_id, $claimed, false);
                }
                return;
            }
            $this->handle($job_id);
            return;
        }

        // A step event of a job that is over: a cancel or the stale sweep
        // ended it between steps, or while a step was being killed. All
        // that is left to do is remove what the build wrote.
        if ('running' !== ($job['status'] ?? null)) {
            self::discard_build($build);
            Backup_Job_Store::update($job_id, [], ['build']);
            wp_clear_scheduled_hook(self::HOOK, [$job_id]);
            return;
        }

        $claim = Backup_Job_Store::claim_step($job_id, self::STEP_IDLE_SECONDS);
        if (null === $claim['job']) {
            if ($claim['busy']) {
                // The live step finishes and schedules the next one; this
                // event comes back in case it dies instead.
                self::arm_watchdog($job_id);
            }
            return;
        }

        $this->run_step($job_id, $claim['job'], $claim['died']);
    }

    /**
     * One step of a chunked build, in this request: arm the watchdog, run
     * the build until the budget is spent, save it, schedule the next step
     * (or record the archive). A failure fails the job and removes every
     * file the build wrote.
     */
    private function run_step(int $job_id, array $job, bool $died): void
    {
        self::extend_limits();
        self::arm_watchdog($job_id);

        $build = is_array($job['build'] ?? null) ? $job['build'] : null;
        try {
            $builder = new Chunked_Archive_Build();
            if (null === $build) {
                $build = $builder->start((string) self::archive_scope($job));
            }
            if ($died) {
                if ((int) $build['attempts'] > self::MAX_STEP_ATTEMPTS) {
                    throw new \RuntimeException(sprintf(
                        'Stopped: the host ended %d steps of this backup in a row before they finished (last at: %s). The partial archive was removed. Lower the step budget (filter wpmcp_backup_step_limits) or raise max_execution_time for WP-Cron requests, and start the backup again.',
                        (int) $build['attempts'],
                        Site_Archive_Builder::describe_progress(Chunked_Archive_Build::progress($build))
                    ));
                }
                $build = Chunked_Archive_Build::recover($build);
            }
            $build['step_running'] = true;
            if (null === Backup_Job_Store::save_step($job_id, $build, Chunked_Archive_Build::progress($build))) {
                throw new Backup_Job_Canceled(sprintf('Backup job %d was canceled.', $job_id));
            }

            $limits = self::step_limits($job);
            $build  = $builder->step($build, $limits, self::heartbeat($job_id));
            $build['step_running'] = false;
            $build['attempts']     = 0;
        } catch (Backup_Job_Canceled $e) {
            unset($e);
            if (null !== $build) {
                self::discard_build($build);
            }
            Backup_Job_Store::update($job_id, [], ['build']);
            wp_clear_scheduled_hook(self::HOOK, [$job_id]);
            return;
        } catch (\Throwable $e) {
            if (null !== $build) {
                self::discard_build($build);
            }
            Backup_Job_Store::finish($job_id, [
                'status' => 'failed',
                'result' => null,
                'error'  => $e->getMessage(),
            ], ['build']);
            wp_clear_scheduled_hook(self::HOOK, [$job_id]);
            self::after_job($job_id);
            return;
        }

        if ('done' === $build['phase']) {
            $recorded = Backup_Job_Store::finish($job_id, [
                'status'   => 'completed',
                'result'   => $build['result'],
                'error'    => null,
                'progress' => null,
            ], ['build']);
            if (null === $recorded) {
                // Canceled while the last step ran: no archive is kept.
                $file = (string) ($build['result']['file'] ?? '');
                if ('' !== $file && is_file($file)) {
                    wp_delete_file($file);
                }
                Backup_Job_Store::update($job_id, [], ['build']);
            }
            wp_clear_scheduled_hook(self::HOOK, [$job_id]);
            self::after_job($job_id);
            return;
        }

        if (null === Backup_Job_Store::save_step($job_id, $build, Chunked_Archive_Build::progress($build))) {
            self::discard_build($build);
            Backup_Job_Store::update($job_id, [], ['build']);
            wp_clear_scheduled_hook(self::HOOK, [$job_id]);
            return;
        }

        // The next step, in the next WP-Cron request. The watchdog this
        // step armed is replaced: the job is no longer mid-step.
        wp_clear_scheduled_hook(self::HOOK, [$job_id]);
        wp_schedule_single_event(time(), self::HOOK, [$job_id]);
    }

    /**
     * Schedule this job's step event to come back once a step that is
     * running now would count as dead, replacing any event already queued
     * for it (WP-Cron refuses a second identical event within ten minutes).
     */
    private static function arm_watchdog(int $job_id): void
    {
        wp_clear_scheduled_hook(self::HOOK, [$job_id]);
        wp_schedule_single_event(time() + self::STEP_IDLE_SECONDS + 10, self::HOOK, [$job_id]);
    }

    /** Remove every file a chunked build wrote but did not finish. */
    private static function discard_build(array $build): void
    {
        Site_Archive_Builder::discard_partial(Chunked_Archive_Build::progress($build));
    }

    /** Retention for a scheduled job, never able to change its outcome. */
    private static function after_job(int $job_id): void
    {
        try {
            Backup_Schedule::after_job($job_id);
        } catch (\Throwable $e) {
            unset($e);
        }

        // Archives made before WPMCP_BACKUP_DIR was set move once no job
        // holds a path into the old folder (issue #846). Local file moves
        // only: the web self-check never runs on the schedule.
        try {
            Backup_Dir_Health::migrate_legacy();
        } catch (\Throwable $e) {
            unset($e);
        }
    }

    /**
     * Vet a queued job and claim it (queued to running in one locked step),
     * or record why it may not run. Returns the claimed record or null.
     */
    private function vet_and_claim(int $job_id, array $job): ?array
    {
        // The user who queued a one-off backup must still be allowed to
        // run one (manage_options, manage_network_options on multisite).
        // Cron has nobody signed in, so Network_Scope's own check cannot
        // tell; this is the check it relies on.
        $refusal = self::owner_refusal($job);
        if (null !== $refusal) {
            Backup_Job_Store::update($job_id, [ 'status' => 'canceled', 'error' => $refusal ]);
            return null;
        }

        // A job a schedule queued on multisite runs only when that
        // schedule's creator is a network admin (issue #541): a sub-site
        // admin's pre-v0.8.168 schedule must not produce one more
        // network-wide archive.
        $refusal = Backup_Schedule::job_refusal($job);
        if (null !== $refusal) {
            Backup_Job_Store::update($job_id, [ 'status' => 'canceled', 'error' => $refusal ]);
            return null;
        }

        // Claim the job: queued to running in one locked step (issue #607),
        // so a second delivery of the same event that read "queued" a moment
        // ago cannot run it as well. The producer receives the post-update
        // record: handing it a job that still claims to be queued would make
        // any status the producer reads or logs a lie.
        return Backup_Job_Store::transition($job_id, 'queued', ['status' => 'running']);
    }

    public function handle(int $job_id): void
    {
        $job = Backup_Job_Store::get($job_id);
        if (null === $job) {
            return;
        }

        // Only a queued job runs (issue #563). A completed, failed or
        // canceled one would be produced again (on multisite, one more
        // network-wide archive), and a running one means this is a
        // duplicate delivery. The job keeps its outcome; the refused run is
        // recorded on it so it is visible in get-backup-status.
        // A queued restore (issue #727) is a job in this store too, but it
        // is Run_Restore_Job's to run; producing a backup for it here would
        // also flip it to completed without restoring anything.
        if (Restore_Site_Backup::JOB_TYPE === ($job['type'] ?? null)) {
            return;
        }

        $status = (string) ($job['status'] ?? '');
        if ('queued' !== $status) {
            Backup_Job_Store::update($job_id, [
                'last_refusal' => sprintf('A run was refused because the job is %s, not queued.', $status),
            ]);
            return;
        }

        $claimed = $this->vet_and_claim($job_id, $job);
        if (null === $claimed) {
            return;
        }
        $job = $claimed;

        self::extend_limits();

        try {
            $artifact = ($this->producer)($job, self::heartbeat($job_id));
            $recorded = Backup_Job_Store::finish($job_id, [
                'status'   => 'completed',
                'result'   => $artifact,
                'error'    => null,
                'progress' => null,
            ]);
            if (null === $recorded) {
                // Canceled between the last heartbeat and the finish: the
                // user asked for no archive, so the one just written goes.
                $file = is_array($artifact) ? (string) ($artifact['file'] ?? '') : '';
                if ('' !== $file && is_file($file)) {
                    wp_delete_file($file);
                }
            }
        } catch (Backup_Job_Canceled $e) {
            // cancel-backup-job wrote the record; the builder removed the
            // partial archive on the way out.
            unset($e);
        } catch (\Throwable $e) {
            Backup_Job_Store::finish($job_id, [
                'status' => 'failed',
                'result' => null,
                'error'  => $e->getMessage(),
            ]);
        }

        // Retention for a scheduled job (issue #455), outside the try so a
        // pruning problem can never turn a completed backup into a failed one.
        self::after_job($job_id);
    }
}
