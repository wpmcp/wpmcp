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
 * hardening fix lands in one copy only. Only `path` is forwarded (job_id is
 * not in this tool's schema, and honouring it would resolve a completed
 * backup zip and then complain it is not a change set), and the locator's
 * backup-flavoured not-found message is reworded so the agent is pointed at
 * the right kind of object.
 *
 * The resolved file must look like something build-change-set wrote
 * (wpmcp-changeset-*.json) before a byte of it is read: the same directory
 * holds full site archives, and file_get_contents() on one of those is a
 * memory_limit fatal, not the RuntimeException Registrar records.
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
        $real = $this->locate(isset($args['path']) ? (string) $args['path'] : '');

        $base = wp_basename($real);
        if (! str_starts_with($base, Build_Change_Set::ARTIFACT_PREFIX) || ! str_ends_with($base, '.json')) {
            throw new \RuntimeException(sprintf(
                'The file is not a change-set artifact: expected a %s*.json file written by build-change-set.',
                esc_html(Build_Change_Set::ARTIFACT_PREFIX)
            ));
        }

        $json = file_get_contents($real);
        $set  = false !== $json ? json_decode($json, true) : null;
        if (! is_array($set)) {
            throw new \RuntimeException('The file is not a readable change-set artifact.');
        }

        $version = isset($set['format_version']) ? (int) $set['format_version'] : 0;
        if (Change_Set_Builder::FORMAT_VERSION !== $version) {
            throw new \RuntimeException(sprintf(
                'That artifact is change-set format version %d; this plugin reads version %d.',
                (int) $version,
                (int) Change_Set_Builder::FORMAT_VERSION
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

    /** @throws \RuntimeException */
    private function locate(string $path): string
    {
        if ('' === trim($path)) {
            throw new \RuntimeException('Pass the path of a change-set artifact, as returned by build-change-set.');
        }

        try {
            return Archive_Locator::resolve(['path' => $path]);
        } catch (\RuntimeException $e) {
            if ('No such backup archive.' === $e->getMessage()) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $e is the previous exception, not message text.
                throw new \RuntimeException('No such change-set artifact.', 0, $e);
            }
            throw $e;
        }
    }

    private function object_summary(array $object): array
    {
        return [
            'object_type'   => $object['object_type'] ?? null,
            'object_id'     => $object['object_id'] ?? null,
            'post_type'     => $object['post_type'] ?? null,
            'post_modified' => $object['post_modified'] ?? null,
            'deleted'       => ! empty($object['deleted']),
            'trashed'       => ! empty($object['trashed']),
        ];
    }
}
