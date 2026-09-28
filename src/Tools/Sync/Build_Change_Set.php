<?php

namespace WPMCP\Tools\Sync;

use WPMCP\Tools\Backup\Site_Backup_Dir;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Build a local-live sync change set from the snapshot ledger (and/or an
 * explicit object selection) and write it as an inspectable JSON artifact
 * (issue #192, phase 1). dry_run lists what would be pushed and writes
 * nothing.
 *
 * The artifact lands in the protected site-backup directory with a random
 * suffix, same exposure reasoning as Site_Archive_Builder: it contains
 * full post content and meta, so it must not be fetchable over HTTP.
 *
 * This tool only reads site data and writes one artifact file; it never
 * mutates user content, so it is not routed through Safe_Mutation. The
 * apply side (apply-change-set) is the mutating half and goes
 * snapshot-first through the safety core on the target site.
 *
 * Failures throw. Registrar wraps every call and records ok:false with the
 * exception class, so a returned ['error' => ...] would be logged, and
 * reported to the MCP client, as a successful call.
 */
class Build_Change_Set
{
    /**
     * Filename prefix of every artifact this tool writes. Get_Change_Set
     * refuses to read anything in the backup directory without it, so a
     * multi-GB site archive can never be slurped into memory by mistake.
     */
    public const ARTIFACT_PREFIX = 'wpmcp-changeset-';

    /** @throws \RuntimeException */
    public function handle(array $args): array
    {
        $marker = $this->marker($args);

        try {
            $change_set = (new Change_Set_Builder())->build($marker);
        } catch (\InvalidArgumentException $e) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the builder escapes the ref it interpolates; $e is the previous exception.
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        $counts = $this->counts($change_set);

        if (! empty($args['dry_run'])) {
            return $this->preview($change_set, $counts);
        }

        if (0 === $counts['exported'] && 0 === $counts['deleted']) {
            // No artifact, so the per-row excluded report has nowhere else to
            // live: a bare "excluded: 3" would leave the operator unable to
            // learn what three things the sync will not carry, or why.
            return [
                'objects'       => $counts,
                'excluded'      => count($change_set['excluded']),
                'excluded_rows' => $change_set['excluded'],
                'truncated'     => $change_set['truncated'],
                'note'          => 'No syncable objects found for this marker; no artifact written.',
            ];
        }

        $dir = Site_Backup_Dir::path();
        Site_Backup_Dir::protect($dir);

        $name = sprintf('%s%s-%s.json', self::ARTIFACT_PREFIX, gmdate('Ymd-His'), wp_generate_password(12, false, false));
        $path = trailingslashit($dir) . $name;

        $json = wp_json_encode($change_set, JSON_UNESCAPED_SLASHES);
        if (false === $json || false === file_put_contents($path, $json)) {
            throw new \RuntimeException('The change-set artifact could not be written to ' . esc_html($dir) . '.');
        }

        return [
            'file'        => $path,
            'size'        => strlen($json),
            'objects'     => $counts,
            'attachments' => count($change_set['dependencies']['attachments']),
            'excluded'    => count($change_set['excluded']),
            'truncated'   => $change_set['truncated'],
            'origin'      => $change_set['origin'],
            'checksum'    => $change_set['checksum'],
        ];
    }

    /**
     * The dry run: what this change set would push, object by object, and
     * what it depends on, without writing an artifact. Nothing here is a
     * target-side prediction (that is apply-change-set's dry run); it is the
     * origin's account of what it would send.
     */
    private function preview(array $set, array $counts): array
    {
        $objects = [];
        foreach ($set['objects'] as $object) {
            $objects[] = [
                'key'       => $object['key'],
                'type'      => $object['object_type'],
                'post_type' => $object['post_type'] ?? null,
                'title'     => $object['data']['post_title'] ?? ($object['data']['name'] ?? ($object['name'] ?? null)),
                'deleted'   => ! empty($object['deleted']),
                'unchanged' => ! empty($object['unchanged']),
                'base'      => $object['base']['state'] ?? null,
                'requires'  => $object['requires'] ?? [],
            ];
        }

        $deps = $set['dependencies'];

        return [
            'dry_run'      => true,
            'counts'       => $counts,
            'objects'      => $objects,
            'dependencies' => [
                'attachments'              => array_map(
                    static fn ($a) => ['key' => $a['key'], 'file' => $a['file'], 'size' => $a['size'], 'bytes_included' => null !== $a['bytes'], 'bytes_omitted' => $a['bytes_omitted']],
                    $deps['attachments']
                ),
                'terms'                    => wp_list_pluck($deps['terms'], 'key'),
                'posts'                    => wp_list_pluck($deps['posts'], 'key'),
                'elementor_global_classes' => array_keys($deps['elementor_global_classes']),
                'external'                 => $deps['external'],
            ],
            'excluded_rows' => $set['excluded'],
            'truncated'     => $set['truncated'],
            'checksum'      => $set['checksum'],
            'note'          => 'Dry run: nothing was written. Run again without dry_run to write the artifact.',
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
     * At most one ledger marker, and it must be non-empty; `objects` (an
     * explicit selection) may stand alone or add to the marker's objects.
     * An empty session_id used to pass isset() and come back as the reassuring "no syncable objects
     * found" rather than an argument error; two markers used to silently
     * drop one of them; and since_id of 0 (which is also what any
     * non-numeric value casts to) used to read the entire surviving ledger,
     * the one thing the tool promises never to do.
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
                if (! is_numeric($args[$key]) || (int) $args[$key] < 1) {
                    throw new \RuntimeException(
                        'since_id must be a ledger row id of 1 or more; to export everything after a known operation, pass its operation_id instead.'
                    );
                }
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

        if (isset($args['objects'])) {
            if (! is_array($args['objects'])) {
                throw new \RuntimeException('objects must be a list of refs such as post:12, option:theme_mods_THEME or term:category:news.');
            }
            $refs = array_values(array_filter(array_map('strval', $args['objects']), static fn ($r) => '' !== trim($r)));
            if ([] !== $refs) {
                $marker['objects'] = $refs;
            }
        }

        if ([] === $marker) {
            throw new \RuntimeException(
                'Pass session_id, operation_id, since_id or objects: a change set is a set of selected objects, never the whole database.'
            );
        }

        return $marker;
    }
}
