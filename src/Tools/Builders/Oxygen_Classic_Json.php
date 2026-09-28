<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Parser and editor for a classic Oxygen (4.x) layout: the JSON tree Oxygen
 * keeps in its ct_builder_json postmeta. Pure string work, so it runs
 * without Oxygen loaded.
 *
 * The tree is a root {"id":0,"name":"root","depth":0,"children":[...]}
 * whose nodes are {"id","name","options","depth","children"?}. `name` is
 * the element (ct_section, ct_div_block, ct_headline, a third-party
 * oxy-... element), `options` holds its settings: ct_id and ct_parent repeat
 * the node's and its parent's ids, `selector` names its CSS id
 * (<name less its first three characters>-<id>-<post id>), `original` holds
 * the desktop values, `media` the breakpoint ones and `ct_content` its text.
 * A text element that holds inline elements (a span, a link) keeps a
 * <span id="ct-placeholder-<id>"></span> for each of them in its ct_content,
 * which is where Oxygen renders them. `depth` is the nesting level Oxygen's
 * editor computes, which its shortcode copy turns into tag suffixes.
 *
 * Elements are addressed by dotted paths of child indexes ("0.1.0" is the
 * first child of the second child of the first top-level element), the same
 * shape as the other layout parsers; each node also reports its id.
 *
 * Edits never decode and re-encode the whole tree. The stored string is
 * scanned for the byte span of every node and of the values an edit touches,
 * and only those spans are rewritten: an update re-encodes the target's
 * options object alone, a move rewrites only the parent and depth numbers in
 * the moved subtree, and add and remove splice whole nodes. New bytes are
 * encoded the way Oxygen's save encodes the tree (json_encode with
 * JSON_UNESCAPED_UNICODE), so every other byte, unknown elements included,
 * stays exactly as it was. Every fragment an edit writes can be passed
 * through a filter first; the content helper uses it to have Oxygen sign
 * dynamic data shortcodes in new text, as its save does.
 */
class Oxygen_Classic_Json
{
    /** Elements that hold other elements (Oxygen's own nestable list, plus the slider). */
    public const CONTAINERS = [
        'ct_section', 'ct_div_block', 'ct_link', 'ct_container', 'ct_inner_content', 'ct_columns', 'ct_column',
        'ct_new_columns', 'ct_nestable_shortcode', 'ct_modal', 'ct_slider', 'ct_slide',
        'oxy_superbox', 'oxy_toggle', 'oxy_tab', 'oxy_tabs', 'oxy_tab_content', 'oxy_tabs_contents',
        'oxy_dynamic_list', 'oxy-product-builder', 'oxy_header', 'oxy_header_row', 'oxy_header_left',
        'oxy_header_center', 'oxy_header_right',
    ];

    /** Options an edit may not set: the ids and selector are structural, the text goes through `text`. */
    private const PROTECTED_OPTIONS = ['ct_id', 'ct_parent', 'selector', 'ct_content', 'ct_depth'];

    private const FLAGS = JSON_UNESCAPED_UNICODE;

    /** Public tree: nodes with path, id, name, options, and text and children where they apply. */
    public static function tree(string $json): array
    {
        return self::export(self::parse($json)['children'], $json);
    }

    /** Whether the tree holds elements: Oxygen's own test for rendering a page from it. */
    public static function has_elements(string $json): bool
    {
        $tree = json_decode($json, true);

        return is_array($tree) && isset($tree['children']) && is_array($tree['children']) && [] !== $tree['children'];
    }

    /**
     * Check a whole tree before it is stored: a root holding nodes that each
     * carry an integer id used once, a name, options whose ct_id and
     * ct_parent match the tree, and an integer depth.
     */
    public static function validate(string $json): void
    {
        $tree = json_decode($json, true);
        if (! is_array($tree) || array_is_list($tree) || 'root' !== ($tree['name'] ?? null) || ! is_array($tree['children'] ?? null) || ! array_is_list($tree['children'])) {
            throw new \InvalidArgumentException(esc_html('The tree must be a JSON object {"id":0,"name":"root","depth":0,"children":[...]}.'));
        }

        $ids = [];
        self::validate_nodes($tree['children'], is_int($tree['id'] ?? null) ? $tree['id'] : 0, $ids);
    }

    /**
     * Merge options into one element (a null value removes the key; objects
     * merge key by key) and/or replace its text.
     */
    public static function update(string $json, string $path, ?array $attrs, ?string $text, ?callable $filter = null): string
    {
        if ((null === $attrs || [] === $attrs) && null === $text) {
            throw new \InvalidArgumentException(esc_html('Provide attrs (the options to merge) and/or text.'));
        }

        $doc = self::parse($json);
        [$node] = self::resolve($doc, $path);
        $options = self::options_object($json, $node);

        if (null !== $attrs) {
            self::assert_options($attrs);
            $options = self::merge($options, $attrs);
        }
        if (null !== $text) {
            foreach ($node['children'] as $child) {
                if (false === strpos($text, self::placeholder($child['id']))) {
                    throw new \InvalidArgumentException(esc_html("The text of element {$path} must keep the placeholder of its inline element {$child['id']}: " . self::placeholder($child['id'])));
                }
            }
            $options->ct_content = $text;
        }

        $fragment = self::filtered(self::encode($options), $filter);

        return self::apply($json, [[$node['options']['start'], $node['options']['end'], $fragment]]);
    }

    /**
     * Insert a new element under a container ('' is the top level) at a
     * child index (null or past the end appends). The element is
     * {name, options?, text? | children?}; it and every child get fresh ids,
     * the selector Oxygen would give them and Oxygen's depth.
     *
     * @return array{0:string,1:string} new tree, and the new element's path
     */
    public static function add(string $json, string $to, ?int $index, array $element, int $post_id, ?callable $filter = null): array
    {
        $doc    = self::parse($json);
        $parent = '' === $to ? $doc : self::resolve($doc, $to)[0];
        self::assert_can_hold($parent);

        $position = self::position($index, count($parent['children']));
        $next     = self::next_id($doc);
        $node     = self::build($element, $parent['id'], $parent['name'], $parent['depth'], $post_id, $next);
        $raw      = self::filtered(self::encode($node), $filter);

        $path = ('' === $to ? '' : $to . '.') . $position;

        return [self::insert($json, $parent, $position, $raw), $path];
    }

    /**
     * Remove an element and everything under it. An inline element's
     * placeholder is taken out of its parent's text too.
     */
    public static function remove(string $json, string $path): string
    {
        $doc = self::parse($json);
        [$node, $parent, $position] = self::resolve($doc, $path);

        $edits = [self::cut($parent, $position)];

        $content = $parent['content'] ?? null;
        if (is_string($content) && false !== strpos($content, self::placeholder($node['id']))) {
            $options             = self::options_object($json, $parent);
            $options->ct_content = str_replace(self::placeholder($node['id']), '', $content);
            $edits[]             = [$parent['options']['start'], $parent['options']['end'], self::encode($options)];
        }

        return self::apply($json, $edits);
    }

    /**
     * Move an element (with its subtree) under a container ('' is the top
     * level). The index is its final position among that container's
     * children. Only the numbers that place it change: the moved element's
     * ct_parent, and the depth of each element in the subtree whose depth
     * Oxygen would compute differently under the new parent.
     */
    public static function move(string $json, string $path, string $to, ?int $index): string
    {
        $doc = self::parse($json);
        [$node, $old_parent, $position] = self::resolve($doc, $path);
        if ($to === $path || 0 === strpos($to . '.', $path . '.')) {
            throw new \InvalidArgumentException(esc_html('An element cannot be moved into itself.'));
        }
        $content = $old_parent['content'] ?? null;
        if (is_string($content) && false !== strpos($content, self::placeholder($node['id']))) {
            throw new \InvalidArgumentException(esc_html("Element {$path} is placed inside its parent's text; move it in Oxygen."));
        }
        $parent = '' === $to ? $doc : self::resolve($doc, $to)[0];
        self::assert_can_hold($parent);

        $edits = [];
        self::replace_number($edits, $node['options']['keys']['ct_parent'] ?? null, $parent['id']);
        self::redepth($edits, $node, $parent['name'], $parent['depth']);
        $slice = self::apply(substr($json, 0, $node['end']), $edits);
        $slice = substr($slice, $node['start']);

        $without = self::apply($json, [self::cut($old_parent, $position)]);
        $doc     = self::parse($without);
        $target  = '' === $to ? $doc : self::find($doc, $parent['id']);

        return self::insert($without, $target, self::position($index, count($target['children'])), $slice);
    }

    // ---------------------------------------------------------------- internals

    /**
     * Scan a stored tree into nodes carrying byte spans. The string is
     * checked with json_decode() first, so the scanner only ever walks valid
     * JSON.
     */
    private static function parse(string $json): array
    {
        $decoded = json_decode($json);
        if (! is_object($decoded) || 'root' !== ($decoded->name ?? null)) {
            throw new \InvalidArgumentException(esc_html('The stored layout is not an Oxygen tree (a JSON object named root).'));
        }

        $i    = 0;
        $root = self::scan($json, $i);

        return self::node($json, $root, '', true);
    }

    /** One node (or the root) from its object span, with its children. */
    private static function node(string $json, array $span, string $path, bool $is_root = false): array
    {
        if ('object' !== $span['type']) {
            throw new \InvalidArgumentException(esc_html("The element at {$path} is not a JSON object."));
        }
        $keys = $span['keys'];
        $name = isset($keys['name']) ? json_decode(self::slice($json, $keys['name'])) : null;
        if (! is_string($name) || '' === $name) {
            throw new \InvalidArgumentException(esc_html("The element at {$path} has no name."));
        }
        $id      = isset($keys['id']) ? json_decode(self::slice($json, $keys['id'])) : null;
        $depth   = isset($keys['depth']) ? json_decode(self::slice($json, $keys['depth'])) : null;
        $options = $keys['options'] ?? null;
        $content = null;
        if (null !== $options && 'object' === $options['type'] && isset($options['keys']['ct_content'])) {
            $content = json_decode(self::slice($json, $options['keys']['ct_content']));
        }

        $node = [
            'path'     => $path,
            'start'    => $span['start'],
            'end'      => $span['end'],
            'id'       => is_int($id) ? $id : ($is_root ? 0 : null),
            'name'     => $name,
            'depth'    => is_int($depth) ? $depth : 0,
            'span'     => $span,
            'options'  => null !== $options && 'object' === $options['type'] ? $options : null,
            'content'  => is_string($content) ? $content : null,
            'list'     => isset($keys['children']) && 'array' === $keys['children']['type'] ? $keys['children'] : null,
            'children' => [],
        ];
        if ($is_root && null === $node['list']) {
            throw new \InvalidArgumentException(esc_html('The Oxygen tree has no children list.'));
        }

        foreach ($node['list']['items'] ?? [] as $position => $item) {
            $node['children'][] = self::node($json, $item, ('' === $path || $is_root ? '' : $path . '.') . $position);
        }

        return $node;
    }

    /**
     * Scan one JSON value from offset $i, returning its span: start, end and
     * type, with `keys` (name => span) for an object and `items` for an array.
     */
    private static function scan(string $s, int &$i): array
    {
        self::skip($s, $i);
        $start = $i;
        $char  = $s[ $i ] ?? '';

        if ('{' === $char || '[' === $char) {
            $object = '{' === $char;
            $close  = $object ? '}' : ']';
            $out    = ['type' => $object ? 'object' : 'array', 'start' => $start];
            $object ? $out['keys'] = [] : $out['items'] = [];
            $i++;
            self::skip($s, $i);
            if ($close === ($s[ $i ] ?? '')) {
                $i++;
                $out['end'] = $i;
                return $out;
            }
            while (true) {
                if ($object) {
                    $key_span = self::scan($s, $i);
                    $key      = json_decode(self::slice($s, $key_span));
                    self::skip($s, $i);
                    $i++; // the colon
                    $out['keys'][ (string) $key ] = self::scan($s, $i);
                } else {
                    $out['items'][] = self::scan($s, $i);
                }
                self::skip($s, $i);
                $sep = $s[ $i ] ?? '';
                $i++;
                if ($close === $sep) {
                    $out['end'] = $i;
                    return $out;
                }
                if (',' !== $sep) {
                    throw new \InvalidArgumentException(esc_html('The stored layout is not valid JSON.'));
                }
            }
        }

        if ('"' === $char) {
            $i++;
            $length = strlen($s);
            while ($i < $length && '"' !== $s[ $i ]) {
                $i += '\\' === $s[ $i ] ? 2 : 1;
            }
            $i++;
            return ['type' => 'scalar', 'start' => $start, 'end' => $i];
        }

        $i += strcspn($s, ",]} \t\r\n", $i);

        return ['type' => 'scalar', 'start' => $start, 'end' => $i];
    }

    private static function skip(string $s, int &$i): void
    {
        $i += strspn($s, " \t\r\n", $i);
    }

    private static function slice(string $s, array $span): string
    {
        return substr($s, $span['start'], $span['end'] - $span['start']);
    }

    /**
     * Resolve a dotted path to [node, parent, position among its siblings].
     *
     * @return array{0:array,1:array,2:int}
     */
    private static function resolve(array $doc, string $path): array
    {
        if ('' === $path) {
            throw new \InvalidArgumentException(esc_html('A path to an element is required.'));
        }
        if (! preg_match('/^\d+(?:\.\d+)*$/', $path)) {
            throw new \InvalidArgumentException(esc_html("Invalid path {$path}; use dotted child indexes such as 0.1.0."));
        }

        $parent   = $doc;
        $node     = $doc;
        $position = 0;
        foreach (explode('.', $path) as $step) {
            if (! isset($node['children'][ (int) $step ])) {
                throw new \InvalidArgumentException(esc_html("No element at path {$path}."));
            }
            $parent   = $node;
            $position = (int) $step;
            $node     = $node['children'][ $position ];
        }

        return [$node, $parent, $position];
    }

    /** A node by id; the ids must be unique for this to be meaningful. */
    private static function find(array $node, int $id): array
    {
        $found = [];
        $walk  = static function (array $node) use (&$walk, &$found, $id): void {
            foreach ($node['children'] as $child) {
                if ($child['id'] === $id) {
                    $found[] = $child;
                }
                $walk($child);
            }
        };
        $walk($node);

        if (1 !== count($found)) {
            throw new \InvalidArgumentException(esc_html("Element id {$id} is not unique in this tree; save the page in Oxygen first."));
        }

        return $found[0];
    }

    /** Whether new elements may go under this node. */
    private static function assert_can_hold(array $node): void
    {
        if ('' === $node['path'] && 'root' === $node['name']) {
            return;
        }
        if (null !== $node['content'] && '' !== $node['content']) {
            throw new \InvalidArgumentException(esc_html("Element {$node['path']} ({$node['name']}) is a text element; its inline elements sit inside its text, so place them in Oxygen."));
        }
        if (null === $node['list'] && ! in_array($node['name'], self::CONTAINERS, true)) {
            throw new \InvalidArgumentException(esc_html("Element {$node['path']} ({$node['name']}) cannot hold child elements."));
        }
    }

    /** The bytes to cut to take one child out of its parent's list, separator included. */
    private static function cut(array $parent, int $position): array
    {
        $items = $parent['list']['items'];
        $count = count($items);
        if (1 === $count) {
            return [$items[0]['start'], $items[0]['end'], ''];
        }
        if ($position < $count - 1) {
            return [$items[ $position ]['start'], $items[ $position + 1 ]['start'], ''];
        }

        return [$items[ $position - 1 ]['end'], $items[ $position ]['end'], ''];
    }

    /** Insert one encoded node into a parent's children, creating the list when it has none. */
    private static function insert(string $json, array $parent, int $position, string $raw): string
    {
        if (null === $parent['list']) {
            return self::apply($json, [[$parent['end'] - 1, $parent['end'] - 1, ',"children":[' . $raw . ']']]);
        }

        $items = $parent['list']['items'];
        if ($position < count($items)) {
            return self::apply($json, [[$items[ $position ]['start'], $items[ $position ]['start'], $raw . ',']]);
        }
        if ([] !== $items) {
            $end = $items[ count($items) - 1 ]['end'];
            return self::apply($json, [[$end, $end, ',' . $raw]]);
        }

        return self::apply($json, [[$parent['list']['start'] + 1, $parent['list']['start'] + 1, $raw]]);
    }

    /** Apply [start, end, replacement] edits, last first so earlier offsets stay valid. */
    private static function apply(string $json, array $edits): string
    {
        usort($edits, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        foreach ($edits as [$start, $end, $replacement]) {
            $json = substr($json, 0, $start) . $replacement . substr($json, $end);
        }

        return $json;
    }

    /** Record a rewrite of one integer value when it changes. */
    private static function replace_number(array &$edits, ?array $span, int $value): void
    {
        if (null !== $span && 'scalar' === $span['type']) {
            $edits[] = [$span['start'], $span['end'], (string) $value, 'number'];
        }
    }

    /** Rewrite the depth of a subtree for a new parent, where it changes. */
    private static function redepth(array &$edits, array $node, string $parent_name, int $parent_depth): void
    {
        $depth = self::depth($node['name'], $parent_name, $parent_depth);
        if ($depth !== $node['depth']) {
            self::replace_number($edits, $node['span']['keys']['depth'] ?? null, $depth);
            self::replace_number($edits, $node['options']['keys']['ct_depth'] ?? null, $depth);
        }
        foreach ($node['children'] as $child) {
            self::redepth($edits, $child, $node['name'], $depth);
        }
    }

    /**
     * The depth Oxygen's editor gives an element under a parent: one below
     * the parent, except for the pairings it keeps on the parent's level
     * (a column in columns, a div block in new columns, a tab in tabs, ...).
     */
    private static function depth(string $name, string $parent_name, int $parent_depth): int
    {
        if ('root' === $parent_name) {
            $parent_depth = 0;
        }

        $same = ('ct_column' === $name && 'ct_columns' === $parent_name)
            || in_array($name, ['oxy_header_row', 'oxy_header_left', 'oxy_header_center', 'oxy_header_right'], true)
            || (in_array($name, ['ct_div_block', 'ct_nestable_shortcode'], true) && in_array($parent_name, ['ct_column', 'ct_new_columns'], true))
            || (in_array($name, ['ct_link', 'ct_section'], true) && 'ct_column' === $parent_name)
            || 'ct_slide' === $name
            || ('oxy_tab' === $name && 'oxy_tabs' === $parent_name)
            || ('oxy_tab_content' === $name && 'oxy_tabs_contents' === $parent_name);

        return $same ? $parent_depth : $parent_depth + 1;
    }

    /**
     * A new node from a spec, with fresh ids from $next.
     *
     * @param mixed $spec
     */
    private static function build($spec, int $parent_id, string $parent_name, int $parent_depth, int $post_id, int &$next): object
    {
        if (! is_array($spec)) {
            throw new \InvalidArgumentException(esc_html('Each element must be an object: {name, options?, text? | children?}.'));
        }
        $name = $spec['name'] ?? null;
        if (! is_string($name) || ! preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $name)) {
            throw new \InvalidArgumentException(esc_html('Each element needs a name such as ct_section, ct_div_block, ct_headline or ct_text_block.'));
        }

        $options = $spec['options'] ?? [];
        if (! is_array($options) || ([] !== $options && array_is_list($options))) {
            throw new \InvalidArgumentException(esc_html("options of {$name} must be an object."));
        }
        self::assert_options($options);

        $text     = $spec['text'] ?? null;
        $children = $spec['children'] ?? [];
        if (null !== $text && ! is_string($text)) {
            throw new \InvalidArgumentException(esc_html("text of {$name} must be a string."));
        }
        if (! is_array($children) || ! array_is_list($children)) {
            throw new \InvalidArgumentException(esc_html("children of {$name} must be a list."));
        }
        if ([] !== $children && (null !== $text || ! in_array($name, self::CONTAINERS, true))) {
            throw new \InvalidArgumentException(esc_html("{$name} cannot hold child elements here; inline elements in text are placed in Oxygen."));
        }

        $id    = $next++;
        $depth = self::depth($name, $parent_name, $parent_depth);

        $opts            = new \stdClass();
        $opts->ct_id     = $id;
        $opts->ct_parent = $parent_id;
        $opts->selector  = substr($name, 3) . '-' . $id . '-' . $post_id;
        $original        = $options['original'] ?? [];
        $opts->original  = [] === $original ? new \stdClass() : self::to_object($original);
        foreach ($options as $key => $value) {
            if ('original' !== $key && null !== $value) {
                $opts->{$key} = self::to_object($value);
            }
        }
        if (null !== $text) {
            $opts->ct_content = $text;
        }

        $node          = new \stdClass();
        $node->id      = $id;
        $node->name    = $name;
        $node->options = $opts;
        $node->depth   = $depth;
        if ([] !== $children) {
            $node->children = [];
            foreach ($children as $child) {
                $node->children[] = self::build($child, $id, $name, $depth, $post_id, $next);
            }
        }

        return $node;
    }

    /** The next id: one past the largest page id (ids from 100000 up belong to outer templates). */
    private static function next_id(array $doc): int
    {
        $max  = 0;
        $walk = static function (array $node) use (&$walk, &$max): void {
            foreach ($node['children'] as $child) {
                if (is_int($child['id']) && $child['id'] < 100000) {
                    $max = max($max, $child['id']);
                }
                $walk($child);
            }
        };
        $walk($doc);

        return $max + 1;
    }

    private static function options_object(string $json, array $node): object
    {
        if (null === $node['options']) {
            throw new \InvalidArgumentException(esc_html("Element {$node['path']} has no options object."));
        }

        return json_decode(self::slice($json, $node['options']));
    }

    /** @param array<int|string,mixed> $options */
    private static function assert_options(array $options): void
    {
        foreach (array_keys($options) as $key) {
            if (! is_string($key) || '' === $key) {
                throw new \InvalidArgumentException(esc_html('Option names must be strings.'));
            }
            if (in_array($key, self::PROTECTED_OPTIONS, true)) {
                throw new \InvalidArgumentException(esc_html("The {$key} option is managed by Oxygen" . ('ct_content' === $key ? '; set the text with text.' : '.')));
            }
        }
    }

    /**
     * Deep merge: objects merge key by key, anything else (lists included)
     * replaces, and a null value removes that key.
     *
     * @param array<string,mixed> $attrs
     */
    private static function merge(object $base, array $attrs): object
    {
        foreach ($attrs as $key => $value) {
            if (null === $value) {
                unset($base->{$key});
                continue;
            }
            $value = self::to_object($value);
            if (is_object($value) && isset($base->{$key}) && is_object($base->{$key})) {
                $base->{$key} = self::merge($base->{$key}, get_object_vars($value));
            } else {
                $base->{$key} = $value;
            }
        }

        return $base;
    }

    /**
     * Decoded input in the stored shape: associative arrays become objects,
     * lists stay arrays, recursively.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function to_object($value)
    {
        if (is_array($value)) {
            if ([] === $value || array_is_list($value)) {
                return array_map([self::class, 'to_object'], $value);
            }
            $out = new \stdClass();
            foreach ($value as $key => $item) {
                $out->{$key} = self::to_object($item);
            }

            return $out;
        }

        return $value;
    }

    /** @param mixed $value */
    private static function encode($value): string
    {
        $out = wp_json_encode($value, self::FLAGS);
        if (! is_string($out)) {
            throw new \InvalidArgumentException(esc_html('The new values could not be encoded as JSON.'));
        }

        return $out;
    }

    private static function filtered(string $fragment, ?callable $filter): string
    {
        return null === $filter ? $fragment : (string) $filter($fragment);
    }

    private static function placeholder(?int $id): string
    {
        $id = (int) $id;

        return '<span id="ct-placeholder-' . ($id >= 100000 ? $id % 100000 : $id) . '"></span>';
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
     * @param array<int,mixed> $nodes
     * @param array<int,true>  $ids
     */
    private static function validate_nodes(array $nodes, int $parent_id, array &$ids): void
    {
        foreach ($nodes as $node) {
            $id = is_array($node) ? ($node['id'] ?? null) : null;
            if (! is_int($id) || ! is_string($node['name'] ?? null) || '' === $node['name'] || ! is_int($node['depth'] ?? null)) {
                throw new \InvalidArgumentException(esc_html('Each element needs an integer id, a name and an integer depth.'));
            }
            if (isset($ids[ $id ])) {
                throw new \InvalidArgumentException(esc_html("Element id {$id} is used twice."));
            }
            $ids[ $id ] = true;

            $options = $node['options'] ?? null;
            if (! is_array($options) || ($options['ct_id'] ?? null) !== $id || ($options['ct_parent'] ?? null) !== $parent_id) {
                throw new \InvalidArgumentException(esc_html("Element {$id} needs options whose ct_id is {$id} and ct_parent is {$parent_id}."));
            }

            $children = $node['children'] ?? [];
            if (! is_array($children) || ! array_is_list($children)) {
                throw new \InvalidArgumentException(esc_html("children of element {$id} must be a list."));
            }
            self::validate_nodes($children, $id, $ids);
        }
    }

    private static function export(array $nodes, string $json): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $options = null === $node['options'] ? [] : json_decode(self::slice($json, $node['options']), true);
            unset($options['ct_content']);

            $item = ['path' => $node['path'], 'id' => $node['id'], 'name' => $node['name'], 'options' => $options];
            if (null !== $node['content']) {
                $item['text'] = $node['content'];
            }
            if (null !== $node['list'] || in_array($node['name'], self::CONTAINERS, true)) {
                $item['children'] = self::export($node['children'], $json);
            }
            $out[] = $item;
        }

        return $out;
    }
}
