<?php

namespace WPMCP\Tools\Backup;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Thrown inside a running backup when its job was canceled from another
 * request (cancel-backup-job on a running job, issue #771). The producer
 * learns of it at its next heartbeat, unwinds through Site_Archive_Builder's
 * cleanup (no partial archive is left behind) and Run_Backup_Job leaves the
 * record as cancel-backup-job wrote it rather than overwriting it with
 * "failed".
 */
class Backup_Job_Canceled extends \RuntimeException
{
}
