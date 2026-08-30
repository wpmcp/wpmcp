<?php

namespace WPMCP\Tools\WidgetBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Renders a custom-widget spec by interpolating control values into its
 * template. Pure and eval-free: every {{name}} placeholder is replaced with the
 * matching setting, escaped according to that control's type (text/textarea →
 * esc_html, wysiwyg → wp_kses_post, url/image → esc_url, everything else →
 * esc_html). Unknown placeholders render empty. This is the single output path
 * the runtime Dynamic_Widget uses, so a stored spec can never execute code.
 */
class Widget_Renderer
{
    /**
     * Renders the spec's template with every interpolated value already
     * escaped by its control type, so the returned string is safe to echo
     * as-is. Callers (Dynamic_Widget::render()) must not escape it again;
     * a second pass would double-encode entities.
     *
     * @param array $spec     Validated widget spec (template + controls).
     * @param array $settings Current control values keyed by control name.
     * @return string Pre-escaped HTML ready for output.
     */
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

    /** @param mixed $value */
    private static function escape(string $type, $value): string
    {
        switch ($type) {
            case 'wysiwyg':
                return wp_kses_post((string) $value);
            case 'url':
            case 'image':
                return esc_url((string) $value);
            default:
                return esc_html((string) $value);
        }
    }
}
