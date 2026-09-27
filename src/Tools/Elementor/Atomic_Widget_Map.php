<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Maps friendly params (title, content, text, image_url, alt, link) to the
 * typed-prop settings each Elementor 4.0+ atomic widget expects.
 *
 * `partial()` maps ONLY the params provided (used by update-atomic-widget, so a
 * partial edit never resets untouched props). `settings()` builds a complete
 * new widget by applying per-type defaults first (used by add-atomic-widget).
 * Types this class does not know still work through the raw-settings escape
 * hatch, so this is a convenience layer, not an allowlist.
 */
class Atomic_Widget_Map
{
    /**
     * Inline tags Elementor 4.3.2 allows in each rich-text prop
     * (Escaped_Html_Prop_Type::get_allowed_html_tags_for_prop). Used only when
     * the installed Elementor does not expose that method itself.
     */
    private const INLINE_TAGS = [
        'e-heading'   => ['b', 'strong', 'sup', 'sub', 's', 'em', 'i', 'u', 'a', 'del', 'span', 'br'],
        'e-paragraph' => ['b', 'strong', 'sup', 'sub', 's', 'em', 'u', 'ul', 'ol', 'li', 'blockquote', 'a', 'del', 'span', 'br'],
        'e-button'    => ['b', 'strong', 'sup', 'sub', 's', 'em', 'i', 'u', 'del', 'span', 'br'],
    ];

    /** Atomic widget types this class can build from friendly params. */
    public const KNOWN = ['e-heading', 'e-paragraph', 'e-button', 'e-image', 'e-divider'];

    public static function knows(string $widget_type): bool
    {
        return in_array($widget_type, self::KNOWN, true);
    }

    /**
     * Map only the params present to typed props (no defaults), plus the shared
     * link / css_id tail. Returns [] for an unknown type with no shared params.
     */
    public static function partial(string $widget_type, array $params): array
    {
        $out = [];

        switch ($widget_type) {
            case 'e-heading':
                if (isset($params['title'])) {
                    $out['title'] = self::rich('e-heading', 'title', $params['title']);
                }
                if (isset($params['tag'])) {
                    $out['tag'] = Atomic_Props::string(self::text($params['tag']));
                }
                break;
            case 'e-paragraph':
                if (isset($params['content']) || isset($params['text'])) {
                    $out['paragraph'] = self::rich('e-paragraph', 'paragraph', $params['content'] ?? $params['text']);
                }
                break;
            case 'e-button':
                if (isset($params['text'])) {
                    $out['text'] = self::rich('e-button', 'text', $params['text']);
                }
                break;
            case 'e-image':
                $image = self::image($params);
                if ([] !== $image) {
                    $out = $image;
                }
                break;
        }

        if (! empty($params['link'])) {
            $out['link'] = Atomic_Props::link(esc_url_raw((string) $params['link']), ! empty($params['target_blank']));
        }
        if (! empty($params['css_id'])) {
            $out['_cssid'] = Atomic_Props::string(sanitize_text_field((string) $params['css_id']));
        }

        return $out;
    }

    /**
     * Build a complete new widget's settings: per-type defaults, overlaid with
     * any provided params, plus the classes tail. Returns null for a type with
     * no mapping (the caller then requires raw settings).
     */
    public static function settings(string $widget_type, array $params): ?array
    {
        if (! self::knows($widget_type)) {
            return null;
        }

        $settings = array_merge(self::defaults($widget_type), self::partial($widget_type, $params));

        if (! isset($settings['classes'])) {
            $settings['classes'] = Atomic_Props::classes();
        }

        return $settings;
    }

    private static function defaults(string $widget_type): array
    {
        switch ($widget_type) {
            case 'e-heading':
                return ['title' => Atomic_Props::rich_text('e-heading', 'title', 'Heading'), 'tag' => Atomic_Props::string('h2')];
            case 'e-paragraph':
                return ['paragraph' => Atomic_Props::rich_text('e-paragraph', 'paragraph', 'Paragraph text')];
            case 'e-button':
                return ['text' => Atomic_Props::rich_text('e-button', 'text', 'Click here')];
            default:
                return [];
        }
    }

    private static function image(array $params): array
    {
        $image_id  = (int) ($params['image_id'] ?? 0);
        $image_url = isset($params['image_url']) ? esc_url_raw((string) $params['image_url']) : '';
        $alt       = isset($params['alt']) ? sanitize_text_field((string) $params['alt']) : '';

        if ($image_id <= 0 && '' === $image_url) {
            return [];
        }
        if ($image_id > 0 && '' !== $alt) {
            update_post_meta($image_id, '_wp_attachment_image_alt', $alt);
        }

        return ['image' => Atomic_Props::image($image_id, $image_url, $alt)];
    }

    private static function text(string $value): string
    {
        return sanitize_text_field($value);
    }

    /**
     * A rich-text friendly param (heading title, paragraph body, button
     * label). Rich text is inline HTML by contract, so it is filtered to the
     * tags Elementor allows in that prop rather than stripped to plain text.
     */
    /** @param mixed $value */
    private static function rich(string $widget_type, string $prop, $value): array
    {
        $text  = is_scalar($value) ? (string) $value : '';
        $clean = wp_kses(wp_check_invalid_utf8($text), self::allowed_inline_html($widget_type, $prop));

        return Atomic_Props::rich_text($widget_type, $prop, trim($clean));
    }

    /** @return array<string, array<string, bool>> wp_kses allowed-HTML map. */
    private static function allowed_inline_html(string $widget_type, string $prop): array
    {
        $tags  = null;
        $class = '\\Elementor\\Modules\\AtomicWidgets\\PropTypes\\Escaped_Html_Prop_Type';
        if (class_exists($class) && method_exists($class, 'get_allowed_html_tags_for_prop')) {
            $tags = $class::get_allowed_html_tags_for_prop($widget_type, $prop);
        }
        if (! is_array($tags)) {
            $tags = self::INLINE_TAGS[ $widget_type ] ?? [];
        }

        $allowed = [];
        foreach ($tags as $tag) {
            $allowed[ (string) $tag ] = 'a' === $tag ? ['href' => true, 'target' => true] : [];
        }

        return $allowed;
    }
}
