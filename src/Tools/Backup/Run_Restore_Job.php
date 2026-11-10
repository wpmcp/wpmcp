<?php

namespace WPMCP\Tools\Backup;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The WP-Cron executor for a queued restore (issue #727). restore-site-backup
 * with dry_run:false queues a 'restore' job in Backup_Job_Store and
 * schedules a single event on self::HOOK with the job id; this runs it.
 *
 * It follows Run_Backup_Job's rules: only a queued job runs (a duplicate
 * delivery or a finished job is refused and the refusal recorded), the
 * queued-to-running claim is one locked transition (issue #607), and the
 * user who queued it must still hold the whole-database capability
 * (manage_options, manage_network_options on multisite) or the job is
 * canceled. That user is then made the current user for the run, so
 * Network_Scope's caller check and Restore_Session (keeping that user
 * signed in) work as they do for a restore inside a request.
 *
 * The restore itself is Restore_Site_Backup::handle() with dry_run false,
 * unchanged: it repeats the compatibility gate and the dump scan, takes the
 * pre-restore safety archive, holds maintenance mode, rolls back a failed
 * import and refuses when another restore holds the restore lock. Its report
 * becomes the job result. The job is completed only when the report says
 * "restored"; any other outcome (rolled back, files not swapped, a refusal or
 * an exception) is failed, with the reason in error and the report, safety
 * archive and maintenance window kept on the record.
 *
 * The job record lives in the options table the restore replaces. handle()
 * puts the job history back after the import; if it stops before that, the
 * record is written back here in full (Backup_Job_Store::put), always, so
 * the outcome is not lost. While the import runs, get-backup-status reads the restore's
 * progress file instead (Restore_Site_Backup::progress()).
 */
class Run_Restore_Job
{
    public const HOOK = 'wpmcp_run_restore_job';

    private ?Restore_Site_Backup $engine;

    /** @param Restore_Site_Backup|null $engine The restore engine; injectable for tests. */
    public function __construct(?Restore_Site_Backup $engine = null)
    {
        $this->engine = $engine;
    }

    public function handle(int $job_id): void
    {
        $job = Backup_Job_Store::get($job_id);
        if (null === $job || Restore_Site_Backup::JOB_TYPE !== ($job['type'] ?? null)) {
            return;
        }

        $status = (string) ($job['status'] ?? '');
        if ('queued' !== $status) {
            Backup_Job_Store::update($job_id, [
                'last_refusal' => sprintf('A run was refused because the job is %s, not queued.', $status),
            ]);
            return;
        }

        $capability = Network_Scope::capability();
        $owner      = (int) ($job['user_id'] ?? 0);
        if ($owner <= 0 || ! user_can($owner, $capability)) {
            Backup_Job_Store::update($job_id, [
                'status' => 'canceled',
                'error'  => sprintf('Canceled because the user who queued this restore no longer has %s.', $capability),
            ]);
            return;
        }

        $claimed = Backup_Job_Store::transition($job_id, 'queued', [
            'status'     => 'running',
            'started_at' => gmdate('c'),
        ]);
        if (null === $claimed) {
            return;
        }

        $previous_user = get_current_user_id();
        wp_set_current_user($owner);

        $engine = ($this->engine ?? new Restore_Site_Backup())->for_job($job_id);
        $fields = [];

        try {
            $report = $engine->handle([
                'path'             => (string) ($job['archive'] ?? ''),
                'dry_run'          => false,
                'include_files'    => ! empty($job['include_files']),
                'preserve_session' => ! array_key_exists('preserve_session', $job) || (bool) $job['preserve_session'],
            ]);

            $restored = 'restored' === ($report['status'] ?? null);
            $fields   = [
                'status'         => $restored ? 'completed' : 'failed',
                'result'         => $report,
                'error'          => $restored ? null : self::failure_message($report),
                'safety_archive' => $report['safety_archive'] ?? null,
                'maintenance'    => $report['maintenance'] ?? null,
            ];
        } catch (\Throwable $e) {
            $fields = [
                'status'      => 'failed',
                'result'      => null,
                'error'       => $e->getMessage(),
                'maintenance' => $engine->last_maintenance_window(),
            ];
        } finally {
            wp_set_current_user($previous_user);
        }

        $fields['finished_at'] = gmdate('c');

        // The whole record, not a merge: if the restore stopped before it
        // put the job history back, the store now holds the backup's copy,
        // which may lack this job or hold an unrelated one under its id.
        Backup_Job_Store::put(array_merge($claimed, $fields));
    }

    /** The error recorded for a restore that ran but did not restore. */
    private static function failure_message(array $report): string
    {
        $status = (string) ($report['status'] ?? 'failed');
        $safety = (int) ($report['safety_archive']['job_id'] ?? 0);

        switch ($status) {
            case 'failed_rolled_back':
                $text = 'The import failed and the site was rolled back to its pre-restore state.';
                break;
            case 'database_restored_files_failed':
                $text = 'The database was restored but wp-content could not be swapped in.';
                break;
            default:
                $text = 'The import failed and the rollback did not complete.';
        }

        $error = $report['failure']['error'] ?? ($report['files']['error'] ?? null);
        if (is_string($error) && '' !== $error) {
            $text .= ' ' . $error;
        }

        if ($safety > 0) {
            $text .= sprintf(' The pre-restore safety archive is backup job %d.', $safety);
        }

        return $text;
    }
}
