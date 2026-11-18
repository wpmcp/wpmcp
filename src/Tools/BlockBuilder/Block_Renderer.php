<?php

namespace WPMCP\Tools\BlockBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Renders a custom block spec by interpolating attribute values into its
 * template. Pure and eval-free: each {{name}} placeholder is replaced with the
 * matching attribute, escaped by that attribute's type (string/color/select →
 * esc_html, richtext → wp_kses_post, url/image → esc_url, number → numeric,
 * boolean → 'yes'/'', media → <img>, link → <a>). {{name.key}} reads one key
 * of a media or link value. {{#name}}...{{/name}} renders its body once per
 * repeater item (sub-fields in scope, escaped by their own types) or once
 * when a boolean is true. Unknown placeholders render empty. The template is
 * scanned in one pass, so a value is never read back as a placeholder. This
 * is the single output path the runtime render_callback uses, so a stored
 * spec never executes code.
 */
class Block_Renderer
{
    private const PATTERN = '/\{\{#\s*([a-z0-9_\-]+)\s*\}\}(.*?)\{\{\/\s*\1\s*\}\}|\{\{\s*([a-z0-9_\-]+)(?:\.([a-z]+))?\s*\}\}/is';

    public static function render(array $spec, array $attributes): string
    {
        $attrs = is_array($spec['attributes'] ?? null) ? $spec['attributes'] : [];
        return self::render_scope((string) ($spec['template'] ?? ''), self::scope($attrs, $attributes));
    }

    /**
     * Name → [definition, raw value] for one level of attributes.
     *
     * @return array<string,array{0:array,1:mixed}>
     */
    private static function scope(array $defs, array $values): array
    {
        $scope = [];
        foreach ($defs as $attr) {
            if (! is_array($attr)) {
                continue;
            }
            $name = sanitize_key((string) ($attr['name'] ?? ''));
            if ('' === $name) {
                continue;
            }
            $scope[$name] = [$attr, $values[$name] ?? ($attr['default'] ?? '')];
        }
        return $scope;
    }

    /** @param array<string,array{0:array,1:mixed}> $scope */
    private static function render_scope(string $template, array $scope): string
    {
        return (string) preg_replace_callback(
            self::PATTERN,
            static function (array $m) use ($scope): string {
                if ('' !== $m[1]) {
                    return self::section(sanitize_key($m[1]), $m[2], $scope);
                }
                $name = sanitize_key($m[3]);
                if (! isset($scope[$name])) {
                    return '';
                }
                [$attr, $raw] = $scope[$name];
                return self::escape((string) ($attr['type'] ?? 'string'), $raw, $attr, strtolower((string) ($m[4] ?? '')));
            },
            $template
        );
    }

    /** @param array<string,array{0:array,1:mixed}> $scope */
    private static function section(string $name, string $body, array $scope): string
    {
        if (! isset($scope[$name])) {
            return '';
        }
        [$attr, $raw] = $scope[$name];
        $type         = (string) ($attr['type'] ?? 'string');

        if ('repeater' === $type) {
            $items = is_array($raw) ? array_values(array_filter($raw, 'is_array')) : [];
            if (isset($attr['max']) && is_numeric($attr['max'])) {
                $items = array_slice($items, 0, max(0, (int) $attr['max']));
            }
            $fields = is_array($attr['fields'] ?? null) ? $attr['fields'] : [];
            $out    = '';
            foreach ($items as $item) {
                // Item fields shadow outer attributes; no nested repeaters,
                // but a boolean sub-field may still open a section.
                $out .= self::render_scope($body, array_merge($scope, self::scope($fields, $item)));
            }
            return $out;
        }

        // Any other type: a conditional section, rendered once when truthy.
        return self::truthy($type, $raw, $attr) ? self::render_scope($body, $scope) : '';
    }

    /** @param mixed $raw */
    private static function truthy(string $type, $raw, array $attr): bool
    {
        if ('media' === $type || 'link' === $type) {
            return is_array($raw) && '' !== trim((string) ($raw['url'] ?? ''));
        }
        if ('select' === $type) {
            return '' !== self::select_value($raw, $attr);
        }
        return is_array($raw) ? [] !== $raw : (bool) $raw;
    }

    /** @param mixed $raw */
    private static function select_value($raw, array $attr): string
    {
        $values = array_column(Block_Spec::options($attr), 'value');
        $value  = is_scalar($raw) ? (string) $raw : '';
        if (in_array($value, $values, true)) {
            return $value;
        }
        $default = isset($attr['default']) && is_scalar($attr['default']) ? (string) $attr['default'] : '';
        return in_array($default, $values, true) ? $default : '';
    }

    /** @param mixed $value */
    private static function escape(string $type, $value, array $attr = [], string $key = ''): string
    {
        switch ($type) {
            case 'richtext':
                return is_scalar($value) ? wp_kses_post((string) $value) : '';
            case 'url':
            case 'image':
                return is_scalar($value) ? esc_url((string) $value) : '';
            case 'number':
                return (string) (0 + (is_numeric($value) ? $value : 0));
            case 'boolean':
                return $value ? 'yes' : '';
            case 'select':
                return esc_html(self::select_value($value, $attr));
            case 'media':
                return self::media(is_array($value) ? $value : [], $key);
            case 'link':
                return self::link(is_array($value) ? $value : [], $key);
            case 'repeater':
                // A list has no scalar form; it renders through a section.
                return '';
            default:
                return is_scalar($value) ? esc_html((string) $value) : '';
        }
    }

    private static function media(array $media, string $key): string
    {
        $url = esc_url(is_scalar($media['url'] ?? null) ? (string) $media['url'] : '');
        $alt = esc_attr(is_scalar($media['alt'] ?? null) ? (string) $media['alt'] : '');
        switch ($key) {
            case 'url':
                return $url;
            case 'alt':
                return $alt;
            case 'id':
                return (string) absint(is_scalar($media['id'] ?? null) ? (int) $media['id'] : 0);
            case '':
                return '' === $url ? '' : sprintf('<img src="%s" alt="%s" />', $url, $alt);
            default:
                return '';
        }
    }

    private static function link(array $link, string $key): string
    {
        $url    = esc_url(is_scalar($link['url'] ?? null) ? (string) $link['url'] : '');
        $text   = esc_html(is_scalar($link['text'] ?? null) ? (string) $link['text'] : '');
        $target = '_blank' === ($link['target'] ?? '') ? '_blank' : '';
        switch ($key) {
            case 'url':
                return $url;
            case 'text':
                return $text;
            case 'target':
                return $target;
            case '':
                if ('' === $url) {
                    return '';
                }
                $extra = '' === $target ? '' : ' target="_blank" rel="noopener noreferrer"';
                return sprintf('<a href="%s"%s>%s</a>', $url, $extra, '' === $text ? $url : $text);
            default:
                return '';
        }
    }
}
