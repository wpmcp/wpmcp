<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Store classic `dimensions` and `gaps` control values (padding, margin,
 * border radius, container gaps) with every side as a string, the form the
 * Elementor editor itself saves (issue #393). Its panel fills an unlinked
 * control with `if ( _.isEmpty( value ) ) value = 0` per side, and
 * underscore's isEmpty() is true for any number, so a numeric side would
 * show as 0 and be saved back as 0 on the next edit of that control.
 *
 * Values are recognised by shape, so responsive variants and repeater items
 * are covered without a control registry lookup.
 */
class Classic_Dimensions
{
    private const SHAPES = [
        [['top', 'right', 'bottom', 'left'], ['unit', 'isLinked']],
        [['column', 'row'], ['unit', 'isLinked', 'size', 'sizes']],
    ];

    public static function normalize(array $settings): array
    {
        foreach ($settings as $key => $value) {
            if (is_array($value)) {
                $settings[ $key ] = self::normalize_value($value);
            }
        }

        return $settings;
    }

    private static function normalize_value(array $value): array
    {
        foreach (self::SHAPES as [$sides, $meta]) {
            $keys = array_keys($value);
            if ([] !== array_intersect($sides, $keys) && [] === array_diff($keys, $sides, $meta)) {
                foreach ($sides as $side) {
                    if (isset($value[ $side ]) && (is_int($value[ $side ]) || is_float($value[ $side ]))) {
                        $value[ $side ] = (string) $value[ $side ];
                    }
                }
                return $value;
            }
        }

        return self::normalize($value);
    }
}
