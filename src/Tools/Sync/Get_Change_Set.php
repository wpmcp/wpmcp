<?php

namespace WPMCP\Tools\Sync;

use WPMCP\Tools\Backup\Archive_Locator;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Inspect a change-set artifact before it is applied anywhere (issue #192).
 *
 * This is the definition-of-done item "the artifact is inspectable": which
 * origin it came from, which objects it carries, which dependencies it
 * resolved, whether the ledger it was derived from had been pruned, and
 * what was excluded and why. By default only the summary is returned; pass
 * include_objects=true for the full per-object data.
 *
 * Read-only. Containment is delegated to Archive_Locator::resolve(), the
 * single security boundary for every path-taking tool in the backup
 * directory: re-implementing realpath + prefix here would mean the next
 * hardening fix lands in one copy only.
 *
 * The artifact itself is untrusted after that check: it is a file on disk
 * that anything with write access to the directory could have truncated or
 * hand-edited, so its shape is validated rather than assumed.
 */
class Get_Change_Set
{
    /** @throws \RuntimeException */
    public function handle(array $args): array
    {
        $real = Archive_Locator::resolve($args);

        $json = file_get_contents($real);
        $set  = false !== $json ? json_decode($json, true) : null;
        if (! is_array($set)) {
            throw new \RuntimeException('The file is not a readable change-set artifact.');
        }

        $version = isset($set['format_version']) ? (int) $set['format_version'] : 0;
        if (Change_Set_Builder::FORMAT_VERSION !== $version) {
            throw new \RuntimeException(sprintf(
                'That artifact is change-set format version %d; this plugin reads version %d.',
                $version,
                Change_Set_Builder::FORMAT_VERSION
            ));
        }

        $objects = array_values(array_filter((array) ($set['objects'] ?? []), 'is_array'));

        $summary = [
            'file'           => $real,
            'format_version' => $version,
            'origin'         => $set['origin'] ?? null,
            'objects'        => array_map([$this, 'object_summary'], $objects),
            'attachments'    => (array) ($set['dependencies']['attachments'] ?? []),
            'excluded'       => (array) ($set['excluded'] ?? []),
            'truncated'      => $set['truncated'] ?? null,
        ];

        if (! empty($args['include_objects'])) {
            $summary['objects_full'] = $objects;
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
        ];
    }
}
