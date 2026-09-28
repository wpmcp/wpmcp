<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Parser and editor for a Thrive Architect layout: the HTML its editor saves
 * in the tve_updated_post postmeta. Pure string work, so it runs without
 * Thrive loaded.
 *
 * Thrive keeps no JSON tree. Every element is an HTML element carrying the
 * thrv_wrapper class (a column is a tcb-flex-col), with its styles keyed by
 * a data-css id into the page's generated CSS. Those elements are the nodes
 * of the tree, addressed by dotted paths of child indexes ("1.0" is the
 * first element inside the second top-level one). Each node has a kind:
 *  - section: a page section (thrv-page-section);
 *  - container: an element holding child elements, or one with an inner
 *    slot new children go into (a section's tve-page-section-in, a content
 *    box's tve-cb, a columns row, a column's tcb-col), listed as `children`;
 *  - element: anything else, whose inner HTML is exposed as `text`.
 * Markup between nodes (a section's background layer, hidden config
 * blocks, comments) belongs to no node and is never rewritten.
 *
 * The HTML is scanned, not loaded into a DOM, so nothing is normalized:
 * every edit splices the original string at byte offsets, and every byte
 * outside the touched element (unknown element classes, attribute quoting,
 * whitespace, entities) is kept exactly. An attribute change rewrites only
 * that attribute inside the opening tag. Attribute values are returned as
 * stored (entities kept) and written inside double quotes, with a double
 * quote written as &quot;.
 *
 * Inner HTML supplied for a write must be well-formed: every element it
 * opens is closed, so the edit cannot change which element later markup
 * belongs to.
 */
class Thrive_Html
{
    /** Classes that make an element a node of the tree. */
    private const NODE_CLASSES = ['thrv_wrapper', 'tcb-flex-col'];

    /** Inner wrappers that take new children, when a container has none. */
    private const SLOT_CLASSES = ['tve-page-section-in', 'tve-cb', 'tcb-flex-row', 'tcb-col'];

    /** Friendly element type by identifying class, first match wins. */
    private const TYPES = [
        'thrv-page-section'         => 'section',
        'thrv-content-box'          => 'content-box',
        'thrv_contentbox_shortcode' => 'content-box',
        'thrv-columns'              => 'columns',
        'tcb-flex-col'              => 'column',
        'thrv_text_element'         => 'text',
        'thrv_heading'              => 'heading',
        'tve_image_caption'         => 'image',
        'thrv-button'               => 'button',
        'thrv_icon'                 => 'icon',
        'thrv-divider'              => 'divider',
        'thrv_symbol'               => 'symbol',
    ];

    private const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'keygen', 'link', 'meta', 'param', 'source', 'track', 'wbr'];

    /** Elements whose content is text up to their closing tag. */
    private const RAW_TEXT = ['script', 'style', 'textarea', 'title'];

    private const TAG = '/\G<(\/?)([A-Za-z][A-Za-z0-9:-]*+)((?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+)>/';

    private const ATTR = '/([^\s"\'>\/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/';

    /** Public tree: nodes with path, type, kind, tag, attrs and children or text. */
    public static function tree(string $html): array
    {
        return self::export(self::parse($html), '', $html);
    }

    /**
     * Merge attributes into one element's opening tag (a null value removes
     * the attribute) and/or replace its inner HTML.
     */
    public static function update(string $html, string $path, ?array $attrs, ?string $text): string
    {
        if (null === $attrs && null === $text) {
            throw new \InvalidArgumentException(esc_html('Provide attrs and/or text to update.'));
        }

        $node = self::resolve($html, $path, false);
        $open = substr($html, $node['start'], $node['open_end'] - $node['start']);
        if (null !== $attrs) {
            $open = self::edit_open_tag($open, $node, $attrs);
        }

        if (null === $text) {
            $body = substr($html, $node['open_end'], $node['end'] - $node['open_end']);
        } else {
            if ($node['void']) {
                throw new \InvalidArgumentException(esc_html("Element {$path} ({$node['tag']}) has no closing tag, so it has no inner HTML."));
            }
            if ('element' !== self::kind($node)) {
                throw new \InvalidArgumentException(esc_html("Element {$path} holds child elements; edit, add or remove those instead of setting its text."));
            }
            self::assert_balanced($text);
            $body = $text . substr($html, $node['close_start'], $node['end'] - $node['close_start']);
        }

        return substr($html, 0, $node['start']) . $open . $body . substr($html, $node['end']);
    }

    /**
     * Insert a new element under a container ('' is the top level) at a
     * child index (null or past the end appends). The element is a spec
     * ({tag?, attrs, text | children}) or {html} holding one element's
     * markup. Returns the new HTML and the new element's path.
     *
     * @return array{0:string,1:string}
     */
    public static function add(string $html, string $parent_path, ?int $index, array $element): array
    {
        $parent   = self::resolve($html, $parent_path, true);
        $markup   = self::element_markup($element);
        $position = self::position($index, count($parent['children']));
        $offset   = self::insert_offset($parent, $parent['children'], $position);

        $path = ('' === $parent_path ? '' : $parent_path . '.') . $position;

        return [substr($html, 0, $offset) . $markup . substr($html, $offset), $path];
    }

    public static function remove(string $html, string $path): string
    {
        $node = self::resolve($html, $path, false);

        return substr($html, 0, $node['start']) . substr($html, $node['end']);
    }

    /**
     * Move an element under a container ('' is the top level). The index is
     * the element's final position among that container's children. The
     * element's bytes move unchanged.
     */
    public static function move(string $html, string $path, string $to, ?int $index): string
    {
        $node = self::resolve($html, $path, false);
        if ($to === $path || 0 === strpos($to . '.', $path . '.')) {
            throw new \InvalidArgumentException(esc_html('An element cannot be moved into itself.'));
        }
        $parent = self::resolve($html, $to, true);

        $siblings = array_values(array_filter(
            $parent['children'],
            static fn (array $child): bool => $child['start'] !== $node['start']
        ));
        if ([] === $siblings && null === $parent['slot']) {
            // Its only child, in a container with no known slot: already there.
            return $html;
        }
        $position = self::position($index, count($siblings));
        $offset   = self::insert_offset($parent, $siblings, $position);

        $slice = substr($html, $node['start'], $node['end'] - $node['start']);

        if ($offset <= $node['start']) {
            return substr($html, 0, $offset) . $slice
                . substr($html, $offset, $node['start'] - $offset)
                . substr($html, $node['end']);
        }

        return substr($html, 0, $node['start'])
            . substr($html, $node['end'], $offset - $node['end'])
            . $slice . substr($html, $offset);
    }

    /**
     * Serialize an element spec: tag (default div), attrs whose class makes
     * it an element (thrv_wrapper, or tcb-flex-col for a column), and either
     * text (inner HTML) or children (a list of specs).
     */
    public static function serialize(array $element): string
    {
        $tag = $element['tag'] ?? 'div';
        if (! is_string($tag) || ! preg_match('/^[A-Za-z][A-Za-z0-9-]*$/', $tag) || in_array(strtolower($tag), self::VOID, true)) {
            throw new \InvalidArgumentException(esc_html('Each element needs a valid tag that can hold content, such as div.'));
        }

        $attrs = $element['attrs'] ?? [];
        if (! is_array($attrs)) {
            throw new \InvalidArgumentException(esc_html("attrs of {$tag} must be an object."));
        }
        $open = '<' . $tag;
        foreach ($attrs as $key => $value) {
            self::assert_attr_key($key);
            if (null !== $value) {
                $open .= ' ' . $key . '="' . self::attr_value($value) . '"';
            }
        }
        self::assert_node_class(isset($attrs['class']) ? (string) $attrs['class'] : null);

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
            self::assert_balanced($element['text']);
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

        return $open . '>' . $body . '</' . $tag . '>';
    }

    /**
     * Refuse HTML that is not well-formed: an element left open, or a
     * closing tag that does not close the innermost open element.
     */
    public static function assert_balanced(string $html): void
    {
        $stack = [];
        foreach (self::tokenize($html) as $token) {
            if ('open' === $token['type']) {
                $stack[] = $token['tag'];
            } elseif ('close' === $token['type']) {
                if (end($stack) !== $token['tag']) {
                    throw new \InvalidArgumentException(esc_html("The HTML is not well-formed: </{$token['tag']}> does not close the element open at that point."));
                }
                array_pop($stack);
            }
        }
        if ([] !== $stack) {
            throw new \InvalidArgumentException(esc_html('The HTML is not well-formed: <' . end($stack) . '> is never closed.'));
        }
    }

    // ------------------------------------------------------------- parsing

    /**
     * Parse into nested nodes carrying byte offsets. Elements that are not
     * nodes may be left unclosed (a closing tag further out ends them); a
     * node must be closed.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function parse(string $html): array
    {
        $nodes = [];
        $stack = [];

        foreach (self::tokenize($html) as $token) {
            if ('close' === $token['type']) {
                for ($i = count($stack) - 1; $i >= 0 && $stack[ $i ]['tag'] !== $token['tag']; $i--) {
                    // Find the element this tag closes.
                }
                if ($i < 0) {
                    continue;
                }
                for ($j = count($stack) - 1; $j > $i; $j--) {
                    if (null !== $stack[ $j ]['node']) {
                        self::unclosed($html, $nodes[ $stack[ $j ]['node'] ]);
                    }
                }
                $frame = $stack[ $i ];
                if (null !== $frame['node']) {
                    $nodes[ $frame['node'] ]['close_start'] = $token['start'];
                    $nodes[ $frame['node'] ]['end']         = $token['end'];
                }
                if (null !== $frame['slot_of']) {
                    $nodes[ $frame['slot_of'] ]['slot'] = $token['start'];
                }
                $stack = array_slice($stack, 0, $i);
                continue;
            }

            $attrs   = self::attributes($html, $token);
            $classes = self::classes($attrs);
            $parent  = null;
            for ($i = count($stack) - 1; $i >= 0; $i--) {
                if (null !== $stack[ $i ]['node']) {
                    $parent = $stack[ $i ]['node'];
                    break;
                }
            }

            $id = null;
            if ([] !== array_intersect($classes, self::NODE_CLASSES)) {
                $id           = count($nodes);
                $void         = 'self' === $token['type'];
                $nodes[ $id ] = [
                    'tag'         => $token['tag'],
                    'attrs'       => $attrs,
                    'classes'     => $classes,
                    'start'       => $token['start'],
                    'open_end'    => $token['end'],
                    'close_start' => $void ? $token['end'] : null,
                    'end'         => $void ? $token['end'] : null,
                    'void'        => $void,
                    'slot'        => null,
                    'slot_claimed' => false,
                    'parent'      => $parent,
                ];
            }

            if ('self' === $token['type']) {
                continue;
            }

            $slot_of = null;
            if (null === $id && null !== $parent && ! $nodes[ $parent ]['slot_claimed'] && [] !== array_intersect($classes, self::SLOT_CLASSES)) {
                $slot_of                          = $parent;
                $nodes[ $parent ]['slot_claimed'] = true;
            }
            $stack[] = ['tag' => $token['tag'], 'node' => $id, 'slot_of' => $slot_of];
        }

        foreach ($stack as $frame) {
            if (null !== $frame['node']) {
                self::unclosed($html, $nodes[ $frame['node'] ]);
            }
        }

        // Nest in document order: a node always follows its parent.
        $children = [];
        foreach ($nodes as $id => $node) {
            $children[ $node['parent'] ?? -1 ][] = $id;
        }
        $build = static function (int $parent) use (&$build, $nodes, $children): array {
            $out = [];
            foreach ($children[ $parent ] ?? [] as $id) {
                $node             = $nodes[ $id ];
                $node['children'] = $build($id);
                $out[]            = $node;
            }
            return $out;
        };

        return $build(-1);
    }

    /** @return never */
    private static function unclosed(string $html, array $node): void
    {
        $line = substr_count(substr($html, 0, $node['start']), "\n") + 1;
        throw new \InvalidArgumentException(esc_html("The layout is not well-formed: the <{$node['tag']}> element on line {$line} is never closed."));
    }

    /**
     * Tags in document order, skipping comments and the text inside raw text
     * elements (a script's string may hold markup).
     *
     * @return array<int,array<string,mixed>>
     */
    private static function tokenize(string $html): array
    {
        $tokens = [];
        $length = strlen($html);
        $pos    = 0;

        while ($pos < $length && false !== ($lt = strpos($html, '<', $pos))) {
            if ('<!--' === substr($html, $lt, 4)) {
                $close = strpos($html, '-->', $lt + 4);
                $pos   = false === $close ? $length : $close + 3;
                continue;
            }
            if (! preg_match(self::TAG, $html, $m, 0, $lt)) {
                $pos = $lt + 1;
                continue;
            }

            $end   = $lt + strlen($m[0]);
            $tag   = strtolower($m[2]);
            $inner = $m[3];
            $self  = '/' === substr(rtrim($inner), -1);
            if ('/' === $m[1]) {
                $type = 'close';
            } else {
                $type = $self || in_array($tag, self::VOID, true) ? 'self' : 'open';
            }
            $tokens[] = [
                'type'        => $type,
                'tag'         => $tag,
                'start'       => $lt,
                'end'         => $end,
                'attrs_start' => $lt + 1 + strlen($m[2]),
                'attrs_end'   => $end - 1,
            ];
            $pos = $end;

            if ('open' === $type && in_array($tag, self::RAW_TEXT, true)) {
                if (preg_match('/<\/' . $tag . '\s*>/i', $html, $close, PREG_OFFSET_CAPTURE, $end)) {
                    $tokens[] = [
                        'type'        => 'close',
                        'tag'         => $tag,
                        'start'       => $close[0][1],
                        'end'         => $close[0][1] + strlen($close[0][0]),
                        'attrs_start' => $close[0][1],
                        'attrs_end'   => $close[0][1],
                    ];
                    $pos = $close[0][1] + strlen($close[0][0]);
                } else {
                    $pos = $length;
                }
            }
        }

        return $tokens;
    }

    /**
     * An opening tag's attributes in order, each with its name, value as
     * stored and absolute byte span.
     *
     * @return array<int,array{name:string,raw_name:string,value:string,start:int,end:int}>
     */
    private static function attributes(string $html, array $token): array
    {
        $text = substr($html, $token['attrs_start'], $token['attrs_end'] - $token['attrs_start']);
        preg_match_all(self::ATTR, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $attrs = [];
        foreach ($matches as $m) {
            $value = '';
            foreach ([2, 3, 4] as $group) {
                if (isset($m[ $group ]) && -1 !== $m[ $group ][1]) {
                    $value = $m[ $group ][0];
                    break;
                }
            }
            $attrs[] = [
                'name'     => strtolower($m[1][0]),
                'raw_name' => $m[1][0],
                'value'    => $value,
                'start'    => $token['attrs_start'] + $m[0][1],
                'end'      => $token['attrs_start'] + $m[0][1] + strlen($m[0][0]),
            ];
        }

        return $attrs;
    }

    /** @return string[] */
    private static function classes(array $attrs): array
    {
        foreach ($attrs as $attr) {
            if ('class' === $attr['name']) {
                return preg_split('/\s+/', trim($attr['value']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            }
        }

        return [];
    }

    // -------------------------------------------------------------- editing

    /** Apply attribute changes to an opening tag, touching only those attributes. */
    private static function edit_open_tag(string $open, array $node, array $changes): string
    {
        $normalized = [];
        foreach ($changes as $key => $value) {
            self::assert_attr_key($key);
            $normalized[ strtolower((string) $key) ] = [(string) $key, null === $value ? null : self::attr_value($value)];
        }

        if (array_key_exists('class', $normalized)) {
            self::assert_node_class($normalized['class'][1]);
        }

        $edits  = [];
        $append = '';
        foreach ($normalized as $name => [$key, $value]) {
            $found = false;
            foreach ($node['attrs'] as $attr) {
                if ($attr['name'] !== $name) {
                    continue;
                }
                $start = $attr['start'] - $node['start'];
                $end   = $attr['end'] - $node['start'];
                if (null === $value) {
                    while ($start > 0 && ctype_space($open[ $start - 1 ])) {
                        $start--;
                    }
                    $edits[] = [$start, $end, ''];
                } elseif (! $found) {
                    $edits[] = [$start, $end, $attr['raw_name'] . '="' . $value . '"'];
                }
                $found = true;
            }
            if (! $found && null !== $value) {
                $append .= ' ' . $key . '="' . $value . '"';
            }
        }

        $insert = strlen($open) - 1;
        if ('/' === ($open[ $insert - 1 ] ?? '')) {
            $insert--;
        }
        while ($insert > 0 && ctype_space($open[ $insert - 1 ])) {
            $insert--;
        }
        if ('' !== $append) {
            $edits[] = [$insert, $insert, $append];
        }

        usort($edits, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        foreach ($edits as [$start, $end, $replacement]) {
            $open = substr($open, 0, $start) . $replacement . substr($open, $end);
        }

        return $open;
    }

    /** One element's markup from a spec or from {html}. */
    private static function element_markup(array $element): string
    {
        if (! array_key_exists('html', $element)) {
            return self::serialize($element);
        }

        $html = $element['html'];
        if (! is_string($html)) {
            throw new \InvalidArgumentException(esc_html('html must be a string.'));
        }
        self::assert_balanced($html);
        $nodes = self::parse($html);
        $lead  = strlen($html) - strlen(ltrim($html));
        if (1 !== count($nodes) || $nodes[0]['start'] !== $lead || $nodes[0]['end'] !== strlen(rtrim($html))) {
            throw new \InvalidArgumentException(esc_html('html must be exactly one element carrying the thrv_wrapper class.'));
        }

        return $html;
    }

    private static function insert_offset(array $parent, array $siblings, int $position): int
    {
        if ($position < count($siblings)) {
            return $siblings[ $position ]['start'];
        }
        if ([] !== $siblings) {
            return $siblings[ count($siblings) - 1 ]['end'];
        }

        return (int) $parent['slot'];
    }

    /**
     * Resolve a dotted path. '' is the top level, allowed only for a
     * container target, which must be able to hold child elements.
     */
    private static function resolve(string $html, string $path, bool $want_container): array
    {
        $node = [
            'tag'      => '',
            'classes'  => [],
            'children' => self::parse($html),
            'slot'     => strlen($html),
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

        if ($want_container && [] === $node['children'] && null === $node['slot']) {
            throw new \InvalidArgumentException(esc_html("Element {$path} (" . self::type($node['classes']) . ') cannot hold child elements.'));
        }

        return $node;
    }

    private static function kind(array $node): string
    {
        if (in_array('thrv-page-section', $node['classes'], true)) {
            return 'section';
        }

        return [] !== $node['children'] || null !== $node['slot'] ? 'container' : 'element';
    }

    /** @param string[] $classes */
    private static function type(array $classes): string
    {
        foreach (self::TYPES as $class => $type) {
            if (in_array($class, $classes, true)) {
                return $type;
            }
        }
        foreach ($classes as $class) {
            if (! in_array($class, self::NODE_CLASSES, true)) {
                return $class;
            }
        }

        return 'unknown';
    }

    private static function export(array $nodes, string $prefix, string $html): array
    {
        $out = [];
        foreach ($nodes as $i => $node) {
            $path  = '' === $prefix ? (string) $i : $prefix . '.' . $i;
            $attrs = [];
            foreach ($node['attrs'] as $attr) {
                if (! array_key_exists($attr['name'], $attrs)) {
                    $attrs[ $attr['name'] ] = $attr['value'];
                }
            }
            $kind = self::kind($node);
            $item = ['path' => $path, 'type' => self::type($node['classes']), 'kind' => $kind, 'tag' => $node['tag'], 'attrs' => $attrs];
            if ('element' !== $kind) {
                $item['children'] = self::export($node['children'], $path, $html);
            } elseif (! $node['void']) {
                $item['text'] = substr($html, $node['open_end'], $node['close_start'] - $node['open_end']);
            }
            $out[] = $item;
        }

        return $out;
    }

    // ----------------------------------------------------------- validation

    /** @param mixed $value */
    private static function attr_value($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (! is_scalar($value)) {
            throw new \InvalidArgumentException(esc_html('Attribute values must be strings, numbers or booleans.'));
        }

        return str_replace('"', '&quot;', (string) $value);
    }

    /** @param int|string $key */
    private static function assert_attr_key($key): void
    {
        if (! is_string($key) || ! preg_match('/^[A-Za-z_:][A-Za-z0-9_:.-]*$/', $key)) {
            throw new \InvalidArgumentException(esc_html('Attribute names must start with a letter and use letters, digits, hyphens, underscores, dots or colons.'));
        }
    }

    private static function assert_node_class(?string $class): void
    {
        $classes = null === $class ? [] : (preg_split('/\s+/', trim($class), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        if ([] === array_intersect($classes, self::NODE_CLASSES)) {
            throw new \InvalidArgumentException(esc_html('A Thrive element keeps the thrv_wrapper class (tcb-flex-col for a column).'));
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
}
