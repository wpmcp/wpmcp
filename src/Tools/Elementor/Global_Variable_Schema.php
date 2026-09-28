<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Validation for Elementor 4 global variables.
 *
 * Elementor stores four variable types, keyed by long prop-type names; the
 * tools speak a short friendly name for each. Anything else is refused rather
 * than mapped to a near match: a variable of the wrong type silently fails to
 * appear in the editor picker for the property it was meant for.
 *
 * Values are checked here because Elementor's own storage accepts any string:
 * a malformed color or size is written happily and then renders as an invalid
 * CSS custom property on every page that uses it.
 */
class Global_Variable_Schema
{
    /** Friendly type => Elementor's variable prop-type key. */
    public const TYPES = [
        'color'       => 'global-color-variable',
        'font'        => 'global-font-variable',
        'size'        => 'global-size-variable',
        'custom-size' => 'global-custom-size-variable',
    ];

    /** Elementor's own limits (Variables Rest_Api). */
    public const MAX_LABEL_LENGTH = 50;
    public const MAX_VALUE_LENGTH = 512;

    /**
     * Friendly type for a friendly or Elementor type key.
     *
     * @return string|\WP_Error
     */
    public static function type($type)
    {
        $type = is_string($type) ? trim($type) : '';
        if (isset(self::TYPES[$type])) {
            return $type;
        }

        $friendly = array_search($type, self::TYPES, true);
        if (false !== $friendly) {
            return $friendly;
        }

        return new \WP_Error(
            'invalid_type',
            sprintf('"type" must be one of %s.', implode(', ', array_keys(self::TYPES)))
        );
    }

    /** Is this a size type (the only types Elementor lets a variable switch between)? */
    public static function is_size(string $friendly): bool
    {
        return 'size' === $friendly || 'custom-size' === $friendly;
    }

    /**
     * The label becomes the CSS custom property name (`--<label>`), so it is
     * held to a character set that is valid there. Elementor itself refuses
     * spaces and anything over 50 characters.
     *
     * @return string|\WP_Error
     */
    public static function label($label)
    {
        $label = is_string($label) ? trim($label) : '';

        if ('' === $label || strlen($label) > self::MAX_LABEL_LENGTH || ! preg_match('/^[A-Za-z0-9_-]+$/', $label)) {
            return new \WP_Error(
                'invalid_label',
                sprintf(
                    'A variable label must be 1-%d characters of letters, digits, "-" or "_" (it becomes the CSS variable name).',
                    self::MAX_LABEL_LENGTH
                )
            );
        }

        return $label;
    }

    /**
     * @return string|\WP_Error the trimmed value.
     */
    public static function value(string $friendly, $value)
    {
        $value = is_string($value) ? trim($value) : '';

        if ('' === $value || strlen($value) > self::MAX_VALUE_LENGTH) {
            return self::bad_value($friendly, 'it must be a non-empty string of at most ' . self::MAX_VALUE_LENGTH . ' characters');
        }

        // No value may break out of the CSS declaration it is rendered into.
        if (preg_match('/[;{}<>\\\\]/', $value)) {
            return self::bad_value($friendly, 'it must not contain ; { } < > or backslashes');
        }

        switch ($friendly) {
            case 'color':
                return self::is_color($value) ? $value : self::bad_value($friendly, 'use hex (#rgb, #rrggbb, #rrggbbaa) or rgb()/rgba()/hsl()/hsla()');
            case 'font':
                return preg_match('/^[A-Za-z0-9 ,\'"_-]+$/', $value) ? $value : self::bad_value($friendly, 'use a font family name');
            case 'size':
                return self::is_size_value($value) ? self::canonical_size($value) : self::bad_value(
                    $friendly,
                    'use a number with one of ' . implode(', ', self::size_units()) . ', or "auto"; use custom-size for a CSS expression'
                );
            default: // custom-size: any CSS expression, e.g. clamp(1rem, 2vw, 2rem).
                return $value;
        }
    }

    private static function is_color(string $value): bool
    {
        if (preg_match('/^#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value)) {
            return true;
        }

        return (bool) preg_match('/^(?:rgba?|hsla?)\(\s*[0-9.%,\s\/deg-]+\)$/i', $value);
    }

    private static function is_size_value(string $value): bool
    {
        if ('auto' === strtolower($value)) {
            return true;
        }
        if (! preg_match('/^-?\d*\.?\d+([a-z%]+)$/i', $value, $m)) {
            return false;
        }

        return in_array(strtolower($m[1]), self::size_units(), true);
    }

    /**
     * The form Elementor reads a size back as ("1.50REM" -> "1.5rem"), so what
     * the tool reports matches what the editor and the next list show.
     */
    private static function canonical_size(string $value): string
    {
        if ('auto' === strtolower($value)) {
            return 'auto';
        }
        preg_match('/^(-?\d*\.?\d+)([a-z%]+)$/i', $value, $m);

        return ($m[1] + 0) . strtolower($m[2]);
    }

    /** Elementor's supported size units, minus the ones that are not a unit suffix. */
    private static function size_units(): array
    {
        $constants = '\\Elementor\\Modules\\AtomicWidgets\\Styles\\Size_Constants';
        $units     = class_exists($constants) && method_exists($constants, 'all_supported_units')
            ? (array) call_user_func([$constants, 'all_supported_units'])
            : ['px', '%', 'em', 'rem', 'vw', 'vh', 'ch', 'vmin', 'vmax', 'fr', 'deg', 'rad', 'grad', 'turn', 's', 'ms'];

        return array_values(array_diff(array_map('strval', $units), ['auto', 'custom']));
    }

    private static function bad_value(string $friendly, string $hint): \WP_Error
    {
        return new \WP_Error('invalid_value', sprintf('Invalid %s variable value: %s.', $friendly, $hint));
    }
}
