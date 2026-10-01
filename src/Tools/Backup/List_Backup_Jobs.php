<?php

namespace WPMCP\Tools\Backup;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: list backup jobs, newest first, with an optional 'status'
 * filter (queued|running|completed|failed|canceled), plus every recurring
 * backup schedule with its next run, overdue flag and last run (issue
 * #455). Does not mutate the site.
 */
class List_Backup_Jobs
{
    public function handle(array $args): array
    {
        $status = isset($args['status']) ? (string) $args['status'] : '';

        return [
            'jobs'      => Backup_Job_Store::list($status),
            'schedules' => Backup_Schedule::report(),
        ];
    }
}
