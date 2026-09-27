<?php

namespace WPMCP\Tools\WidgetBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Validation and the control-type vocabulary for custom-widget specs.
 *
 * A spec is data, never code: { title, name?, icon?, keywords?, controls[], template }.
 * Each control is { name, type, label, default? }. The template is HTML with
 * {{name}} placeholders. Because a spec is interpreted (not compiled to PHP),
 * there is no eval anywhere in this feature.
 */
class Widget_Spec
{
    /** Supported control types, mapped to the Elementor control each one uses. */
    public const CONTROL_TYPES = [
        'text'     => ['elementor' => 'text', 'desc' => 'Single-line text (escaped on output)'],
        'textarea' => ['elementor' => 'textarea', 'desc' => 'Multi-line text (escaped on output)'],
        'wysiwyg'  => ['elementor' => 'wysiwyg', 'desc' => 'Rich text (rendered with wp_kses_post)'],
        'number'   => ['elementor' => 'number', 'desc' => 'Numeric value'],
        'url'      => ['elementor' => 'url', 'desc' => 'Link URL (escaped with esc_url)'],
        'image'    => ['elementor' => 'media', 'desc' => 'Media-library image; {{name}} outputs the image URL'],
        'icon'     => ['elementor' => 'icons', 'desc' => 'Icon picker; {{name}} outputs the icon class (the file URL for an inline SVG icon)'],
        'color'    => ['elementor' => 'color', 'desc' => 'Color value. Authors without unfiltered_html cannot use it inside a style attribute: wp_kses_post drops any style rule containing a {{placeholder}}'],
        'select'   => ['elementor' => 'select', 'desc' => 'Choice from options'],
        'switcher' => ['elementor' => 'switcher', 'desc' => 'On/off toggle (yes/empty)'],
    ];

    /**
     * @return true|\WP_Error true when the spec is well-formed.
     */
    public static function validate(array $spec)
    {
        if ('' === trim((string) ($spec['title'] ?? ''))) {
            return new \WP_Error('invalid_spec', 'A non-empty title is required.');
        }

        $controls = $spec['controls'] ?? null;
        if (! is_array($controls) || [] === $controls) {
            return new \WP_Error('invalid_spec', 'At least one control is required.');
        }

        $seen = [];
        foreach ($controls as $control) {
            if (! is_array($control)) {
                return new \WP_Error('invalid_control', 'Each control must be an object.');
            }
            $name = sanitize_key((string) ($control['name'] ?? ''));
            if ('' === $name) {
                return new \WP_Error('invalid_control', 'Each control needs a name.');
            }
            if (isset($seen[$name])) {
                return new \WP_Error('invalid_control', sprintf('Duplicate control name "%s".', $name));
            }
            $seen[$name] = true;

            $type = (string) ($control['type'] ?? '');
            if (! isset(self::CONTROL_TYPES[$type])) {
                return new \WP_Error(
                    'invalid_control',
                    sprintf('"%s" is not a supported control type (%s).', $type, implode(', ', array_keys(self::CONTROL_TYPES)))
                );
            }
            if ('' === trim((string) ($control['label'] ?? ''))) {
                return new \WP_Error('invalid_control', sprintf('Control "%s" needs a label.', $name));
            }
        }

        if ('' === trim((string) ($spec['template'] ?? ''))) {
            return new \WP_Error('invalid_spec', 'A non-empty template is required.');
        }

        return true;
    }

    /** Normalize a validated spec: derive a machine name from the title when absent. */
    public static function normalize(array $spec): array
    {
        $name = sanitize_title((string) ($spec['name'] ?? ''));
        if ('' === $name) {
            $name = sanitize_title((string) $spec['title']);
        }
        $spec['name'] = $name ?: 'custom-widget';

        return $spec;
    }
}
