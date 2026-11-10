<?php

namespace WPMCP\Tools\Backup;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Cancel a backup job.
 *
 * A queued job is marked canceled and its WP-Cron event unscheduled, so it
 * never runs. A running job (issue #771) is marked canceled too: if its run
 * is alive it sees the new status at its next heartbeat and stops, removing
 * the partial archive; if the run died (the PHP time or memory limit ended
 * the cron request, so nothing will ever finish it) the partial archive its
 * progress names is removed here. Either way no archive comes of the job.
 *
 * A job in a terminal status (completed, failed, canceled) is refused with a
 * clear WP_Error rather than silently no-op'd: its outcome has already
 * happened, so "cancel" would be misleading.
 */
class Cancel_Backup_Job
{
    public function handle(array $args)
    {
        $job_id = (int) ($args['job_id'] ?? 0);
        $job    = Backup_Job_Store::get($job_id);

        if (null === $job) {
            return new \WP_Error('wpmcp_backup_job_not_found', "No backup job found with id \"{$job_id}\".");
        }

        $status = (string) $job['status'];
        if (! in_array($status, ['queued', 'running'], true)) {
            return new \WP_Error(
                'wpmcp_backup_job_not_cancelable',
                "Backup job \"{$job_id}\" cannot be canceled: its status is \"{$status}\", not \"queued\" or \"running\"."
            );
        }

        // A running restore (issue #727) cannot be stopped from outside: its
        // import is replacing the database and marking it canceled while it
        // runs would misreport what the site ends up with. Its progress file
        // reports it, and a dead one is found failed by get-backup-status.
        if ('running' === $status && Restore_Site_Backup::JOB_TYPE === ($job['type'] ?? null)) {
            return new \WP_Error(
                'wpmcp_backup_job_not_cancelable',
                "Backup job \"{$job_id}\" is a running restore and cannot be canceled; poll get-backup-status for its outcome."
            );
        }

        if ('queued' === $status) {
            wp_clear_scheduled_hook(Run_Backup_Job::HOOK, [$job_id]);
            // A queued restore (issue #727) waits on its own hook.
            wp_clear_scheduled_hook(Run_Restore_Job::HOOK, [$job_id]);
        }

        // From the status read above, under the lock: a run that finished
        // in the meantime keeps its outcome.
        $updated = Backup_Job_Store::transition($job_id, $status, [
            'status' => 'canceled',
            'result' => null,
            'error'  => 'running' === $status
                ? 'Canceled by cancel-backup-job while running; no archive was kept.'
                : null,
        ]);

        if (null === $updated) {
            $now = Backup_Job_Store::get($job_id);
            return new \WP_Error(
                'wpmcp_backup_job_not_cancelable',
                sprintf('Backup job "%d" cannot be canceled: it is now "%s".', $job_id, (string) ($now['status'] ?? 'gone'))
            );
        }

        if (is_array($updated['progress'] ?? null)) {
            Site_Archive_Builder::discard_partial($updated['progress']);
        }

        return [
            'job_id' => $job_id,
            'status' => $updated['status'],
        ];
    }
}
