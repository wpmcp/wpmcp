<?php

namespace WPMCP\Tools\Backup;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: return a backup job's current record (status, result/error,
 * timestamps) by id. Does not mutate the site.
 *
 * A queued restore (issue #727) is a job too. While its import runs, the
 * options table holding the job record is being replaced, so the record
 * may be missing or stale; the restore's progress file on disk is read
 * instead and the job is reported as running, with the statement and table
 * reached and the time maintenance mode went on. A progress file whose
 * restore no longer holds the lock means the run died mid-import: the job
 * is reported failed, with the safety archive to restore. Backup_Job_Store
 * marks the stored record failed the same way (issue #860), so
 * list-backup-jobs agrees and the recovery restore is not refused.
 *
 * Every answer also carries `storage` (issue #846): the backup folder in
 * use, whether it came from WPMCP_BACKUP_DIR, whether it is outside the web
 * root, and the last check that the folder is not downloadable. That check
 * is refreshed here when it is more than a day old (Backup_Dir_Health).
 */
class Get_Backup_Status
{
    public function handle(array $args)
    {
        $job_id = (int) ($args['job_id'] ?? 0);
        $job    = Backup_Job_Store::get($job_id);

        $live = self::live_restore($job_id, $job);
        if (null !== $live) {
            // The database is being replaced: report, never write.
            return $live + ['storage' => Backup_Dir_Health::report()];
        }

        if (null === $job) {
            return new \WP_Error('wpmcp_backup_job_not_found', "No backup job found with id \"{$job_id}\".");
        }

        // A chunked build's step cursor (issue #783) is internal; its
        // progress record says how far the build got.
        unset($job['build']);
        $job['storage'] = self::storage();

        return $job;
    }

    /**
     * The folder in use and its last exposure check, refreshed when due.
     * Only the check runs here; moving archives is left to the backup jobs.
     */
    private static function storage(): array
    {
        if (Backup_Dir_Health::is_due()) {
            try {
                Backup_Dir_Health::self_check();
            } catch (\Throwable $e) {
                unset($e);
            }
        }

        return Backup_Dir_Health::report();
    }

    /** The record of a restore whose import is under way or died, or null. */
    private static function live_restore(int $job_id, ?array $job): ?array
    {
        if ($job_id <= 0) {
            return null;
        }

        $progress = Restore_Site_Backup::progress();
        if (null === $progress || $job_id !== (int) ($progress['state']['restore_job_id'] ?? 0)) {
            return null;
        }

        // A finished record is the outcome; a leftover progress file does
        // not override it.
        $finished = is_array($job) && Restore_Site_Backup::JOB_TYPE === ($job['type'] ?? null)
            && in_array($job['status'] ?? null, ['completed', 'failed', 'canceled'], true);
        if ($finished && ! $progress['running']) {
            return null;
        }

        $base = is_array($job) && Restore_Site_Backup::JOB_TYPE === ($job['type'] ?? null)
            ? $job
            : [
                'id'         => $job_id,
                'type'       => Restore_Site_Backup::JOB_TYPE,
                'scope'      => '',
                'created_at' => Backup_Job_Store::now(),
                'updated_at' => Backup_Job_Store::now(),
            ];

        $stopped = Restore_Site_Backup::stopped_fields($progress['state']);

        if ($progress['running']) {
            return array_merge($base, $stopped, ['status' => 'running', 'error' => null]);
        }

        return array_merge($base, $stopped);
    }
}
