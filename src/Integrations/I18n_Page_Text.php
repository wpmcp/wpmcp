<?php

namespace WPMCP\Integrations;

use WPMCP\Tools\Elementor\Elementor_Page_Data;
use WPMCP\Tools\SEO\SEO_Adapter;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Every translatable string of a post as numbered segments, and the write
 * that puts translated segments back (issue #498, get-page-text and
 * apply-page-text on I18n_Pack).
 *
 * A segment is ['key', 'kind', 'text', 'where']:
 *  - 'title' and 'excerpt', the post's own columns;
 *  - 'seo.title' and 'seo.description', through SEO_Adapter, for an SEO
 *    plugin that keeps them in post meta (a plugin with its own table is
 *    left out, since the post snapshot would not cover it);
 *  - on an Elementor page, 'el:<element id>:<setting>' for a widget's text,
 *    textarea and rich text controls (link labels are text controls), and
 *    'el:<element id>:<setting>:<index>:<field>' inside a repeater, plus
 *    the rich text props of atomic widgets. Dynamic settings, ids, CSS
 *    classes and URLs are not text;
 *  - otherwise 'content:<n>', each run of text in post_content between
 *    block level tags and block comments, with its inline markup (block
 *    pages and classic pages alike). Scripts, styles and comments are not
 *    text.
 *
 * Kinds are title, excerpt, seo_title, seo_description, text (no markup)
 * and html (inline markup a translation should keep). The text is the
 * stored markup, entities included.
 *
 * The hash covers everything the segments are read from (the post's title,
 * excerpt and content, its Elementor data and its SEO fields), so it moves
 * whenever any of them changes, and only then.
 */
final class I18n_Page_Text
{
    /** Elementor control types that hold text for a reader. */
    private const ELEMENTOR_TEXT_CONTROLS = [ 'text' => 'text', 'textarea' => 'text', 'wysiwyg' => 'html' ];

    /** Setting names that hold text when Elementor's own control registry cannot be asked. */
    private const ELEMENTOR_TEXT_KEYS = [
        'title' => 'text', 'text' => 'text', 'title_text' => 'text', 'button_text' => 'text', 'link_text' => 'text',
        'heading' => 'text', 'sub_heading' => 'text', 'caption' => 'text', 'prefix' => 'text', 'suffix' => 'text',
        'inner_text' => 'text', 'label' => 'text', 'tab_title' => 'text', 'alert_title' => 'text',
        'testimonial_name' => 'text', 'testimonial_job' => 'text', 'description_text' => 'html', 'editor' => 'html',
        'description' => 'html', 'tab_content' => 'html', 'alert_description' => 'html', 'testimonial_content' => 'html',
    ];

    /** Setting names that are never text, whatever their control type. */
    private const ELEMENTOR_NOT_TEXT = '/(^_|css|class|anchor|(^|_)id$|url|link$|^tag$|attributes|html_tag|icon|align|size|color|font|animation)/';

    /** Atomic widget prop types that carry rich text. */
    private const ATOMIC_TEXT_TYPES = [ 'string' => 'text', 'escaped-html' => 'html', 'html' => 'html', 'html-v2' => 'html' ];

    /** Inline elements: they stay inside a run of text. */
    private const INLINE = [
        'a', 'abbr', 'b', 'bdi', 'bdo', 'br', 'cite', 'code', 'data', 'del', 'dfn', 'em', 'i', 'img', 'ins', 'kbd',
        'mark', 'q', 's', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var', 'wbr',
    ];

    /** Elements whose content is never text for a reader. */
    private const OPAQUE = [ 'script', 'style', 'svg', 'template', 'textarea', 'noscript', 'iframe', 'object' ];

    /**
     * Which builder the segments come from: 'elementor', 'blocks' or 'classic'.
     */
    public static function builder(\WP_Post $post): string
    {
        if ('builder' === get_post_meta((int) $post->ID, '_elementor_edit_mode', true) && [] !== Elementor_Page_Data::get((int) $post->ID)) {
            return 'elementor';
        }
        return has_blocks((string) $post->post_content) ? 'blocks' : 'classic';
    }

    /**
     * Every segment of the post, in reading order.
     *
     * @return array<int, array{key: string, kind: string, text: string, where: string}>
     */
    public static function segments(\WP_Post $post): array
    {
        $out = [ self::segment('title', 'title', (string) $post->post_title, 'post title') ];
        if ('' !== trim((string) $post->post_excerpt)) {
            $out[] = self::segment('excerpt', 'excerpt', (string) $post->post_excerpt, 'post excerpt');
        }
        foreach (self::seo_fields((int) $post->ID) as $field => $text) {
            if ('' !== trim($text)) {
                $out[] = self::segment('seo.' . $field, 'seo_' . $field, $text, 'SEO ' . $field);
            }
        }

        if ('elementor' === self::builder($post)) {
            return array_merge($out, self::elementor_segments(Elementor_Page_Data::get((int) $post->ID)));
        }

        foreach (self::content_runs((string) $post->post_content) as $n => $run) {
            $out[] = self::segment('content:' . $n, $run['html'] ? 'html' : 'text', $run['text'], $run['where']);
        }
        return $out;
    }

    /** The content hash of a post: it changes whenever anything a segment is read from does. */
    public static function hash(\WP_Post $post): string
    {
        $post_id = (int) $post->ID;
        $parts   = [
            (string) $post->post_title,
            (string) $post->post_excerpt,
            (string) $post->post_content,
            (string) get_post_meta($post_id, '_elementor_edit_mode', true),
            (string) get_post_meta($post_id, '_elementor_data', true),
            self::seo_fields($post_id),
        ];
        return substr(hash('sha256', (string) wp_json_encode($parts)), 0, 32);
    }

    /**
     * Write translated segments back to the post: the post's columns in one
     * update, the Elementor tree in one save, the SEO fields in one write.
     * $texts is key => text and every key must be one of segments(); the
     * caller checks that first. Text a user without unfiltered_html writes
     * into builder data or SEO fields is filtered the way post content is.
     *
     * @param array<string, string> $texts
     * @return int How many segments were written.
     */
    public static function apply(\WP_Post $post, array $texts): int
    {
        $post_id = (int) $post->ID;
        $columns = [];
        if (array_key_exists('title', $texts)) {
            $columns['post_title'] = $texts['title'];
        }
        if (array_key_exists('excerpt', $texts)) {
            $columns['post_excerpt'] = $texts['excerpt'];
        }

        $content = [];
        $tree    = [];
        $seo     = [];
        foreach ($texts as $key => $text) {
            if (0 === strpos($key, 'content:')) {
                $content[ (int) substr($key, 8) ] = $text;
            } elseif (0 === strpos($key, 'el:')) {
                $tree[ $key ] = self::filtered($text);
            } elseif (0 === strpos($key, 'seo.')) {
                $seo[ substr($key, 4) ] = self::filtered($text);
            }
        }

        if ([] !== $content) {
            $columns['post_content'] = self::replace_runs((string) $post->post_content, $content);
        }
        if ([] !== $columns) {
            $updated = wp_update_post(wp_slash([ 'ID' => $post_id ] + $columns), true);
            if (is_wp_error($updated)) {
                throw new Operation_Refused('write_failed', esc_html($updated->get_error_message()));
            }
        }
        if ([] !== $tree) {
            $elements = Elementor_Page_Data::get($post_id);
            self::write_elementor($elements, $tree);
            Elementor_Page_Data::save($post_id, $elements);
        }
        if ([] !== $seo) {
            SEO_Adapter::update_meta($post_id, $seo);
        }

        return count($texts);
    }

    /** @return array{key: string, kind: string, text: string, where: string} */
    private static function segment(string $key, string $kind, string $text, string $where): array
    {
        return [ 'key' => $key, 'kind' => $kind, 'text' => $text, 'where' => $where ];
    }

    /** Text from the caller, filtered as post content is for a user without unfiltered_html. */
    private static function filtered(string $text): string
    {
        return current_user_can('unfiltered_html') ? $text : wp_kses_post($text);
    }

    /**
     * The SEO title and description, when the active SEO plugin keeps them
     * in post meta.
     *
     * @return array<string, string>
     */
    private static function seo_fields(int $post_id): array
    {
        if ('' === SEO_Adapter::active_plugin() || null !== SEO_Adapter::table_store()) {
            return [];
        }
        $meta = SEO_Adapter::get_meta($post_id);
        return [ 'title' => (string) ($meta['title'] ?? ''), 'description' => (string) ($meta['description'] ?? '') ];
    }

    // ---------------------------------------------------------------
    // Elementor
    // ---------------------------------------------------------------

    /** @return array<int, array{key: string, kind: string, text: string, where: string}> */
    private static function elementor_segments(array $elements): array
    {
        $out = [];
        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }
            if ('widget' === ($element['elType'] ?? '') && is_array($element['settings'] ?? null)) {
                foreach (self::widget_texts($element) as $key => [ $kind, $text, $where ]) {
                    $out[] = self::segment($key, $kind, $text, $where);
                }
            }
            if (is_array($element['elements'] ?? null)) {
                $out = array_merge($out, self::elementor_segments($element['elements']));
            }
        }
        return $out;
    }

    /**
     * A widget's text settings: key => [kind, text, where].
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    private static function widget_texts(array $element): array
    {
        $id       = (string) ($element['id'] ?? '');
        $type     = (string) ($element['widgetType'] ?? '');
        $settings = (array) $element['settings'];
        $dynamic  = (array) ($settings['__dynamic__'] ?? []);
        $controls = self::controls($type);
        $out      = [];

        foreach ($settings as $name => $value) {
            $name = (string) $name;
            if (isset($dynamic[ $name ])) {
                continue;
            }
            $where = trim('elementor ' . $type . ' ' . $name);
            if (is_array($value) && isset($value['$$type'])) {
                $atomic = 1 === preg_match(self::ELEMENTOR_NOT_TEXT, $name) ? null : self::atomic_text($value);
                if (null !== $atomic) {
                    $out[ 'el:' . $id . ':' . $name ] = [ $atomic[0], $atomic[1], $where ];
                }
                continue;
            }
            if (is_array($value) && array_is_list($value)) {
                $fields = self::repeater_fields($controls[ $name ] ?? null);
                foreach ($value as $index => $item) {
                    foreach (is_array($item) ? $item : [] as $field => $text) {
                        $kind = self::text_kind((string) $field, $text, $fields);
                        if (null !== $kind) {
                            $out[ 'el:' . $id . ':' . $name . ':' . $index . ':' . $field ] = [ $kind, $text, $where . ' ' . $field ];
                        }
                    }
                }
                continue;
            }
            $kind = self::text_kind($name, $value, $controls);
            if (null !== $kind) {
                $out[ 'el:' . $id . ':' . $name ] = [ $kind, $value, $where ];
            }
        }
        return $out;
    }

    /**
     * The kind of text a setting holds, or null when it holds none.
     * $controls, from Elementor's registry, decides when it knows the
     * setting; otherwise the known text setting names do.
     *
     * @param mixed                               $value
     * @param array<string, array<string, mixed>>|null $controls
     */
    private static function text_kind(string $name, $value, ?array $controls): ?string
    {
        if (! is_string($value) || '' === trim($value) || 1 === preg_match(self::ELEMENTOR_NOT_TEXT, $name)) {
            return null;
        }
        if (null !== $controls && isset($controls[ $name ])) {
            return self::ELEMENTOR_TEXT_CONTROLS[ (string) ($controls[ $name ]['type'] ?? '') ] ?? null;
        }
        return self::ELEMENTOR_TEXT_KEYS[ $name ] ?? null;
    }

    /**
     * [kind, text] of an atomic widget prop that carries text, or null.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function atomic_text(array $prop): ?array
    {
        $type  = (string) $prop['$$type'];
        $value = $prop['value'] ?? null;
        if ('html-v3' === $type && is_array($value) && is_string($value['content']['value'] ?? null)) {
            $value = $value['content']['value'];
            $type  = 'html';
        }
        if (! isset(self::ATOMIC_TEXT_TYPES[ $type ]) || ! is_string($value) || '' === trim($value)) {
            return null;
        }
        return [ self::ATOMIC_TEXT_TYPES[ $type ], $value ];
    }

    /**
     * A widget type's controls from Elementor's registry, or null when
     * Elementor cannot be asked.
     *
     * @return array<string, array<string, mixed>>|null
     */
    private static function controls(string $type): ?array
    {
        static $cache = [];
        if (array_key_exists($type, $cache)) {
            return $cache[ $type ];
        }
        $controls = null;
        if ('' !== $type && class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::instance()->widgets_manager)) {
            try {
                $widget   = \Elementor\Plugin::instance()->widgets_manager->get_widget_types($type);
                $controls = is_object($widget) && method_exists($widget, 'get_controls') ? (array) $widget->get_controls() : null;
            } catch (\Throwable $e) {
                $controls = null;
            }
        }
        $cache[ $type ] = $controls;
        return $controls;
    }

    /**
     * The fields of a repeater control, keyed by name, or null when the
     * control is unknown.
     *
     * @param mixed $control
     * @return array<string, array<string, mixed>>|null
     */
    private static function repeater_fields($control): ?array
    {
        if (! is_array($control) || ! is_array($control['fields'] ?? null)) {
            return null;
        }
        $fields = [];
        foreach ($control['fields'] as $key => $field) {
            $name            = (string) (is_array($field) && isset($field['name']) ? $field['name'] : $key);
            $fields[ $name ] = (array) $field;
        }
        return $fields;
    }

    /**
     * Put the translated texts into the tree, by element id.
     *
     * @param array<string, string> $texts 'el:...' key => text
     */
    private static function write_elementor(array &$elements, array $texts): void
    {
        foreach ($texts as $key => $text) {
            $parts   = explode(':', $key);
            $element = &Elementor_Page_Data::find($elements, (string) ($parts[1] ?? ''));
            if (null === $element) {
                unset($element);
                continue;
            }
            $name = (string) ($parts[2] ?? '');
            if (4 < count($parts)) {
                $element['settings'][ $name ][ (int) $parts[3] ][ (string) $parts[4] ] = $text;
            } elseif (is_array($element['settings'][ $name ] ?? null) && isset($element['settings'][ $name ]['$$type'])) {
                if ('html-v3' === $element['settings'][ $name ]['$$type']) {
                    $element['settings'][ $name ]['value']['content']['value'] = $text;
                } else {
                    $element['settings'][ $name ]['value'] = $text;
                }
            } else {
                $element['settings'][ $name ] = $text;
            }
            unset($element);
        }
    }

    // ---------------------------------------------------------------
    // Block and classic content
    // ---------------------------------------------------------------

    /**
     * The runs of text in post content: byte offset, length, the run's
     * markup, whether it carries inline tags, and the block it sits in.
     *
     * @return array<int, array{start: int, length: int, text: string, html: bool, where: string}>
     */
    public static function content_runs(string $content): array
    {
        $runs     = [];
        $blocks   = [];
        $classic  = ! has_blocks($content);
        $start    = null;
        $end      = 0;
        $pattern  = '#<!--.*?-->|<(' . implode('|', self::OPAQUE) . ')\b[^>]*>.*?</\1\s*>|</?([a-zA-Z][a-zA-Z0-9-]*)\b[^>]*>#si';
        $offset   = 0;
        $close    = static function () use (&$start, &$end, &$runs, &$blocks, $content, $classic): void {
            if (null !== $start) {
                $run = self::narrow($content, $start, $end);
                if (null !== $run) {
                    $run['where'] = $classic ? 'classic' : (string) (end($blocks) ?: 'freeform');
                    $runs[]       = $run;
                }
            }
            $start = null;
        };

        preg_match_all($pattern, $content, $tokens, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($tokens as $token) {
            [ $markup, $at ] = $token[0];
            if ($at > $offset) {
                $start = $start ?? $offset;
                $end   = $at;
            }
            $offset = $at + strlen($markup);
            $tag    = strtolower((string) ($token[2][0] ?? ''));

            if ('' !== $tag && '' === (string) ($token[1][0] ?? '') && in_array($tag, self::INLINE, true)) {
                $start = $start ?? $at;
                $end   = $offset;
                continue;
            }
            $close();
            if (1 === preg_match('#^<!--\s+(/?)wp:([a-z0-9/_-]+)(.*?)-->$#s', $markup, $m)) {
                $name = false === strpos($m[2], '/') ? 'core/' . $m[2] : $m[2];
                if ('/' === $m[1]) {
                    array_pop($blocks);
                } elseif ('/' !== substr(rtrim($m[3]), -1)) {
                    $blocks[] = $name;
                }
            }
        }
        if ($offset < strlen($content)) {
            $start = $start ?? $offset;
            $end   = strlen($content);
        }
        $close();

        return $runs;
    }

    /**
     * Trim a run to its text: no surrounding whitespace, and no element that
     * wraps the whole run (a button's link keeps its href out of the text).
     * Null when no text is left.
     *
     * @return array{start: int, length: int, text: string, html: bool}|null
     */
    private static function narrow(string $content, int $start, int $end): ?array
    {
        while (true) {
            while ($start < $end && ctype_space($content[ $start ])) {
                ++$start;
            }
            while ($end > $start && ctype_space($content[ $end - 1 ])) {
                --$end;
            }
            $text = substr($content, $start, $end - $start);
            if (1 !== preg_match('#^<([a-zA-Z][a-zA-Z0-9-]*)\b[^>]*>#', $text, $open) || ! self::wraps($text, strtolower($open[1]))) {
                break;
            }
            $start += strlen($open[0]);
            $end   -= strlen('</' . $open[1] . '>');
        }
        $text = substr($content, $start, max(0, $end - $start));
        if ('' === trim(wp_strip_all_tags($text))) {
            return null;
        }
        return [ 'start' => $start, 'length' => $end - $start, 'text' => $text, 'html' => false !== strpos($text, '<') ];
    }

    /** Whether the element opening $text closes exactly at its end. */
    private static function wraps(string $text, string $tag): bool
    {
        $closing = '</' . $tag . '>';
        if (strtolower(substr($text, -strlen($closing))) !== $closing) {
            return false;
        }
        preg_match_all('#<(/?)' . preg_quote($tag, '#') . '\b[^>]*>#i', $text, $all, PREG_OFFSET_CAPTURE);
        $depth = 0;
        foreach ($all[0] as $i => [ $markup, $at ]) {
            $depth += '/' === $all[1][ $i ][0] ? -1 : 1;
            if (0 === $depth) {
                return $at + strlen($markup) === strlen($text);
            }
        }
        return false;
    }

    /**
     * Content with the runs numbered in $texts replaced, last first, so the
     * earlier offsets still hold.
     *
     * @param array<int, string> $texts run number => text
     */
    private static function replace_runs(string $content, array $texts): string
    {
        $runs = self::content_runs($content);
        krsort($texts);
        foreach ($texts as $n => $text) {
            if (isset($runs[ $n ])) {
                $content = substr_replace($content, $text, $runs[ $n ]['start'], $runs[ $n ]['length']);
            }
        }
        return $content;
    }
}
