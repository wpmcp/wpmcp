<?php

namespace WPMCP\Tools\Backup;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Kick off an async backup: create a queued job record and schedule a
 * single WP-Cron event (Run_Backup_Job::HOOK, passed the job id) that will
 * actually produce the backup artifact and flip the job to completed/failed.
 *
 * This only reads site data and writes to the job-store option and (once the
 * scheduled event runs) a backup artifact file; it never mutates user
 * content, so it is not routed through Safe_Mutation and does not touch the
 * safety core. Governance and the capability gate (manage_options, checked
 * by the Registrar/Ability layer at registration) are the only gates.
 *
 * With every (issue #455) it sets, replaces or removes the type's recurring
 * schedule instead of queueing a job now; keep is that schedule's
 * retention. See Backup_Schedule.
 */
class Trigger_Backup
{
    /** @return array<string,mixed>|\WP_Error */
    public function handle(array $args)
    {
        $type  = isset($args['type']) ? (string) $args['type'] : 'full';
        $scope = isset($args['scope']) ? (string) $args['scope'] : 'all';

        if (isset($args['every'])) {
            $keep = isset($args['keep']) ? (int) $args['keep'] : null;
            return Backup_Schedule::set($type, $scope, (string) $args['every'], $keep);
        }
        if (isset($args['keep'])) {
            return new \WP_Error('wpmcp_backup_schedule_invalid', 'keep applies to a schedule: pass every as well.');
        }

        $job = Backup_Job_Store::create($type, $scope);

        wp_schedule_single_event(time(), Run_Backup_Job::HOOK, [$job['id']]);

        return [
            'job_id' => $job['id'],
            'status' => $job['status'],
        ];
    }
}
