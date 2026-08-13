<?php

namespace WPMCP\Tools\Backup;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read the manifest out of a completed site-backup archive, identified by
 * job id or by path.
 *
 * This is what makes an archive inspectable before it is trusted: which site
 * it came from, when, at what scope, how many rows and files it holds, and
 * which tables carry BLOB columns. An agent asked to restore or migrate
 * reads this first to confirm it has the right archive, rather than
 * inferring from a filename.
 *
 * Read-only: opens the zip, extracts one entry, closes it.
 */
class Get_Backup_Manifest
{
    public function handle(array $args): array
    {
        $archive  = Archive_Locator::resolve($args);
        $manifest = Archive_Locator::read_manifest($archive);

        clearstatcache(true, $archive);

        return [
            'file'     => $archive,
            'size'     => (int) filesize($archive),
            'manifest' => $manifest,
        ];
    }
}
