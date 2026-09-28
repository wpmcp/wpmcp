<?php

namespace WPMCP\Tools\BlockBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Validation and the attribute-type vocabulary for custom Gutenberg block
 * specs. Like the widget builder, a block spec is data, never code:
 * { title, name?, category?, attributes[], template }. Each attribute is
 * { name, type, label, default? }; the template is HTML with {{name}}
 * placeholders. A dynamic render_callback interprets it at runtime, so there is
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
        'boolean'  => ['json' => 'boolean', 'desc' => 'True/false toggle'],
        'color'    => ['json' => 'string', 'desc' => 'Color value'],
    ];

    /**
     * @return true|\WP_Error
     */
    public static function validate(array $spec)
    {
        if ('' === trim((string) ($spec['title'] ?? ''))) {
            return new \WP_Error('invalid_spec', 'A non-empty title is required.');
        }

        $attributes = $spec['attributes'] ?? null;
        if (! is_array($attributes) || [] === $attributes) {
            return new \WP_Error('invalid_spec', 'At least one attribute is required.');
        }

        $seen = [];
        foreach ($attributes as $attr) {
            if (! is_array($attr)) {
                return new \WP_Error('invalid_attribute', 'Each attribute must be an object.');
            }
            $name = sanitize_key((string) ($attr['name'] ?? ''));
            if ('' === $name) {
                return new \WP_Error('invalid_attribute', 'Each attribute needs a name.');
            }
            if (isset($seen[$name])) {
                return new \WP_Error('invalid_attribute', sprintf('Duplicate attribute name "%s".', $name));
            }
            $seen[$name] = true;

            $type = (string) ($attr['type'] ?? '');
            if (! isset(self::ATTRIBUTE_TYPES[$type])) {
                return new \WP_Error(
                    'invalid_attribute',
                    sprintf('"%s" is not a supported attribute type (%s).', $type, implode(', ', array_keys(self::ATTRIBUTE_TYPES)))
                );
            }
            if ('' === trim((string) ($attr['label'] ?? ''))) {
                return new \WP_Error('invalid_attribute', sprintf('Attribute "%s" needs a label.', $name));
            }
        }

        if ('' === trim((string) ($spec['template'] ?? ''))) {
            return new \WP_Error('invalid_spec', 'A non-empty template is required.');
        }

        return true;
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
