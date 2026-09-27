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
    /**
     * Supported control types, mapped to the Elementor control each one uses
     * and to the escaper applied to its value on output.
     *
     * The 'escaper' key is the SINGLE source of truth for output escaping:
     * Widget_Renderer (runtime interpolation) and Compiler\Widget_Compiler
     * (emitted PHP) both read it, so a spec escapes identically whether it is
     * rendered by the dynamic widget or compiled to a class. Adding a control
     * type without an escaper here is impossible by construction; there is no
     * raw/unescaped output path.
     */
    public const CONTROL_TYPES = [
        'text'     => ['elementor' => 'text', 'escaper' => 'esc_html', 'desc' => 'Single-line text (escaped on output)'],
        'textarea' => ['elementor' => 'textarea', 'escaper' => 'esc_html', 'desc' => 'Multi-line text (escaped on output)'],
        'wysiwyg'  => ['elementor' => 'wysiwyg', 'escaper' => 'wp_kses_post', 'desc' => 'Rich text (rendered with wp_kses_post)'],
        'number'   => ['elementor' => 'number', 'escaper' => 'esc_html', 'desc' => 'Numeric value'],
        'url'      => ['elementor' => 'url', 'escaper' => 'esc_url', 'desc' => 'Link URL (escaped with esc_url)'],
        'image'    => ['elementor' => 'media', 'escaper' => 'esc_url', 'desc' => 'Media-library image; {{name}} outputs the image URL'],
        'icon'     => ['elementor' => 'icons', 'escaper' => 'esc_attr', 'desc' => 'Icon picker; {{name}} outputs the icon class (the file URL for an inline SVG icon)'],
        'color'    => ['elementor' => 'color', 'escaper' => 'esc_attr', 'desc' => 'Color value. Authors without unfiltered_html cannot use it inside a style attribute: wp_kses_post drops any style rule containing a {{placeholder}}'],
        'select'   => ['elementor' => 'select', 'escaper' => 'esc_html', 'desc' => 'Choice from options'],
        'switcher' => ['elementor' => 'switcher', 'escaper' => 'esc_attr', 'desc' => 'On/off toggle (yes/empty)'],
    ];

    /** The escaper declared for a control type; esc_html for anything unknown. */
    public static function escaper_for(string $type): string
    {
        return (string) (self::CONTROL_TYPES[$type]['escaper'] ?? 'esc_html');
    }

    /** Size ceilings. A spec is authored data, so every field is bounded. */
    public const MAX_CONTROLS = 50;
    public const MAX_KEYWORDS = 20;
    public const MAX_NAME     = 64;
    public const MAX_TEXT     = 200;
    public const MAX_DEFAULT  = 10000;
    public const MAX_TEMPLATE = 65535;

    /**
     * Static, side-effect-free validation. Hostile or malformed input gets a
     * WP_Error, never a PHP warning: every field is type-checked BEFORE it is
     * cast, because a cast is where an array becomes the literal "Array" and a
     * notice. Every field is also bounded, and the identifiers that end up in
     * markup or in generated PHP (control names, the icon class) are held to a
     * strict character set rather than silently sanitized into something the
     * author did not write.
     *
     * @return true|\WP_Error true when the spec is well-formed.
     */
    public static function validate(array $spec)
    {
        $title = $spec['title'] ?? '';
        if (! is_string($title) || '' === trim($title)) {
            return new \WP_Error('invalid_spec', 'A non-empty title is required.');
        }
        if (strlen($title) > self::MAX_TEXT) {
            return new \WP_Error('invalid_spec', sprintf('The title is longer than %d bytes.', self::MAX_TEXT));
        }
        if (isset($spec['name']) && (! is_string($spec['name']) || strlen($spec['name']) > self::MAX_TEXT)) {
            return new \WP_Error('invalid_spec', 'The name must be a short string.');
        }
        if (isset($spec['icon']) && (! is_string($spec['icon']) || 1 !== preg_match('/^[A-Za-z0-9_\- ]{1,100}$/', $spec['icon']))) {
            return new \WP_Error('invalid_spec', 'The icon must be an icon class name (letters, digits, "-", "_" and spaces).');
        }
        if (isset($spec['keywords'])) {
            $keywords = $spec['keywords'];
            if (! is_array($keywords) || count($keywords) > self::MAX_KEYWORDS) {
                return new \WP_Error('invalid_spec', sprintf('Keywords must be a list of at most %d strings.', self::MAX_KEYWORDS));
            }
            foreach ($keywords as $keyword) {
                if (! is_string($keyword) || strlen($keyword) > self::MAX_TEXT) {
                    return new \WP_Error('invalid_spec', 'Each keyword must be a short string.');
                }
            }
        }

        $controls = $spec['controls'] ?? null;
        if (! is_array($controls) || [] === $controls) {
            return new \WP_Error('invalid_spec', 'At least one control is required.');
        }
        if (count($controls) > self::MAX_CONTROLS) {
            return new \WP_Error('invalid_spec', sprintf('A widget may declare at most %d controls.', self::MAX_CONTROLS));
        }

        $seen = [];
        foreach ($controls as $control) {
            if (! is_array($control)) {
                return new \WP_Error('invalid_control', 'Each control must be an object.');
            }
            $raw_name = $control['name'] ?? '';
            if (! is_string($raw_name) || '' === $raw_name) {
                return new \WP_Error('invalid_control', 'Each control needs a name.');
            }
            if (1 !== preg_match('/^[A-Za-z0-9_\-]{1,' . self::MAX_NAME . '}$/', $raw_name)) {
                return new \WP_Error(
                    'invalid_control',
                    sprintf('Control names may use only letters, digits, "-" and "_", up to %d characters.', self::MAX_NAME)
                );
            }
            $name = sanitize_key($raw_name);
            if (isset($seen[$name])) {
                return new \WP_Error('invalid_control', sprintf('Duplicate control name "%s".', $name));
            }
            $seen[$name] = true;

            $type = $control['type'] ?? '';
            if (! is_string($type) || ! isset(self::CONTROL_TYPES[$type])) {
                return new \WP_Error(
                    'invalid_control',
                    sprintf('Control "%s" has an unsupported type; use one of: %s.', $name, implode(', ', array_keys(self::CONTROL_TYPES)))
                );
            }
            $label = $control['label'] ?? '';
            if (! is_string($label) || '' === trim($label)) {
                return new \WP_Error('invalid_control', sprintf('Control "%s" needs a label.', $name));
            }
            if (strlen($label) > self::MAX_TEXT) {
                return new \WP_Error('invalid_control', sprintf('The label of control "%s" is longer than %d bytes.', $name, self::MAX_TEXT));
            }
            $default = $control['default'] ?? null;
            if (null !== $default && ! is_scalar($default)) {
                return new \WP_Error('invalid_control', sprintf('The default of control "%s" must be a plain value.', $name));
            }
            if (is_string($default) && strlen($default) > self::MAX_DEFAULT) {
                return new \WP_Error('invalid_control', sprintf('The default of control "%s" is longer than %d bytes.', $name, self::MAX_DEFAULT));
            }
        }

        $template = $spec['template'] ?? '';
        if (! is_string($template) || '' === trim($template)) {
            return new \WP_Error('invalid_spec', 'A non-empty template is required.');
        }
        if (strlen($template) > self::MAX_TEMPLATE) {
            return new \WP_Error('invalid_spec', sprintf('The template is longer than %d bytes.', self::MAX_TEMPLATE));
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
