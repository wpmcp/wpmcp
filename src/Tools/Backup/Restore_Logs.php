<?php

namespace WPMCP\Tools\Backup;

use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\MCP\Request_Log;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Keeps the wpmcp audit and request logs across a database restore (issue
 * #796), and records the restore in the audit log.
 *
 * Both logs are rows in the options table, so a dump puts back the copies
 * it was taken with: restoring an older backup erased every call logged
 * since, the call that started the restore included. The logs describe what
 * was done on this installation, not site content, so the live copies are
 * taken before the import and written back after it, the way the backup job
 * history is kept. This holds for a push-site-archive apply too: the
 * receiving site keeps its own logs, and the sender's are not imported.
 *
 * The undo records (the snapshots table) are not carried over: they point
 * at states of the site the restore just replaced.
 */
final class Restore_Logs
{
    /** The options that hold the logs. */
    public const OPTIONS = [ Governance_Audit_Log::OPTION, Request_Log::OPTION ];

    /** The ability a restore entry is recorded under. */
    public const ABILITY = 'wpmcp/restore-site-backup';

    /**
     * The live logs, before the import.
     *
     * @return array<string, mixed> option => value, null when absent.
     */
    public static function capture(): array
    {
        $captured = [];
        foreach (self::OPTIONS as $option) {
            $captured[ $option ] = get_option($option, null);
        }
        return $captured;
    }

    /**
     * Write the captured logs back over what the dump imported. An option
     * that did not exist before the restore is deleted, so the dump's copy
     * of another site's log is not left behind.
     *
     * @param array<string, mixed> $captured
     */
    public static function reapply(array $captured): void
    {
        foreach (self::OPTIONS as $option) {
            if (! array_key_exists($option, $captured)) {
                continue;
            }
            if (is_array($captured[ $option ])) {
                // The request log is kept out of autoload, as Request_Log stores it.
                update_option($option, $captured[ $option ], Request_Log::OPTION === $option ? false : null);
            } else {
                delete_option($option);
            }
        }
    }

    /**
     * The audit entry for a finished restore: who ran it, which archive,
     * how it ended, and the safety archive that undoes it (restore that
     * backup job with restore-site-backup). Never throws.
     */
    public static function record(string $archive, string $status, ?int $safety_job_id): void
    {
        Governance_Audit_Log::record_quietly(
            self::ABILITY,
            'restored' === $status || 'database_restored_files_failed' === $status,
            sprintf(
                'database-restore:%s:archive=%s:user=%d:undo=safety_job_%d',
                $status,
                basename($archive),
                get_current_user_id(),
                (int) $safety_job_id
            )
        );
    }
}
