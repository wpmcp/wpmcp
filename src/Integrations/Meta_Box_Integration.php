<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Meta Box custom-fields integration (wpmcp/metabox-read + metabox-write),
 * delegating to Meta Box's public API (rwmb_meta / rwmb_set_meta / the meta_box
 * registry, verified against Meta Box 5.x). Mirrors the ACF integration's
 * posture: reads are open; the write op is default-off (opt in via the
 * wpmcp_enable_metabox_write filter) and snapshots the post, since Meta Box
 * values are ordinary postmeta and rollback-operation restores them exactly.
 */
class Meta_Box_Integration extends Integration_Dispatcher
{
    public function integration(): string
    {
        return 'metabox';
    }

    public function is_available(): bool
    {
        return function_exists('rwmb_meta') && function_exists('rwmb_set_meta');
    }

    protected function summary(): string
    {
        return 'Meta Box (registered meta boxes and per-post custom field values)';
    }

    protected function operations(): array
    {
        return [
            'list-meta-boxes' => [
                'mode'         => 'read',
                'description'  => 'List registered Meta Box meta boxes: id, title, post types, and their field ids/types',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => function (): array {
                    $boxes = [];
                    foreach (self::registry_boxes() as $box) {
                        $config = is_object($box) && isset($box->meta_box) ? (array) $box->meta_box : (array) $box;
                        $fields = [];
                        foreach ((array) ($config['fields'] ?? []) as $field) {
                            $field    = (array) $field;
                            $fields[] = [
                                'id'   => (string) ($field['id'] ?? ''),
                                'name' => (string) ($field['name'] ?? ($field['id'] ?? '')),
                                'type' => (string) ($field['type'] ?? ''),
                            ];
                        }
                        $boxes[] = [
                            'id'         => (string) ($config['id'] ?? ''),
                            'title'      => (string) ($config['title'] ?? ''),
                            'post_types' => array_values((array) ($config['post_types'] ?? [])),
                            'fields'     => $fields,
                        ];
                    }
                    return [ 'meta_boxes' => $boxes, 'total' => count($boxes) ];
                },
            ],
            'get-fields' => [
                'mode'         => 'read',
                'objects'      => [ 'post_id' => 'post' ],
                'description'  => 'Read a post\'s Meta Box field values for the given field keys, via rwmb_meta()',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                        'keys'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    ],
                    'required'   => [ 'post_id', 'keys' ],
                ],
                'handler'      => function (array $args): array {
                    $post_id = (int) $args['post_id'];
                    $values  = [];
                    foreach ((array) $args['keys'] as $key) {
                        $key            = (string) $key;
                        $values[$key]   = rwmb_meta($key, [], $post_id);
                    }
                    return [ 'post_id' => $post_id, 'fields' => $values ];
                },
            ],
            'update-fields' => [
                'mode'               => 'write',
                'objects'            => [ 'post_id' => 'post' ],
                'description'        => 'Set one or more Meta Box field values on a post via rwmb_set_meta(). Snapshotted on the post target; restorable with rollback-operation. Disabled by default (site opts in via the wpmcp_enable_metabox_write filter)',
                'enabled_by_default' => (bool) apply_filters('wpmcp_enable_metabox_write', false),
                'input_schema'       => [
                    'type'       => 'object',
                    'properties' => [
                        'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                        'fields'  => [ 'type' => 'object', 'minProperties' => 1 ],
                    ],
                    'required'   => [ 'post_id', 'fields' ],
                ],
                'handler'            => function (array $args): array {
                    $post_id = (int) $args['post_id'];
                    foreach ((array) $args['fields'] as $key => $value) {
                        rwmb_set_meta($post_id, (string) $key, $value);
                    }
                    $out = [];
                    foreach (array_keys((array) $args['fields']) as $key) {
                        $out[(string) $key] = rwmb_meta((string) $key, [], $post_id);
                    }
                    return [ 'post_id' => $post_id, 'fields' => $out ];
                },
                'snapshot'           => fn (array $args) => [
                    'object_type' => 'post',
                    'object_id'   => (int) $args['post_id'],
                ],
            ],
        ];
    }

    /** @return array<int,mixed> the registered meta_box objects (empty when the registry is absent). */
    private static function registry_boxes(): array
    {
        if (! function_exists('rwmb_get_registry')) {
            return [];
        }
        $registry = rwmb_get_registry('meta_box');
        if (is_object($registry) && method_exists($registry, 'all')) {
            return array_values((array) $registry->all());
        }
        return [];
    }
}
