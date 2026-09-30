<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Plugin_Table_Rows_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Pods ops on the plugin-data pair (issue #299).
 *
 * The pod for a post type comes from Pods' own API,
 * pods_api()->load_pod(['name' => $post_type]), which covers pods stored in
 * the database, in files and registered in code. Its storage decides where
 * values live:
 *  - meta storage: post meta under the field name, snapshotted on the post;
 *  - table storage: one row per post (id = post id) in the pod's own table,
 *    snapshotted as a 'plugin_table_rows' row image, so an inserted row is
 *    removed again and a changed row returns exactly.
 *
 * Writes cover the simple field types, whose value is one scalar in one
 * place. Relationship, file and other tableless fields keep their values
 * across meta and Pods' relationship table, and a repeatable field keeps one
 * meta row per value, so those are listed and read but a write refuses them
 * rather than leaving the stores out of step.
 */
final class Pods_Pack
{
    /** Field types whose value is one scalar in one column or meta key. */
    private const WRITABLE_TYPES = [
        'text', 'paragraph', 'wysiwyg', 'code', 'password', 'slug', 'email', 'phone', 'website',
        'color', 'number', 'currency', 'date', 'datetime', 'time', 'boolean', 'oembed',
    ];

    /** Pods' tableless field types (PodsForm::tableless_field_types()). */
    private const TABLELESS_TYPES = [ 'pick', 'file', 'avatar', 'taxonomy', 'comment', 'author' ];

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_pods_active', function_exists('pods_api'));
    }

    /** @return true|array{code: string, message: string} */
    public static function requirement()
    {
        return self::active() ? true : [
            'code'    => 'pods_inactive',
            'message' => 'Pods is not active on this site.',
        ];
    }

    /**
     * The pod extending a post type, or null when there is none.
     *
     * @return array{name: string, storage: string, table: string, fields: array<string, array{name: string, label: string, type: string, repeatable: bool, writable: bool}>}|null
     */
    public static function pod(string $post_type): ?array
    {
        if ('' === $post_type || ! function_exists('pods_api')) {
            return null;
        }
        try {
            $pod = pods_api()->load_pod([ 'name' => $post_type ], false);
        } catch (\Throwable $e) {
            return null;
        }
        if (! is_array($pod) && ! $pod instanceof \ArrayAccess) {
            return null;
        }
        if ('post_type' !== (string) ($pod['type'] ?? '')) {
            return null;
        }

        global $wpdb;
        $name     = (string) ($pod['name'] ?? $post_type);
        $tableless = function_exists('pods_tableless') && pods_tableless();
        $storage  = ! $tableless && 'table' === (string) ($pod['storage'] ?? 'meta') ? 'table' : 'meta';
        $table    = (string) ($pod['pod_table'] ?? '');

        $fields = [];
        foreach ((array) ($pod['fields'] ?? []) as $key => $field) {
            if (! is_array($field) && ! $field instanceof \ArrayAccess) {
                continue;
            }
            $field_name = (string) ($field['name'] ?? $key);
            $type       = (string) ($field['type'] ?? '');
            $repeatable = ! empty($field['repeatable']);
            if ('' === $field_name) {
                continue;
            }
            $fields[ $field_name ] = [
                'name'       => $field_name,
                'label'      => (string) ($field['label'] ?? $field_name),
                'type'       => $type,
                'repeatable' => $repeatable,
                'writable'   => in_array($type, self::WRITABLE_TYPES, true) && ! $repeatable,
            ];
        }

        return [
            'name'    => $name,
            'storage' => $storage,
            'table'   => '' !== $table ? $table : $wpdb->prefix . 'pods_' . $name,
            'fields'  => $fields,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $requires = static fn () => self::requirement();

        return [
            'pods-list-fields'   => [
                'mode'         => 'read',
                'description'  => 'List the Pods fields of a post type pod (name, label, type, writable) and its storage (meta or table)',
                'input_schema' => Plugin_Data_Integration::post_type_schema(),
                'requires'     => $requires,
                'validate'     => static function (array $args): ?array {
                    return null === self::pod((string) $args['post_type']) ? self::no_pod((string) $args['post_type']) : null;
                },
                'handler'      => static function (array $args): array {
                    $pod = (array) self::pod((string) $args['post_type']);
                    return [
                        'post_type' => (string) $args['post_type'],
                        'pod'       => $pod['name'],
                        'storage'   => $pod['storage'],
                        'fields'    => array_values(array_map(
                            static fn (array $f): array => [ 'name' => $f['name'], 'label' => $f['label'], 'type' => $f['type'], 'writable' => $f['writable'] ],
                            $pod['fields']
                        )),
                    ];
                },
            ],
            'pods-get-fields'    => [
                'mode'         => 'read',
                'objects'      => [ 'post_id' => 'post' ],
                'description'  => 'Read a post\'s Pods field values (every field of its pod, or the given keys) from meta or the pod table',
                'input_schema' => Plugin_Data_Integration::get_fields_schema(),
                'requires'     => $requires,
                'validate'     => static fn (array $args): ?array => self::refusal($args, false),
                'handler'      => static function (array $args): array {
                    $post_id = (int) $args['post_id'];
                    $pod     = (array) self::pod((string) get_post_type($post_id));
                    $keys    = isset($args['keys']) ? array_map('strval', (array) $args['keys']) : array_keys($pod['fields']);
                    return [ 'post_id' => $post_id, 'storage' => $pod['storage'], 'fields' => self::values($post_id, $pod, $keys) ];
                },
            ],
            'pods-update-fields' => [
                'mode'               => 'write',
                'objects'            => [ 'post_id' => 'post' ],
                'description'        => 'Set simple Pods field values (text, number, date, boolean and the like) on a post, in meta or the pod table. Snapshotted; restorable with rollback-operation. Off until the site opts in via the wpmcp_enable_pods_write filter',
                'enabled_by_default' => (bool) apply_filters('wpmcp_enable_pods_write', false),
                'input_schema'       => Plugin_Data_Integration::update_fields_schema(),
                'requires'           => $requires,
                'validate'           => static fn (array $args): ?array => self::refusal($args, true),
                'snapshot'           => static function (array $args): array {
                    $post_id = (int) $args['post_id'];
                    $pod     = (array) self::pod((string) get_post_type($post_id));
                    if ('table' !== $pod['storage']) {
                        return [ 'object_type' => 'post', 'object_id' => $post_id ];
                    }
                    return [
                        'object_type' => Plugin_Table_Rows_Snapshot::TYPE,
                        'object_id'   => Plugin_Table_Rows_Snapshot::key('pods', (string) Plugin_Table_Rows_Snapshot::suffix_of('pods', $pod['table']), [ $post_id ]),
                    ];
                },
                'handler'            => static function (array $args): array {
                    $post_id = (int) $args['post_id'];
                    $pod     = (array) self::pod((string) get_post_type($post_id));
                    $values  = [];
                    foreach ((array) $args['fields'] as $key => $value) {
                        $values[ (string) $key ] = is_bool($value) ? (int) $value : $value;
                    }
                    if ('table' === $pod['storage']) {
                        self::write_row($pod['table'], $post_id, $values);
                    } else {
                        foreach ($values as $key => $value) {
                            update_post_meta($post_id, $key, wp_slash($value));
                        }
                    }
                    clean_post_cache($post_id);
                    return [ 'post_id' => $post_id, 'storage' => $pod['storage'], 'fields' => self::values($post_id, $pod, array_keys($values)) ];
                },
            ],
        ];
    }

    /**
     * Stored values for the given keys: the table column for a simple field
     * of a table pod, otherwise post meta (every row for a tableless or
     * repeatable field, which Pods keeps one row per value).
     *
     * @param string[] $keys
     * @return array<string, mixed>
     */
    private static function values(int $post_id, array $pod, array $keys): array
    {
        $row = null;
        if ('table' === $pod['storage']) {
            $rows = Plugin_Table_Rows_Snapshot::table_exists($pod['table']) ? Plugin_Table_Rows_Snapshot::rows($pod['table'], [ $post_id ]) : [];
            $row  = $rows[0] ?? [];
        }

        $out = [];
        foreach ($keys as $key) {
            $field = $pod['fields'][ $key ];
            $multi = $field['repeatable'] || in_array($field['type'], self::TABLELESS_TYPES, true);
            if (null !== $row && ! $multi) {
                $out[ $key ] = $row[ $key ] ?? null;
            } elseif ($multi) {
                $out[ $key ] = get_post_meta($post_id, $key, false);
            } else {
                $out[ $key ] = Plugin_Data_Integration::meta_value($post_id, $key);
            }
        }
        return $out;
    }

    /** Update the post's row of a pod table, inserting it when there is none. */
    private static function write_row(string $table, int $post_id, array $values): void
    {
        global $wpdb;

        if ([] !== Plugin_Table_Rows_Snapshot::rows($table, [ $post_id ])) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the pod's own table has no WP API; the post cache is cleaned by the caller.
            $ok = $wpdb->update($table, $values, [ 'id' => $post_id ]);
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the pod's own table has no WP API.
            $ok = $wpdb->insert($table, [ 'id' => $post_id ] + $values);
        }
        if (false === $ok) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.
            throw new Operation_Refused('pods_write_failed', 'Could not write the Pods table row: ' . $wpdb->last_error);
        }
    }

    /** @return array{code: string, message: string, data: array} */
    private static function no_pod(string $post_type): array
    {
        return [
            'code'    => 'no_pod',
            'message' => sprintf('Post type "%s" has no Pods pod.', $post_type),
            'data'    => [ 'post_type' => $post_type ],
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
        $pod       = self::pod($post_type);
        if (null === $pod) {
            return self::no_pod($post_type);
        }

        $fields  = $write ? (array) $args['fields'] : [];
        $keys    = $write ? array_map('strval', array_keys($fields)) : array_map('strval', (array) ($args['keys'] ?? []));
        $refusal = Plugin_Data_Integration::unknown_keys_refusal($pod['fields'], $keys, $post_type, 'Pods');
        if (null !== $refusal || ! $write) {
            return $refusal;
        }

        $columns = [];
        if ('table' === $pod['storage']) {
            if (null === Plugin_Table_Rows_Snapshot::suffix_of('pods', $pod['table']) || ! Plugin_Table_Rows_Snapshot::table_exists($pod['table'])) {
                return [
                    'code'    => 'pod_table_missing',
                    'message' => sprintf('The table of pod "%s" is missing or not a Pods table on this site.', $pod['name']),
                    'data'    => [ 'pod' => $pod['name'] ],
                ];
            }
            $columns = Plugin_Table_Rows_Snapshot::columns($pod['table']);
        }

        foreach ($fields as $key => $value) {
            $key   = (string) $key;
            $field = $pod['fields'][ $key ];
            if (! $field['writable'] || ('table' === $pod['storage'] && ! in_array($key, $columns, true))) {
                return [
                    'code'    => 'field_not_writable',
                    'message' => sprintf('Pods field "%s" (%s%s) is not a simple field this op can write.', $key, $field['type'], $field['repeatable'] ? ', repeatable' : ''),
                    'data'    => [ 'field' => $key ],
                ];
            }
            if (null !== $value && ! is_scalar($value)) {
                return [
                    'code'    => 'invalid_value',
                    'message' => sprintf('Pods field "%s" takes a single scalar value.', $key),
                    'data'    => [ 'field' => $key ],
                ];
            }
        }
        return null;
    }
}
