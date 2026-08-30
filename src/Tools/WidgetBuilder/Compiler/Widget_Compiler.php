<?php

namespace WPMCP\Tools\WidgetBuilder\Compiler;

use WPMCP\Tools\WidgetBuilder\Widget_Spec;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The sole PHP emitter for spec-compiled widgets (issue #72). The agent never
 * writes PHP: it submits a data spec (already validated by Widget_Spec), and
 * this class compiles that spec into a real Elementor widget class. Every
 * interpolated template placeholder is escaped according to its control's
 * declared type (see ESCAPERS), and the emitted source must pass
 * Generated_Code_Lint before a single byte reaches disk.
 */
class Widget_Compiler
{
    /**
     * Escaping function per declared control type. A placeholder whose
     * control type is not in this map cannot be compiled at all; there is no
     * raw/unescaped output path by construction.
     */
    private const ESCAPERS = [
        'text'     => 'esc_html',
        'textarea' => 'esc_html',
        'url'      => 'esc_url',
        'color'    => 'esc_attr',
        'number'   => 'floatval',
        'select'   => 'esc_html',
        'switcher' => 'esc_attr',
    ];

    /**
     * Compile a validated spec to PHP source (in memory; the caller is
     * responsible for the lint + manifest write).
     *
     * TODO(#72): emit the widget class body: meta (name/title/icon), typed
     * control registration per section, and a render() built from the
     * template placeholders with the per-type escaper applied. Until then
     * this returns wpmcp_compiler_incomplete so nothing can reach disk.
     *
     * @param array $spec a spec that already passed Widget_Spec::validate().
     * @return string|\WP_Error the full PHP source of the widget class.
     */
    public static function compile(array $spec)
    {
        $valid = Widget_Spec::validate($spec);
        if (is_wp_error($valid)) {
            return $valid;
        }

        foreach ((array) ($spec['controls'] ?? []) as $control) {
            $type = (string) ($control['type'] ?? '');
            if (! isset(self::ESCAPERS[$type])) {
                return new \WP_Error(
                    'wpmcp_widget_spec_invalid',
                    sprintf('Control type "%s" has no declared escaper and cannot be compiled.', $type)
                );
            }
        }

        return new \WP_Error(
            'wpmcp_compiler_incomplete',
            'The spec compiler does not emit widget classes yet (issue #72 WIP); use create-custom-widget for the data-driven runtime widget.'
        );
    }

    /** The PSR-adjacent class name a spec compiles to, derived from its machine name. */
    public static function class_name_for(string $name): string
    {
        $parts = array_map('ucfirst', explode('-', sanitize_key($name)));
        return 'WPMCP_Compiled_Widget_' . implode('_', $parts);
    }
}
