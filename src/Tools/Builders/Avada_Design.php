<?php

namespace WPMCP\Tools\Builders;

use WPMCP\Safety\Mutation_Failed;
use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Avada's (Fusion Builder) site-wide design data and element catalog on the
 * builder pair (issue #637), per the Avada 7.16 / Avada Builder 3.16 source.
 *
 * - Global options are one array in the `fusion_options` option (Avada's
 *   original option name; a multilingual site keeps per-language copies
 *   under other names, which are not read or written here). The global
 *   color palette sits under `color_palette` (slug => {color, label};
 *   Avada's own color1 to color8, then custom_color_<n>) and the global
 *   typography sets under `typography_sets` (slug => {label, font-family,
 *   ...}; typography1 to typography5, then custom_typography_<n>). While
 *   Avada is loaded the palette and sets are read through its own
 *   AWB_Global_Colors / AWB_Global_Typography, which fill in its defaults.
 * - The element catalog is Avada Builder's element map, the global
 *   $all_fusion_builder_elements its fusion_builder_map() fills, built on
 *   demand through awb_maybe_init_element_maps().
 *
 * Writes change one entry of fusion_options, snapshotted whole first so
 * rollback-operation puts it back, only against the hash the design_system
 * read returned, and only with edit_theme_options (global options style
 * every page). Every page's compiled CSS is then reset the way Avada's own
 * options save resets it (Builder_Design_Refresh, Avada_Cache).
 */
final class Avada_Design
{
    public const OPTION = 'fusion_options';

    public const CAPABILITY = 'edit_theme_options';

    /** list => [stored key, slug prefix of an added entry, built-in slugs that cannot be removed] */
    private const LISTS = [
        'palettes'   => ['color_palette', 'custom_color_', '/^color[1-8]$/'],
        'typography' => ['typography_sets', 'custom_typography_', '/^typography[1-5]$/'],
    ];

    private const ERRORS = [
        1 => 'invalid_design_entry',
        2 => 'design_entry_not_found',
        3 => 'invalid_design_request',
        4 => 'invalid_design_path',
    ];

    /** @return array<string,mixed> */
    public static function design_system(): array
    {
        $options = self::options();
        $rest    = $options;
        unset($rest['color_palette'], $rest['typography_sets']);
        $hash = Builder_Design_Data::option_hash(self::OPTION);

        return [
            'builder'        => 'avada',
            'scope'          => 'design_system',
            'plugin_active'  => Avada_Content::plugin_active(),
            'plugin_version' => Avada_Content::plugin_version(),
            'palettes'       => self::entries('palettes', $options),
            'typography'     => self::entries('typography', $options),
            'options'        => $rest,
            'hashes'         => ['palettes' => $hash, 'typography' => $hash, 'options' => $hash],
        ];
    }

    /**
     * The element list, or one element's params when $element names its
     * shortcode tag.
     *
     * @return array<string,mixed>|\WP_Error
     */
    public static function catalog(string $element = '')
    {
        if (! Avada_Content::plugin_active()) {
            return Builder_Design_Data::not_loaded('avada');
        }
        if (function_exists('awb_maybe_init_element_maps')) {
            awb_maybe_init_element_maps();
        }
        $map = $GLOBALS['all_fusion_builder_elements'] ?? null;
        if (! is_array($map)) {
            return Builder_Design_Data::not_loaded('avada');
        }
        ksort($map);

        if ('' !== $element) {
            if (! isset($map[ $element ]) || ! is_array($map[ $element ])) {
                return Builder_Design_Data::element_not_found('avada', $element);
            }
            $module = $map[ $element ];
            $params = [];
            foreach ((array) ($module['params'] ?? []) as $param) {
                if (is_array($param)) {
                    unset($param['callback']);
                    $params[] = $param;
                }
            }

            return [
                'builder' => 'avada',
                'scope'   => 'catalog',
                'element' => self::summary($element, $module) + [
                    'defaults' => is_array($module['defaults'] ?? null) ? $module['defaults'] : [],
                    'params'   => $params,
                ],
            ];
        }

        $elements = [];
        foreach ($map as $tag => $module) {
            if (is_array($module)) {
                $elements[] = self::summary((string) $tag, $module);
            }
        }

        return [
            'builder'        => 'avada',
            'scope'          => 'catalog',
            'plugin_version' => Avada_Content::plugin_version(),
            'elements'       => $elements,
        ];
    }

    /** @return array<string,mixed> */
    private static function summary(string $tag, array $module): array
    {
        $out = [
            'tag'  => $tag,
            'name' => is_string($module['name'] ?? null) ? $module['name'] : $tag,
        ];
        if (is_string($module['element_child'] ?? null)) {
            $out['child'] = $module['element_child'];
        }
        if (! empty($module['component'])) {
            // Avada offers these in Layout sections only (post title, content ...).
            $out['layout_section_only'] = true;
        }
        if (! empty($module['form_component'])) {
            $out['form_only'] = true;
        }
        if (! empty($module['hide_from_builder'])) {
            $out['hidden'] = true;
        }

        return $out;
    }

    /** @return array<string,mixed> the stored options, empty when there are none */
    private static function options(): array
    {
        $value = get_option(self::OPTION, []);

        return is_array($value) ? $value : [];
    }

    /** @return array<string,mixed> a list as Avada reads it */
    private static function entries(string $list, array $options): array
    {
        if (Avada_Content::plugin_active()) {
            if ('palettes' === $list && function_exists('AWB_Global_Colors')) {
                return (array) \AWB_Global_Colors()->get_palette();
            }
            if ('typography' === $list && function_exists('AWB_Global_Typography')) {
                return (array) \AWB_Global_Typography()->get_typography();
            }
        }
        $stored = $options[ self::LISTS[ $list ][0] ] ?? [];

        return is_array($stored) ? $stored : [];
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>|\WP_Error
     */
    public static function write(array $args)
    {
        if (! current_user_can(self::CAPABILITY)) {
            return new \WP_Error('forbidden', 'Writing Avada global options requires the edit_theme_options capability.');
        }

        $operation = (string) ($args['operation'] ?? '');
        if (! in_array($operation, ['add', 'update', 'remove'], true)) {
            return new \WP_Error('invalid_design_request', "operation must be add, update or remove for scope design_system; got '{$operation}'.");
        }

        $expected = (string) ($args['expected_hash'] ?? '');
        if ('' === $expected) {
            return new \WP_Error('missing_expected_hash', '"expected_hash" is required: read get-builder-content scope design_system with builder avada first and pass back its hash.');
        }
        if (! hash_equals(Builder_Design_Data::option_hash(self::OPTION), $expected)) {
            return new \WP_Error('stale_expected_hash', 'Stale expected_hash: the Avada global options changed since they were read. Nothing was written; re-read and retry.');
        }

        try {
            [$options, $path] = self::apply(self::options(), $operation, $args);
        } catch (\InvalidArgumentException $e) {
            return new \WP_Error(self::ERRORS[ $e->getCode() ] ?? 'invalid_design_entry', $e->getMessage());
        }

        try {
            $out = Safe_Mutation::run(
                [
                    'object_type'         => 'option',
                    'object_id'           => self::OPTION,
                    'session_id'          => (string) ($args['session_id'] ?? 'default'),
                    'tool_name'           => 'update-builder-content',
                    'args'                => $args,
                    'extra_snapshot_data' => ['restore_capability' => self::CAPABILITY],
                ],
                static function () use ($options) {
                    update_option(self::OPTION, $options);
                    return get_option(self::OPTION);
                },
                static fn ($stored) => $stored === $options
            );
        } catch (Mutation_Failed $e) {
            return new \WP_Error('mutation_failed', 'fusion_options did not store the intended value; the previous value was restored.');
        }

        Builder_Design_Refresh::after_options([self::OPTION]);

        $result = [
            'operation_id' => $out['operation_id'],
            'builder'      => 'avada',
            'hash'         => Builder_Design_Data::option_hash(self::OPTION),
        ];
        if (null !== $path) {
            $result['path'] = $path;
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $options
     * @param array<string,mixed> $args
     * @return array{0:array<string,mixed>,1:?string}
     */
    private static function apply(array $options, string $operation, array $args): array
    {
        $address = 'add' === $operation ? (string) ($args['to'] ?? '') : (string) ($args['path'] ?? '');
        $parts   = explode('/', $address, 2);
        $list    = $parts[0];
        $key     = $parts[1] ?? '';

        if ('options' === $list) {
            if ('update' !== $operation) {
                throw new \InvalidArgumentException(esc_html('Global options can only be updated: operation update, path options/<key>, attrs {value}.'), 3);
            }

            return [self::update_option_key($options, $key, $args), "options/{$key}"];
        }

        if (! isset(self::LISTS[ $list ]) || ('add' === $operation ? '' !== $key : ('' === $key || false !== strpos($key, '/')))) {
            $hint = 'add' === $operation ? "to must be 'palettes' or 'typography'" : "path must be 'palettes/<slug>', 'typography/<slug>' or 'options/<key>'";
            throw new \InvalidArgumentException(esc_html("Bad address '{$address}': {$hint}."), 4);
        }

        [$stored_key, $prefix, $builtin] = self::LISTS[ $list ];
        $entries = self::entries($list, $options);

        if ('add' === $operation) {
            $entry = $args['element'] ?? null;
            if (! is_array($entry) || [] === $entry || self::is_list($entry)) {
                throw new \InvalidArgumentException(esc_html('add needs element: the entry as an object.'), 1);
            }
            $key = $prefix . self::next_number($entries, $prefix);
            self::validate($list, $entry);
            $entries[ $key ] = $entry;
        } elseif (! isset($entries[ $key ]) || ! is_array($entries[ $key ])) {
            throw new \InvalidArgumentException(esc_html("No entry '{$key}' in {$list}; re-read scope design_system for its entries."), 2);
        } elseif ('remove' === $operation) {
            if (preg_match($builtin, $key)) {
                throw new \InvalidArgumentException(esc_html("'{$key}' is one of Avada's own {$list} and cannot be removed, only changed."), 3);
            }
            unset($entries[ $key ]);
            $key = null;
        } else {
            $attrs = $args['attrs'] ?? null;
            if (! is_array($attrs) || [] === $attrs || self::is_list($attrs)) {
                throw new \InvalidArgumentException(esc_html('update needs attrs: the fields to merge (null removes one).'), 3);
            }
            $entry = $entries[ $key ];
            foreach ($attrs as $name => $value) {
                if (null === $value) {
                    unset($entry[ $name ]);
                } else {
                    $entry[ $name ] = $value;
                }
            }
            self::validate($list, $entry);
            $entries[ $key ] = $entry;
        }

        $options[ $stored_key ] = $entries;

        return [$options, null === $key ? null : "{$list}/{$key}"];
    }

    private static function is_list(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
    }

    /** @param array<string,mixed> $entries */
    private static function next_number(array $entries, string $prefix): int
    {
        $max = 0;
        foreach (array_keys($entries) as $slug) {
            if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', (string) $slug, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return $max + 1;
    }

    /** Refuse an entry Avada could not read back. */
    private static function validate(string $list, array $entry): void
    {
        $problem = null;
        if (! is_string($entry['label'] ?? null) || '' === trim($entry['label'])) {
            $problem = 'label must be a non-empty string';
        } elseif ('palettes' === $list && (! is_string($entry['color'] ?? null) || '' === trim($entry['color']))) {
            $problem = 'color must be a non-empty CSS color string';
        } else {
            foreach ($entry as $name => $value) {
                if (! is_string($name) || (! is_scalar($value))) {
                    $problem = "field '{$name}' must be a named string, number or boolean";
                    break;
                }
            }
        }

        if (null !== $problem) {
            throw new \InvalidArgumentException(esc_html("Malformed entry, nothing written: {$problem}."), 1);
        }
    }

    /**
     * @param array<string,mixed> $options
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private static function update_option_key(array $options, string $key, array $args): array
    {
        if ('' === $key || in_array($key, ['color_palette', 'typography_sets'], true)) {
            throw new \InvalidArgumentException(esc_html("Bad address 'options/{$key}': name one global option; the palette and typography sets are written at palettes/<slug> and typography/<slug>."), 4);
        }
        $attrs = $args['attrs'] ?? null;
        if (! is_array($attrs) || ! array_key_exists('value', $attrs)) {
            throw new \InvalidArgumentException(esc_html('update of a global option needs attrs {value}: its new value.'), 3);
        }
        if (! array_key_exists($key, $options)) {
            throw new \InvalidArgumentException(esc_html("No stored global option '{$key}'; re-read scope design_system for the options Avada has saved."), 2);
        }

        $old   = $options[ $key ];
        $value = $attrs['value'];
        if (is_array($old) !== is_array($value) || (! is_array($value) && ! is_scalar($value))) {
            throw new \InvalidArgumentException(esc_html("Option '{$key}' holds " . (is_array($old) ? 'an object or list' : 'a single value') . '; the new value must be the same kind.'), 1);
        }
        // Avada stores its scalar options as strings.
        $options[ $key ] = is_string($old) && ! is_array($value) ? (string) $value : $value;

        return $options;
    }
}
