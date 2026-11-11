<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Tree view and editor for a Cornerstone (Themeco) element list (issue
 * #646): the decoded `_cornerstone_data` postmeta. Pure data work, so it
 * runs without Cornerstone loaded.
 *
 * Recorded from Cornerstone 7.8.12 (Content::updateElements() and
 * ShortcodeContentBuilder::build_output()): the row is a JSON list of
 * top-level elements, each an object with `_type` (the element type, such as
 * section, layout-row, layout-column, headline, text or a classic:* type),
 * the values that differ from that element's defaults as flat keys
 * (text_content, section_padding and so on), an optional `_label` and
 * `_active`, and its children in `_modules`. Element ids (`_id`) are given
 * out on every save and are not stored, so elements are addressed here by
 * dotted child indexes: "0" is the first top-level element, "0.1" its second
 * child.
 *
 * Where an element may go is checked through a callback the caller supplies
 * (Cornerstone's own definitions: valid_children and valid_parent), with
 * 'region' standing for the top level as it does in Cornerstone.
 */
final class Cornerstone_Tree
{
    /** Keys an update may not set: the element's identity and children. */
    private const PROTECTED_KEYS = ['_type', '_modules', '_id', '_region'];

    /**
     * Decode a stored row as cs_maybe_json_decode() does (older rows were
     * stored with escaped slashes), unwrapping a {data: [...]} envelope.
     *
     * @param mixed $raw
     * @return array<int,array<string,mixed>>|null null when it is not a list of elements
     */
    public static function decode($raw): ?array
    {
        if (is_array($raw)) {
            $list = $raw;
        } elseif (is_string($raw) && '' !== $raw) {
            $list = json_decode($raw, true);
            if (null === $list) {
                $list = json_decode(wp_unslash($raw), true);
            }
        } else {
            return null;
        }
        if (is_array($list) && isset($list['data']) && is_array($list['data'])) {
            $list = $list['data'];
        }

        return is_array($list) && array_is_list($list) ? $list : null;
    }

    /**
     * Decode a stored JSON object row (settings, template data) the same
     * way; [] when it is not one.
     *
     * @param mixed $raw
     * @return array<string,mixed>
     */
    public static function decode_object($raw): array
    {
        if (is_string($raw) && '' !== $raw) {
            $decoded = json_decode($raw, true);
            $raw     = null === $decoded ? json_decode(wp_unslash($raw), true) : $decoded;
        }

        return is_array($raw) ? $raw : [];
    }

    /**
     * The element tree with paths: {path, type, label?, active?, children}.
     *
     * @param array<int,mixed> $elements
     * @return array<int,array<string,mixed>>
     */
    public static function tree(array $elements, string $prefix = ''): array
    {
        $out = [];
        foreach ($elements as $i => $element) {
            $path = '' === $prefix ? (string) $i : $prefix . '.' . $i;
            $node = ['path' => $path, 'type' => is_array($element) ? (string) ($element['_type'] ?? '') : ''];
            if (is_array($element) && isset($element['_label']) && is_string($element['_label']) && '' !== $element['_label']) {
                $node['label'] = $element['_label'];
            }
            if (is_array($element) && array_key_exists('_active', $element) && ! $element['_active']) {
                $node['active'] = false;
            }
            $children         = is_array($element) && is_array($element['_modules'] ?? null) ? $element['_modules'] : [];
            $node['children'] = self::tree($children, $path);
            $out[]            = $node;
        }

        return $out;
    }

    /**
     * Check a whole list: every element is an object with a `_type`, and
     * every placement passes $placement (parent type, child type => error or
     * null), the top level counting as 'region'.
     *
     * @param mixed $elements
     */
    public static function validate($elements, callable $placement, string $parent = 'region', string $where = 'the top level'): void
    {
        if (! is_array($elements) || ! array_is_list($elements)) {
            throw new \InvalidArgumentException(esc_html("The children of {$where} must be a list of elements."));
        }
        foreach ($elements as $i => $element) {
            if (! is_array($element) || ! is_string($element['_type'] ?? null) || '' === $element['_type']) {
                throw new \InvalidArgumentException(esc_html("Element {$i} under {$where} needs a _type."));
            }
            $error = $placement($parent, $element['_type']);
            if (null !== $error) {
                throw new \InvalidArgumentException(esc_html($error));
            }
            if (array_key_exists('_modules', $element)) {
                self::validate($element['_modules'], $placement, $element['_type'], "a {$element['_type']} element");
            }
        }
    }

    /**
     * An element spec as the caller gives it, in Cornerstone's stored
     * shape: {type, attrs?, children?} (children in the same shape), or an
     * element as get-builder-content returns it ({_type, ..., _modules?}).
     *
     * @param mixed $spec
     * @return array<string,mixed>
     */
    public static function from_spec($spec): array
    {
        if (! is_array($spec)) {
            throw new \InvalidArgumentException(esc_html('An element must be an object: {type, attrs?, children?}.'));
        }
        if (array_key_exists('_type', $spec)) {
            return $spec;
        }
        $type = $spec['type'] ?? null;
        if (! is_string($type) || '' === $type) {
            throw new \InvalidArgumentException(esc_html('An element needs type: a Cornerstone element type such as headline (see scope catalog).'));
        }
        $extra = array_diff(array_keys($spec), ['type', 'attrs', 'children']);
        if ([] !== $extra) {
            throw new \InvalidArgumentException(esc_html('An element takes only type, attrs and children; got ' . implode(', ', $extra) . '.'));
        }
        $attrs = $spec['attrs'] ?? [];
        if (! is_array($attrs) || ($attrs !== [] && array_is_list($attrs))) {
            throw new \InvalidArgumentException(esc_html('attrs must be an object of element values.'));
        }
        foreach (array_keys($attrs) as $key) {
            if (in_array($key, self::PROTECTED_KEYS, true)) {
                throw new \InvalidArgumentException(esc_html("attrs may not set {$key}; use type and children."));
            }
        }

        $element = ['_type' => $type] + $attrs;
        if (array_key_exists('children', $spec)) {
            if (! is_array($spec['children']) || ! array_is_list($spec['children'])) {
                throw new \InvalidArgumentException(esc_html('children must be a list of elements.'));
            }
            $element['_modules'] = array_map([self::class, 'from_spec'], $spec['children']);
        }

        return $element;
    }

    /** @return array<string,mixed> a copy of the element at $path */
    public static function node(array $elements, string $path): array
    {
        $indexes = self::indexes($path);
        $list    = $elements;
        $node    = null;
        foreach ($indexes as $depth => $i) {
            if (! is_array($list[ $i ] ?? null)) {
                throw new \InvalidArgumentException(esc_html("No element at path '{$path}'."));
            }
            $node = $list[ $i ];
            $list = $depth < count($indexes) - 1 ? (is_array($node['_modules'] ?? null) ? $node['_modules'] : []) : [];
        }

        return $node;
    }

    /** The type of the element at $path, or 'region' for '' (the top level). */
    public static function type_at(array $elements, string $path): string
    {
        return '' === $path ? 'region' : (string) (self::node($elements, $path)['_type'] ?? '');
    }

    /**
     * Merge values into an element: each key replaces, a null value removes
     * the key. `_type` and `_modules` cannot be changed this way.
     *
     * @param array<string,mixed> $attrs
     */
    public static function update(array $elements, string $path, array $attrs): array
    {
        if ([] === $attrs || array_is_list($attrs)) {
            throw new \InvalidArgumentException(esc_html('update needs attrs: an object of the values to set (null removes a key).'));
        }
        foreach (array_keys($attrs) as $key) {
            if (in_array($key, self::PROTECTED_KEYS, true)) {
                throw new \InvalidArgumentException(esc_html("update cannot change {$key}; use add, move or remove."));
            }
        }
        $node = self::node($elements, $path);
        foreach ($attrs as $key => $value) {
            if (null === $value) {
                unset($node[ $key ]);
            } else {
                $node[ $key ] = $value;
            }
        }

        return self::put($elements, self::indexes($path), $node);
    }

    /**
     * Insert elements under $to ('' is the top level) at $index (null
     * appends), each placement checked with $placement.
     *
     * @param array<int,array<string,mixed>> $new
     * @return array{0:array,1:string} the list and the first new element's path
     */
    public static function insert(array $elements, string $to, ?int $index, array $new, callable $placement): array
    {
        $parent   = self::type_at($elements, $to);
        $children = self::children($elements, $to);
        foreach ($new as $element) {
            self::validate([$element], $placement, $parent, '' === $to ? 'the top level' : "path '{$to}'");
        }
        $count = count($children);
        $at    = null === $index ? $count : $index;
        if ($at < 0 || $at > $count) {
            throw new \InvalidArgumentException(esc_html("index {$at} is out of range: '" . ('' === $to ? 'the top level' : $to) . "' has {$count} children."));
        }
        array_splice($children, $at, 0, $new);

        return [self::set_children($elements, $to, $children), ('' === $to ? '' : $to . '.') . $at];
    }

    public static function remove(array $elements, string $path): array
    {
        $indexes = self::indexes($path);
        self::node($elements, $path);
        $last     = array_pop($indexes);
        $parent   = implode('.', $indexes);
        $children = self::children($elements, $parent);
        array_splice($children, $last, 1);

        return self::set_children($elements, $parent, $children);
    }

    /**
     * Move the element at $path under $to at $index, where $to and $index
     * are read against the list as it is before the move.
     *
     * @return array{0:array,1:string} the list and the element's new path
     */
    public static function move(array $elements, string $path, string $to, ?int $index, callable $placement): array
    {
        $node = self::node($elements, $path);
        if ($to === $path || 0 === strpos($to . '.', $path . '.')) {
            throw new \InvalidArgumentException(esc_html('An element cannot be moved into itself.'));
        }
        $from     = self::indexes($path);
        $target   = '' === $to ? [] : self::indexes($to);
        self::children($elements, $to);
        $siblings = count($from) - 1 === count($target) && array_slice($from, 0, -1) === $target;
        $count    = count(self::children($elements, $to));
        $at       = null === $index ? $count : $index;
        if ($at < 0 || $at > $count) {
            throw new \InvalidArgumentException(esc_html("index {$at} is out of range: '" . ('' === $to ? 'the top level' : $to) . "' has {$count} children."));
        }

        // Removing the element shifts later siblings of any ancestor of $to
        // that comes after it, and the index itself when $to is its parent.
        $last = end($from);
        $depth = count($from) - 1;
        if (count($target) > $depth && array_slice($target, 0, $depth) === array_slice($from, 0, $depth) && $target[ $depth ] > $last) {
            $target[ $depth ]--;
        }
        if ($siblings && $at > $last) {
            $at--;
        }

        $without = self::remove($elements, $path);

        return self::insert($without, implode('.', $target), $at, [$node], $placement);
    }

    /** @return int[] */
    private static function indexes(string $path): array
    {
        if (! preg_match('/^\d+(\.\d+)*$/', $path)) {
            throw new \InvalidArgumentException(esc_html("A path is dotted child indexes such as 0.1 (see the tree in get-builder-content); got '{$path}'."));
        }

        return array_map('intval', explode('.', $path));
    }

    /** @return array<int,mixed> the children of $path ('' is the top level) */
    private static function children(array $elements, string $path): array
    {
        if ('' === $path) {
            return $elements;
        }
        $node = self::node($elements, $path);
        if (array_key_exists('_modules', $node) && ! is_array($node['_modules'])) {
            throw new \InvalidArgumentException(esc_html("The element at '{$path}' has malformed children."));
        }

        return is_array($node['_modules'] ?? null) ? $node['_modules'] : [];
    }

    private static function set_children(array $elements, string $path, array $children): array
    {
        if ('' === $path) {
            return array_values($children);
        }
        $node             = self::node($elements, $path);
        $node['_modules'] = array_values($children);

        return self::put($elements, self::indexes($path), $node);
    }

    /** @param int[] $indexes */
    private static function put(array $list, array $indexes, array $node): array
    {
        $i = array_shift($indexes);
        if ([] === $indexes) {
            $list[ $i ] = $node;
            return $list;
        }
        $list[ $i ]['_modules'] = self::put($list[ $i ]['_modules'], $indexes, $node);

        return $list;
    }
}
