<?php

namespace WPMCP\Tools\Sync;

use WPMCP\Tools\Backup\Site_Backup_Dir;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Inspect a change-set artifact before it is applied anywhere (issue #192).
 *
 * This is the definition-of-done item "the artifact is inspectable": which
 * origin it came from, which objects it carries, which dependencies it
 * resolved, and what was excluded and why. By default only the summary is
 * returned; pass include_objects=true for the full per-object data.
 *
 * Read-only. Paths outside the site-backup directory are refused, same
 * boundary as Get_Backup_Manifest.
 */
class Get_Change_Set
{
    public function handle(array $args): array
    {
        $path = (string) ($args['path'] ?? '');
        if ('' === $path) {
            return ['error' => 'Pass path: the change-set artifact file to inspect.'];
        }

        $real = realpath($path);
        $dir  = realpath(Site_Backup_Dir::path());
        if (! $real || ! $dir || 0 !== strpos($real, $dir . DIRECTORY_SEPARATOR)) {
            return ['error' => 'Path is not inside the site-backup directory; refused.'];
        }

        $json = file_get_contents($real);
        $set  = $json ? json_decode($json, true) : null;
        if (! is_array($set)) {
            return ['error' => 'The file is not a readable change-set artifact.'];
        }

        $summary = [
            'file'           => $real,
            'format_version' => $set['format_version'] ?? null,
            'origin'         => $set['origin'] ?? null,
            'objects'        => array_map([$this, 'object_summary'], (array) ($set['objects'] ?? [])),
            'attachments'    => $set['dependencies']['attachments'] ?? [],
            'excluded'       => $set['excluded'] ?? [],
        ];

        if (! empty($args['include_objects'])) {
            $summary['objects_full'] = $set['objects'];
        }

        return $summary;
    }

    private function object_summary(array $object): array
    {
        return [
            'object_type'   => $object['object_type'] ?? null,
            'object_id'     => $object['object_id'] ?? null,
            'post_type'     => $object['post_type'] ?? null,
            'post_modified' => $object['post_modified'] ?? null,
            'deleted'       => ! empty($object['deleted']),
            'pending'       => $object['pending'] ?? null,
        ];
    }
}
