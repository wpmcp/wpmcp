<?php

namespace WPMCP\Tools\WidgetBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Renders a custom-widget spec by interpolating control values into its
 * template. Pure and eval-free: every {{name}} placeholder is replaced with the
 * matching setting, escaped with the escaper that control type declares in
 * Widget_Spec::CONTROL_TYPES (the one table both this renderer and the
 * compiler read). Unknown placeholders render empty. This is the single output path
 * the runtime Dynamic_Widget uses, so a stored spec can never execute code.
 */
class Widget_Renderer
{
    public static function render(array $spec, array $settings): string
    {
        $controls = is_array($spec['controls'] ?? null) ? $spec['controls'] : [];
        $template = (string) ($spec['template'] ?? '');

        $values = [];
        foreach ($controls as $control) {
            $name = sanitize_key((string) ($control['name'] ?? ''));
            if ('' === $name) {
                continue;
            }
            $raw = $settings[$name] ?? ($control['default'] ?? '');
            $values[$name] = self::escape((string) ($control['type'] ?? 'text'), $raw);
        }

        return (string) preg_replace_callback(
            '/\{\{\s*([a-z0-9_\-]+)\s*\}\}/i',
            static function (array $m) use ($values): string {
                $key = sanitize_key($m[1]);
                return $values[$key] ?? '';
            },
            $template
        );
    }

    /**
     * Escape a value with the escaper its control type declares in
     * Widget_Spec::CONTROL_TYPES. That table is the single source of truth,
     * shared with Compiler\\Widget_Compiler, so a spec escapes identically
     * whether it is interpolated here or compiled into a widget class.
     *
     * @param mixed $value
     */
    private static function escape(string $type, $value): string
    {
        $escaper = Widget_Spec::escaper_for($type);
        switch ($escaper) {
            case 'wp_kses_post':
                return wp_kses_post((string) $value);
            case 'esc_url':
                return esc_url((string) $value);
            case 'esc_attr':
                return esc_attr((string) $value);
            default:
                return esc_html((string) $value);
        }
    }
}
