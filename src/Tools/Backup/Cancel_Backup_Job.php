<?php

namespace WPMCP\Tools\Backup;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Cancel a queued backup job: mark it canceled and unschedule its WP-Cron
 * event so it never runs. Only a job still in 'queued' status can be
 * canceled; once it has moved to running (or any terminal status) there is
 * either nothing left to unschedule or the outcome has already happened, so
 * "cancel" would be misleading and this refuses with a clear WP_Error
 * instead of silently no-op'ing.
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

        if ('queued' !== $job['status']) {
            return new \WP_Error(
                'wpmcp_backup_job_not_cancelable',
                "Backup job \"{$job_id}\" cannot be canceled: its status is \"{$job['status']}\", not \"queued\"."
            );
        }

        wp_clear_scheduled_hook(Run_Backup_Job::HOOK, [$job_id]);
        // A queued restore (issue #727) waits on its own hook.
        wp_clear_scheduled_hook(Run_Restore_Job::HOOK, [$job_id]);

        $updated = Backup_Job_Store::update($job_id, ['status' => 'canceled']);

        return [
            'job_id' => $job_id,
            'status' => $updated['status'],
        ];
    }
}
