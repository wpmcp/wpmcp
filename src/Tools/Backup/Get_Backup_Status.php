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
 * is reported failed, with the safety archive to restore.
 */
class Get_Backup_Status
{
    public function handle(array $args)
    {
        $job_id = (int) ($args['job_id'] ?? 0);
        $job    = Backup_Job_Store::get($job_id);

        $live = self::live_restore($job_id, $job);
        if (null !== $live) {
            return $live;
        }

        if (null === $job) {
            return new \WP_Error('wpmcp_backup_job_not_found', "No backup job found with id \"{$job_id}\".");
        }

        return $job;
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

        $state  = $progress['state'];
        $safety = (int) ($state['safety_job_id'] ?? 0);
        $base   = is_array($job) && Restore_Site_Backup::JOB_TYPE === ($job['type'] ?? null)
            ? $job
            : [
                'id'         => $job_id,
                'type'       => Restore_Site_Backup::JOB_TYPE,
                'scope'      => '',
                'created_at' => Backup_Job_Store::now(),
                'updated_at' => Backup_Job_Store::now(),
            ];

        $live = [
            'progress'       => [
                'phase'     => (string) ($state['phase'] ?? 'import'),
                'statement' => (int) ($state['statement'] ?? 0),
                'table'     => (string) ($state['table'] ?? ''),
            ],
            'safety_archive' => ['job_id' => $safety, 'file' => isset($state['safety_file']) ? basename((string) $state['safety_file']) : null],
            'maintenance'    => [
                'enabled_during_restore' => true,
                'started_at'             => $state['maintenance_started_at'] ?? null,
                'ended_at'               => null,
                'seconds'                => null,
            ],
        ];

        if ($progress['running']) {
            return array_merge($base, $live, ['status' => 'running', 'error' => null]);
        }

        // Name the file (issue #814): the half import rewound the job store,
        // so the job id may already belong to a different archive.
        $path = Restore_Site_Backup::safety_archive_path($state);
        $undo = null !== $path
            ? sprintf('Restore the file %s (its pre-restore safety archive; restore-site-backup with path %s)', esc_html(basename($path)), esc_html($path))
            : sprintf('Restore backup job %d (its pre-restore safety archive)', $safety);

        return array_merge($base, $live, [
            'status' => 'failed',
            'error'  => sprintf(
                'The restore stopped mid-import without finishing (last at statement %d on table %s). %s to put the site back; maintenance mode may still be on (disable-maintenance turns it off).',
                (int) ($state['statement'] ?? 0),
                esc_html((string) ($state['table'] ?? 'unknown')),
                $undo
            ),
        ]);
    }
}
