<?php

namespace WPMCP\Tools\Sync;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Inspect a change-set artifact before it is applied anywhere (issue #192).
 *
 * This is the definition-of-done item "the artifact is inspectable": which
 * origin it came from, which objects it carries (with each one's base
 * revision state and what it requires), which dependencies it resolved,
 * whether the ledger it was derived from had been pruned, and what was
 * excluded and why. By default only the summary is returned, with media
 * bytes replaced by their size; include_objects=true adds the full
 * per-object data, and raw=true returns the artifact itself, verbatim, for
 * handing to apply-change-set on another site over the connect layer.
 *
 * Read-only. Loading goes through Change_Set_Format::load_path(), which
 * delegates containment to Archive_Locator::resolve() (the single security
 * boundary for path-taking backup tools) and refuses anything that is not a
 * wpmcp-changeset-*.json file before reading a byte of it. The artifact is
 * then validated, checksum included, rather than trusted.
 */
class Get_Change_Set
{
    /** @throws \RuntimeException */
    public function handle(array $args): array
    {
        [$real, $set] = Change_Set_Format::load_path(isset($args['path']) ? (string) $args['path'] : '');

        Change_Set_Format::validate($set);

        if (! empty($args['raw'])) {
            return ['file' => $real, 'change_set' => $set];
        }

        $objects = array_values(array_filter((array) $set['objects'], 'is_array'));
        $deps    = (array) $set['dependencies'];

        $summary = [
            'file'           => $real,
            'format_version' => (int) $set['format_version'],
            'checksum'       => (string) $set['checksum'],
            'origin'         => $set['origin'] ?? null,
            'marker'         => $set['marker'] ?? null,
            'objects'        => array_map([$this, 'object_summary'], $objects),
            'attachments'    => array_map([$this, 'attachment_summary'], array_values(array_filter((array) ($deps['attachments'] ?? []), 'is_array'))),
            'terms'          => array_values(array_filter(array_map(static fn ($t) => is_array($t) ? ($t['key'] ?? null) : null, (array) ($deps['terms'] ?? [])))),
            'posts'          => array_map([$this, 'object_summary'], array_values(array_filter((array) ($deps['posts'] ?? []), 'is_array'))),
            'global_classes' => array_keys((array) ($deps['elementor_global_classes'] ?? [])),
            'external'       => (array) ($deps['external'] ?? []),
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
            'key'           => $object['key'] ?? null,
            'object_type'   => $object['object_type'] ?? null,
            'object_id'     => $object['object_id'] ?? null,
            'post_type'     => $object['post_type'] ?? null,
            'title'         => $object['data']['post_title'] ?? ($object['data']['name'] ?? ($object['name'] ?? null)),
            'post_modified' => $object['post_modified'] ?? null,
            'base'          => $object['base']['state'] ?? null,
            'unchanged'     => ! empty($object['unchanged']),
            'deleted'       => ! empty($object['deleted']),
            'trashed'       => ! empty($object['trashed']),
            'requires'      => (array) ($object['requires'] ?? []),
        ];
    }

    /** Media without its bytes: an inspection summary must stay readable. */
    private function attachment_summary(array $attachment): array
    {
        $bytes = $attachment['bytes'] ?? null;
        unset($attachment['bytes']);
        $attachment['bytes_included'] = is_string($bytes) && '' !== $bytes;
        return $attachment;
    }
}
