<?php

namespace WPMCP\Tools\BlockBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Validation and the attribute-type vocabulary for custom Gutenberg block
 * specs. Like the widget builder, a block spec is data, never code:
 * { title, name?, category?, attributes[], template, supports?, panels? }.
 * Each attribute is { name, type, label, default?, help?, panel? } plus
 * options (select) or fields/min/max (repeater); the template is HTML with
 * {{name}} placeholders and {{#name}}...{{/name}} sections. A dynamic render_callback interprets it at runtime, so there is
 * no eval anywhere in this feature.
 */
class Block_Spec
{
    /** Supported attribute types, mapped to the block.json attribute type each uses. */
    public const ATTRIBUTE_TYPES = [
        'string'   => ['json' => 'string', 'desc' => 'Single-line text (escaped on output)'],
        'richtext' => ['json' => 'string', 'desc' => 'Rich text (rendered with wp_kses_post)'],
        'url'      => ['json' => 'string', 'desc' => 'Link URL (escaped with esc_url)'],
        'image'    => ['json' => 'string', 'desc' => 'Image URL (escaped with esc_url)'],
        'number'   => ['json' => 'number', 'desc' => 'Numeric value'],
        'boolean'  => ['json' => 'boolean', 'desc' => 'True/false toggle; {{#name}}...{{/name}} renders only when true'],
        'color'    => ['json' => 'string', 'desc' => 'Color value (theme palette in the editor)'],
        'select'   => ['json' => 'string', 'desc' => 'One of a fixed list: options [{value,label}] or [value]; an unknown value renders the default'],
        'media'    => ['json' => 'object', 'desc' => 'Media library item {id,url,alt}: {{name}} renders an <img>, or use {{name.url}}, {{name.alt}}, {{name.id}}'],
        'link'     => ['json' => 'object', 'desc' => 'Link {text,url,target}: {{name}} renders an <a>, or use {{name.url}}, {{name.text}}, {{name.target}}'],
        'repeater' => ['json' => 'array', 'desc' => 'List of items with typed sub-fields (fields[], min, max): {{#name}}...{{title}}...{{/name}} renders once per item'],
    ];

    /**
     * Attribute names WordPress itself owns on a block (block supports, the
     * editor's lock and metadata, layout). Compared after sanitize_key(), so
     * "className" and "classname" are the same name.
     */
    public const RESERVED_NAMES = [
        'classname', 'style', 'align', 'anchor', 'lock', 'metadata',
        'backgroundcolor', 'textcolor', 'gradient', 'fontsize', 'fontfamily',
        'bordercolor',
    ];

    /**
     * Block supports a spec may turn on, each with the sub-features `true`
     * expands to. Applied through get_block_wrapper_attributes() on the front
     * end and useBlockProps() in the editor.
     */
    public const SUPPORTS = [
        'color'      => ['background', 'text', 'link', 'gradients'],
        'spacing'    => ['padding', 'margin'],
        'typography' => ['fontSize', 'lineHeight'],
        'border'     => ['color', 'radius', 'style', 'width'],
        'shadow'     => [],
        'align'      => ['left', 'center', 'right', 'wide', 'full'],
    ];

    /** Attribute types a repeater item may use (no nested repeaters). */
    private const ITEM_TYPES = ['string', 'richtext', 'url', 'image', 'number', 'boolean', 'color', 'select', 'media', 'link'];

    /**
     * @param bool $strict false skips the reserved-name rule, for specs
     *                     stored before it existed (they keep registering).
     * @return true|\WP_Error
     */
    public static function validate(array $spec, bool $strict = true)
    {
        if ('' === trim((string) ($spec['title'] ?? ''))) {
            return new \WP_Error('invalid_spec', 'A non-empty title is required.');
        }

        $attributes = $spec['attributes'] ?? null;
        if (! is_array($attributes) || [] === $attributes) {
            return new \WP_Error('invalid_spec', 'At least one attribute is required.');
        }

        $valid = self::validate_attributes($attributes, array_keys(self::ATTRIBUTE_TYPES), $strict, '');
        if (true !== $valid) {
            return $valid;
        }

        if ('' === trim((string) ($spec['template'] ?? ''))) {
            return new \WP_Error('invalid_spec', 'A non-empty template is required.');
        }

        $valid = self::validate_supports($spec['supports'] ?? null);
        if (true !== $valid) {
            return $valid;
        }

        return self::validate_panels($spec['panels'] ?? null);
    }

    /**
     * @param string[] $types the types allowed at this level
     * @return true|\WP_Error
     */
    private static function validate_attributes(array $attributes, array $types, bool $strict, string $parent)
    {
        $seen = [];
        foreach ($attributes as $attr) {
            if (! is_array($attr)) {
                return new \WP_Error('invalid_attribute', 'Each attribute must be an object.');
            }
            $name = sanitize_key((string) ($attr['name'] ?? ''));
            if ('' === $name) {
                return new \WP_Error('invalid_attribute', 'Each attribute needs a name.');
            }
            $where = '' === $parent ? $name : $parent . '.' . $name;
            if (isset($seen[$name])) {
                return new \WP_Error('invalid_attribute', sprintf('Duplicate attribute name "%s".', $where));
            }
            $seen[$name] = true;
            if ($strict && '' === $parent && in_array($name, self::RESERVED_NAMES, true)) {
                return new \WP_Error('invalid_attribute', sprintf('Attribute name "%s" is reserved by WordPress; choose another.', $name));
            }

            $type = (string) ($attr['type'] ?? '');
            if (! in_array($type, $types, true)) {
                return new \WP_Error(
                    'invalid_attribute',
                    sprintf('"%s" is not a supported attribute type for "%s" (%s).', $type, $where, implode(', ', $types))
                );
            }
            if ('' === trim((string) ($attr['label'] ?? ''))) {
                return new \WP_Error('invalid_attribute', sprintf('Attribute "%s" needs a label.', $where));
            }
            foreach (['help', 'panel'] as $text) {
                if (isset($attr[$text]) && ! is_string($attr[$text])) {
                    return new \WP_Error('invalid_attribute', sprintf('Attribute "%s": %s must be a string.', $where, $text));
                }
            }

            if ('select' === $type) {
                $values = array_column(self::options($attr), 'value');
                if ([] === $values) {
                    return new \WP_Error('invalid_attribute', sprintf('Select "%s" needs a non-empty options list.', $where));
                }
                if (isset($attr['default']) && '' !== $attr['default'] && ! in_array((string) $attr['default'], $values, true)) {
                    return new \WP_Error('invalid_attribute', sprintf('Select "%s": default "%s" is not one of its options.', $where, (string) $attr['default']));
                }
            }

            if ('repeater' === $type) {
                $fields = $attr['fields'] ?? null;
                if (! is_array($fields) || [] === $fields) {
                    return new \WP_Error('invalid_attribute', sprintf('Repeater "%s" needs a non-empty fields list.', $where));
                }
                $valid = self::validate_attributes($fields, self::ITEM_TYPES, $strict, $where);
                if (true !== $valid) {
                    return $valid;
                }
                foreach (['min', 'max'] as $bound) {
                    if (isset($attr[$bound]) && (! is_numeric($attr[$bound]) || (int) $attr[$bound] < 0)) {
                        return new \WP_Error('invalid_attribute', sprintf('Repeater "%s": %s must be a whole number of 0 or more.', $where, $bound));
                    }
                }
                if (isset($attr['min'], $attr['max']) && (int) $attr['min'] > (int) $attr['max']) {
                    return new \WP_Error('invalid_attribute', sprintf('Repeater "%s": min is larger than max.', $where));
                }
                if (isset($attr['default']) && ! is_array($attr['default'])) {
                    return new \WP_Error('invalid_attribute', sprintf('Repeater "%s": default must be a list of items.', $where));
                }
            }

            if (in_array($type, ['media', 'link'], true) && isset($attr['default']) && '' !== $attr['default'] && ! is_array($attr['default'])) {
                return new \WP_Error('invalid_attribute', sprintf('Attribute "%s": default must be an object.', $where));
            }
        }
        return true;
    }

    /**
     * A select's options as [{value,label}], from either objects or bare
     * values. Invalid entries are dropped.
     *
     * @return array<int,array{value:string,label:string}>
     */
    public static function options(array $attr): array
    {
        $out = [];
        foreach ((array) ($attr['options'] ?? []) as $option) {
            if (is_array($option)) {
                if (! isset($option['value']) || ! is_scalar($option['value'])) {
                    continue;
                }
                $value = (string) $option['value'];
                $label = isset($option['label']) && is_scalar($option['label']) ? (string) $option['label'] : $value;
            } elseif (is_scalar($option)) {
                $value = (string) $option;
                $label = $value;
            } else {
                continue;
            }
            if ('' !== $value) {
                $out[] = ['value' => $value, 'label' => $label];
            }
        }
        return $out;
    }

    /** @param mixed $supports @return true|\WP_Error */
    private static function validate_supports($supports)
    {
        if (null === $supports) {
            return true;
        }
        if (! is_array($supports)) {
            return new \WP_Error('invalid_spec', 'supports must be an object.');
        }
        foreach ($supports as $key => $value) {
            if (! isset(self::SUPPORTS[$key])) {
                return new \WP_Error('invalid_spec', sprintf('"%s" is not a supported block support (%s).', $key, implode(', ', array_keys(self::SUPPORTS))));
            }
            if (is_bool($value)) {
                continue;
            }
            if (! is_array($value) || [] === self::SUPPORTS[$key]) {
                return new \WP_Error('invalid_spec', sprintf('supports.%s must be true or false.', $key));
            }
            $features = 'align' === $key ? $value : array_keys($value);
            foreach ($features as $feature) {
                if (! in_array($feature, self::SUPPORTS[$key], true)) {
                    return new \WP_Error('invalid_spec', sprintf('supports.%s: "%s" is not one of %s.', $key, (string) $feature, implode(', ', self::SUPPORTS[$key])));
                }
            }
        }
        return true;
    }

    /** @param mixed $panels @return true|\WP_Error */
    private static function validate_panels($panels)
    {
        if (null === $panels) {
            return true;
        }
        if (! is_array($panels)) {
            return new \WP_Error('invalid_spec', 'panels must be a list of {title, help?, opened?}.');
        }
        foreach ($panels as $panel) {
            if (! is_array($panel) || '' === trim((string) ($panel['title'] ?? '')) || (isset($panel['help']) && ! is_string($panel['help']))) {
                return new \WP_Error('invalid_spec', 'Each panel needs a title; help, when given, is a string.');
            }
        }
        return true;
    }

    /**
     * The spec's supports in register_block_type form: `true` expands to
     * every sub-feature; align keeps its list.
     */
    public static function block_supports(array $spec): array
    {
        $out = [];
        foreach ((array) ($spec['supports'] ?? []) as $key => $value) {
            if (! isset(self::SUPPORTS[$key]) || false === $value) {
                continue;
            }
            if ('align' === $key) {
                $out['align'] = is_array($value) ? array_values(array_intersect($value, self::SUPPORTS['align'])) : ['wide', 'full'];
                continue;
            }
            if ([] === self::SUPPORTS[$key]) {
                $out[$key] = (bool) $value;
                continue;
            }
            $features = [];
            foreach (self::SUPPORTS[$key] as $feature) {
                $features[$feature] = true === $value || ! empty($value[$feature]);
            }
            // WordPress still reads border support under its experimental key.
            $out['border' === $key ? '__experimentalBorder' : $key] = $features;
        }
        return $out;
    }

    /**
     * Normalize: a namespaced block name (wpmcp/<slug>) derived from name or
     * title. Idempotent, so a stored spec read back and written again (an
     * update, a cloud pull, a bundle import) keeps its name: any provided
     * namespace is stripped BEFORE sanitize_title(), which would otherwise
     * fold "wpmcp/callout" into the slug "wpmcp-callout".
     */
    public static function normalize(array $spec): array
    {
        $raw  = preg_replace('#^[A-Za-z0-9_\-]+/#', '', trim((string) ($spec['name'] ?? '')));
        $slug = sanitize_title((string) $raw);
        if ('' === $slug) {
            $slug = sanitize_title((string) $spec['title']);
        }
        $slug = $slug ?: 'custom-block';
        // The block always lives under wpmcp/.
        $spec['name'] = 'wpmcp/' . $slug;

        return $spec;
    }
}
