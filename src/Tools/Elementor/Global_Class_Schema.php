<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Authoring + validation layer for Elementor 4.0+ global classes.
 *
 * A global class is { id, type:'class', label, variants[] }, and each variant
 * is { meta:{breakpoint,state}, props:{ css-property: $$type value } }. Two
 * jobs live here:
 *
 *  1. Authoring: friendly flat `styles` (color, background_color, padding,
 *     gap, ...) are converted into the typed props Elementor's v4 renderer
 *     reads, using the same Atomic_Props constructors the atomic widget tools
 *     already use, so a class authored over MCP is byte-identical to one the
 *     editor would have written. A raw `props` escape hatch is merged last.
 *
 *  2. Validation: the finished class is run through Elementor's OWN
 *     Style_Parser against Style_Schema before anything is persisted. The
 *     parser silently DROPS style keys it does not know, so we diff the props
 *     we sent against the props it returned and refuse the write naming the
 *     dropped keys, instead of reporting success for styles that were quietly
 *     thrown away.
 */
class Global_Class_Schema
{
    public const PARSER = '\\Elementor\\Modules\\AtomicWidgets\\Parsers\\Style_Parser';
    public const SCHEMA = '\\Elementor\\Modules\\AtomicWidgets\\Styles\\Style_Schema';
    public const STATES = '\\Elementor\\Modules\\AtomicWidgets\\Styles\\Style_States';

    /** Elementor's v4 breakpoint ids a variant may target. */
    public const BREAKPOINTS = [
        'desktop',
        'widescreen',
        'laptop',
        'tablet_extra',
        'tablet',
        'mobile_extra',
        'mobile',
    ];

    /** Friendly key => style-schema key, for the plain string style props. */
    private const STRING_STYLES = [
        'direction'       => 'flex-direction',
        'flex_direction'  => 'flex-direction',
        'justify'         => 'justify-content',
        'justify_content' => 'justify-content',
        'align'           => 'align-items',
        'align_items'     => 'align-items',
        'wrap'            => 'flex-wrap',
        'flex_wrap'       => 'flex-wrap',
        'display'         => 'display',
        'overflow'        => 'overflow',
        'font_weight'     => 'font-weight',
        'text_align'      => 'text-align',
        'text_transform'  => 'text-transform',
        'text_decoration' => 'text-decoration',
        'font_style'      => 'font-style',
    ];

    /** Friendly key => style-schema key, for the length props (accept <key>_unit). */
    private const SIZE_STYLES = [
        'width'          => 'width',
        'height'         => 'height',
        'min_width'      => 'min-width',
        'min_height'     => 'min-height',
        'max_width'      => 'max-width',
        'max_height'     => 'max-height',
        'border_radius'  => 'border-radius',
        'border_width'   => 'border-width',
        'font_size'      => 'font-size',
        'line_height'    => 'line-height',
        'letter_spacing' => 'letter-spacing',
        'gap'            => 'gap',
        'column_gap'     => 'column-gap',
    ];

    /** Friendly key => style-schema key, for the color props. */
    private const COLOR_STYLES = [
        'color'        => 'color',
        'border_color' => 'border-color',
    ];

    /** Dimension shorthands: friendly prefix => style-schema key. */
    private const DIMENSION_STYLES = ['padding' => 'padding', 'margin' => 'margin'];

    /** Logical dimension side => friendly per-side suffix. */
    private const SIDES = [
        'block-start'  => 'top',
        'block-end'    => 'bottom',
        'inline-start' => 'left',
        'inline-end'   => 'right',
    ];

    /** Whether Elementor exposes the v4 style schema this class validates against. */
    public static function is_supported(): bool
    {
        return class_exists(self::PARSER) && class_exists(self::SCHEMA);
    }

    /** Every friendly `styles` key the tools accept, for error messages and docs. */
    public static function style_keys(): array
    {
        $keys = array_merge(
            array_keys(self::STRING_STYLES),
            array_keys(self::SIZE_STYLES),
            array_keys(self::COLOR_STYLES),
            ['background_color']
        );

        foreach (array_keys(self::DIMENSION_STYLES) as $prefix) {
            $keys[] = $prefix;
            foreach (self::SIDES as $suffix) {
                $keys[] = $prefix . '_' . $suffix;
            }
        }

        sort($keys);

        return $keys;
    }

    /**
     * The friendly keys whose value is a CSS length (and therefore accept a
     * `<key>_unit` companion), including the padding/margin shorthand and its
     * per-side keys. Callers that pre-coerce lengths (Atomic_Styles) need to
     * know which keys those are.
     */
    public static function size_keys(): array
    {
        $keys = array_keys(self::SIZE_STYLES);

        foreach (array_keys(self::DIMENSION_STYLES) as $prefix) {
            $keys[] = $prefix;
            foreach (self::SIDES as $suffix) {
                $keys[] = $prefix . '_' . $suffix;
            }
        }

        return $keys;
    }

    /**
     * Validate a class label the way Elementor's own class manager does: it
     * becomes a CSS class name, so spaces, leading digits and exotic
     * characters are refused up front rather than written and then rejected.
     *
     * @return string|\WP_Error
     */
    public static function label(string $label)
    {
        $label = sanitize_text_field($label);

        if (strlen($label) < 2 || strlen($label) > 50) {
            return new \WP_Error('invalid_label', 'A class label must be between 2 and 50 characters.');
        }
        if ('container' === strtolower($label)) {
            return new \WP_Error('invalid_label', '"container" is reserved by Elementor and cannot be used as a class label.');
        }
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]*$/', $label) || preg_match('/^--/', $label)) {
            return new \WP_Error(
                'invalid_label',
                sprintf('"%s" is not a valid CSS class name: use letters, digits, hyphens and underscores, and do not start with a digit.', $label)
            );
        }

        return $label;
    }

    /**
     * The { breakpoint, state } meta a variant targets.
     *
     * Unlike EMCP, which silently coerces an unknown breakpoint to desktop, an
     * unrecognized breakpoint or state is an error: quietly writing the styles
     * to a different breakpoint than the caller asked for is worse than
     * refusing, because the agent believes the responsive rule landed.
     *
     * @return array|\WP_Error
     */
    public static function variant_meta(array $args)
    {
        $breakpoint = isset($args['breakpoint']) ? sanitize_key((string) $args['breakpoint']) : 'desktop';
        if (! in_array($breakpoint, self::BREAKPOINTS, true)) {
            return new \WP_Error(
                'invalid_breakpoint',
                sprintf('"%s" is not an Elementor breakpoint. Use one of: %s.', $breakpoint, implode(', ', self::BREAKPOINTS))
            );
        }

        $state = null;
        if (isset($args['state']) && '' !== trim((string) $args['state'])) {
            $state = trim((string) $args['state']);
            if (! self::is_valid_state($state)) {
                return new \WP_Error(
                    'invalid_state',
                    sprintf('"%s" is not a valid style state. Use one of: %s.', $state, implode(', ', self::valid_states()))
                );
            }
        }

        return ['breakpoint' => $breakpoint, 'state' => $state];
    }

    /** The non-null states Elementor accepts, sourced from Elementor when available. */
    public static function valid_states(): array
    {
        if (class_exists(self::STATES) && is_callable([self::STATES, 'get_valid_states'])) {
            $states = call_user_func([self::STATES, 'get_valid_states']);
            $states = array_values(array_filter((array) $states, 'is_string'));
            if ([] !== $states) {
                return $states;
            }
        }

        return ['hover', 'active', 'focus', 'focus-visible', 'checked', 'e--selected', 'e--disabled'];
    }

    private static function is_valid_state(string $state): bool
    {
        return in_array($state, self::valid_states(), true);
    }

    /**
     * Build a variant's typed props from friendly `styles` plus a raw `props`
     * escape hatch. An unknown friendly key is an error (EMCP ignores it), so
     * a typo never silently produces a class with no styles.
     *
     * @return array|\WP_Error
     */
    public static function props(array $args)
    {
        $styles = is_array($args['styles'] ?? null) ? $args['styles'] : [];
        $props  = [];

        $unknown = array_diff(array_keys($styles), self::accepted_style_input_keys());
        if ([] !== $unknown) {
            return new \WP_Error(
                'unknown_style_key',
                sprintf(
                    'Unsupported styles key(s): %s. Supported: %s. Anything else goes in the raw "props" object.',
                    implode(', ', $unknown),
                    implode(', ', self::style_keys())
                )
            );
        }

        foreach (self::STRING_STYLES as $input => $css) {
            if (isset($styles[$input]) && '' !== $styles[$input]) {
                $props[$css] = Atomic_Props::string((string) $styles[$input]);
            }
        }

        foreach (self::SIZE_STYLES as $input => $css) {
            if (isset($styles[$input])) {
                $props[$css] = Atomic_Props::size($styles[$input], (string) ($styles[$input . '_unit'] ?? 'px'));
            }
        }

        foreach (self::COLOR_STYLES as $input => $css) {
            if (isset($styles[$input])) {
                $color = sanitize_hex_color((string) $styles[$input]);
                if (empty($color)) {
                    return new \WP_Error('invalid_color', sprintf('"%s" is not a valid hex color.', (string) $styles[$input]));
                }
                $props[$css] = Atomic_Props::color($color);
            }
        }

        if (isset($styles['background_color'])) {
            $color = sanitize_hex_color((string) $styles['background_color']);
            if (empty($color)) {
                return new \WP_Error('invalid_color', sprintf('"%s" is not a valid hex color.', (string) $styles['background_color']));
            }
            $props['background'] = Atomic_Props::background_color($color);
        }

        foreach (self::DIMENSION_STYLES as $prefix => $css) {
            $dimensions = self::dimensions($styles, $prefix);
            if (null !== $dimensions) {
                $props[$css] = $dimensions;
            }
        }

        $raw = is_array($args['props'] ?? null) ? $args['props'] : [];
        foreach ($raw as $key => $value) {
            if (! is_string($key) || ! is_array($value) || ! isset($value['$$type']) || ! is_string($value['$$type'])) {
                return new \WP_Error(
                    'invalid_prop',
                    sprintf('Raw prop "%s" must be a $$type-wrapped object, e.g. {"$$type":"size","value":{"size":8,"unit":"px"}}.', (string) $key)
                );
            }
            // The raw escape hatch deliberately wins over a built style for
            // the same CSS key: it is the caller's explicit override.
            $props[$key] = $value;
        }

        return $props;
    }

    /**
     * A `padding`/`margin` dimensions prop: the shorthand sets all four sides,
     * per-side keys fill individual sides, and the shorthand wins when both
     * are given. Null when the caller set neither.
     */
    private static function dimensions(array $styles, string $prefix): ?array
    {
        if (isset($styles[$prefix])) {
            $value = Atomic_Props::size($styles[$prefix], (string) ($styles[$prefix . '_unit'] ?? 'px'));

            return Atomic_Props::dimensions(array_fill_keys(array_keys(self::SIDES), $value));
        }

        $sides = [];
        foreach (self::SIDES as $side => $suffix) {
            $key = $prefix . '_' . $suffix;
            if (isset($styles[$key])) {
                $sides[$side] = Atomic_Props::size($styles[$key], (string) ($styles[$key . '_unit'] ?? 'px'));
            }
        }

        return [] === $sides ? null : Atomic_Props::dimensions($sides);
    }

    /** Friendly style keys plus their `_unit` companions. */
    private static function accepted_style_input_keys(): array
    {
        $keys = self::style_keys();
        foreach (array_merge(array_keys(self::SIZE_STYLES), array_keys(self::DIMENSION_STYLES)) as $key) {
            $keys[] = $key . '_unit';
        }
        foreach (array_keys(self::DIMENSION_STYLES) as $prefix) {
            foreach (self::SIDES as $suffix) {
                $keys[] = $prefix . '_' . $suffix . '_unit';
            }
        }

        return $keys;
    }

    /** A fresh `g-` class id that collides with nothing already stored. */
    public static function mint_id(array $items): string
    {
        do {
            $id = 'g-' . substr(md5(wp_generate_uuid4()), 0, 7);
        } while (isset($items[$id]));

        return $id;
    }

    /**
     * Run a finished class item through Elementor's own Style_Parser and
     * return the sanitized item, or a WP_Error describing what is wrong.
     *
     * Elementor's parser reports structural problems but silently discards
     * style keys outside Style_Schema, so the surviving props are diffed
     * against what was submitted and any dropped key fails the call. That is
     * the difference between "your hover shadow was saved" and the truth.
     *
     * @return array|\WP_Error
     */
    public static function validate_item(array $item)
    {
        if (! self::is_supported()) {
            return new \WP_Error(
                'schema_unavailable',
                'Elementor\'s v4 style schema is unavailable, so a global class cannot be validated before writing.'
            );
        }

        $parser = call_user_func([self::PARSER, 'make'], call_user_func([self::SCHEMA, 'get']));
        $result = $parser->parse($item);

        if (! $result->is_valid()) {
            return new \WP_Error(
                'invalid_class',
                'Elementor rejected this global class: ' . $result->errors()->to_string()
            );
        }

        $sanitized = (array) $result->unwrap();
        $dropped   = self::dropped_props($item, $sanitized);
        if ([] !== $dropped) {
            return new \WP_Error(
                'unknown_style_prop',
                sprintf(
                    'Elementor\'s style schema has no such style propert%s: %s. Nothing was written (Elementor would have dropped %s silently).',
                    1 === count($dropped) ? 'y' : 'ies',
                    implode(', ', $dropped),
                    1 === count($dropped) ? 'it' : 'them'
                )
            );
        }

        return $sanitized;
    }

    /** Prop keys that went into the parser but did not come back out. */
    private static function dropped_props(array $item, array $sanitized): array
    {
        $dropped = [];

        foreach ((array) ($item['variants'] ?? []) as $index => $variant) {
            $sent = array_keys((array) ($variant['props'] ?? []));
            $kept = array_keys((array) ($sanitized['variants'][$index]['props'] ?? []));
            foreach (array_diff($sent, $kept) as $key) {
                $dropped[] = (string) $key;
            }
        }

        return array_values(array_unique($dropped));
    }
}
