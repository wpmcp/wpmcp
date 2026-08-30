<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Shared style-class builder for Elementor 4.0+ atomic elements (issue #62).
 *
 * Turns a flat map of common CSS-ish params (color, background_color,
 * font_size, padding, ...) into one local style class in the exact shape the
 * v4 editor stores on an element: a `styles` entry keyed by a generated
 * `e-<id>-<hash>` class id plus a `classes` settings ref pointing at it. Every
 * atomic add/update tool funnels its `style` param through here so the prop
 * shapes ($$type size/color/background/dimensions) stay consistent.
 */
class Atomic_Styles
{
    /** Flat param name => style schema key for props stored as a size. */
    private const SIZE_PROPS = [
        'font_size'     => 'font-size',
        'width'         => 'width',
        'height'        => 'height',
        'max_width'     => 'max-width',
        'gap'           => 'gap',
        'border_radius' => 'border-radius',
    ];

    /** Flat param name => style schema key for plain string props. */
    private const STRING_PROPS = [
        'text_align'      => 'text-align',
        'font_weight'     => 'font-weight',
        'display'         => 'display',
        'flex_direction'  => 'flex-direction',
        'justify_content' => 'justify-content',
        'align_items'     => 'align-items',
    ];

    /**
     * Build a local style class from flat style params.
     *
     * @param string $element_id Element the class is generated for (id seed).
     * @param array  $style      Flat params, e.g. ['color' => '#222', 'font_size' => 18].
     * @return array{class_id: string, styles: array, warnings: array}
     */
    public static function build(string $element_id, array $style): array
    {
        $props    = [];
        $warnings = [];

        foreach ($style as $key => $value) {
            if ('color' === $key) {
                $props['color'] = Atomic_Props::color((string) $value);
            } elseif ('background_color' === $key) {
                $props['background'] = Atomic_Props::background_color((string) $value);
            } elseif ('padding' === $key || 'margin' === $key) {
                $props[$key] = Atomic_Props::dimensions(self::sides($value));
            } elseif (isset(self::SIZE_PROPS[$key])) {
                $props[self::SIZE_PROPS[$key]] = self::size_value($value);
            } elseif (isset(self::STRING_PROPS[$key])) {
                $props[self::STRING_PROPS[$key]] = Atomic_Props::string((string) $value);
            } else {
                // TODO(#62): grow coverage (border, box-shadow, typography
                // family/line-height, responsive variants per breakpoint).
                $warnings[] = sprintf('Unknown style param "%s" was ignored.', $key);
            }
        }

        $class_id = sprintf('e-%s-%s', $element_id, substr(md5(wp_json_encode($props)), 0, 7));

        return [
            'class_id' => $class_id,
            'styles'   => [
                $class_id => [
                    'id'       => $class_id,
                    'label'    => 'local',
                    'type'     => 'class',
                    'variants' => [
                        [
                            'meta'       => ['breakpoint' => 'desktop', 'state' => null],
                            'props'      => $props,
                            'custom_css' => null,
                        ],
                    ],
                ],
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * Attach a built local class to an element: merge the styles blob and add
     * the class id to the element's `classes` settings ref.
     */
    public static function attach(array $element, array $built): array
    {
        $element['styles'] = array_merge($element['styles'] ?? [], $built['styles']);

        $existing = $element['settings']['classes']['value'] ?? [];
        $element['settings']['classes'] = Atomic_Props::classes(
            array_merge($existing, [$built['class_id']])
        );

        return $element;
    }

    /**
     * A shorthand length: "12px" / "1.5em" / bare number (px). CSS-wide
     * keywords pass through as strings.
     */
    private static function size_value($value): array
    {
        if (is_numeric($value)) {
            return Atomic_Props::size($value);
        }
        if (is_string($value) && preg_match('/^(-?[\d.]+)\s*([a-z%]+)$/i', trim($value), $m)) {
            return Atomic_Props::size($m[1], strtolower($m[2]));
        }
        return Atomic_Props::string((string) $value);
    }

    /**
     * Padding/margin shorthand: a single length applies to all four logical
     * sides; an array maps top/right/bottom/left (or logical keys) directly.
     *
     * @return array<string,array>
     */
    private static function sides($value): array
    {
        $logical = [
            'top'    => 'block-start',
            'bottom' => 'block-end',
            'left'   => 'inline-start',
            'right'  => 'inline-end',
        ];

        if (! is_array($value)) {
            $size = self::size_value($value);
            return array_fill_keys(array_values($logical), $size);
        }

        $sides = [];
        foreach ($value as $side => $length) {
            $key = $logical[$side] ?? (in_array($side, $logical, true) ? $side : null);
            if (null !== $key) {
                $sides[$key] = self::size_value($length);
            }
        }
        return $sides;
    }
}
