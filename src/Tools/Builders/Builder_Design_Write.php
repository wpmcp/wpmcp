<?php

namespace WPMCP\Tools\Builders;

use WPMCP\Safety\Mutation_Failed;
use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * update-builder-content's design_system scope (issue #391, phase two):
 * add, update or remove one entry of a builder's global design data.
 *
 * Every call rewrites exactly one option, snapshotted whole first so
 * rollback-operation puts it back, and only when the caller passes the
 * hash of that option from the design_system read (expected_hash): a
 * change by anyone since the read refuses the write. Entries are validated
 * against the shape the builder reads before anything is written, so a
 * malformed entry is refused instead of stored.
 *
 * Addresses are slash paths: `path` names an entry for update and remove,
 * `to` names the list for add.
 *
 * - Bricks (storage per its 2.3 source): `classes/<id>` in
 *   `bricks_global_classes`, `variables/<id>` in `bricks_global_variables`,
 *   `palettes/<id>` and a palette's colors `palettes/<id>/<color id>` in
 *   `bricks_color_palette`, all serialized arrays.
 * - Breakdance (storage per its 1.6 source): selectors `classes/<name>` in
 *   `breakdance_breakdance_classes_json_string`, and palette colors
 *   `palettes/<cssVariableName>` at settings.colors.palette.colors in
 *   `breakdance_global_settings_json_string`, each a JSON document stored
 *   the way the engine's set_global_option() does (JSON-encoded twice).
 *   Every other global setting is left as it was.
 *
 * Not written: Breakdance variables and all Oxygen 6 design data, whose
 * storage could not be confirmed from either product's source.
 *
 * Global design data styles every page, so writing it (and undoing a
 * write) takes edit_theme_options, the capability core gates site-wide
 * styling behind.
 */
final class Builder_Design_Write
{
    public const CAPABILITY = 'edit_theme_options';

    /** @var array<string,array<string,string>> builder => list => option */
    private const OPTIONS = [
        'bricks'     => [
            'classes'   => 'bricks_global_classes',
            'variables' => 'bricks_global_variables',
            'palettes'  => 'bricks_color_palette',
        ],
        'breakdance' => [
            'classes'  => 'breakdance_breakdance_classes_json_string',
            'palettes' => 'breakdance_global_settings_json_string',
        ],
    ];

    /** Error codes, by the code of the \InvalidArgumentException apply() throws. */
    private const ERRORS = [
        1 => 'invalid_design_entry',
        2 => 'design_entry_not_found',
        3 => 'invalid_design_request',
        4 => 'invalid_design_storage',
    ];

    /** The path into Breakdance's global settings that holds its palette colors. */
    private const BREAKDANCE_PALETTE = ['settings', 'colors', 'palette', 'colors'];

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>|\WP_Error
     */
    public static function write(array $args)
    {
        $builder = (string) ($args['builder'] ?? '');
        if (! isset(self::OPTIONS[ $builder ])) {
            return new \WP_Error(
                'unsupported_builder',
                "Design writes support 'bricks' and 'breakdance'; got '{$builder}'. Oxygen 6 design data is read-only."
            );
        }

        if (! current_user_can(self::CAPABILITY)) {
            return new \WP_Error('forbidden', 'Writing global design data requires the edit_theme_options capability.');
        }

        $operation = (string) ($args['operation'] ?? '');
        if (! in_array($operation, ['add', 'update', 'remove'], true)) {
            return new \WP_Error('invalid_design_request', "operation must be add, update or remove for scope design_system; got '{$operation}'.");
        }

        $address = self::address($builder, 'add' === $operation ? (string) ($args['to'] ?? '') : (string) ($args['path'] ?? ''), 'add' === $operation);
        if (is_wp_error($address)) {
            return $address;
        }
        [$list, $key, $sub] = $address;
        $option = self::OPTIONS[ $builder ][ $list ];

        $expected = (string) ($args['expected_hash'] ?? '');
        if ('' === $expected) {
            return new \WP_Error(
                'missing_expected_hash',
                "\"expected_hash\" is required: read get-builder-content scope design_system first and pass back hashes.{$list}."
            );
        }
        if (! hash_equals(Builder_Design_Data::option_hash($option), $expected)) {
            return new \WP_Error(
                'stale_expected_hash',
                "Stale expected_hash: {$list} changed since it was read, or the hash is not hashes.{$list}. Nothing was written; re-read and retry."
            );
        }

        $document = self::load($builder, $list, $option);
        if (is_wp_error($document)) {
            return $document;
        }

        try {
            [$document, $path] = self::apply($builder, $list, $document, $operation, $key, $sub, $args);
        } catch (\InvalidArgumentException $e) {
            return new \WP_Error(self::ERRORS[ $e->getCode() ] ?? 'invalid_design_entry', $e->getMessage());
        }

        $value = 'breakdance' === $builder ? Builder_Design_Data::engine_encode($document) : $document;

        try {
            $out = Safe_Mutation::run(
                [
                    'object_type'         => 'option',
                    'object_id'           => $option,
                    'session_id'          => (string) ($args['session_id'] ?? 'default'),
                    'tool_name'           => 'update-builder-content',
                    'args'                => $args,
                    'extra_snapshot_data' => ['restore_capability' => self::CAPABILITY],
                ],
                static function () use ($option, $value, $builder) {
                    update_option($option, $value, 'breakdance' === $builder ? false : null);
                    return get_option($option);
                },
                static fn ($stored) => $stored === $value
            );
        } catch (Mutation_Failed $e) {
            return new \WP_Error('mutation_failed', 'The option did not store the intended design data; the previous value was restored.');
        }

        Builder_Design_Refresh::after_options([$option]);

        $result = [
            'operation_id' => $out['operation_id'],
            'builder'      => $builder,
            'hash'         => Builder_Design_Data::option_hash($option),
        ];
        if (null !== $path) {
            $result['path'] = $path;
        }

        return $result;
    }

    /**
     * Split and check an address: [list, entry key, color key]. An add
     * names a list (Bricks also a palette, to add one of its colors); an
     * update or remove names an entry.
     *
     * @return array{0:string,1:string,2:string}|\WP_Error
     */
    private static function address(string $builder, string $address, bool $is_add)
    {
        $parts  = explode('/', $address, 2);
        $list   = $parts[0];
        $rest   = $parts[1] ?? null;
        $nested = 'bricks' === $builder && 'palettes' === $list;

        if (! isset(self::OPTIONS[ $builder ][ $list ])) {
            $lists = implode(', ', array_keys(self::OPTIONS[ $builder ]));
            return new \WP_Error('invalid_design_path', "'{$address}' names no {$builder} design list; use {$lists}.");
        }

        if ($is_add) {
            if (null === $rest) {
                return [$list, '', ''];
            }
            if ($nested && '' !== $rest && false === strpos($rest, '/')) {
                return [$list, $rest, ''];
            }
        } elseif (null !== $rest && '' !== $rest) {
            if (! $nested) {
                return [$list, $rest, ''];
            }
            $pair = explode('/', $rest, 2);
            $sub  = $pair[1] ?? null;
            if ('' !== $pair[0] && '' !== $sub && (null === $sub || false === strpos($sub, '/'))) {
                return [$list, $pair[0], (string) $sub];
            }
        }

        $hint = $is_add
            ? "to must be '{$list}'" . ($nested ? " or '{$list}/<palette id>'" : '')
            : "path must be '{$list}/<id>'" . ($nested ? " or '{$list}/<palette id>/<color id>'" : '');

        return new \WP_Error('invalid_design_path', "Bad address '{$address}': {$hint}.");
    }

    /**
     * The decoded document the option holds, empty when it is not stored yet.
     *
     * @return array<int|string,mixed>|\WP_Error
     */
    private static function load(string $builder, string $list, string $option)
    {
        if ('bricks' === $builder) {
            $value = get_option($option, []);
            if (false === $value || '' === $value) {
                $value = [];
            }
            if (! is_array($value) || ! self::is_list($value)) {
                return self::bad_storage($option);
            }

            return $value;
        }

        [$ok, $document] = Builder_Design_Data::engine_decode($option);
        if (! $ok || (null !== $document && ! is_array($document))) {
            return self::bad_storage($option);
        }
        $document = $document ?? [];

        $entries = 'classes' === $list ? $document : self::dig($document, self::BREAKDANCE_PALETTE);
        if (null !== $entries && (! is_array($entries) || ! self::is_list($entries))) {
            return self::bad_storage($option);
        }

        return $document;
    }

    private static function bad_storage(string $option): \WP_Error
    {
        return new \WP_Error('invalid_design_storage', "The stored '{$option}' is not in the shape the builder writes, so it was left untouched; resave it in the builder first.");
    }

    /**
     * Apply one operation to the document.
     *
     * @param array<int|string,mixed> $document
     * @param array<string,mixed>     $args
     * @return array{0:array<int|string,mixed>,1:?string} new document, and the entry's path
     */
    private static function apply(string $builder, string $list, array $document, string $operation, string $key, string $sub, array $args): array
    {
        if ('breakdance' === $builder && 'palettes' === $list) {
            $colors = self::dig($document, self::BREAKDANCE_PALETTE) ?? [];
            [$colors, $id] = self::apply_list($colors, 'breakdance_color', $operation, $key, $args);

            return [self::put($document, self::BREAKDANCE_PALETTE, $colors), null === $id ? null : "{$list}/{$id}"];
        }

        if ('bricks' === $builder && 'palettes' === $list && ('' !== $sub || ('add' === $operation && '' !== $key))) {
            $index = self::find($document, 'id', $key);
            if (null === $index) {
                throw new \InvalidArgumentException(esc_html("No palette '{$key}' in palettes."), 2);
            }
            $colors = $document[ $index ]['colors'] ?? [];
            if (! is_array($colors) || ! self::is_list($colors)) {
                throw new \InvalidArgumentException(esc_html("Palette '{$key}' has no colors list; resave it in the builder first."), 4);
            }
            [$colors, $id] = self::apply_list($colors, 'bricks_color', $operation, $sub, $args);
            $document[ $index ]['colors'] = $colors;

            return [$document, null === $id ? null : "{$list}/{$key}/{$id}"];
        }

        $kind = $builder . '_' . ['classes' => 'class', 'variables' => 'variable', 'palettes' => 'palette'][ $list ];
        [$document, $id] = self::apply_list($document, $kind, $operation, $key, $args);

        return [$document, null === $id ? null : "{$list}/{$id}"];
    }

    /**
     * Add, update or remove one entry of a list.
     *
     * @param array<int,mixed>    $entries
     * @param array<string,mixed> $args
     * @return array{0:array<int,mixed>,1:?string} new list, and the entry's key when it still exists
     */
    private static function apply_list(array $entries, string $kind, string $operation, string $key, array $args): array
    {
        $field = self::key_field($kind);

        if ('add' === $operation) {
            $entry = $args['element'] ?? null;
            if (! is_array($entry) || ([] !== $entry && self::is_list($entry))) {
                throw new \InvalidArgumentException(esc_html('add needs element: the entry as an object.'), 1);
            }
            $entry     = self::complete($kind, $entry, $entries);
            self::validate($kind, $entry, $entries);
            $entries[] = $entry;

            return [$entries, (string) $entry[ $field ]];
        }

        $index = self::find($entries, $field, $key);
        if (null === $index) {
            throw new \InvalidArgumentException(esc_html("No entry '{$key}' in that list; re-read scope design_system for its entries."), 2);
        }

        if ('remove' === $operation) {
            unset($entries[ $index ]);

            return [array_values($entries), null];
        }

        $attrs = $args['attrs'] ?? null;
        if (! is_array($attrs) || [] === $attrs || self::is_list($attrs)) {
            throw new \InvalidArgumentException(esc_html('update needs attrs: the fields to merge (null removes one).'), 3);
        }
        $entry = is_array($entries[ $index ]) ? $entries[ $index ] : [];
        foreach ($attrs as $name => $value) {
            if (null === $value) {
                unset($entry[ $name ]);
            } else {
                $entry[ $name ] = $value;
            }
        }
        if ('bricks_class' === $kind) {
            $entry['modified'] = time();
            $entry['user_id']  = get_current_user_id();
        }
        $others = $entries;
        unset($others[ $index ]);
        self::validate($kind, $entry, $others);
        $entries[ $index ] = $entry;

        return [$entries, (string) $entry[ $field ]];
    }

    private static function key_field(string $kind): string
    {
        if ('breakdance_class' === $kind) {
            return 'name';
        }

        return 'breakdance_color' === $kind ? 'cssVariableName' : 'id';
    }

    /**
     * Fill what an added entry may leave out: its key and the fields the
     * builder always stores.
     *
     * @param array<string,mixed> $entry
     * @param array<int,mixed>    $siblings
     * @return array<string,mixed>
     */
    private static function complete(string $kind, array $entry, array $siblings): array
    {
        if ('breakdance_class' === $kind) {
            return $entry + ['type' => 'class', 'properties' => []];
        }

        if ('breakdance_color' === $kind) {
            if (! isset($entry['cssVariableName']) && is_string($entry['label'] ?? null) && '' !== $entry['label']) {
                // The way the engine names a new palette color.
                $entry = ['cssVariableName' => 'bde-palette-' . sanitize_title($entry['label']) . '-' . wp_generate_uuid4()] + $entry;
            }

            return $entry;
        }

        if (! isset($entry['id'])) {
            $entry = ['id' => self::new_id($siblings)] + $entry;
        }
        if ('bricks_class' === $kind) {
            $entry += ['settings' => []];
            $entry['modified'] = time();
            $entry['user_id']  = get_current_user_id();
        }
        if ('bricks_palette' === $kind) {
            $entry += ['colors' => []];
        }

        return $entry;
    }

    /** A Bricks-style six character id no sibling uses. */
    private static function new_id(array $siblings): string
    {
        do {
            $id = strtolower(wp_generate_password(6, false, false));
        } while (null !== self::find($siblings, 'id', $id));

        return $id;
    }

    /**
     * Refuse an entry the builder could not read back.
     *
     * @param array<string,mixed> $entry
     * @param array<int,mixed>    $others the list's other entries
     */
    private static function validate(string $kind, array $entry, array $others): void
    {
        $problem = self::problem($kind, $entry);
        if (null === $problem) {
            $unique = 'bricks_class' === $kind || 'bricks_variable' === $kind ? [self::key_field($kind), 'name'] : [self::key_field($kind)];
            foreach ($unique as $field) {
                if (null !== self::find($others, $field, (string) $entry[ $field ])) {
                    $problem = "{$field} '{$entry[ $field ]}' is already taken in that list";
                    break;
                }
            }
        }

        if (null !== $problem) {
            throw new \InvalidArgumentException(esc_html("Malformed entry, nothing written: {$problem}."), 1);
        }
    }

    /** @param array<string,mixed> $entry */
    private static function problem(string $kind, array $entry): ?string
    {
        $field = self::key_field($kind);
        $token = '/^[A-Za-z0-9_-]+$/';

        if ('bricks_class' !== $kind && 'breakdance_class' !== $kind && (! is_string($entry[ $field ] ?? null) || ! preg_match($token, $entry[ $field ]))) {
            return "{$field} must be a non-empty string of letters, digits, _ or -";
        }

        switch ($kind) {
            case 'bricks_class':
                if (! is_string($entry['id'] ?? null) || ! preg_match($token, $entry['id'])) {
                    return 'id must be a non-empty string of letters, digits, _ or -';
                }
                if (! self::class_name($entry['name'] ?? null)) {
                    return 'name must be a class name without spaces or a leading dot';
                }
                return self::object_field($entry, 'settings');

            case 'bricks_variable':
                if (! is_string($entry['name'] ?? null) || '' === $entry['name'] || preg_match('/\s/', $entry['name'])) {
                    return 'name must be a non-empty string without spaces';
                }
                if (! is_string($entry['value'] ?? null)) {
                    return 'value must be a string';
                }
                return self::string_fields($entry, ['category']);

            case 'bricks_palette':
                if (! is_string($entry['name'] ?? null) || '' === trim($entry['name'])) {
                    return 'name must be a non-empty string';
                }
                if (! is_array($entry['colors'] ?? null) || ! self::is_list($entry['colors'])) {
                    return 'colors must be a list of colors';
                }
                $seen = [];
                foreach ($entry['colors'] as $color) {
                    $problem = is_array($color) ? self::problem('bricks_color', $color) : 'every color must be an object';
                    if (null !== $problem) {
                        return "colors: {$problem}";
                    }
                    if (isset($seen[ $color['id'] ])) {
                        return "colors: id '{$color['id']}' appears twice";
                    }
                    $seen[ $color['id'] ] = true;
                }
                return null;

            case 'bricks_color':
                $problem = self::string_fields($entry, ['raw', 'light', 'dark', 'hex', 'rgb', 'hsl', 'name']);
                if (null !== $problem) {
                    return $problem;
                }
                foreach (['light', 'raw', 'hex'] as $value_field) {
                    if ('' !== ($entry[ $value_field ] ?? '')) {
                        return null;
                    }
                }
                return 'a color needs light, raw or hex';

            case 'breakdance_class':
                $type = $entry['type'] ?? null;
                if (! in_array($type, ['class', 'custom'], true)) {
                    return "type must be 'class' or 'custom'";
                }
                $name = $entry['name'] ?? null;
                if ('class' === $type ? ! self::class_name($name) : (! is_string($name) || '' === trim($name))) {
                    return 'class' === $type ? 'name must be a class name without spaces or a leading dot' : 'name must be a non-empty selector';
                }
                return self::object_field($entry, 'properties');

            case 'breakdance_color':
                $problem = self::string_fields($entry, ['label']);
                if (null !== $problem) {
                    return $problem;
                }
                $value = $entry['value'] ?? null;
                if (! (is_string($value) && '' !== $value) && ! (is_array($value) && [] !== $value)) {
                    return 'value must be a color string or a gradient object';
                }
                return null;
        }

        return 'unknown entry kind';
    }

    /** @param mixed $name */
    private static function class_name($name): bool
    {
        return is_string($name) && (bool) preg_match('/^[^\s.][^\s]*$/u', $name);
    }

    /** @param array<string,mixed> $entry */
    private static function object_field(array $entry, string $field): ?string
    {
        $value = $entry[ $field ] ?? [];

        return is_array($value) && ([] === $value || ! self::is_list($value)) ? null : "{$field} must be an object";
    }

    /**
     * @param array<string,mixed> $entry
     * @param string[]            $fields
     */
    private static function string_fields(array $entry, array $fields): ?string
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $entry) && ! is_string($entry[ $field ])) {
                return "{$field} must be a string";
            }
        }

        return null;
    }

    /** @param array<int|string,mixed> $entries */
    private static function find(array $entries, string $field, string $value): ?int
    {
        foreach ($entries as $index => $entry) {
            if (is_array($entry) && isset($entry[ $field ]) && (string) $entry[ $field ] === $value) {
                return (int) $index;
            }
        }

        return null;
    }

    /** @param array<int|string,mixed> $value */
    private static function is_list(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1) || [] === $value;
    }

    /**
     * @param array<int|string,mixed> $document
     * @param string[]                $path
     * @return mixed
     */
    private static function dig(array $document, array $path)
    {
        foreach ($path as $step) {
            if (! is_array($document) || ! array_key_exists($step, $document)) {
                return null;
            }
            $document = $document[ $step ];
        }

        return $document;
    }

    /**
     * @param array<int|string,mixed> $document
     * @param string[]                $path
     * @param mixed                   $value
     * @return array<int|string,mixed>
     */
    private static function put(array $document, array $path, $value): array
    {
        $step = array_shift($path);
        if ([] === $path) {
            $document[ $step ] = $value;

            return $document;
        }
        $child             = isset($document[ $step ]) && is_array($document[ $step ]) ? $document[ $step ] : [];
        $document[ $step ] = self::put($child, $path, $value);

        return $document;
    }
}
