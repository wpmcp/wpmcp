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
 * The whole archive is still built in one request; building it in resumable
 * steps across cron runs is a separate change.
 */
class Run_Backup_Job
{
    public const HOOK = 'wpmcp_run_backup_job';

    /** Job types that produce a site archive rather than a WXR export. */
    public const ARCHIVE_TYPES = ['full', 'database', 'files', 'uploads'];

    /** @var callable(array, callable): array */
    private $producer;

    public function __construct(?callable $producer = null)
    {
        $this->producer = $producer ?? self::default_producer();
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

        // The user who queued a one-off backup must still be allowed to
        // run one (manage_options, manage_network_options on multisite).
        // Cron has nobody signed in, so Network_Scope's own check cannot
        // tell; this is the check it relies on.
        $refusal = self::owner_refusal($job);
        if (null !== $refusal) {
            Backup_Job_Store::update($job_id, [ 'status' => 'canceled', 'error' => $refusal ]);
            return;
        }

        // A job a schedule queued on multisite runs only when that
        // schedule's creator is a network admin (issue #541): a sub-site
        // admin's pre-v0.8.168 schedule must not produce one more
        // network-wide archive.
        $refusal = Backup_Schedule::job_refusal($job);
        if (null !== $refusal) {
            Backup_Job_Store::update($job_id, [ 'status' => 'canceled', 'error' => $refusal ]);
            return;
        }

        // Claim the job: queued to running in one locked step (issue #607),
        // so a second delivery of the same event that read "queued" a moment
        // ago cannot run it as well. The producer receives the post-update
        // record: handing it a job that still claims to be queued would make
        // any status the producer reads or logs a lie.
        $claimed = Backup_Job_Store::transition($job_id, 'queued', ['status' => 'running']);
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
        try {
            Backup_Schedule::after_job($job_id);
        } catch (\Throwable $e) {
            unset($e);
        }
    }
}
