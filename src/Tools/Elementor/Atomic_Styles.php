<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Shared style-class builder for Elementor 4.0+ atomic elements (issue #62).
 *
 * Turns a flat map of friendly CSS-ish params (color, background_color,
 * font_size, padding, ...) into one local style class in the exact shape the
 * v4 editor stores on an element: a `styles` entry keyed by a generated
 * `e-<id>-<hash>` class id plus a `classes` settings ref pointing at it.
 * add-atomic-widget, update-atomic-widget and the atomic container tools all
 * funnel their `style` param through here, so the prop shapes stay consistent.
 *
 * The props themselves are built by Global_Class_Schema, the same authoring
 * layer create-global-class uses: one dialect, one key set, one set of failure
 * semantics. That means colors go through sanitize_hex_color(), lengths through
 * Atomic_Props::build_size(), and an unknown key is a hard error rather than a
 * warning, so a typo never silently produces a class with no styles. The
 * finished class is then run through Elementor's own Style_Parser
 * (Global_Class_Schema::validate_item) before it is attached, so a prop the
 * style schema would silently drop fails the call instead of being reported as
 * a successful write.
 */
class Atomic_Styles
{
    /**
     * Side names accepted inside a `padding` / `margin` object, mapped to the
     * per-side suffix Global_Class_Schema authors with. Both the physical
     * names and Elementor's logical ones are accepted.
     */
    private const SIDE_SUFFIX = [
        'top'           => 'top',
        'bottom'        => 'bottom',
        'left'          => 'left',
        'right'         => 'right',
        'block-start'   => 'top',
        'block-end'     => 'bottom',
        'inline-start'  => 'left',
        'inline-end'    => 'right',
    ];

    /** Every style key `style` accepts, for schemas, docs and error messages. */
    public static function style_keys(): array
    {
        return Global_Class_Schema::style_keys();
    }

    /**
     * Build a local style class from flat style params.
     *
     * @param string $element_id Element the class is generated for (id seed).
     * @param mixed  $style      Flat params, e.g. ['color' => '#222', 'font_size' => 18].
     *
     * @return array{class_id: string, styles: array, warnings: array}|\WP_Error
     */
    public static function build(string $element_id, $style)
    {
        if (! is_array($style)) {
            return new \WP_Error(
                'invalid_style',
                sprintf(
                    '"style" must be an object of style keys, e.g. {"color":"#222222","font_size":18}. Supported keys: %s.',
                    implode(', ', self::style_keys())
                )
            );
        }

        $styles = self::flatten($style);
        if (is_wp_error($styles)) {
            return $styles;
        }

        // One dialect for both style-authoring paths: unknown keys, invalid
        // hex colors and the raw prop escape hatch are all handled here.
        $props = Global_Class_Schema::props(['styles' => $styles]);
        if (is_wp_error($props)) {
            return $props;
        }

        if ([] === $props) {
            return new \WP_Error(
                'empty_style',
                'None of the supplied style values produced a style prop, so no class was created.'
            );
        }

        $class_id = sprintf('e-%s-%s', $element_id, substr(md5(wp_json_encode($props)), 0, 7));
        // Same item shape create-global-class writes (and validates), so both
        // style-authoring paths hand Elementor's parser identical structures.
        $item = [
            'id'       => $class_id,
            'type'     => 'class',
            'label'    => 'local',
            'variants' => [
                [
                    'meta'  => ['breakpoint' => 'desktop', 'state' => null],
                    'props' => $props,
                ],
            ],
        ];

        $warnings = [];

        if (Global_Class_Schema::is_supported()) {
            $validated = Global_Class_Schema::validate_item($item);
            if (is_wp_error($validated)) {
                return $validated;
            }
            $item       = $validated;
            $item['id'] = $class_id;
        } else {
            $warnings[] = 'Elementor\'s v4 style schema is unavailable, so the generated style class could not be validated before writing.';
        }

        return [
            'class_id' => $class_id,
            'styles'   => [$class_id => $item],
            'warnings' => $warnings,
        ];
    }

    /**
     * Attach a built local class to an element: merge the styles blob and add
     * the class id to the element's `classes` settings ref.
     *
     * A local class this builder generated for the same element earlier is
     * replaced rather than accumulated, so repeated style updates on one
     * element leave exactly one generated class behind.
     */
    public static function attach(array $element, array $built): array
    {
        $prefix = 'e-' . (string) ($element['id'] ?? '') . '-';
        $styles = is_array($element['styles'] ?? null) ? $element['styles'] : [];

        foreach (array_keys($styles) as $id) {
            if (is_string($id) && 0 === strpos($id, $prefix)) {
                unset($styles[$id]);
            }
        }

        $element['styles'] = array_merge($styles, $built['styles']);

        $existing = self::existing_classes($element, $prefix);
        $existing[] = $built['class_id'];

        $element['settings']['classes'] = Atomic_Props::classes($existing);

        return $element;
    }

    /**
     * The class ids already on an element, minus any this builder generated
     * for it. Defensive about the stored shape: `classes` may have come from
     * a caller as a bare string or something else entirely (Atomic_Props::map
     * passes settings through untouched for element types Elementor declares
     * no schema for), and array_merge() on a non-array is a fatal.
     *
     * @return array<int,string>
     */
    private static function existing_classes(array $element, string $prefix): array
    {
        $value = $element['settings']['classes']['value'] ?? [];

        if (is_string($value)) {
            $value = preg_split('/\s+/', trim($value)) ?: [];
        }
        if (! is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $class) {
            if (! is_scalar($class)) {
                continue;
            }
            $class = (string) $class;
            if ('' === $class || 0 === strpos($class, $prefix)) {
                continue;
            }
            $ids[] = $class;
        }

        return $ids;
    }

    /**
     * Normalize the caller's style map into the flat `styles` dialect
     * Global_Class_Schema::props() accepts: `padding`/`margin` objects become
     * per-side keys, and every length is coerced up front (a number, a CSS
     * length string, or a { size, unit } object) so a value that is not a
     * length is refused by name instead of stored as size 0 or as a string
     * prop on a Size_Prop_Type key.
     *
     * @return array|\WP_Error
     */
    private static function flatten(array $style)
    {
        $expanded = [];

        foreach ($style as $raw_key => $value) {
            $key = (string) $raw_key;

            if (('padding' === $key || 'margin' === $key) && is_array($value) && ! isset($value['size'])) {
                $sides = self::expand_sides($key, $value);
                if (is_wp_error($sides)) {
                    return $sides;
                }
                $expanded = array_merge($expanded, $sides);
                continue;
            }

            $expanded[$key] = $value;
        }

        $size_keys = array_flip(Global_Class_Schema::size_keys());
        $out       = [];

        foreach ($expanded as $key => $value) {
            if (isset($size_keys[$key])) {
                $size = Atomic_Props::build_size($value);
                if (null === $size) {
                    return new \WP_Error(
                        'invalid_style_value',
                        sprintf(
                            '"%s" is not a length %s can use. Pass a number (18), a CSS length ("1.5rem") or {"size":18,"unit":"rem"}.',
                            self::describe($value),
                            $key
                        )
                    );
                }

                $out[$key]            = $size['value']['size'];
                $out[$key . '_unit'] = $size['value']['unit'];
                continue;
            }

            if (! is_scalar($value)) {
                return new \WP_Error(
                    'invalid_style_value',
                    sprintf('"%s" expects a plain value, but %s was supplied.', $key, self::describe($value))
                );
            }

            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * A `padding` / `margin` object keyed by side. An unrecognized key (a typo,
     * or a positional list like [10, 20, 30, 40]) is refused rather than
     * dropped, since a silently discarded side is a style the caller believes
     * it applied.
     *
     * @return array<string,mixed>|\WP_Error
     */
    private static function expand_sides(string $prefix, array $value)
    {
        $out = [];

        foreach ($value as $side => $length) {
            $suffix = self::SIDE_SUFFIX[(string) $side] ?? null;
            if (null === $suffix) {
                return new \WP_Error(
                    'unknown_style_side',
                    sprintf(
                        '"%s" is not a %s side. Use top, right, bottom, left (or the logical block-start, block-end, inline-start, inline-end), or pass a single length for all four sides.',
                        (string) $side,
                        $prefix
                    )
                );
            }

            $out[$prefix . '_' . $suffix] = $length;
        }

        if ([] === $out) {
            return new \WP_Error(
                'empty_style',
                sprintf('"%s" was an empty object, so no %s was applied.', $prefix, $prefix)
            );
        }

        return $out;
    }

    /** @param mixed $value */
    private static function describe($value): string
    {
        if (is_array($value)) {
            return 'an array';
        }
        if (is_object($value)) {
            return 'an object';
        }
        if (null === $value) {
            return 'null';
        }

        return sprintf('"%s"', is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
    }
}
