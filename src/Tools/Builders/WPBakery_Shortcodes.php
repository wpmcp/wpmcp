<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Parser and editor for a WPBakery layout: the vc_row / vc_column /
 * vc_column_text ... shortcodes it stores in post_content. Pure string work,
 * so it runs without the WPBakery plugin loaded.
 *
 * The tree is addressed by dotted paths of child indexes ("0.1.0" is the
 * first child of the second child of the first top-level element); WPBakery
 * keeps no per-element ids in its markup. Each node is one of:
 *  - container: an enclosing shortcode whose body is only shortcodes, listed
 *    as `children`;
 *  - leaf: an enclosing shortcode with any other body, exposed as `text`
 *    (raw inner content, nested non-layout shortcodes included);
 *  - void: a shortcode with no closing tag (vc_single_image, vc_btn ...).
 *
 * Edits splice the original string by offsets, so every byte outside the
 * touched element is kept exactly, and an element stored self-closed
 * (`[tag ... /]`) keeps that form when its attributes change. Attribute
 * values are returned as stored; on write, the three characters that would
 * break the shortcode (double quote and square brackets) are encoded the way
 * the WPBakery editor encodes them (``, `{`, `}`).
 *
 * Other builders that store the same kind of nested shortcode layout reuse
 * this parser through a subclass that overrides the three dialect constants
 * below.
 */
class WPBakery_Shortcodes
{
    /** Tags always written with a closing tag, even when empty. */
    protected const CONTAINER_TAG = '/^vc_(?:section|row|row_inner|column|column_inner|tta_[a-z_]+|tabs|tab|tour|accordion|accordion_tab)$/';

    /** How a written attribute value encodes `"`, `[` and `]`. */
    protected const ATTR_ENCODING = ['"' => '``', '[' => '`{`', ']' => '`}`'];

    /** Whether a new element with no body is written self-closed (`[tag /]`). */
    protected const SELF_CLOSE_EMPTY = false;

    private const TAG_TOKEN = '/\[(\/?)([A-Za-z][\w-]*)((?:\s[^\]]*?)?)(\/?)\]/';

    /** Public tree: nodes with path, tag, attrs and children or text. */
    public static function tree(string $content): array
    {
        return self::export(self::parse($content), '', $content);
    }

    /**
     * Every `css` attribute value in document order that carries a
     * `.vc_custom_<n>{...}` rule, which is what WPBakery compiles into the
     * _wpb_shortcodes_custom_css postmeta on save.
     *
     * @return string[]
     */
    public static function custom_css_rules(string $content): array
    {
        $rules = [];
        self::walk(self::parse($content), static function (array $node) use (&$rules): void {
            $css = $node['attrs']['css'] ?? null;
            if (is_string($css) && preg_match('/^\.vc_custom_\d+\s*\{/', $css)) {
                $rules[] = $css;
            }
        });

        return $rules;
    }

    /**
     * Merge attributes into one element (a null value removes the key) and/or
     * replace its text body.
     */
    public static function update(string $content, string $path, ?array $attrs, ?string $text): string
    {
        if (null === $attrs && null === $text) {
            throw new \InvalidArgumentException(esc_html('Provide attrs and/or text to update.'));
        }

        $node = self::resolve($content, $path, false);

        $open = substr($content, $node['start'], $node['open_end'] - $node['start']);
        if (null !== $attrs) {
            $merged = $node['attrs'];
            foreach ($attrs as $key => $value) {
                self::assert_attr_key($key);
                if (null === $value) {
                    unset($merged[strtolower((string) $key)]);
                    continue;
                }
                $merged[strtolower((string) $key)] = self::attr_value($value);
            }
            $open = self::open_tag($node['tag'], $merged, (bool) preg_match('#/\]$#', $open));
        }

        if (null === $text) {
            $body = substr($content, $node['open_end'], $node['end'] - $node['open_end']);
        } else {
            if ('void' === $node['kind']) {
                throw new \InvalidArgumentException(esc_html("Element {$path} ({$node['tag']}) has no closing tag, so it has no text body."));
            }
            if ([] !== $node['children']) {
                throw new \InvalidArgumentException(esc_html("Element {$path} has child elements; edit or remove those instead of setting text."));
            }
            self::assert_text($node['tag'], $text);
            $body = $text . substr($content, $node['close_start'], $node['end'] - $node['close_start']);
        }

        return substr($content, 0, $node['start']) . $open . $body . substr($content, $node['end']);
    }

    /**
     * Insert a new element under a container ('' is the top level) at a
     * child index (null or past the end appends). Returns the new content
     * and the new element's path.
     *
     * @return array{0:string,1:string}
     */
    public static function add(string $content, string $parent_path, ?int $index, array $element): array
    {
        $parent = self::resolve($content, $parent_path, true);
        $markup = self::serialize($element);

        $children = $parent['children'];
        $position = self::position($index, count($children));
        $offset   = $position < count($children) ? $children[$position]['start'] : $parent['close_start'];

        $path = ('' === $parent_path ? '' : $parent_path . '.') . $position;

        return [substr($content, 0, $offset) . $markup . substr($content, $offset), $path];
    }

    public static function remove(string $content, string $path): string
    {
        $node = self::resolve($content, $path, false);

        return substr($content, 0, $node['start']) . substr($content, $node['end']);
    }

    /**
     * Move an element under a container ('' is the top level). The index is
     * the element's final position among that container's children.
     */
    public static function move(string $content, string $path, string $to, ?int $index): string
    {
        $node = self::resolve($content, $path, false);
        if ($to === $path || 0 === strpos($to . '.', $path . '.')) {
            throw new \InvalidArgumentException(esc_html('An element cannot be moved into itself.'));
        }
        $parent = self::resolve($content, $to, true);

        $siblings = array_values(array_filter(
            $parent['children'],
            static fn (array $child): bool => $child['start'] !== $node['start']
        ));
        $position = self::position($index, count($siblings));
        $offset   = $position < count($siblings) ? $siblings[$position]['start'] : $parent['close_start'];

        $slice = substr($content, $node['start'], $node['end'] - $node['start']);

        if ($offset <= $node['start']) {
            return substr($content, 0, $offset) . $slice
                . substr($content, $offset, $node['start'] - $offset)
                . substr($content, $node['end']);
        }

        return substr($content, 0, $node['start'])
            . substr($content, $node['end'], $offset - $node['end'])
            . $slice . substr($content, $offset);
    }

    /**
     * Serialize an element spec: tag, optional attrs, and either text or
     * children (a list of specs).
     */
    public static function serialize(array $element): string
    {
        $tag = $element['tag'] ?? null;
        if (! is_string($tag) || ! preg_match('/^[A-Za-z][\w-]*$/', $tag)) {
            throw new \InvalidArgumentException(esc_html('Each element needs a valid shortcode tag.'));
        }

        $attrs = $element['attrs'] ?? [];
        if (! is_array($attrs)) {
            throw new \InvalidArgumentException(esc_html("attrs of {$tag} must be an object."));
        }
        $clean = [];
        foreach ($attrs as $key => $value) {
            self::assert_attr_key($key);
            if (null !== $value) {
                $clean[strtolower((string) $key)] = self::attr_value($value);
            }
        }

        $has_text     = array_key_exists('text', $element) && null !== $element['text'];
        $has_children = array_key_exists('children', $element) && null !== $element['children'];
        if ($has_text && $has_children) {
            throw new \InvalidArgumentException(esc_html("{$tag} cannot have both text and children."));
        }

        $body = '';
        if ($has_text) {
            if (! is_string($element['text'])) {
                throw new \InvalidArgumentException(esc_html("text of {$tag} must be a string."));
            }
            self::assert_text($tag, $element['text']);
            $body = $element['text'];
        } elseif ($has_children) {
            if (! is_array($element['children'])) {
                throw new \InvalidArgumentException(esc_html("children of {$tag} must be a list."));
            }
            foreach ($element['children'] as $child) {
                if (! is_array($child)) {
                    throw new \InvalidArgumentException(esc_html("children of {$tag} must be element objects."));
                }
                $body .= self::serialize($child);
            }
        }

        if ($has_text || $has_children || preg_match(static::CONTAINER_TAG, $tag)) {
            return self::open_tag($tag, $clean) . $body . '[/' . $tag . ']';
        }

        return self::open_tag($tag, $clean, static::SELF_CLOSE_EMPTY);
    }

    /**
     * Parse into internal nodes carrying byte offsets.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function parse(string $content): array
    {
        $tokens = self::tokenize($content);

        // Pass 1: pair each opening tag with its closing tag. An opener left
        // unmatched when an outer tag closes is a void (self-closing) tag.
        $pair  = [];
        $stack = [];
        foreach ($tokens as $i => $token) {
            if ('open' === $token['type']) {
                $stack[] = $i;
                continue;
            }
            if ('close' !== $token['type']) {
                continue;
            }
            for ($s = count($stack) - 1; $s >= 0; $s--) {
                if ($tokens[ $stack[ $s ] ]['tag'] === $token['tag']) {
                    break;
                }
            }
            if ($s < 0) {
                continue;
            }
            $pair[ $stack[ $s ] ] = $i;
            $pair[ $i ]           = $stack[ $s ];
            $stack                = array_slice($stack, 0, $s);
        }

        // Pass 2: nest the paired tags.
        $root  = ['children' => []];
        $path  = [];
        $build = [&$root];
        foreach ($tokens as $i => $token) {
            $parent = &$build[ count($build) - 1 ];
            if ('close' === $token['type']) {
                if (isset($pair[ $i ]) && count($build) > 1) {
                    $parent['close_start'] = $token['start'];
                    $parent['end']         = $token['end'];
                    array_pop($build);
                }
                unset($parent);
                continue;
            }

            $node = [
                'tag'         => $token['tag'],
                'attrs'       => self::parse_attrs($token['attrs']),
                'start'       => $token['start'],
                'open_end'    => $token['end'],
                'end'         => $token['end'],
                'close_start' => null,
                'kind'        => 'void',
                'children'    => [],
            ];
            $parent['children'][] = $node;

            if ('open' === $token['type'] && isset($pair[ $i ])) {
                $build[] = &$parent['children'][ count($parent['children']) - 1 ];
            }
            unset($parent);
        }
        unset($build);

        return self::classify($root['children'], $content);
    }

    /** Decide container vs leaf for each enclosing node, recursively. */
    private static function classify(array $nodes, string $content): array
    {
        foreach ($nodes as &$node) {
            if (null === $node['close_start']) {
                continue;
            }
            $gap    = '';
            $cursor = $node['open_end'];
            foreach ($node['children'] as $child) {
                $gap   .= substr($content, $cursor, $child['start'] - $cursor);
                $cursor = $child['end'];
            }
            $gap .= substr($content, $cursor, $node['close_start'] - $cursor);

            if ('' === trim($gap)) {
                $node['kind']     = 'container';
                $node['children'] = self::classify($node['children'], $content);
            } else {
                $node['kind']     = 'leaf';
                $node['children'] = [];
            }
        }
        unset($node);

        return $nodes;
    }

    /** @return array<int,array<string,mixed>> */
    private static function tokenize(string $content): array
    {
        preg_match_all(self::TAG_TOKEN, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $tokens = [];
        foreach ($matches as $m) {
            $start = $m[0][1];
            $end   = $start + strlen($m[0][0]);
            // [[tag]] is WordPress's escape for a literal shortcode.
            if ($start > 0 && '[' === $content[ $start - 1 ] && ']' === ($content[ $end ] ?? '')) {
                continue;
            }
            $type = '/' === $m[1][0] ? 'close' : ('/' === $m[4][0] ? 'self' : 'open');
            $tokens[] = [
                'type'  => $type,
                'tag'   => $m[2][0],
                'attrs' => 'close' === $type ? '' : $m[3][0],
                'start' => $start,
                'end'   => $end,
            ];
        }

        return $tokens;
    }

    /**
     * Shortcode attribute parsing as WordPress does it, minus the
     * stripcslashes, so values round-trip byte for byte.
     */
    private static function parse_attrs(string $text): array
    {
        $attrs   = [];
        $pattern = '/([\w-]+)\s*=\s*"([^"]*)"(?:\s|$)|([\w-]+)\s*=\s*\'([^\']*)\'(?:\s|$)|([\w-]+)\s*=\s*([^\s\'"]+)(?:\s|$)|"([^"]*)"(?:\s|$)|\'([^\']*)\'(?:\s|$)|(\S+)(?:\s|$)/';
        if (! preg_match_all($pattern, trim($text), $matches, PREG_SET_ORDER)) {
            return $attrs;
        }
        foreach ($matches as $m) {
            if (isset($m[1]) && '' !== $m[1]) {
                $attrs[ strtolower($m[1]) ] = $m[2];
            } elseif (isset($m[3]) && '' !== $m[3]) {
                $attrs[ strtolower($m[3]) ] = $m[4];
            } elseif (isset($m[5]) && '' !== $m[5]) {
                $attrs[ strtolower($m[5]) ] = $m[6];
            } elseif (isset($m[7]) && '' !== $m[7]) {
                $attrs[] = $m[7];
            } elseif (isset($m[8]) && '' !== $m[8]) {
                $attrs[] = $m[8];
            } elseif (isset($m[9])) {
                $attrs[] = $m[9];
            }
        }

        return $attrs;
    }

    private static function open_tag(string $tag, array $attrs, bool $self_close = false): string
    {
        $out = '[' . $tag;
        foreach ($attrs as $key => $value) {
            $value = strtr((string) $value, static::ATTR_ENCODING);
            $out  .= is_int($key) ? ' "' . $value . '"' : ' ' . $key . '="' . $value . '"';
        }

        return $out . ($self_close ? ' /]' : ']');
    }

    /** @param mixed $value */
    private static function attr_value($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (! is_scalar($value)) {
            throw new \InvalidArgumentException(esc_html('Attribute values must be strings, numbers or booleans.'));
        }

        return (string) $value;
    }

    /** @param int|string $key */
    private static function assert_attr_key($key): void
    {
        if (! is_string($key) || ! preg_match('/^[A-Za-z_][\w-]*$/', $key)) {
            throw new \InvalidArgumentException(esc_html('Attribute names must be letters, digits, underscores or hyphens.'));
        }
    }

    private static function assert_text(string $tag, string $text): void
    {
        if (false !== stripos($text, '[/' . $tag . ']')) {
            throw new \InvalidArgumentException(esc_html("Text for {$tag} cannot contain its own closing tag."));
        }
    }

    private static function position(?int $index, int $count): int
    {
        if (null === $index || $index > $count) {
            return $count;
        }
        if ($index < 0) {
            throw new \InvalidArgumentException(esc_html('index must be 0 or greater.'));
        }

        return $index;
    }

    /**
     * Resolve a dotted path. '' is the top level, allowed only for a
     * container target, which must be a container (or the top level).
     */
    private static function resolve(string $content, string $path, bool $want_container): array
    {
        $node = [
            'tag'         => '',
            'kind'        => 'container',
            'children'    => self::parse($content),
            'close_start' => strlen($content),
        ];

        if ('' !== $path) {
            if (! preg_match('/^\d+(?:\.\d+)*$/', $path)) {
                throw new \InvalidArgumentException(esc_html("Invalid path {$path}; use dotted child indexes such as 0.1.0."));
            }
            foreach (explode('.', $path) as $step) {
                if (! isset($node['children'][ (int) $step ])) {
                    throw new \InvalidArgumentException(esc_html("No element at path {$path}."));
                }
                $node = $node['children'][ (int) $step ];
            }
        } elseif (! $want_container) {
            throw new \InvalidArgumentException(esc_html('A path to an element is required.'));
        }

        if ($want_container && 'container' !== $node['kind']) {
            throw new \InvalidArgumentException(esc_html("Element {$path} ({$node['tag']}) cannot hold child elements."));
        }

        return $node;
    }

    private static function export(array $nodes, string $prefix, string $content): array
    {
        $out = [];
        foreach ($nodes as $i => $node) {
            $path = '' === $prefix ? (string) $i : $prefix . '.' . $i;
            $item = ['path' => $path, 'tag' => $node['tag'], 'attrs' => $node['attrs']];
            if ('container' === $node['kind']) {
                $item['children'] = self::export($node['children'], $path, $content);
            } elseif ('leaf' === $node['kind']) {
                $item['text'] = substr($content, $node['open_end'], $node['close_start'] - $node['open_end']);
            }
            $out[] = $item;
        }

        return $out;
    }

    private static function walk(array $nodes, callable $visit): void
    {
        foreach ($nodes as $node) {
            $visit($node);
            self::walk($node['children'], $visit);
        }
    }
}
