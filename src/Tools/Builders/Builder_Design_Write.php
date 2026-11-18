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
 * - Oxygen 6 (storage per its 6.1 source, issue #805): selectors
 *   `classes/<id>` in `oxygen_oxy_selectors_json_string` and variables
 *   `variables/<id>` in `oxygen_variables_json_string`, each a list its
 *   savers (saveSelectors(), saveVariables()) hand set_global_option() as
 *   an array, so it is JSON-encoded once, with empty objects as null the way
 *   those savers leave them; palette colors `palettes/<cssVariableName>`
 *   in `oxygen_global_settings_json_string`, stored like Breakdance's. Both
 *   lists are keyed by id because elements reference a selector by id and
 *   selectors a variable by {var-<id>}; a new entry gets a uuid id and the
 *   builder's default collection, and must name a collection the builder
 *   lists. Oxygen must be loaded (plugin_missing otherwise).
 *
 * Not written: Breakdance variables, whose storage could not be confirmed
 * from its source.
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
        'oxygen'     => [
            'classes'   => 'oxygen_oxy_selectors_json_string',
            'variables' => 'oxygen_variables_json_string',
            'palettes'  => 'oxygen_global_settings_json_string',
        ],
    ];

    /** Where Oxygen keeps the collection names its builder groups each list by. */
    private const OXYGEN_COLLECTIONS = [
        'classes'   => 'oxygen_oxy_selectors_collections_json_string',
        'variables' => 'oxygen_variables_collections_json_string',
    ];

    /** The collection Oxygen's builder files a new entry under when none is chosen. */
    private const OXYGEN_DEFAULT_COLLECTION = 'Default';

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
                "Design writes support 'bricks', 'breakdance' and 'oxygen'; got '{$builder}'."
            );
        }

        if ('oxygen' === $builder) {
            $unavailable = Oxygen_Page::unavailable();
            if (null !== $unavailable) {
                return $unavailable;
            }
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

        $collections = 'oxygen' === $builder && isset(self::OXYGEN_COLLECTIONS[ $list ]) ? self::oxygen_collections($list) : [];

        try {
            [$document, $path] = self::apply($builder, $list, $document, $operation, $key, $sub, $args, $collections);
        } catch (\InvalidArgumentException $e) {
            return new \WP_Error(self::ERRORS[ $e->getCode() ] ?? 'invalid_design_entry', $e->getMessage());
        }

        $value = self::encode($builder, $list, $document);
        $engine = 'bricks' !== $builder;

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
                static function () use ($option, $value, $engine) {
                    update_option($option, $value, $engine ? false : null);
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

        $entries = 'palettes' === $list ? self::dig($document, self::BREAKDANCE_PALETTE) : $document;
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
    private static function apply(string $builder, string $list, array $document, string $operation, string $key, string $sub, array $args, array $collections = []): array
    {
        if ('bricks' !== $builder && 'palettes' === $list) {
            // Oxygen 6 keeps its palette where Breakdance does, in the same shape.
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
        [$document, $id] = self::apply_list($document, $kind, $operation, $key, $args, $collections);

        return [$document, null === $id ? null : "{$list}/{$id}"];
    }

    /**
     * Add, update or remove one entry of a list.
     *
     * @param array<int,mixed>    $entries
     * @param array<string,mixed> $args
     * @param string[]            $collections the collections an Oxygen entry may name ([] when not checked)
     * @return array{0:array<int,mixed>,1:?string} new list, and the entry's key when it still exists
     */
    private static function apply_list(array $entries, string $kind, string $operation, string $key, array $args, array $collections = []): array
    {
        $field = self::key_field($kind);

        if ('add' === $operation) {
            $entry = $args['element'] ?? null;
            if (! is_array($entry) || ([] !== $entry && self::is_list($entry))) {
                throw new \InvalidArgumentException(esc_html('add needs element: the entry as an object.'), 1);
            }
            $entry     = self::complete($kind, $entry, $entries, $collections);
            self::validate($kind, $entry, $entries, $collections);
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
        if ('oxygen_class' === $kind || 'oxygen_variable' === $kind) {
            // Only what the caller sends is normalized; the rest of the entry stays as stored.
            $attrs = array_intersect_key(self::oxygen_entry($attrs), $attrs);
        }
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
        self::validate($kind, $entry, $others, $collections);
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
     * @param string[]            $collections
     * @return array<string,mixed>
     */
    private static function complete(string $kind, array $entry, array $siblings, array $collections = []): array
    {
        if ('oxygen_class' === $kind || 'oxygen_variable' === $kind) {
            // The way Oxygen's builder makes a new entry: a uuid id, and for
            // a variable a CSS name slugged from its label.
            if (! isset($entry['id'])) {
                $entry = ['id' => wp_generate_uuid4()] + $entry;
            }
            if ('oxygen_variable' === $kind && ! isset($entry['cssVariableName']) && is_string($entry['label'] ?? null)) {
                $entry['cssVariableName'] = sanitize_title($entry['label']);
            }
            $entry += ['collection' => $collections[0] ?? self::first_collection($siblings)];

            $entry = 'oxygen_class' === $kind ? $entry + ['type' => 'class', 'properties' => [], 'children' => []] : $entry;

            return self::oxygen_entry($entry);
        }

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
     * @param string[]            $collections the collections an Oxygen entry may name ([] when not checked)
     */
    private static function validate(string $kind, array $entry, array $others, array $collections = []): void
    {
        $problem = self::problem($kind, $entry);
        if (null === $problem && [] !== $collections && ! in_array($entry['collection'], $collections, true)) {
            $problem = "collection '{$entry['collection']}' is not one of the builder's collections (" . implode(', ', $collections) . ')';
        }
        if (null === $problem) {
            $unique = [
                'bricks_class'    => ['id', 'name'],
                'bricks_variable' => ['id', 'name'],
                'oxygen_class'    => ['id', 'name'],
                'oxygen_variable' => ['id', 'cssVariableName'],
            ][ $kind ] ?? [self::key_field($kind)];
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

            case 'oxygen_class':
                $type = $entry['type'] ?? null;
                if (! in_array($type, ['class', 'custom'], true)) {
                    return "type must be 'class' or 'custom'";
                }
                $name = $entry['name'] ?? null;
                if ('class' === $type ? ! self::class_name($name) : (! is_string($name) || '' === trim($name))) {
                    return 'class' === $type ? 'name must be a class name without spaces or a leading dot' : 'name must be a non-empty selector';
                }
                if (null !== ($entry['properties'] ?? null) && null !== self::object_field($entry, 'properties')) {
                    return 'properties must be an object';
                }
                $children = $entry['children'] ?? [];
                if (! is_array($children) || ! self::is_list($children)) {
                    return 'children must be a list of nested selectors';
                }
                foreach ($children as $child) {
                    if (! is_array($child) || ! is_string($child['id'] ?? null) || '' === $child['id'] || ! is_string($child['name'] ?? null)) {
                        return 'every child needs a string id and name';
                    }
                }
                return self::collection_field($entry);

            case 'oxygen_variable':
                if (! is_string($entry['cssVariableName'] ?? null) || ! preg_match($token, $entry['cssVariableName'])) {
                    return 'cssVariableName must be a non-empty string of letters, digits, _ or - (give a label to derive it)';
                }
                if (! is_string($entry['label'] ?? null) || '' === trim($entry['label'])) {
                    return 'label must be a non-empty string';
                }
                if (! is_string($entry['type'] ?? null) || '' === $entry['type']) {
                    return "type must be a non-empty string, such as 'color', 'unit', 'font_family' or 'image_url'";
                }
                if (! array_key_exists('value', $entry)) {
                    return 'value is required';
                }
                return self::collection_field($entry);

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

    /** @param array<string,mixed> $entry */
    private static function collection_field(array $entry): ?string
    {
        return is_string($entry['collection'] ?? null) && '' !== trim($entry['collection']) ? null : 'collection must be a non-empty string';
    }

    /**
     * The collections an Oxygen entry may name: the list the builder stores
     * for its collection picker. When none is stored the builder derives it
     * from the entries themselves, so any name is accepted ([]).
     *
     * @return string[]
     */
    private static function oxygen_collections(string $list): array
    {
        [$ok, $stored] = Builder_Design_Data::engine_decode(self::OXYGEN_COLLECTIONS[ $list ]);
        if (! $ok || ! is_array($stored)) {
            return [];
        }

        return array_values(array_filter($stored, static fn ($name) => is_string($name) && '' !== $name));
    }

    /** @param array<int,mixed> $siblings */
    private static function first_collection(array $siblings): string
    {
        foreach ($siblings as $sibling) {
            if (is_array($sibling) && is_string($sibling['collection'] ?? null) && '' !== $sibling['collection']) {
                return $sibling['collection'];
            }
        }

        return self::OXYGEN_DEFAULT_COLLECTION;
    }

    /**
     * The option value for a document, stored the way the builder's own
     * saver stores it.
     *
     * @param array<int|string,mixed> $document
     * @return array<int|string,mixed>|string
     */
    private static function encode(string $builder, string $list, array $document)
    {
        if ('bricks' === $builder) {
            return $document;
        }
        if ('oxygen' === $builder && 'palettes' !== $list) {
            return (string) wp_json_encode($document);
        }

        return Builder_Design_Data::engine_encode($document);
    }

    /**
     * Oxygen's selector and variable savers turn every empty JSON object
     * into null before decoding, so PHP never stores one as a list; the
     * entry (or attrs) being written has its empty properties, the empty
     * objects within them and an empty variable value stored that way too.
     *
     * @param mixed $entry
     * @return mixed
     */
    private static function oxygen_entry($entry)
    {
        if (! is_array($entry)) {
            return $entry;
        }
        foreach (['properties', 'value'] as $field) {
            if (array_key_exists($field, $entry) && is_array($entry[ $field ])) {
                $entry[ $field ] = self::empty_objects_to_null($entry[ $field ]);
            }
        }
        if (isset($entry['children']) && is_array($entry['children'])) {
            $entry['children'] = array_map([self::class, 'oxygen_entry'], $entry['children']);
        }

        return $entry;
    }

    /**
     * @param array<int|string,mixed> $value
     * @return array<int|string,mixed>|null
     */
    private static function empty_objects_to_null(array $value): ?array
    {
        if ([] === $value) {
            return null;
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[ $key ] = self::empty_objects_to_null($item);
            }
        }

        return $value;
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
