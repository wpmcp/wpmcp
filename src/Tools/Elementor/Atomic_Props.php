<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Constructors for Elementor 4.0+ atomic "typed prop" values, plus the shared
 * coercion/repair mapper every atomic write path runs settings through
 * (issue #137).
 *
 * Atomic elements store each setting as { "$$type": <kind>, "value": <value> }
 * rather than a bare scalar. The constructors below build that public
 * Elementor data shape; `map()` takes whatever an agent actually sent
 * (a bare string, an aliased prop name, a value wrapped in the wrong `$$type`)
 * and turns it into props the v4 editor can read, using Elementor's own prop
 * metadata via Atomic_Prop_Schema rather than a hand-kept table.
 *
 * The mapper is deliberately conservative about what it writes: a value it
 * cannot express in the prop's declared type is dropped and reported, never
 * stored, because a wrongly-typed prop is exactly what makes the v4 editor
 * refuse to open an element. Every rename, rewrap, and drop comes back to the
 * caller in `coerced` / `warnings`, and every atomic write is snapshot-first,
 * so nothing here is silent and nothing here is one-way.
 */
class Atomic_Props
{
    public static function string(string $value): array
    {
        return ['$$type' => 'string', 'value' => $value];
    }

    /** @param int|float|string $value */
    public static function number($value): array
    {
        return ['$$type' => 'number', 'value' => is_numeric($value) ? $value + 0 : 0];
    }

    public static function boolean(bool $value): array
    {
        return ['$$type' => 'boolean', 'value' => $value];
    }

    /** Rich-text prop (headings, paragraphs, button labels). */
    public static function html(string $text): array
    {
        return [
            '$$type' => 'html-v3',
            'value'  => [
                'content'  => self::string($text),
                'children' => [],
            ],
        ];
    }

    /** @param array<int,string> $class_ids */
    public static function classes(array $class_ids = []): array
    {
        return ['$$type' => 'classes', 'value' => array_values($class_ids)];
    }

    public static function link(string $url, bool $target_blank = false): array
    {
        $value = ['destination' => self::string($url)];
        if ($target_blank) {
            $value['isTargetBlank'] = self::boolean(true);
        }
        return ['$$type' => 'link', 'value' => $value];
    }

    /**
     * A CSS length prop ({ size, unit }), the shape every Size_Prop_Type style
     * key in Elementor's style schema expects (width, gap, font-size, ...).
     *
     * @param int|float|string $value
     */
    public static function size($value, string $unit = 'px'): array
    {
        return [
            '$$type' => 'size',
            'value'  => [
                'size' => is_numeric($value) ? $value + 0 : 0,
                'unit' => '' !== $unit ? $unit : 'px',
            ],
        ];
    }

    /** A Color_Prop_Type value (the `color`, `border-color`, ... style keys). */
    public static function color(string $value): array
    {
        return ['$$type' => 'color', 'value' => $value];
    }

    /**
     * Elementor has no `background-color` style key: background is a single
     * Background_Prop_Type whose value carries a nested color prop.
     */
    public static function background_color(string $value): array
    {
        return [
            '$$type' => 'background',
            'value'  => ['color' => self::color($value)],
        ];
    }

    /**
     * A Dimensions_Prop_Type value (padding / margin). Sides are the logical
     * CSS keys block-start, block-end, inline-start, inline-end, each a size
     * prop; omitted sides are simply not set.
     *
     * @param array<string,array> $sides
     */
    public static function dimensions(array $sides): array
    {
        return ['$$type' => 'dimensions', 'value' => $sides];
    }

    public static function image(int $image_id, string $image_url = '', string $alt = ''): array
    {
        $src = $image_id > 0
            ? ['id' => self::number($image_id), 'url' => null]
            : ['id' => null, 'url' => self::string($image_url)];

        return [
            '$$type' => 'image',
            'value'  => [
                'src'  => ['$$type' => 'image-src', 'value' => $src],
                'size' => self::string('full'),
                'alt'  => '' !== $alt ? self::string($alt) : null,
            ],
        ];
    }

    /** Whether a value is already an Elementor typed prop. */
    public static function is_typed($value): bool
    {
        return is_array($value) && isset($value['$$type']) && is_string($value['$$type']);
    }

    /**
     * The single mapper shared by add-atomic-widget, update-atomic-widget, the
     * atomic container tools, and the build-page builder dialect, so all four
     * emit byte-identical props for the same intent.
     *
     * @param string $element_type e-heading, e-flexbox, ... (a widgetType or elType).
     * @param array  $settings     Raw settings as supplied by the caller.
     *
     * @return array{settings: array, coerced: array<int,string>, warnings: array<int,string>}
     */
    public static function map(string $element_type, array $settings): array
    {
        $out      = [];
        $coerced  = [];
        $warnings = [];
        $known    = Atomic_Prop_Schema::known($element_type);

        foreach ($settings as $raw_name => $value) {
            $name = (string) $raw_name;

            $prop = Atomic_Prop_Schema::canonical($element_type, $name);
            if (null === $prop) {
                // Underscore-prefixed keys are Elementor's own internals
                // (__globals__, _element_id, ...). They are not declared in a
                // props schema, so they pass through untouched rather than
                // being treated as a typo.
                if (0 === strpos($name, '_')) {
                    $out[ $name ] = $value;
                    continue;
                }

                $warnings[] = sprintf(
                    'Dropped "%s": %s has no such prop and declares no alias for it (props: %s).',
                    $name,
                    $element_type,
                    implode(', ', array_keys(Atomic_Prop_Schema::for_type($element_type)))
                );
                continue;
            }

            if ($prop !== $name) {
                $coerced[] = sprintf('Renamed "%s" to "%s" (Elementor declares it as an alias).', $name, $prop);
            }

            $kind   = $known ? Atomic_Prop_Schema::kind($element_type, $prop) : null;
            $result = self::typed_value($element_type, $prop, $value, $kind);

            if (null !== $result['warning']) {
                $warnings[] = $result['warning'];
            }
            if (null !== $result['note']) {
                $coerced[] = $result['note'];
            }
            if (! $result['drop']) {
                $out[ $prop ] = $result['value'];
            }
        }

        return ['settings' => $out, 'coerced' => $coerced, 'warnings' => $warnings];
    }

    /**
     * Decide what actually gets stored for one prop.
     *
     * @param mixed $value
     * @return array{value: mixed, note: ?string, warning: ?string, drop: bool}
     */
    private static function typed_value(string $element_type, string $prop, $value, ?string $kind): array
    {
        if (self::is_typed($value)) {
            return self::retype($element_type, $prop, $value, $kind);
        }

        if (null === $kind) {
            // No metadata for this element (not atomic, or Elementor absent):
            // infer from the PHP type so a plain value is still usable, and
            // leave anything structural exactly as the caller sent it.
            $inferred = self::infer($value);
            if (null === $inferred) {
                return self::keep($value);
            }

            return self::keep($inferred, sprintf('Wrapped plain "%s" as a $$type "%s" prop.', $prop, $inferred['$$type']));
        }

        $built = self::build($kind, $value);
        if (null === $built) {
            return [
                'value'   => null,
                'note'    => null,
                'drop'    => true,
                'warning' => sprintf(
                    'Dropped "%s": %s expects a $$type "%s" prop and the supplied %s cannot be coerced into one. '
                    . 'Pass an already-wrapped value for this prop.',
                    $prop,
                    $element_type,
                    $kind,
                    self::describe($value)
                ),
            ];
        }

        return self::enforce_enum(
            $element_type,
            $prop,
            $built,
            sprintf('Wrapped plain "%s" as a $$type "%s" prop.', $prop, $kind)
        );
    }

    /**
     * An already-typed value whose `$$type` disagrees with the schema is the
     * classic hand-written-JSON failure. Rewrap it from its inner value when
     * the target type can express it; drop it when it cannot, because storing
     * the wrong type is what breaks the editor.
     *
     * @return array{value: mixed, note: ?string, warning: ?string, drop: bool}
     */
    private static function retype(string $element_type, string $prop, array $value, ?string $kind): array
    {
        $have = (string) $value['$$type'];

        // A prop Elementor wraps in a union legitimately accepts several
        // types (its own, plus the dynamic-tag and component-override
        // alternates); none of those is a mistake to be repaired.
        if (null === $kind || $have === $kind || Atomic_Prop_Schema::accepts($element_type, $prop, $have)) {
            return self::enforce_enum($element_type, $prop, $value, null);
        }

        $repaired = self::build($kind, self::unwrap($value));
        if (null !== $repaired) {
            return self::enforce_enum(
                $element_type,
                $prop,
                $repaired,
                sprintf('Rewrapped "%s" from $$type "%s" to "%s", the type %s declares.', $prop, $have, $kind, $element_type)
            );
        }

        return [
            'value'   => null,
            'note'    => null,
            'drop'    => true,
            'warning' => sprintf(
                'Dropped "%s": stored as $$type "%s" but %s declares "%s", and the value could not be converted.',
                $prop,
                $have,
                $element_type,
                $kind
            ),
        ];
    }

    /**
     * Elementor declares the allowed values for enum props (heading tag h1..h6,
     * paragraph tag p|span). A case-only mismatch is repaired; anything else is
     * reported and left alone, since the element still renders and the caller
     * may know about a value the local Elementor build does not.
     *
     * @param mixed $value
     * @return array{value: mixed, note: ?string, warning: ?string, drop: bool}
     */
    private static function enforce_enum(string $element_type, string $prop, $value, ?string $note): array
    {
        $enum = Atomic_Prop_Schema::enum($element_type, $prop);

        if (null === $enum || ! is_array($value) || ! isset($value['value']) || ! is_string($value['value'])) {
            return ['value' => $value, 'note' => $note, 'warning' => null, 'drop' => false];
        }

        $given = $value['value'];
        if (in_array($given, $enum, true)) {
            return ['value' => $value, 'note' => $note, 'warning' => null, 'drop' => false];
        }

        foreach ($enum as $allowed) {
            if (0 === strcasecmp($allowed, $given)) {
                $value['value'] = $allowed;

                return [
                    'value'   => $value,
                    'note'    => sprintf('Normalized "%s" from "%s" to "%s" (Elementor enum).', $prop, $given, $allowed),
                    'warning' => null,
                    'drop'    => false,
                ];
            }
        }

        return [
            'value'   => $value,
            'note'    => $note,
            'drop'    => false,
            'warning' => sprintf(
                'Kept "%s" = "%s", which is outside the values %s declares (%s); Elementor may ignore it.',
                $prop,
                $given,
                $element_type,
                implode(', ', $enum)
            ),
        ];
    }

    /**
     * Build a typed prop of `$kind` from a plain value, or null when the kind
     * cannot be expressed from what was supplied.
     *
     * @param mixed $value
     */
    private static function build(string $kind, $value): ?array
    {
        switch ($kind) {
            case 'string':
                return is_scalar($value) ? self::string(self::stringify($value)) : null;
            case 'number':
                return is_numeric($value) ? self::number($value) : null;
            case 'boolean':
                return is_bool($value) || is_numeric($value) ? self::boolean((bool) $value) : null;
            case 'html':
            case 'html-v2':
                return is_scalar($value) ? ['$$type' => $kind, 'value' => self::stringify($value)] : null;
            case 'html-v3':
                return is_scalar($value) ? self::html(self::stringify($value)) : null;
            case 'url':
            case 'color':
            case 'date-string':
            case 'time-string':
                return is_scalar($value) ? ['$$type' => $kind, 'value' => self::stringify($value)] : null;
            case 'classes':
                return self::build_classes($value);
            case 'string-array':
                return self::build_string_array($value);
            case 'link':
                return self::build_link($value);
            case 'image':
                return self::build_image($value);
            case 'size':
                return self::build_size($value);
        }

        return null;
    }

    /** @param mixed $value */
    private static function build_classes($value): ?array
    {
        if (is_string($value)) {
            $value = preg_split('/\s+/', trim($value)) ?: [];
        }
        if (! is_array($value)) {
            return null;
        }

        $ids = [];
        foreach ($value as $class) {
            if (! is_scalar($class) || '' === (string) $class) {
                return null;
            }
            $ids[] = (string) $class;
        }

        return self::classes($ids);
    }

    /** @param mixed $value */
    private static function build_string_array($value): ?array
    {
        if (is_string($value)) {
            $value = [$value];
        }
        if (! is_array($value)) {
            return null;
        }

        $items = [];
        foreach ($value as $item) {
            if (! is_scalar($item)) {
                return null;
            }
            $items[] = self::stringify($item);
        }

        return ['$$type' => 'string-array', 'value' => $items];
    }

    /** @param mixed $value */
    private static function build_link($value): ?array
    {
        if (is_string($value)) {
            return self::link(esc_url_raw($value));
        }
        if (! is_array($value)) {
            return null;
        }

        $url = $value['url'] ?? $value['destination'] ?? $value['href'] ?? null;
        if (is_array($url)) {
            $url = $url['value'] ?? null;
        }
        if (! is_string($url) || '' === $url) {
            return null;
        }

        $blank = $value['isTargetBlank'] ?? $value['target_blank'] ?? false;
        if (is_array($blank)) {
            $blank = $blank['value'] ?? false;
        }

        return self::link(esc_url_raw($url), (bool) $blank);
    }

    /** @param mixed $value */
    private static function build_image($value): ?array
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return self::image((int) $value);
        }
        if (is_string($value)) {
            return '' === trim($value) ? null : self::image(0, esc_url_raw($value));
        }
        if (! is_array($value)) {
            return null;
        }

        $id  = (int) ($value['id'] ?? $value['attachment_id'] ?? $value['image_id'] ?? 0);
        $url = (string) ($value['url'] ?? $value['src'] ?? $value['image_url'] ?? '');
        $alt = (string) ($value['alt'] ?? '');

        if ($id <= 0 && '' === trim($url)) {
            return null;
        }

        return self::image($id, '' !== $url ? esc_url_raw($url) : '', $alt);
    }

    /**
     * Coerce a caller-supplied length into a size prop, or null when it is not
     * a length at all. Accepts a number (18), a CSS length string ("1.5rem")
     * and the { size, unit } object shape. Public because the style-class
     * builders (Atomic_Styles, the global-class tools) need exactly this
     * coercion before they can decide whether a style value is usable.
     *
     * @param mixed $value
     */
    public static function build_size($value): ?array
    {
        if (is_numeric($value)) {
            return self::size((float) $value);
        }
        if (is_string($value) && preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*([a-z%]*)\s*$/i', $value, $m)) {
            return self::size((float) $m[1], '' !== $m[2] ? strtolower($m[2]) : 'px');
        }
        if (is_array($value) && isset($value['size']) && is_numeric($value['size'])) {
            return self::size((float) $value['size'], (string) ($value['unit'] ?? 'px'));
        }

        return null;
    }

    /**
     * Kind guess for elements we have no metadata for, so an agent working
     * against a third-party atomic widget still gets valid typed props for the
     * obvious scalar cases.
     *
     * @param mixed $value
     */
    private static function infer($value): ?array
    {
        if (is_bool($value)) {
            return self::boolean($value);
        }
        if (is_int($value) || is_float($value)) {
            return self::number($value);
        }
        if (is_string($value)) {
            return self::string($value);
        }

        return null;
    }

    /** @param mixed $value */
    private static function unwrap(array $value)
    {
        $inner = $value['value'] ?? null;

        // html-v3 nests its text one level deeper: value.content.value.
        if (is_array($inner) && isset($inner['content'])) {
            $content = $inner['content'];
            if (is_array($content) && array_key_exists('value', $content)) {
                return $content['value'];
            }
            return $content;
        }

        return $inner;
    }

    /**
     * @param mixed $value
     * @return array{value: mixed, note: ?string, warning: ?string, drop: bool}
     */
    private static function keep($value, ?string $note = null): array
    {
        return ['value' => $value, 'note' => $note, 'warning' => null, 'drop' => false];
    }

    /** @param mixed $value */
    private static function stringify($value): string
    {
        return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }

    /** @param mixed $value */
    private static function describe($value): string
    {
        if (is_array($value)) {
            return 'array';
        }
        if (is_object($value)) {
            return 'object';
        }
        if (null === $value) {
            return 'null';
        }

        return gettype($value);
    }
}
