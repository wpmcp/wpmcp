<?php

namespace WPMCP\Tools\Backup;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Delete a site-backup archive from disk, identified by job id or by path.
 *
 * Backups are the largest files this plugin ever writes, and a site that
 * backs up on a schedule will fill its disk without a way to prune. The
 * containment check in Archive_Locator is what keeps this from being a
 * general-purpose file deleter driven by model output.
 *
 * When the archive is reached through a job id, the job record is kept but
 * marked so its result no longer claims a file that is gone: a job history
 * that points at missing artifacts is how someone discovers mid-incident
 * that the backup they were counting on was pruned.
 */
class Delete_Backup_Archive
{
    public function handle(array $args): array
    {
        $archive = Archive_Locator::resolve($args);
        $size    = (int) filesize($archive);

        wp_delete_file($archive);

        clearstatcache(true, $archive);
        if (is_file($archive)) {
            throw new \RuntimeException('The archive could not be deleted; check file permissions.');
        }

        $job_id = isset($args['job_id']) ? (int) $args['job_id'] : 0;
        if ($job_id > 0) {
            $job = Backup_Job_Store::get($job_id);
            if (null !== $job && is_array($job['result'] ?? null)) {
                $result             = $job['result'];
                $result['deleted']  = true;
                Backup_Job_Store::update($job_id, ['result' => $result]);
            }
        }

        return [
            'deleted'      => true,
            'file'         => $archive,
            'bytes_freed'  => $size,
        ];
    }
}
