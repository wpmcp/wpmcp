<?php

namespace WPMCP\Tools\Backup;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: list backup jobs, newest first, with an optional 'status'
 * filter (queued|running|completed|failed|canceled), plus every recurring
 * backup schedule with its next run, overdue flag and last run (issue
 * #455). On multisite it adds disabled_schedules, every schedule on the
 * network disabled for lack of a network admin creator (issue #541). Does
 * not mutate the site.
 */
class List_Backup_Jobs
{
    public function handle(array $args): array
    {
        $status = isset($args['status']) ? (string) $args['status'] : '';

        $out = [
            'jobs'      => Backup_Job_Store::list($status),
            'schedules' => Backup_Schedule::report(),
        ];
        // Multisite: schedules disabled anywhere on the network because
        // their creator was not a network admin (issue #541).
        if (is_multisite()) {
            $out['disabled_schedules'] = Backup_Schedule::network_report();
        }

        return $out;
    }
}
