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
    /**
     * Interpolates control values into the spec's template.
     *
     * The exact output contract, stated precisely because it is the
     * justification for the phpcs:ignore on the echo in Dynamic_Widget:
     *
     * - Every interpolated VALUE is escaped by its control type before it
     *   reaches the template with the escaper its type declares in
     *   Widget_Spec::CONTROL_TYPES (wysiwyg -> wp_kses_post, url/image ->
     *   esc_url, icon/color/switcher -> esc_attr, everything else ->
     *   esc_html), so no control value can inject markup
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
        $data   = [];
        foreach ($controls as $control) {
            $name = sanitize_key((string) ($control['name'] ?? ''));
            if ('' === $name) {
                continue;
            }
            $type = (string) ($control['type'] ?? 'text');
            $raw  = $settings[$name] ?? ($control['default'] ?? '');
            if (Widget_Spec::is_data($type)) {
                // Rendered lazily, only when the template uses it, so an
                // unused data control never queries or fetches anything.
                $data[$name] = [$type, $control['query'] ?? [], is_scalar($raw) ? (string) $raw : ''];
                continue;
            }
            $values[$name] = self::escape($type, $raw);
        }

        return (string) preg_replace_callback(
            '/\{\{\s*([a-z0-9_\-]+)\s*\}\}/i',
            static function (array $m) use (&$values, $data): string {
                $key = sanitize_key($m[1]);
                if (! isset($values[$key]) && isset($data[$key])) {
                    // Output-ready: Widget_Data escapes every value it emits.
                    $values[$key] = Data\Widget_Data::render($data[$key][0], $data[$key][1], $data[$key][2]);
                }
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
        $value = self::scalarize($type, $value);

        switch (Widget_Spec::escaper_for($type)) {
            case 'wp_kses_post':
                return wp_kses_post($value);
            case 'esc_url':
                return esc_url($value);
            case 'esc_attr':
                return esc_attr($value);
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
     * ['url' => .., 'id' => ..] and ICONS returns ['value' => .., 'library' => ..],
     * where `value` is itself ['url' => .., 'id' => ..] for an inline SVG from the
     * media library. Casting those to string yields the literal "Array" plus a
     * PHP notice, so pick the member the control type actually documents:
     * {{name}} outputs the link URL, the image URL, the icon class (or the SVG
     * file URL for an svg-library icon). An array under any other control type
     * is not something the type documents and renders empty rather than leaking
     * a type name, or a member of an unexpected shape, into the page.
     *
     * @param mixed $value
     */
    private static function scalarize(string $type, $value): string
    {
        if (is_array($value)) {
            switch ($type) {
                case 'url':
                case 'image':
                    $value = $value['url'] ?? '';
                    break;
                case 'icon':
                    $value = $value['value'] ?? '';
                    if (is_array($value)) {
                        $value = $value['url'] ?? '';
                    }
                    break;
                default:
                    $value = '';
            }
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
