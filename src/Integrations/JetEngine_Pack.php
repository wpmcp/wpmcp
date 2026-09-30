<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * JetEngine ops on the plugin-data pair (issue #299).
 *
 * Field definitions come from JetEngine's own registry,
 * jet_engine()->meta_boxes->get_fields_for_context('post_type', $slug),
 * which covers meta boxes and the fields of JetEngine's own post types.
 * Layout entries (tabs, accordions, endpoints) are skipped, and so is the
 * html field type, which only renders markup and holds no value.
 *
 * JetEngine keeps post field values as ordinary post meta under the field
 * name (checkbox and repeater values as arrays), so values are read and
 * written with the meta API and a write is snapshotted on the post:
 * rollback-operation restores the meta exactly. Only keys that are fields
 * of the post's type are read or written, never arbitrary meta.
 */
final class JetEngine_Pack
{
    /** Field types that hold no value. */
    private const LAYOUT_TYPES = [ 'html' ];

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_jetengine_active', function_exists('jet_engine'));
    }

    /** @return true|array{code: string, message: string} */
    public static function requirement()
    {
        return self::active() ? true : [
            'code'    => 'jetengine_inactive',
            'message' => 'JetEngine is not active on this site.',
        ];
    }

    /**
     * The value fields JetEngine registers for a post type, keyed by name.
     *
     * @return array<string, array{name: string, title: string, type: string}>
     */
    public static function fields(string $post_type): array
    {
        if (! function_exists('jet_engine')) {
            return [];
        }
        $engine     = jet_engine();
        $meta_boxes = is_object($engine) ? ($engine->meta_boxes ?? null) : null;
        if (! is_object($meta_boxes) || ! method_exists($meta_boxes, 'get_fields_for_context')) {
            return [];
        }

        $out = [];
        foreach ((array) $meta_boxes->get_fields_for_context('post_type', $post_type) as $field) {
            $field = (array) $field;
            $name  = (string) ($field['name'] ?? '');
            if ('' === $name || 'field' !== (string) ($field['object_type'] ?? 'field')) {
                continue;
            }
            $out[ $name ] = [
                'name'  => $name,
                'title' => (string) ($field['title'] ?? $name),
                'type'  => (string) ($field['type'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * The fields of a post type that hold a value (no html fields).
     *
     * @return array<string, array{name: string, title: string, type: string}>
     */
    public static function value_fields(string $post_type): array
    {
        return array_filter(
            self::fields($post_type),
            static fn (array $field): bool => ! in_array($field['type'], self::LAYOUT_TYPES, true)
        );
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $requires = static fn () => self::requirement();

        return [
            'jetengine-list-fields'   => [
                'mode'         => 'read',
                'description'  => 'List the JetEngine fields registered for a post type (name, title, type)',
                'input_schema' => Plugin_Data_Integration::post_type_schema(),
                'requires'     => $requires,
                'handler'      => static function (array $args): array {
                    $post_type = (string) $args['post_type'];
                    return [ 'post_type' => $post_type, 'fields' => array_values(self::value_fields($post_type)) ];
                },
            ],
            'jetengine-get-fields'    => [
                'mode'         => 'read',
                'objects'      => [ 'post_id' => 'post' ],
                'description'  => 'Read a post\'s JetEngine field values (every field of its post type, or the given keys)',
                'input_schema' => Plugin_Data_Integration::get_fields_schema(),
                'requires'     => $requires,
                'validate'     => static fn (array $args): ?array => self::refusal($args, false),
                'handler'      => static function (array $args): array {
                    $post_id = (int) $args['post_id'];
                    $keys    = isset($args['keys']) ? array_map('strval', (array) $args['keys']) : array_keys(self::value_fields((string) get_post_type($post_id)));
                    $values  = [];
                    foreach ($keys as $key) {
                        $values[ $key ] = Plugin_Data_Integration::meta_value($post_id, $key);
                    }
                    return [ 'post_id' => $post_id, 'fields' => $values ];
                },
            ],
            'jetengine-update-fields' => [
                'mode'               => 'write',
                'objects'            => [ 'post_id' => 'post' ],
                'description'        => 'Set JetEngine field values on a post (keys must be fields of its post type). Snapshotted on the post; restorable with rollback-operation. Off until the site opts in via the wpmcp_enable_jetengine_write filter',
                'enabled_by_default' => (bool) apply_filters('wpmcp_enable_jetengine_write', false),
                'input_schema'       => Plugin_Data_Integration::update_fields_schema(),
                'requires'           => $requires,
                'validate'           => static fn (array $args): ?array => self::refusal($args, true),
                'snapshot'           => static fn (array $args): array => [
                    'object_type' => 'post',
                    'object_id'   => (int) $args['post_id'],
                ],
                'handler'            => static function (array $args): array {
                    $post_id = (int) $args['post_id'];
                    $out     = [];
                    foreach ((array) $args['fields'] as $key => $value) {
                        update_post_meta($post_id, (string) $key, wp_slash($value));
                        $out[ (string) $key ] = Plugin_Data_Integration::meta_value($post_id, (string) $key);
                    }
                    return [ 'post_id' => $post_id, 'fields' => $out ];
                },
            ],
        ];
    }

    /** @return array{code: string, message: string, data: array}|null */
    private static function refusal(array $args, bool $write): ?array
    {
        $post_id = (int) $args['post_id'];
        $refusal = Plugin_Data_Integration::post_refusal($post_id, $write);
        if (null !== $refusal) {
            return $refusal;
        }

        $post_type = (string) get_post_type($post_id);
        $fields    = self::fields($post_type);
        $keys      = $write ? array_map('strval', array_keys((array) $args['fields'])) : array_map('strval', (array) ($args['keys'] ?? []));
        $refusal   = Plugin_Data_Integration::unknown_keys_refusal($fields, $keys, $post_type, 'JetEngine');
        if (null !== $refusal || ! $write) {
            return $refusal;
        }

        foreach ($keys as $key) {
            if (in_array($fields[ $key ]['type'], self::LAYOUT_TYPES, true)) {
                return [
                    'code'    => 'field_not_writable',
                    'message' => sprintf('JetEngine field "%s" is a %s field, which holds no value.', $key, $fields[ $key ]['type']),
                    'data'    => [ 'field' => $key ],
                ];
            }
        }
        return null;
    }
}
