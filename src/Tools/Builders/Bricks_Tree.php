<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Element operations on a Bricks layout row (issue #633). Bricks stores a
 * layout as a flat list of elements, each {id, name, parent, children,
 * settings, label?}: `parent` is 0 at the top level or the parent's id,
 * `children` lists the child ids in order, and the top-level order is the
 * order of the parentless elements in the list (Frontend::render_data).
 * Ids are six lowercase characters (Helpers::generate_random_id).
 *
 * Every operation takes the list, checks it is a tree, and returns a new
 * list written depth first, the order Bricks' editor saves in. Nothing here
 * reads or writes the database.
 */
final class Bricks_Tree
{
    /**
     * The elements by id, after checking the list is a tree: unique ids,
     * every parent and child present and the two links agreeing.
     *
     * @param array<int|string,mixed> $elements
     * @return array<string,array<string,mixed>>
     * @throws \InvalidArgumentException
     */
    public static function index(array $elements): array
    {
        $by_id = [];
        foreach ($elements as $element) {
            $id = is_array($element) ? (string) ($element['id'] ?? '') : '';
            if ('' === $id || ! is_string($element['name'] ?? null) || isset($by_id[$id])) {
                throw new \InvalidArgumentException(esc_html('The stored Bricks layout is not a list of elements with unique ids and names.'));
            }
            $element['children'] = array_values(array_map('strval', is_array($element['children'] ?? null) ? $element['children'] : []));
            $by_id[$id]          = $element;
        }

        foreach ($by_id as $id => $element) {
            $parent = self::parent_of($element);
            if ('' !== $parent && ! in_array((string) $id, $by_id[$parent]['children'] ?? [], true)) {
                throw new \InvalidArgumentException(esc_html("The stored Bricks layout is not a tree: element {$id} names parent {$parent}, which does not list it."));
            }
            foreach ($element['children'] as $child) {
                if (! isset($by_id[$child]) || self::parent_of($by_id[$child]) !== (string) $id) {
                    throw new \InvalidArgumentException(esc_html("The stored Bricks layout is not a tree: element {$id} lists child {$child}, which does not name it as parent."));
                }
            }
        }

        return $by_id;
    }

    /**
     * Nested outline of the layout: id, name, label when set, children.
     *
     * @param array<int|string,mixed> $elements
     * @return array<int,array<string,mixed>>
     */
    public static function outline(array $elements): array
    {
        try {
            $by_id = self::index($elements);
        } catch (\InvalidArgumentException $e) {
            unset($e);
            return [];
        }

        $build = static function (string $id) use (&$build, $by_id): array {
            $node = ['id' => $id, 'name' => (string) $by_id[$id]['name']];
            if (isset($by_id[$id]['label']) && '' !== (string) $by_id[$id]['label']) {
                $node['label'] = (string) $by_id[$id]['label'];
            }
            $node['children'] = array_map($build, $by_id[$id]['children']);

            return $node;
        };

        return array_map($build, self::roots($by_id));
    }

    /**
     * Merge settings into one element (null removes a key) and set or clear
     * its label.
     *
     * @param array<int|string,mixed> $elements
     * @param array<string,mixed>     $attrs
     * @param array<string,mixed>     $fields only `label`
     * @return array<int,array<string,mixed>>
     */
    public static function update(array $elements, string $id, array $attrs, array $fields = []): array
    {
        $by_id = self::index($elements);
        self::require_id($by_id, $id);
        if ([] !== array_diff(array_keys($fields), ['label'])) {
            throw new \InvalidArgumentException(esc_html('update takes element {label} only; settings go in attrs.'));
        }

        $settings = is_array($by_id[$id]['settings'] ?? null) ? $by_id[$id]['settings'] : [];
        foreach ($attrs as $key => $value) {
            if (null === $value) {
                unset($settings[$key]);
            } else {
                $settings[$key] = $value;
            }
        }
        $by_id[$id]['settings'] = $settings;

        if (array_key_exists('label', $fields)) {
            if (null === $fields['label'] || '' === (string) $fields['label']) {
                unset($by_id[$id]['label']);
            } else {
                $by_id[$id]['label'] = sanitize_text_field((string) $fields['label']);
            }
        }

        return self::flatten($by_id, self::roots($by_id));
    }

    /**
     * Add an element (with nested children) under `to` ('' is the top
     * level) at `index` (null or past the end appends).
     *
     * @param array<int|string,mixed> $elements
     * @param array<string,mixed>     $spec {name, settings?, label?, children?: spec[]}
     * @return array{0:array<int,array<string,mixed>>,1:string} new list and the new element's id
     */
    public static function add(array $elements, string $to, ?int $index, array $spec): array
    {
        $by_id = self::index($elements);
        $roots = self::roots($by_id);
        if ('' !== $to) {
            self::require_id($by_id, $to);
        }

        $new_id = self::insert_spec($by_id, $spec, $to);
        $roots  = self::place($by_id, $roots, $to, $index, $new_id);

        return [self::flatten($by_id, $roots), $new_id];
    }

    /**
     * Add copies of a list's top-level elements (with their descendants)
     * under `to` from `index` on, every copied element given a new id.
     *
     * @param array<int|string,mixed> $elements
     * @param array<int|string,mixed> $source
     * @return array{0:array<int,array<string,mixed>>,1:string} new list and the first copy's id
     */
    public static function insert_copy(array $elements, string $to, ?int $index, array $source): array
    {
        $by_id  = self::index($elements);
        $roots  = self::roots($by_id);
        $source = self::index($source);
        if ('' !== $to) {
            self::require_id($by_id, $to);
        }
        if ([] === $source) {
            throw new \InvalidArgumentException(esc_html('The template holds no elements.'));
        }

        $first = null;
        foreach (self::roots($source) as $offset => $root) {
            $copy  = self::copy($source, $root, $to, $by_id);
            $roots = self::place($by_id, $roots, $to, null === $index ? null : $index + $offset, $copy);
            $first = $first ?? $copy;
        }

        return [self::flatten($by_id, $roots), (string) $first];
    }

    /**
     * One element and its descendants as a standalone list with new ids,
     * the element at the top level.
     *
     * @param array<int|string,mixed> $elements
     * @return array<int,array<string,mixed>>
     */
    public static function extract(array $elements, string $id): array
    {
        $by_id = self::index($elements);
        self::require_id($by_id, $id);
        $out  = [];
        $copy = self::copy($by_id, $id, '', $out);

        return self::flatten($out, [$copy]);
    }

    /**
     * A whole list with every id replaced, links kept.
     *
     * @param array<int|string,mixed> $elements
     * @return array<int,array<string,mixed>>
     */
    public static function renumber(array $elements): array
    {
        $by_id = self::index($elements);
        $out   = [];
        $roots = [];
        foreach (self::roots($by_id) as $root) {
            $roots[] = self::copy($by_id, $root, '', $out);
        }

        return self::flatten($out, $roots);
    }

    /**
     * Remove an element and its descendants.
     *
     * @param array<int|string,mixed> $elements
     * @return array<int,array<string,mixed>>
     */
    public static function remove(array $elements, string $id): array
    {
        $by_id = self::index($elements);
        self::require_id($by_id, $id);
        $roots  = self::detach($by_id, self::roots($by_id), $id);
        $remove = [$id];
        while ([] !== $remove) {
            $next = array_pop($remove);
            $remove = array_merge($remove, $by_id[$next]['children']);
            unset($by_id[$next]);
        }

        return self::flatten($by_id, $roots);
    }

    /**
     * Move an element (with its descendants) under `to` at `index`.
     *
     * @param array<int|string,mixed> $elements
     * @return array<int,array<string,mixed>>
     */
    public static function move(array $elements, string $id, string $to, ?int $index): array
    {
        $by_id = self::index($elements);
        self::require_id($by_id, $id);
        if ('' !== $to) {
            self::require_id($by_id, $to);
            for ($at = $to; '' !== $at; $at = self::parent_of($by_id[$at])) {
                if ($at === $id) {
                    throw new \InvalidArgumentException(esc_html("Element {$id} cannot move into itself or one of its own descendants."));
                }
            }
        }

        $roots = self::detach($by_id, self::roots($by_id), $id);
        $roots = self::place($by_id, $roots, $to, $index, $id);

        return self::flatten($by_id, $roots);
    }

    // ----------------------------------------------------------- internals

    /** @param array<string,mixed> $element */
    private static function parent_of(array $element): string
    {
        $parent = $element['parent'] ?? 0;

        return empty($parent) ? '' : (string) $parent;
    }

    /**
     * @param array<string,array<string,mixed>> $by_id
     * @return string[] the top-level ids in stored order
     */
    private static function roots(array $by_id): array
    {
        $roots = [];
        foreach ($by_id as $id => $element) {
            if ('' === self::parent_of($element)) {
                $roots[] = (string) $id;
            }
        }

        return $roots;
    }

    /** @param array<string,array<string,mixed>> $by_id */
    private static function require_id(array $by_id, string $id): void
    {
        if ('' === $id || ! isset($by_id[$id])) {
            throw new \InvalidArgumentException(esc_html("No element with id '{$id}' on this page; read get-builder-content for the ids in tree."));
        }
    }

    /**
     * Take an element out of its parent's children (or the top level).
     *
     * @param array<string,array<string,mixed>> $by_id
     * @param string[]                           $roots
     * @return string[] roots
     */
    private static function detach(array &$by_id, array $roots, string $id): array
    {
        $parent = self::parent_of($by_id[$id]);
        if ('' === $parent) {
            return array_values(array_diff($roots, [$id]));
        }
        $by_id[$parent]['children'] = array_values(array_diff($by_id[$parent]['children'], [$id]));

        return $roots;
    }

    /**
     * Put an element under `to` at `index` and set its parent.
     *
     * @param array<string,array<string,mixed>> $by_id
     * @param string[]                           $roots
     * @return string[] roots
     */
    private static function place(array &$by_id, array $roots, string $to, ?int $index, string $id): array
    {
        $by_id[$id]['parent'] = '' === $to ? 0 : $to;
        $list                 = '' === $to ? $roots : $by_id[$to]['children'];
        $at                   = null === $index ? count($list) : max(0, min($index, count($list)));
        array_splice($list, $at, 0, [$id]);
        if ('' === $to) {
            return $list;
        }
        $by_id[$to]['children'] = $list;

        return $roots;
    }

    /**
     * Store an element spec and its nested children; return the new id.
     *
     * @param array<string,array<string,mixed>> $by_id
     * @param mixed                              $spec
     */
    private static function insert_spec(array &$by_id, $spec, string $parent): string
    {
        $name = is_array($spec) && is_string($spec['name'] ?? null) ? trim($spec['name']) : '';
        if ('' === $name || ! preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
            throw new \InvalidArgumentException(esc_html('An element needs name: a Bricks element name such as section, container, heading (see get-builder-content scope catalog).'));
        }
        $settings = $spec['settings'] ?? [];
        $children = $spec['children'] ?? [];
        if (! is_array($settings) || ! is_array($children)) {
            throw new \InvalidArgumentException(esc_html('An element takes settings as an object and children as a list of elements.'));
        }

        $id      = self::new_id($by_id);
        $element = ['id' => $id, 'name' => $name, 'parent' => '' === $parent ? 0 : $parent, 'children' => [], 'settings' => $settings];
        if (is_string($spec['label'] ?? null) && '' !== trim($spec['label'])) {
            $element['label'] = sanitize_text_field($spec['label']);
        }
        $by_id[$id] = $element;

        foreach (array_values($children) as $child) {
            $child_id                 = self::insert_spec($by_id, $child, $id);
            $by_id[$id]['children'][] = $child_id;
        }

        return $id;
    }

    /**
     * Copy one element of $source and its descendants into $into with new
     * ids; return the copy's id.
     *
     * @param array<string,array<string,mixed>> $source
     * @param array<string,array<string,mixed>> $into
     */
    private static function copy(array $source, string $id, string $parent, array &$into): string
    {
        $new                 = self::new_id($into);
        $element             = $source[$id];
        $element['id']       = $new;
        $element['parent']   = '' === $parent ? 0 : $parent;
        $element['children'] = [];
        $into[$new]          = $element;
        foreach ($source[$id]['children'] as $child) {
            $child_id                 = self::copy($source, $child, $new, $into);
            $into[$new]['children'][] = $child_id;
        }

        return $new;
    }

    /** @param array<string,mixed> $taken */
    private static function new_id(array $taken): string
    {
        do {
            $id = substr(bin2hex(random_bytes(4)), 0, 6);
        } while (isset($taken[$id]) || ctype_digit($id));

        return $id;
    }

    /**
     * The list depth first from the roots, the order Bricks saves in.
     *
     * @param array<string,array<string,mixed>> $by_id
     * @param string[]                           $roots
     * @return array<int,array<string,mixed>>
     */
    private static function flatten(array $by_id, array $roots): array
    {
        $out  = [];
        $walk = static function (string $id) use (&$walk, &$out, $by_id): void {
            $out[] = $by_id[$id];
            foreach ($by_id[$id]['children'] as $child) {
                $walk($child);
            }
        };
        foreach ($roots as $root) {
            $walk($root);
        }

        return $out;
    }
}
