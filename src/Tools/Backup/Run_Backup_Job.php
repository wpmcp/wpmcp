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
 * to Export_Content (matching the same optional-dependency pattern as e.g.
 * Clear_Cache's Page_Cache_Detector). This is not merely a test convenience:
 * Export_Content's underlying export_wp() can only be safely called once per
 * PHP process, ever (a real WordPress core limitation, see Export_Content's
 * docblock), a budget already shared with ExportContentTest. Injecting the
 * producer lets tests verify this executor's own status-transition and
 * artifact-recording behavior (what this class is actually responsible for)
 * without contending over that unresettable, process-wide resource; the
 * default callable itself is exercised in production exactly as written.
 */
class Run_Backup_Job
{
    public const HOOK = 'wpmcp_run_backup_job';

    /** @var callable(): array */
    private $producer;

    public function __construct(?callable $producer = null)
    {
        $this->producer = $producer ?? static fn(): array => (new Export_Content())->handle([]);
    }

    public function handle(int $job_id): void
    {
        $job = Backup_Job_Store::get($job_id);
        if (null === $job) {
            return;
        }

        Backup_Job_Store::update($job_id, ['status' => 'running']);

        try {
            $artifact = ($this->producer)();
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
