<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Element operations over a Kirki layout (issue #641): the flat map of
 * elements by id that Kirki_Content reads and stores, rooted at `body`.
 * Pure functions: each takes the map and returns the new one, or throws
 * InvalidArgumentException naming what is wrong, so nothing is written.
 *
 * Operations address elements by id. An element's parentId and its
 * parent's children list are kept in step on every change.
 */
final class Kirki_Tree
{
    public const ROOT = 'body';

    /**
     * Element types this adapter knows the shape of, recorded from the
     * elements of Kirki's own page templates: whether one holds children,
     * and the tag a new one gets when the add names none (a heading gets
     * h2).
     */
    public const CATALOG = [
        'section'   => ['tag' => 'section', 'container' => true],
        'div'       => ['tag' => 'div', 'container' => true],
        'heading'   => ['tag' => 'h2', 'container' => false],
        'paragraph' => ['tag' => 'p', 'container' => false],
        'text'      => ['tag' => 'span', 'container' => false],
        'button'    => ['tag' => 'button', 'container' => true],
        'svg'       => ['tag' => 'svg', 'container' => false],
        'symbol'    => ['tag' => '', 'container' => true],
    ];

    /** Element fields update may set through `element`. */
    private const ELEMENT_FIELDS = ['title', 'visibility', 'collapse', 'className', 'styleIds'];

    private const MAX_DEPTH = 200;

    /**
     * An outline from the body: id, name, title, the text when the
     * element holds plain text, and the children.
     *
     * @param array<string,array<string,mixed>> $blocks
     * @return array<string,mixed>
     */
    public static function outline(array $blocks): array
    {
        if (! isset($blocks[ self::ROOT ]) || ! is_array($blocks[ self::ROOT ])) {
            return [];
        }
        $seen = [];

        return self::outline_node($blocks, self::ROOT, $seen, 0);
    }

    /**
     * @param array<string,array<string,mixed>> $blocks
     * @param array<string,bool> $seen
     * @return array<string,mixed>
     */
    private static function outline_node(array $blocks, string $id, array &$seen, int $depth): array
    {
        $seen[ $id ] = true;
        $element     = $blocks[ $id ];
        $node        = [
            'id'    => $id,
            'name'  => (string) ($element['name'] ?? ''),
            'title' => (string) ($element['title'] ?? ''),
        ];
        $text = self::plain_text($element);
        if (null !== $text && '' !== $text) {
            $node['text'] = strlen($text) > 120 ? substr($text, 0, 117) . '...' : $text;
        }
        $children = [];
        if ($depth < self::MAX_DEPTH) {
            foreach (self::children($element) as $child) {
                if (isset($seen[ $child ]) || ! isset($blocks[ $child ]) || ! is_array($blocks[ $child ])) {
                    continue;
                }
                $children[] = self::outline_node($blocks, $child, $seen, $depth + 1);
            }
        }
        if ([] !== $children) {
            $node['children'] = $children;
        }

        return $node;
    }

    /**
     * Check a whole layout before it is stored: a body root, ids matching
     * their keys, every child present and naming its parent, every parent
     * listing its children, and no element its own ancestor.
     *
     * @param mixed $blocks
     * @return array<string,array<string,mixed>>
     * @throws \InvalidArgumentException
     */
    public static function validate($blocks): array
    {
        if (! is_array($blocks) || [] === $blocks || array_is_list($blocks)) {
            throw new \InvalidArgumentException(esc_html('Kirki content must be a JSON object of elements keyed by id, with a body element.'));
        }
        $root = $blocks[ self::ROOT ] ?? null;
        if (! is_array($root) || 'root' !== ($root['parentId'] ?? null)) {
            throw new \InvalidArgumentException(esc_html("Kirki content needs the body element, with parentId 'root'."));
        }
        foreach ($blocks as $id => $element) {
            $id = (string) $id;
            if (! is_array($element) || ($element['id'] ?? null) !== $id || ! is_string($element['name'] ?? null) || '' === $element['name']) {
                throw new \InvalidArgumentException(esc_html("Element '{$id}' needs a name and an id matching its key."));
            }
            if (array_key_exists('children', $element) && (! is_array($element['children']) || ! array_is_list($element['children']))) {
                throw new \InvalidArgumentException(esc_html("Element '{$id}' children must be a list of ids."));
            }
            foreach (self::children($element) as $child) {
                if (! isset($blocks[ $child ]) || ! is_array($blocks[ $child ])) {
                    throw new \InvalidArgumentException(esc_html("Element '{$id}' lists a child '{$child}' that is not in the layout."));
                }
                if (($blocks[ $child ]['parentId'] ?? null) !== $id) {
                    throw new \InvalidArgumentException(esc_html("Element '{$child}' is listed under '{$id}' but names another parentId."));
                }
            }
            if (self::ROOT === $id) {
                continue;
            }
            $parent = (string) ($element['parentId'] ?? '');
            if (! isset($blocks[ $parent ]) || ! in_array($id, self::children($blocks[ $parent ]), true)) {
                throw new \InvalidArgumentException(esc_html("Element '{$id}' names parentId '{$parent}', which does not list it as a child."));
            }
        }
        foreach (array_keys($blocks) as $id) {
            self::assert_reaches_root($blocks, (string) $id);
        }

        return $blocks;
    }

    /**
     * Merge properties (null removes a key), set the plain text, or set
     * element fields (title, visibility, collapse, className, styleIds).
     *
     * @param array<string,array<string,mixed>> $blocks
     * @param array<string,mixed> $attrs
     * @param array<string,mixed> $fields
     * @return array<string,array<string,mixed>>
     * @throws \InvalidArgumentException
     */
    public static function update(array $blocks, string $id, array $attrs, ?string $text, array $fields): array
    {
        $element = self::element($blocks, $id);
        foreach ($fields as $key => $value) {
            if (! in_array($key, self::ELEMENT_FIELDS, true)) {
                throw new \InvalidArgumentException(esc_html("update's element sets only title, visibility, collapse, className and styleIds; got '{$key}'. Use move to change the parent."));
            }
            if ('styleIds' === $key && (! is_array($value) || ! array_is_list($value))) {
                throw new \InvalidArgumentException(esc_html('styleIds must be a list of style block ids.'));
            }
            $element[ $key ] = $value;
        }

        $properties = is_array($element['properties'] ?? null) ? $element['properties'] : [];
        foreach ($attrs as $key => $value) {
            if (null === $value) {
                unset($properties[ $key ]);
            } else {
                $properties[ $key ] = $value;
            }
        }
        if (null !== $text) {
            if (isset($properties['contents']) && null === self::plain_text(['properties' => $properties])) {
                throw new \InvalidArgumentException(esc_html("Element '{$id}' holds rich contents (inline elements); set attrs.contents instead of text."));
            }
            $properties['contents'] = [$text];
        }
        $element['properties'] = $properties;
        $blocks[ $id ]         = $element;

        return $blocks;
    }

    /**
     * Add an element (with its own children) under a parent, at an index
     * (the end when null). `to` '' is the body. Returns the new layout and
     * the new element's id.
     *
     * @param array<string,array<string,mixed>> $blocks
     * @param array<string,mixed> $spec {name, title, text, properties, styleIds, className, children} or {symbol: id}
     * @param callable(mixed):array<string,mixed> $symbol resolves {symbol: id} to an element spec
     * @return array{0:array<string,array<string,mixed>>,1:string}
     * @throws \InvalidArgumentException
     */
    public static function add(array $blocks, string $to, ?int $index, array $spec, callable $symbol): array
    {
        if ([] === $blocks) {
            $blocks = [self::ROOT => self::body()];
        }
        $to     = '' === $to ? self::ROOT : $to;
        $parent = self::container($blocks, $to);
        $id     = self::build($blocks, $spec, $to, $symbol, 0);

        $children = self::children($parent);
        array_splice($children, self::index($index, count($children)), 0, [$id]);
        $parent['children'] = $children;
        $blocks[ $to ]      = $parent;

        return [$blocks, $id];
    }

    /**
     * Remove an element and everything under it.
     *
     * @param array<string,array<string,mixed>> $blocks
     * @return array<string,array<string,mixed>>
     * @throws \InvalidArgumentException
     */
    public static function remove(array $blocks, string $id): array
    {
        if (self::ROOT === $id) {
            throw new \InvalidArgumentException(esc_html('The body cannot be removed; remove its children instead.'));
        }
        $element = self::element($blocks, $id);
        foreach (self::subtree($blocks, $id) as $gone) {
            unset($blocks[ $gone ]);
        }
        $parent = (string) ($element['parentId'] ?? '');
        if (isset($blocks[ $parent ]) && is_array($blocks[ $parent ])) {
            $blocks[ $parent ]['children'] = array_values(array_diff(self::children($blocks[ $parent ]), [$id]));
        }

        return $blocks;
    }

    /**
     * Move an element under another parent (or the same one) at an index
     * (the end when null), counted after it leaves its old place.
     *
     * @param array<string,array<string,mixed>> $blocks
     * @return array<string,array<string,mixed>>
     * @throws \InvalidArgumentException
     */
    public static function move(array $blocks, string $id, string $to, ?int $index): array
    {
        if (self::ROOT === $id) {
            throw new \InvalidArgumentException(esc_html('The body cannot be moved.'));
        }
        $element = self::element($blocks, $id);
        $to      = '' === $to ? self::ROOT : $to;
        self::container($blocks, $to);
        if (in_array($to, self::subtree($blocks, $id), true)) {
            throw new \InvalidArgumentException(esc_html("Element '{$id}' cannot move inside itself."));
        }

        $old = (string) ($element['parentId'] ?? '');
        if (isset($blocks[ $old ]) && is_array($blocks[ $old ])) {
            $blocks[ $old ]['children'] = array_values(array_diff(self::children($blocks[ $old ]), [$id]));
        }
        $children = self::children($blocks[ $to ]);
        array_splice($children, self::index($index, count($children)), 0, [$id]);
        $blocks[ $to ]['children'] = $children;
        $element['parentId']       = $to;
        $blocks[ $id ]             = $element;

        return $blocks;
    }

    /**
     * Store one element spec (and its children) under a parent; returns
     * its new id.
     *
     * @param array<string,array<string,mixed>> $blocks
     * @param array<string,mixed> $spec
     * @throws \InvalidArgumentException
     */
    private static function build(array &$blocks, array $spec, string $parent, callable $symbol, int $depth): string
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \InvalidArgumentException(esc_html('The element is nested too deeply.'));
        }
        if (array_key_exists('symbol', $spec)) {
            if (1 !== count($spec)) {
                throw new \InvalidArgumentException(esc_html('element {symbol} takes only a component id.'));
            }
            $spec = $symbol($spec['symbol']);
        }
        $name = $spec['name'] ?? null;
        if (! is_string($name) || 1 !== preg_match('/^[a-z][a-z0-9_-]*$/', $name)) {
            throw new \InvalidArgumentException(esc_html('Each element needs a name, a Kirki element type such as section, div, heading, paragraph, text or button (scope catalog lists them).'));
        }
        $shape      = self::CATALOG[ $name ] ?? null;
        $properties = $spec['properties'] ?? [];
        $kids       = $spec['children'] ?? [];
        if (! is_array($properties) || ! is_array($kids) || ! array_is_list($kids)) {
            throw new \InvalidArgumentException(esc_html("Element '{$name}': properties must be an object and children a list of elements."));
        }
        if (null !== $shape && ! $shape['container'] && [] !== $kids) {
            throw new \InvalidArgumentException(esc_html("Element type '{$name}' holds no children."));
        }
        if (isset($spec['text'])) {
            if (! is_scalar($spec['text'])) {
                throw new \InvalidArgumentException(esc_html("Element '{$name}': text must be a string."));
            }
            $properties['contents'] = [(string) $spec['text']];
        }
        if (! isset($properties['tag']) && null !== $shape && '' !== $shape['tag']) {
            $properties['tag'] = $shape['tag'];
        }
        $style_ids = $spec['styleIds'] ?? [];
        if (! is_array($style_ids) || ! array_is_list($style_ids)) {
            throw new \InvalidArgumentException(esc_html('styleIds must be a list of style block ids.'));
        }

        $id      = self::new_id($blocks);
        $element = [
            'visibility' => true,
            'collapse'   => false,
            'name'       => $name,
            'title'      => isset($spec['title']) && is_scalar($spec['title']) ? (string) $spec['title'] : ucfirst(str_replace(['-', '_'], ' ', $name)),
            'properties' => $properties,
            'styleIds'   => $style_ids,
            'className'  => isset($spec['className']) && is_scalar($spec['className']) ? (string) $spec['className'] : '',
            'source'     => 'kirki',
            'id'         => $id,
            'parentId'   => $parent,
        ];
        if ([] !== $kids || null === $shape || $shape['container']) {
            $element['children'] = [];
        }
        $blocks[ $id ] = $element;

        $children = [];
        foreach ($kids as $kid) {
            if (! is_array($kid)) {
                throw new \InvalidArgumentException(esc_html('children must be a list of elements.'));
            }
            $children[] = self::build($blocks, $kid, $id, $symbol, $depth + 1);
        }
        if (isset($blocks[ $id ]['children'])) {
            $blocks[ $id ]['children'] = $children;
        }

        return $id;
    }

    /** @return array<string,mixed> the body a blank page starts from, as Kirki's editor creates it */
    private static function body(): array
    {
        return [
            'visibility' => true,
            'collapse'   => false,
            'name'       => 'body',
            'title'      => 'Body',
            'properties' => ['tag' => 'div'],
            'styleIds'   => [],
            'className'  => 'kirki-body',
            'source'     => 'kirki',
            'children'   => [],
            'id'         => self::ROOT,
            'parentId'   => 'root',
        ];
    }

    /** A new element id in Kirki's form ('k-' and 8 lowercase letters or digits), unused in the layout. */
    private static function new_id(array $blocks): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
        do {
            $id = 'k-';
            for ($i = 0; $i < 8; $i++) {
                $id .= $chars[ wp_rand(0, 35) ];
            }
        } while (isset($blocks[ $id ]));

        return $id;
    }

    /**
     * @param array<string,array<string,mixed>> $blocks
     * @return array<string,mixed>
     * @throws \InvalidArgumentException
     */
    private static function element(array $blocks, string $id): array
    {
        if ('' === $id || ! isset($blocks[ $id ]) || ! is_array($blocks[ $id ])) {
            throw new \InvalidArgumentException(esc_html("No element '{$id}' on this page; get-builder-content lists the ids."));
        }

        return $blocks[ $id ];
    }

    /**
     * The element at `$id`, which must be able to hold children.
     *
     * @param array<string,array<string,mixed>> $blocks
     * @return array<string,mixed>
     * @throws \InvalidArgumentException
     */
    private static function container(array $blocks, string $id): array
    {
        $element = self::element($blocks, $id);
        $name    = (string) ($element['name'] ?? '');
        $shape   = self::CATALOG[ $name ] ?? null;
        if (! is_array($element['children'] ?? null) && ! (null !== $shape && $shape['container'])) {
            throw new \InvalidArgumentException(esc_html("Element '{$id}' ({$name}) holds no children; add to its parent instead."));
        }

        return $element;
    }

    /**
     * The element and everything under it, by id.
     *
     * @param array<string,array<string,mixed>> $blocks
     * @return string[]
     */
    private static function subtree(array $blocks, string $id): array
    {
        $out   = [];
        $queue = [$id];
        while ([] !== $queue) {
            $next = array_shift($queue);
            if (isset($out[ $next ]) || ! isset($blocks[ $next ]) || ! is_array($blocks[ $next ])) {
                continue;
            }
            $out[ $next ] = true;
            foreach (self::children($blocks[ $next ]) as $child) {
                $queue[] = $child;
            }
        }

        return array_keys($out);
    }

    /** @throws \InvalidArgumentException */
    private static function assert_reaches_root(array $blocks, string $id): void
    {
        $seen = [];
        $at   = $id;
        while (self::ROOT !== $at) {
            if (isset($seen[ $at ]) || ! isset($blocks[ $at ])) {
                throw new \InvalidArgumentException(esc_html("Element '{$id}' does not lead back to the body."));
            }
            $seen[ $at ] = true;
            $at          = (string) ($blocks[ $at ]['parentId'] ?? '');
        }
    }

    /** @return string[] */
    private static function children(array $element): array
    {
        $children = $element['children'] ?? [];

        return is_array($children) ? array_values(array_filter(array_map(static fn ($c) => is_scalar($c) ? (string) $c : '', $children), static fn ($c) => '' !== $c)) : [];
    }

    /** The element's text when its contents are plain strings, else null. */
    private static function plain_text(array $element): ?string
    {
        $contents = $element['properties']['contents'] ?? null;
        if (! is_array($contents)) {
            return null;
        }
        foreach ($contents as $part) {
            if (! is_string($part)) {
                return null;
            }
        }

        return implode('', $contents);
    }

    private static function index(?int $index, int $count): int
    {
        if (null === $index || $index > $count) {
            return $count;
        }

        return max(0, $index);
    }
}
