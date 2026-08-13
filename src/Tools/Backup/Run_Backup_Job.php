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
 */
class Run_Backup_Job
{
    public const HOOK = 'wpmcp_run_backup_job';

    /** Job types that produce a site archive rather than a WXR export. */
    public const ARCHIVE_TYPES = ['full', 'database', 'files', 'uploads'];

    /** @var callable(array): array */
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
     * @return callable(array): array
     */
    public static function default_producer(): callable
    {
        return static function (array $job): array {
            $scope = self::archive_scope($job);

            if (null !== $scope) {
                return (new Site_Archive_Builder())->build($scope);
            }

            return (new Export_Content())->handle([]);
        };
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

    public function handle(int $job_id): void
    {
        $job = Backup_Job_Store::get($job_id);
        if (null === $job) {
            return;
        }

        // The producer receives the post-update record, not the record as it
        // was read a line earlier: handing it a job that still claims to be
        // queued would make any status the producer reads or logs a lie.
        $job = Backup_Job_Store::update($job_id, ['status' => 'running']) ?? $job;

        try {
            $artifact = ($this->producer)($job);
            Backup_Job_Store::update($job_id, [
                'status' => 'completed',
                'result' => $artifact,
                'error'  => null,
            ]);
        } catch (\Throwable $e) {
            Backup_Job_Store::update($job_id, [
                'status' => 'failed',
                'result' => null,
                'error'  => $e->getMessage(),
            ]);
        }
    }
}
