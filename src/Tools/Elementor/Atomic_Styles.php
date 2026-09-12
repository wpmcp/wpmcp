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
 * Atomic_Props::build_size(), a raw `props` object is accepted as the same
 * escape hatch create-global-class exposes, and an unknown key is a hard error
 * rather than a warning, so a typo never silently produces a class with no
 * styles.
 *
 * The finished class is always run through Elementor's own Style_Parser
 * (Global_Class_Schema::validate_item) before it is attached, and a build fails
 * closed with schema_unavailable when that parser is missing, exactly like
 * create-global-class. Writing an unvalidated class would put caller-controlled
 * text into generated CSS.
 *
 * Ownership of the generated class is recorded on the class itself, as the
 * label Atomic_Styles::OWNED_LABEL. The v4 editor names a human-authored local
 * class `e-<element-id>-<hash>`, the same shape this builder mints, so id
 * prefix cannot tell the two apart and a tool style write must never delete a
 * class it did not create.
 */
class Atomic_Styles
{
    /**
     * The label every class this builder generates carries. Ownership has to be
     * recorded, not inferred: `e-<element-id>-<hash>` is also the id shape the
     * v4 editor gives a human-authored local class, so deleting by id prefix
     * would wipe styling a person wrote in the editor.
     */
    public const OWNED_LABEL = 'wpmcp-local';

    /**
     * CSS length units a `style` value may carry. The unit reaches Elementor's
     * generated CSS as text, so it is allowlisted here rather than trusted to
     * the style parser: `{"size":1,"unit":"px;color:red"}` is refused by name.
     */
    private const UNITS = [
        'px', 'em', 'rem', '%', 'vh', 'vw', 'vmin', 'vmax', 'ch', 'ex',
        'cm', 'mm', 'q', 'in', 'pt', 'pc', 'fr', 's', 'ms',
    ];

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

        $authored = self::flatten($style);
        if (is_wp_error($authored)) {
            return $authored;
        }

        // One dialect for both style-authoring paths: unknown keys, invalid
        // hex colors and the raw `props` escape hatch are all handled here.
        $props = Global_Class_Schema::props($authored);
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
            'label'    => self::OWNED_LABEL,
            'variants' => [
                [
                    'meta'  => ['breakpoint' => 'desktop', 'state' => null],
                    'props' => $props,
                ],
            ],
        ];

        // Fail closed, exactly like create-global-class::validate_item(): an
        // unvalidated class would put caller-controlled text into the CSS
        // Elementor generates, and a warning on a successful write is not a
        // signal any agent acts on.
        $validated = Global_Class_Schema::validate_item($item, 'style');
        if (is_wp_error($validated)) {
            return $validated;
        }

        // The parser returns the class it accepted; the id is ours (it seeds
        // the `classes` ref written alongside), so it is restored verbatim in
        // case the parser normalized it.
        $item       = $validated;
        $item['id'] = $class_id;
        $item['label'] = self::OWNED_LABEL;

        return [
            'class_id' => $class_id,
            'styles'   => [$class_id => $item],
            'warnings' => [],
        ];
    }

    /**
     * Attach a built local class to an element: merge the styles blob and add
     * the class id to the element's `classes` settings ref.
     *
     * A class this builder generated earlier (recognized by its OWNED_LABEL
     * label, never by its id) is replaced rather than accumulated, so repeated
     * style updates on one element leave exactly one generated class behind.
     * Every other entry in `styles` survives untouched, including a local
     * `e-<id>-<hash>` class the v4 editor wrote and all of its breakpoint and
     * state variants.
     */
    public static function attach(array $element, array $built): array
    {
        $styles = is_array($element['styles'] ?? null) ? $element['styles'] : [];

        $owned = [];
        foreach ($styles as $id => $class) {
            if (is_array($class) && self::OWNED_LABEL === ($class['label'] ?? null)) {
                $owned[] = (string) $id;
                unset($styles[$id]);
            }
        }

        $element['styles'] = array_merge($styles, $built['styles']);

        $existing   = self::existing_classes($element, $owned);
        $existing[] = $built['class_id'];

        $element['settings']['classes'] = Atomic_Props::classes($existing);

        return $element;
    }

    /**
     * The class ids already on an element, minus the ones this builder owns
     * (which the caller has just replaced). Defensive about the stored shape:
     * `classes` may be the typed { $$type, value } prop, a bare list, or a
     * space-separated string, because Atomic_Props::map passes settings through
     * untouched for element types Elementor declares no schema for.
     *
     * @param array<int,string> $owned Class ids being replaced.
     *
     * @return array<int,string>
     */
    private static function existing_classes(array $element, array $owned): array
    {
        $classes = $element['settings']['classes'] ?? [];

        if (is_array($classes)) {
            // A typed prop carries its ids under `value`; anything else that is
            // still an array is treated as the list itself.
            $value = array_key_exists('value', $classes) ? $classes['value'] : $classes;
        } else {
            $value = $classes;
        }

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
            if ('' === $class || in_array($class, $owned, true)) {
                continue;
            }
            $ids[] = $class;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Normalize the caller's style map into the arguments
     * Global_Class_Schema::props() accepts.
     *
     * `padding`/`margin` objects become per-side keys, every length is coerced
     * up front (a number, a CSS length string, or a { size, unit } object) so a
     * value that is not a length is refused by name instead of stored as size 0,
     * and every unit is allowlisted so caller text cannot reach the generated
     * CSS. A `<key>_unit` companion is collected before any length is built, so
     * the unit a caller supplies wins regardless of where it sits in the JSON
     * object: key order carries no meaning and must not decide the result.
     *
     * `props` is passed through as the raw escape hatch create-global-class
     * exposes, so both style-authoring paths really do speak one dialect.
     *
     * @return array{styles: array, props: array}|\WP_Error
     */
    private static function flatten(array $style)
    {
        $size_keys = Global_Class_Schema::size_keys();
        $expanded  = [];
        $units     = [];
        $raw_props = [];

        foreach ($style as $raw_key => $value) {
            $key = (string) $raw_key;

            if ('props' === $key) {
                if (! is_array($value)) {
                    return new \WP_Error(
                        'invalid_style_value',
                        '"props" must be an object of raw $$type-wrapped style props, e.g. {"width":{"$$type":"size","value":{"size":100,"unit":"%"}}}.'
                    );
                }
                $raw_props = $value;
                continue;
            }

            // Collected, never passed through as a style of its own: the size
            // branch below writes the same `<key>_unit` key, and whichever of
            // the two landed last would otherwise win.
            if (self::unit_companion($key, $size_keys)) {
                $units[substr($key, 0, -5)] = $value;
                continue;
            }

            if (('padding' === $key || 'margin' === $key) && is_array($value) && ! self::is_size_object($value)) {
                // Anything that is not exactly the { size[, unit] } shorthand is
                // read as a per-side object, so a mixture such as
                // {"size":5,"top":10} is refused rather than having a side
                // silently discarded.
                $sides = self::expand_sides($key, $value);
                if (is_wp_error($sides)) {
                    return $sides;
                }
                $expanded = array_merge($expanded, $sides);
                continue;
            }

            $expanded[$key] = $value;
        }

        $is_size = array_flip($size_keys);
        $out     = [];

        foreach ($expanded as $key => $value) {
            if (isset($is_size[$key])) {
                $size = Atomic_Props::build_size($value);
                if (null === $size) {
                    return new \WP_Error(
                        'invalid_style_value',
                        sprintf(
                            '"%s" cannot take %s: pass a number (18), a CSS length ("1.5rem") or {"size":18,"unit":"rem"}.',
                            $key,
                            self::describe($value)
                        )
                    );
                }

                $unit = array_key_exists($key, $units) ? $units[$key] : $size['value']['unit'];
                unset($units[$key]);

                if (! is_scalar($unit) || ! in_array(strtolower((string) $unit), self::UNITS, true)) {
                    return new \WP_Error(
                        'invalid_style_value',
                        sprintf(
                            '"%s" is not a CSS unit "%s" can use. Supported units: %s.',
                            is_scalar($unit) ? (string) $unit : gettype($unit),
                            $key,
                            implode(', ', self::UNITS)
                        )
                    );
                }

                $out[$key]           = $size['value']['size'];
                $out[$key . '_unit'] = strtolower((string) $unit);
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

        if ([] !== $units) {
            return new \WP_Error(
                'unknown_style_key',
                sprintf(
                    'Unit key(s) with no length to apply to: %s. Pass the length itself as well, e.g. {"font_size":18,"font_size_unit":"rem"}.',
                    implode(', ', array_map(static fn($key) => $key . '_unit', array_keys($units)))
                )
            );
        }

        return ['styles' => $out, 'props' => $raw_props];
    }

    /** Whether $key is the `<length-key>_unit` companion of a known size key. */
    private static function unit_companion(string $key, array $size_keys): bool
    {
        return '_unit' === substr($key, -5)
            && in_array(substr($key, 0, -5), $size_keys, true);
    }

    /** Whether an array is exactly the { size[, unit] } length shorthand. */
    private static function is_size_object(array $value): bool
    {
        return isset($value['size']) && [] === array_diff(array_keys($value), ['size', 'unit']);
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
