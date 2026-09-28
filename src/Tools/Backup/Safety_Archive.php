<?php

namespace WPMCP\Tools\Backup;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The "snapshot first" step for whole-database writes (issue #191).
 *
 * A site-wide URL rewrite, or a migration that replaces every table, is
 * outside the per-object model Safety\Snapshot_Store captures: a snapshot
 * of one post cannot undo a pass over six tables. The undo for those
 * writes is a database-scope site archive taken immediately before, through
 * the ordinary backup job machinery, so it shows up in list-backup-jobs and
 * is restored like any other archive (restore-site-backup with its job id).
 *
 * take() runs the job synchronously and throws when no archive file was
 * produced. Callers must treat that as "refuse the write": a whole-database
 * change with no way back is exactly what this class exists to prevent.
 *
 * Restore_Site_Backup (issue #190) takes its own pre-restore archive the
 * same way; this is the shared form for the tools that came after it.
 */
class Safety_Archive
{
    /**
     * @param string        $purpose  Recorded on the job so list-backup-jobs
     *                                says why the archive exists.
     * @param callable|null $producer Run_Backup_Job's artifact producer;
     *                                null uses the real database archive.
     * @return array{job_id: int, file: string}
     * @throws \RuntimeException When the archive could not be produced.
     */
    public static function take(string $purpose, ?callable $producer = null): array
    {
        $job = Backup_Job_Store::create('database', 'database');
        $id  = (int) $job['id'];
        Backup_Job_Store::update($id, ['purpose' => $purpose]);

        (new Run_Backup_Job($producer))->handle($id);

        $done = Backup_Job_Store::get($id);
        $file = is_array($done) ? (string) ($done['result']['file'] ?? '') : '';

        if (! is_array($done) || 'completed' !== $done['status'] || '' === $file || ! is_file($file)) {
            $error = is_array($done) && ! empty($done['error']) ? (string) $done['error'] : 'no archive file was produced';
            throw new \RuntimeException(sprintf(
                'The %s (backup job %d) failed, so nothing was changed: %s',
                esc_html($purpose),
                (int) $id,
                esc_html($error)
            ));
        }

        return ['job_id' => $id, 'file' => $file];
    }

    /** The instruction a caller reports so a human or agent can undo the write. */
    public static function undo_hint(int $job_id): string
    {
        return sprintf(
            'To put the database back exactly as it was before this change, run restore-site-backup with job_id %d and dry_run false.',
            $job_id
        );
    }
}
