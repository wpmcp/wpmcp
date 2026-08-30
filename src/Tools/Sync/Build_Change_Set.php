<?php

namespace WPMCP\Tools\Sync;

use WPMCP\Tools\Backup\Site_Backup_Dir;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Build a local-live sync change set from the snapshot ledger and write it
 * as an inspectable JSON artifact (issue #192, phase 1).
 *
 * The artifact lands in the protected site-backup directory with a random
 * suffix, same exposure reasoning as Site_Archive_Builder: it contains
 * full post content and meta, so it must not be fetchable over HTTP.
 *
 * This tool only reads site data and writes one artifact file; it never
 * mutates user content, so it is not routed through Safe_Mutation. The
 * apply side (phase 2) is the mutating half and WILL go snapshot-first
 * through the safety core on the target site.
 */
class Build_Change_Set
{
    public function handle(array $args): array
    {
        $marker = [];
        if (isset($args['session_id'])) {
            $marker['session_id'] = (string) $args['session_id'];
        } elseif (isset($args['since_id'])) {
            $marker['since_id'] = (int) $args['since_id'];
        } else {
            return ['error' => 'Pass session_id or since_id: a change set is derived from a marker, never from the whole database.'];
        }

        $change_set = (new Change_Set_Builder())->build($marker);

        if (empty($change_set['objects'])) {
            return [
                'objects'  => 0,
                'excluded' => count($change_set['excluded']),
                'note'     => 'No syncable objects found for this marker; no artifact written.',
            ];
        }

        $dir = Site_Backup_Dir::path();
        Site_Backup_Dir::protect($dir);

        $name = sprintf('wpmcp-changeset-%s-%s.json', gmdate('Ymd-His'), wp_generate_password(12, false, false));
        $path = trailingslashit($dir) . $name;

        $json = wp_json_encode($change_set, JSON_UNESCAPED_SLASHES);
        if (false === $json || false === file_put_contents($path, $json)) {
            return ['error' => 'The change-set artifact could not be written to ' . $dir . '.'];
        }

        return [
            'file'         => $path,
            'size'         => strlen($json),
            'objects'      => count($change_set['objects']),
            'attachments'  => count($change_set['dependencies']['attachments']),
            'excluded'     => count($change_set['excluded']),
            'origin'       => $change_set['origin'],
        ];
    }
}
