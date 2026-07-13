<?php

namespace WPMCP\Tools\Analysis;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Pure WCAG 2.x contrast math. No WordPress dependency: given hex colors it
 * computes sRGB relative luminance, the contrast ratio, and AA/AAA pass/fail
 * for normal and large text, per the WCAG 2.1 definitions.
 */
class Color_Contrast
{
    /**
     * Parse a hex color string to an [r, g, b] triple (0-255), or null when the
     * input is not a valid 3-, 6-, or 8-digit hex color. A leading '#' is
     * optional. For 8-digit input the trailing alpha byte is ignored and the
     * opaque color is returned.
     *
     * @return array{0:int,1:int,2:int}|null
     */
    public static function hex_to_rgb(string $hex): ?array
    {
        $hex = ltrim(trim($hex), '#');

        if (! preg_match('/^[0-9a-fA-F]+$/', $hex)) {
            return null;
        }

        $len = strlen($hex);

        if (3 === $len) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        } elseif (8 === $len) {
            $hex = substr($hex, 0, 6);
        } elseif (6 !== $len) {
            return null;
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * WCAG relative luminance of an [r, g, b] triple, in the range 0.0 (black)
     * to 1.0 (white).
     *
     * @param array{0:int,1:int,2:int} $rgb
     */
    public static function relative_luminance(array $rgb): float
    {
        $channels = [];
        foreach ($rgb as $value) {
            $srgb = $value / 255;
            $channels[] = $srgb <= 0.03928
                ? $srgb / 12.92
                : (($srgb + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /**
     * WCAG contrast ratio between two hex colors, from 1.0 (identical) to 21.0
     * (black vs white). Order-independent. Returns null when either color is
     * not a valid hex string.
     */
    public static function contrast_ratio(string $foreground, string $background): ?float
    {
        $fg = self::hex_to_rgb($foreground);
        $bg = self::hex_to_rgb($background);

        if (null === $fg || null === $bg) {
            return null;
        }

        $l1 = self::relative_luminance($fg);
        $l2 = self::relative_luminance($bg);

        $lighter = max($l1, $l2);
        $darker  = min($l1, $l2);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * Whether a contrast ratio meets a WCAG threshold. AA requires 4.5:1 for
     * normal text and 3:1 for large text; AAA requires 7:1 for normal text and
     * 4.5:1 for large text.
     */
    public static function passes(float $ratio, bool $large = false, string $level = 'AA'): bool
    {
        if ('AAA' === $level) {
            $threshold = $large ? 4.5 : 7.0;
        } else {
            $threshold = $large ? 3.0 : 4.5;
        }

        return $ratio >= $threshold;
    }
}
