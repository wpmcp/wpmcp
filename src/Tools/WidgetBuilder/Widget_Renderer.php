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
     * Interpolates control values into the spec's template.
     *
     * The exact output contract, stated precisely because it is the
     * justification for the phpcs:ignore on the echo in Dynamic_Widget:
     *
     * - Every interpolated VALUE is escaped by its control type before it
     *   reaches the template (wysiwyg -> wp_kses_post, url/image -> esc_url,
     *   everything else -> esc_html), so no control value can inject markup
     *   and escaping a value a second time would only double-encode it.
     * - The TEMPLATE ITSELF is passed through unmodified. It is author-supplied
     *   HTML, trusted on the same terms as a theme template or a Custom HTML
     *   block. Widget_Spec::validate() only checks that it is non-empty; the
     *   markup trust decision is made on write, in Widget_Spec_Store, which
     *   stores the template verbatim for an author holding `unfiltered_html`
     *   and wp_kses_post's it for anyone else.
     *
     * So the return value is output-ready, not "sanitized here". Callers must
     * not escape it again.
     *
     * @param array $spec     Validated widget spec (template + controls).
     * @param array $settings Current control values keyed by control name.
     * @return string The template with escaped control values interpolated.
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
        $value = self::scalarize($type, $value);

        switch ($type) {
            case 'wysiwyg':
                return wp_kses_post($value);
            case 'url':
            case 'image':
                return esc_url($value);
            default:
                return esc_html($value);
        }
    }

    /**
     * Reduces a raw control value to the single string the template
     * interpolates.
     *
     * Elementor does not hand every control back as a string: URL returns
     * ['url' => .., 'is_external' => .., 'nofollow' => ..], MEDIA returns
     * ['url' => .., 'id' => ..] and ICONS returns ['value' => .., 'library' => ..].
     * Casting those to string yields the literal "Array" plus a PHP notice, so
     * pick the member the control type actually documents ({{name}} outputs the
     * image URL, the icon class, the link URL). Anything else non-scalar renders
     * empty rather than leaking a type name into the page.
     *
     * @param mixed $value
     */
    private static function scalarize(string $type, $value): string
    {
        if (is_array($value)) {
            $value = $value['icon' === $type ? 'value' : 'url'] ?? '';
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
