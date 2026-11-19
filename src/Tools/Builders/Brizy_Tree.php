<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Element operations over a Brizy page's editor data (issue #645), pure
 * array work with no plugin calls.
 *
 * Recorded from Brizy 2.8.26 (its editor build and Brizy_Editor_Post): the
 * page data is a JSON object whose `items` list holds the blocks; a block is
 * {blockId, type, value} with type Section, SectionHeader, SectionFooter,
 * SectionPopup, SectionPopup2, SectionMegaMenu or GlobalBlock (a reference to
 * a global block, whose value `_id` is that block's uid), and every element
 * below is {type, value} with its children in `value.items`. Elements are
 * addressed by `value._id`, which Brizy generates as 36 lowercase letters.
 */
final class Brizy_Tree
{
    /** The block types Brizy keeps at the top of a page, and only there. */
    public const BLOCK_TYPES = ['Section', 'SectionHeader', 'SectionFooter', 'SectionPopup', 'SectionPopup2', 'SectionMegaMenu', 'GlobalBlock'];

    /** The blockId the editor gives a block made from scratch. */
    public const BLANK_BLOCK_ID = 'Kit2Blank000Light';

    /** A fresh element id in Brizy's own format (36 lowercase letters). */
    public static function new_id(): string
    {
        $id = '';
        for ($i = 0; $i < 36; $i++) {
            $id .= chr(97 + wp_rand(0, 25));
        }

        return $id;
    }

    /**
     * A whole page (an object with `items`, or a bare list of blocks)
     * checked and normalised: every element a {type, value}, blocks only at
     * the top, missing ids filled, duplicate ids refused.
     *
     * @param array<int|string,mixed> $data
     * @return array<string,mixed>
     * @throws \InvalidArgumentException
     */
    public static function validate(array $data): array
    {
        if (array_is_list($data)) {
            $data = ['items' => $data];
        }
        if (! isset($data['items']) || ! is_array($data['items']) || ! array_is_list($data['items'])) {
            throw new \InvalidArgumentException(esc_html('Brizy page data needs an items list of blocks.'));
        }
        $seen = [];
        foreach ($data['items'] as $i => $block) {
            $data['items'][ $i ] = self::check_node($block, true, $seen, (string) $i);
        }

        return $data;
    }

    /**
     * @param mixed                $node
     * @param array<string,bool>   $seen
     * @return array<string,mixed>
     * @throws \InvalidArgumentException
     */
    private static function check_node($node, bool $top, array &$seen, string $where): array
    {
        if (! is_array($node) || ! is_string($node['type'] ?? null) || '' === $node['type']) {
            throw new \InvalidArgumentException(esc_html("Element at {$where} needs a type."));
        }
        $type = $node['type'];
        if (! isset($node['value'])) {
            $node['value'] = [];
        }
        if (! is_array($node['value'])) {
            throw new \InvalidArgumentException(esc_html("Element at {$where} ({$type}) needs value to be an object."));
        }
        $is_block = in_array($type, self::BLOCK_TYPES, true);
        if ($top && ! $is_block) {
            throw new \InvalidArgumentException(esc_html("Type '{$type}' cannot sit at the top of a Brizy page; the top holds blocks (" . implode(', ', self::BLOCK_TYPES) . ').'));
        }
        if (! $top && $is_block) {
            throw new \InvalidArgumentException(esc_html("Block type '{$type}' can only sit at the top of a Brizy page."));
        }
        if ($top && (! is_string($node['blockId'] ?? null) || '' === $node['blockId'])) {
            $node['blockId'] = self::BLANK_BLOCK_ID;
        }
        $id = $node['value']['_id'] ?? null;
        if (null === $id || '' === $id) {
            $id = self::new_id();
            while (isset($seen[ $id ])) {
                $id = self::new_id();
            }
            $node['value']['_id'] = $id;
        }
        if (! is_string($id)) {
            throw new \InvalidArgumentException(esc_html("Element at {$where} ({$type}) has an _id that is not a string."));
        }
        if (isset($seen[ $id ])) {
            throw new \InvalidArgumentException(esc_html("Element id '{$id}' appears twice; ids must be unique on a page."));
        }
        $seen[ $id ] = true;
        if (isset($node['value']['items'])) {
            if (! is_array($node['value']['items']) || ! array_is_list($node['value']['items'])) {
                throw new \InvalidArgumentException(esc_html("Element '{$id}' ({$type}) has items that are not a list."));
            }
            foreach ($node['value']['items'] as $i => $child) {
                $node['value']['items'][ $i ] = self::check_node($child, false, $seen, "{$where}.{$i}");
            }
        }

        return $node;
    }

    /**
     * Every element id on the page.
     *
     * @param array<string,mixed> $data
     * @return array<string,bool>
     */
    public static function ids(array $data): array
    {
        $ids  = [];
        $walk = static function (array $items) use (&$walk, &$ids): void {
            foreach ($items as $node) {
                if (! is_array($node)) {
                    continue;
                }
                $id = $node['value']['_id'] ?? null;
                if (is_string($id)) {
                    $ids[ $id ] = true;
                }
                if (is_array($node['value']['items'] ?? null)) {
                    $walk($node['value']['items']);
                }
            }
        };
        $walk(is_array($data['items'] ?? null) ? $data['items'] : []);

        return $ids;
    }

    /**
     * A compact outline of the page: id, type, a short label for text
     * elements and the children, which is what operations address.
     *
     * @param array<string,mixed> $data
     * @return array<int,array<string,mixed>>
     */
    public static function outline(array $data): array
    {
        $walk = static function (array $items) use (&$walk): array {
            $out = [];
            foreach ($items as $node) {
                if (! is_array($node) || ! is_array($node['value'] ?? null)) {
                    continue;
                }
                $value = $node['value'];
                $entry = ['id' => (string) ($value['_id'] ?? ''), 'type' => (string) ($node['type'] ?? '')];
                if (isset($node['blockId']) && is_string($node['blockId'])) {
                    $entry['block_id'] = $node['blockId'];
                }
                $text = is_string($value['text'] ?? null) ? trim(wp_strip_all_tags($value['text'])) : '';
                if ('' !== $text) {
                    $entry['label'] = mb_substr($text, 0, 80);
                }
                if (is_array($value['items'] ?? null) && [] !== $value['items']) {
                    $entry['children'] = $walk($value['items']);
                }
                $out[] = $entry;
            }
            return $out;
        };

        return $walk(is_array($data['items'] ?? null) ? $data['items'] : []);
    }

    /**
     * The index path (into nested `items` lists) of an element.
     *
     * @param array<string,mixed> $data
     * @return int[]
     * @throws \InvalidArgumentException
     */
    public static function locate(array $data, string $id): array
    {
        if ('' === $id) {
            throw new \InvalidArgumentException(esc_html('path must be an element id (value._id) from the read.'));
        }
        $find = static function (array $items, array $prefix) use (&$find, $id): ?array {
            foreach ($items as $i => $node) {
                if (! is_array($node)) {
                    continue;
                }
                if (($node['value']['_id'] ?? null) === $id) {
                    return array_merge($prefix, [$i]);
                }
                if (is_array($node['value']['items'] ?? null)) {
                    $hit = $find($node['value']['items'], array_merge($prefix, [$i]));
                    if (null !== $hit) {
                        return $hit;
                    }
                }
            }
            return null;
        };
        $path = $find($data['items'] ?? [], []);
        if (null === $path) {
            throw new \InvalidArgumentException(esc_html("No element with id '{$id}' on this page; read it again for the ids."));
        }

        return $path;
    }

    /**
     * @param array<string,mixed> $data
     * @param int[]               $path
     * @return array<string,mixed>
     */
    private static function &node_ref(array &$data, array $path): array
    {
        $ref = &$data['items'];
        $last = count($path) - 1;
        foreach ($path as $depth => $i) {
            if ($depth === $last) {
                $ref = &$ref[ $i ];
                break;
            }
            $ref = &$ref[ $i ]['value']['items'];
        }

        return $ref;
    }

    /**
     * Merge fields into an element's value (null removes one). Its id and
     * children are not changed this way.
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $attrs
     * @return array<string,mixed>
     * @throws \InvalidArgumentException
     */
    public static function update(array $data, string $id, array $attrs): array
    {
        $path = self::locate($data, $id);
        $node = &self::node_ref($data, $path);
        if ('GlobalBlock' === $node['type']) {
            throw new \InvalidArgumentException(esc_html("'{$id}' is a global block: its content lives in the global block itself, which this page only references."));
        }
        foreach ($attrs as $key => $value) {
            $key = (string) $key;
            if ('_id' === $key || 'items' === $key) {
                throw new \InvalidArgumentException(esc_html("attrs cannot change {$key}; use add, remove or move for children."));
            }
            if (null === $value) {
                unset($node['value'][ $key ]);
            } else {
                $node['value'][ $key ] = $value;
            }
        }
        unset($node);

        return $data;
    }

    /**
     * Insert elements into a container (or at the top when `to` is ''),
     * each with fresh ids throughout.
     *
     * @param array<string,mixed>              $data
     * @param array<int,array<string,mixed>>   $nodes
     * @return array{0:array<string,mixed>,1:string} the page and the first new id
     * @throws \InvalidArgumentException
     */
    public static function insert(array $data, string $to, ?int $index, array $nodes, bool $keep_ids = false): array
    {
        $taken = self::ids($data);
        $top   = '' === $to;
        $fresh = [];
        foreach ($nodes as $n => $node) {
            $seen = [];
            $node = self::check_node($keep_ids ? $node : self::strip_ids($node), $top, $seen, "element {$n}");
            if (! $keep_ids) {
                $node = self::reid($node, $taken);
            } elseif ([] !== array_intersect_key($seen, $taken)) {
                throw new \InvalidArgumentException(esc_html('That element is already on this page.'));
            }
            $taken  += self::ids(['items' => [$node]]);
            $fresh[] = $node;
        }
        if ([] === $fresh) {
            throw new \InvalidArgumentException(esc_html('Nothing to add.'));
        }

        if ($top) {
            $list = &$data['items'];
        } else {
            $parent = &self::node_ref($data, self::locate($data, $to));
            if (! is_array($parent['value']['items'] ?? null)) {
                throw new \InvalidArgumentException(esc_html("Element '{$to}' ({$parent['type']}) holds no children."));
            }
            $list = &$parent['value']['items'];
        }
        $at = null === $index ? count($list) : max(0, min($index, count($list)));
        array_splice($list, $at, 0, $fresh);
        unset($list, $parent);

        return [$data, (string) $fresh[0]['value']['_id']];
    }

    /**
     * @param array<string,mixed> $node
     * @return array<string,mixed>
     */
    private static function strip_ids(array $node): array
    {
        if ('GlobalBlock' === ($node['type'] ?? null)) {
            return $node;
        }
        if (is_array($node['value'] ?? null)) {
            unset($node['value']['_id']);
            if (is_array($node['value']['items'] ?? null)) {
                foreach ($node['value']['items'] as $i => $child) {
                    $node['value']['items'][ $i ] = is_array($child) ? self::strip_ids($child) : $child;
                }
            }
        }

        return $node;
    }

    /**
     * Give ids that clash with the page fresh ones (ids just filled in by
     * check_node are new already).
     *
     * @param array<string,mixed> $node
     * @param array<string,bool>  $taken
     * @return array<string,mixed>
     */
    private static function reid(array $node, array $taken): array
    {
        if (isset($taken[ $node['value']['_id'] ])) {
            if ('GlobalBlock' === $node['type']) {
                throw new \InvalidArgumentException(esc_html("Global block '{$node['value']['_id']}' is already on this page."));
            }
            $node['value']['_id'] = self::new_id();
        }
        if (is_array($node['value']['items'] ?? null)) {
            foreach ($node['value']['items'] as $i => $child) {
                $node['value']['items'][ $i ] = self::reid($child, $taken);
            }
        }

        return $node;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     * @throws \InvalidArgumentException
     */
    public static function remove(array $data, string $id): array
    {
        $path = self::locate($data, $id);
        $i    = array_pop($path);
        if ([] === $path) {
            array_splice($data['items'], $i, 1);
            return $data;
        }
        $parent = &self::node_ref($data, $path);
        array_splice($parent['value']['items'], $i, 1);
        unset($parent);

        return $data;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     * @throws \InvalidArgumentException
     */
    public static function move(array $data, string $id, string $to, ?int $index): array
    {
        $path = self::locate($data, $id);
        $node = self::node_ref($data, $path);
        if ('' !== $to) {
            $target = self::locate($data, $to);
            if (array_slice($target, 0, count($path)) === $path) {
                throw new \InvalidArgumentException(esc_html('An element cannot move into itself.'));
            }
        }
        $data = self::remove($data, $id);
        [$data] = self::insert($data, $to, $index, [$node], true);

        return $data;
    }
}
