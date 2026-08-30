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
 *
 * Failures throw. Registrar wraps every call and records ok:false with the
 * exception class, so a returned ['error' => ...] would be logged, and
 * reported to the MCP client, as a successful call.
 */
class Build_Change_Set
{
    /** @throws \RuntimeException */
    public function handle(array $args): array
    {
        $marker = $this->marker($args);

        $change_set = (new Change_Set_Builder())->build($marker);

        $counts = $this->counts($change_set);

        if (0 === $counts['exported'] && 0 === $counts['deleted']) {
            return [
                'objects'   => $counts,
                'excluded'  => count($change_set['excluded']),
                'truncated' => $change_set['truncated'],
                'note'      => 'No syncable objects found for this marker; no artifact written.',
            ];
        }

        $dir = Site_Backup_Dir::path();
        Site_Backup_Dir::protect($dir);

        $name = sprintf('wpmcp-changeset-%s-%s.json', gmdate('Ymd-His'), wp_generate_password(12, false, false));
        $path = trailingslashit($dir) . $name;

        $json = wp_json_encode($change_set, JSON_UNESCAPED_SLASHES);
        if (false === $json || false === file_put_contents($path, $json)) {
            throw new \RuntimeException('The change-set artifact could not be written to ' . $dir . '.');
        }

        return [
            'file'        => $path,
            'size'        => strlen($json),
            'objects'     => $counts,
            'attachments' => count($change_set['dependencies']['attachments']),
            'excluded'    => count($change_set['excluded']),
            'truncated'   => $change_set['truncated'],
            'origin'      => $change_set['origin'],
        ];
    }

    /**
     * Exported, deleted and total reported as three numbers. A single
     * "objects: 12" that silently counts deletion markers is a number an
     * operator would read as "12 pages ready to push".
     *
     * @return array{exported:int, deleted:int, total:int}
     */
    private function counts(array $change_set): array
    {
        $deleted = 0;
        foreach ($change_set['objects'] as $object) {
            if (! empty($object['deleted'])) {
                $deleted++;
            }
        }
        $total = count($change_set['objects']);

        return [
            'exported' => $total - $deleted,
            'deleted'  => $deleted,
            'total'    => $total,
        ];
    }

    /**
     * Exactly one marker, and it must be non-empty. An empty session_id used
     * to pass isset() and come back as the reassuring "no syncable objects
     * found" rather than an argument error; two markers used to silently
     * drop one of them.
     *
     * @throws \RuntimeException
     */
    private function marker(array $args): array
    {
        $marker = [];
        foreach (['session_id', 'operation_id', 'since_id'] as $key) {
            if (! isset($args[$key])) {
                continue;
            }
            if ('since_id' === $key) {
                $marker[$key] = (int) $args[$key];
                continue;
            }
            if ('' === trim((string) $args[$key])) {
                continue;
            }
            $marker[$key] = trim((string) $args[$key]);
        }

        if (count($marker) > 1) {
            throw new \RuntimeException(
                'Pass exactly one marker (session_id, operation_id or since_id); '
                . 'combining them would silently pick one and hide the other.'
            );
        }
        if ([] === $marker) {
            throw new \RuntimeException(
                'Pass session_id, operation_id or since_id: a change set is derived from a marker, never from the whole database.'
            );
        }

        return $marker;
    }
}
