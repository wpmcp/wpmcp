<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Tree view and editor for a Breakdance engine document: the JSON the
 * engine keeps in the `tree_json_string` key of its `_breakdance_data`
 * postmeta, or `_oxygen_data` for Oxygen 6, which runs on the same engine.
 * Pure data work, so it runs without either plugin loaded.
 *
 * The document is {root, _nextNodeId, status, ...}. `root` is a node with
 * data.type "root" and id 1 (Breakdance) or 0 (Oxygen 6); every node id is
 * above the root's. Every node is {id, data: {type, properties},
 * children, _parentId}, where `id` is an integer unique in the document,
 * `data.type` is the element class (EssentialElements\Heading,
 * OxygenElements\Text and so on),
 * `data.properties` holds the element's settings (nested objects with
 * per-breakpoint values, or null) and `_parentId` is the parent's id. New
 * ids come from `_nextNodeId`, which only ever grows. Any other key a node or
 * the document carries is kept as it is.
 *
 * The document is decoded to objects rather than arrays, so an empty object
 * stays {} and an empty list stays []. It is encoded the way the builder's
 * editor serializes it (unescaped slashes and Unicode), so an edit leaves the
 * bytes of every untouched node exactly as they were.
 *
 * Nodes are addressed by id, as a string (the MCP argument type). The only
 * placement rule enforced is Breakdance's column pairing: a Column sits
 * directly in a Columns element and a Columns element holds only Columns;
 * other nesting rules live in each element's definition inside the plugin.
 */
class Breakdance_Tree
{
    public const COLUMNS = 'EssentialElements\\Columns';
    public const COLUMN  = 'EssentialElements\\Column';

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS;

    /** Parse a tree_json_string; null unless it has a root with id, data and children. */
    public static function decode(string $json): ?object
    {
        $doc = json_decode($json);
        if (! is_object($doc) || ! isset($doc->root) || ! is_object($doc->root)) {
            return null;
        }
        $root = $doc->root;
        if (! property_exists($root, 'id') || ! property_exists($root, 'data') || ! isset($root->children) || ! is_array($root->children)) {
            return null;
        }

        return $doc;
    }

    public static function encode(object $doc): string
    {
        return (string) wp_json_encode($doc, self::JSON_FLAGS);
    }

    /** An empty document, as the builder starts one. */
    public static function blank(): object
    {
        return (object) [
            'root'        => (object) [
                'id'       => 1,
                'data'     => (object) ['type' => 'root', 'properties' => []],
                'children' => [],
            ],
            '_nextNodeId' => 100,
            'status'      => 'exported',
        ];
    }

    /** @return object[] the top-level nodes, as stored */
    public static function nodes(object $doc): array
    {
        return $doc->root->children;
    }

    /**
     * Replace every top-level node. Each item is a stored node (as nodes()
     * returns it) or a spec {type, properties?, children?}; nodes that carry
     * an id keep it, the rest get new ones.
     *
     * @param mixed $list
     */
    public static function replace(object $doc, $list): object
    {
        if (! is_array($list) || ! array_is_list($list)) {
            throw new \InvalidArgumentException(esc_html('Content must be a JSON array of top-level nodes.'));
        }

        $doc  = self::copy($doc);
        $root = $doc->root;
        $root->children = [];

        // Ids carried by the list are reserved first, so a new node never takes one.
        $taken = [];
        self::collect_ids($list, $taken);
        $doc->_nextNodeId = max(self::next_id($doc), [] === $taken ? 0 : max(array_keys($taken)) + 1);

        foreach ($list as $spec) {
            $root->children[] = self::build($doc, $spec, $root);
        }

        return $doc;
    }

    /**
     * Deep-merge properties into a node: objects merge key by key, anything
     * else (lists included) replaces, and a null value removes that key.
     *
     * @param array<string,mixed> $attrs
     */
    public static function update(object $doc, string $id, array $attrs): object
    {
        if ([] === $attrs) {
            throw new \InvalidArgumentException(esc_html('Provide attrs: the properties to merge.'));
        }

        $doc  = self::copy($doc);
        $node = self::require_node($doc, $id);

        $current = isset($node->data->properties) && is_object($node->data->properties) ? $node->data->properties : new \stdClass();
        $node->data->properties = self::merge($current, $attrs);

        return $doc;
    }

    /**
     * Insert a new node (with optional children) under `to` ('' for the
     * root) at `index` (append when null).
     *
     * @param array<string,mixed> $spec
     * @return array{0:object,1:string} document, new node id
     */
    public static function add(object $doc, string $to, ?int $index, array $spec): array
    {
        $doc    = self::copy($doc);
        $parent = '' === $to ? $doc->root : self::require_node($doc, $to, true);
        $index  = self::clamp_index($index, count($parent->children));

        $node = self::build($doc, (object) $spec, $parent, true);
        array_splice($parent->children, $index, 0, [$node]);

        return [$doc, (string) $node->id];
    }

    /** Remove a node and everything under it. */
    public static function remove(object $doc, string $id): object
    {
        $doc = self::copy($doc);
        [$parent, $position] = self::locate($doc, $id);
        array_splice($parent->children, $position, 1);

        return $doc;
    }

    /**
     * Move a node (with its subtree) under `to` ('' for the root) at its
     * final `index` among the new siblings (append when null).
     */
    public static function move(object $doc, string $id, string $to, ?int $index): object
    {
        $doc = self::copy($doc);
        [$old_parent, $position] = self::locate($doc, $id);
        $node   = $old_parent->children[$position];
        $parent = '' === $to ? $doc->root : self::require_node($doc, $to, true);

        if (null !== self::find($node, (int) $parent->id)) {
            throw new \InvalidArgumentException(esc_html("Cannot move node {$id} inside itself."));
        }
        self::assert_placement((string) $node->data->type, $parent);

        array_splice($old_parent->children, $position, 1);
        $index = self::clamp_index($index, count($parent->children));
        array_splice($parent->children, $index, 0, [$node]);
        $node->_parentId = $parent->id;

        return $doc;
    }

    // ---------------------------------------------------------------- internals

    /**
     * Turn a stored node or a spec into a stored node under `parent`,
     * recursively.
     *
     * @param mixed $spec
     */
    private static function build(object $doc, $spec, object $parent, bool $fresh_ids = false): object
    {
        if (! is_object($spec) && ! is_array($spec)) {
            throw new \InvalidArgumentException(esc_html('Each node must be an object: {type, properties?, children?}.'));
        }
        $spec = self::to_object($spec);

        if (isset($spec->data)) {
            $node = $spec;
            if (! is_object($node->data)) {
                throw new \InvalidArgumentException(esc_html('A node\'s data must be an object: {type, properties}.'));
            }
            $type = $node->data->type ?? null;
            if (! property_exists($node->data, 'properties')) {
                $node->data->properties = null;
            }
        } else {
            $type = $spec->type ?? null;
            $node = new \stdClass();
            if (isset($spec->id)) {
                $node->id = $spec->id;
            }
            $node->data = (object) ['type' => $type, 'properties' => $spec->properties ?? null];
        }

        if (! is_string($type) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)+$/', $type)) {
            throw new \InvalidArgumentException(esc_html('Node type must be an element class, such as EssentialElements\\Heading or OxygenElements\\Text.'));
        }
        if (null !== $node->data->properties && ! is_object($node->data->properties)) {
            throw new \InvalidArgumentException(esc_html('Node properties must be an object or null.'));
        }
        self::assert_placement($type, $parent);

        if ($fresh_ids || ! property_exists($node, 'id')) {
            $node = self::with_id_first($node, self::next_id($doc));
            $doc->_nextNodeId = $node->id + 1;
        } elseif (! is_int($node->id) || $node->id <= self::root_id($doc)) {
            throw new \InvalidArgumentException(esc_html('A node id must be an integer above the root\'s id (' . self::root_id($doc) . ').'));
        }

        $children = $spec->children ?? [];
        if (! is_array($children) || ! array_is_list($children)) {
            throw new \InvalidArgumentException(esc_html('children must be an array of nodes.'));
        }
        $node->children = [];
        foreach ($children as $child) {
            $node->children[] = self::build($doc, $child, $node, $fresh_ids);
        }
        $node->_parentId = $parent->id;

        return $node;
    }

    /** A copy of a node with `id` as its first key, the way the builder orders them. */
    private static function with_id_first(object $node, int $id): object
    {
        $out     = new \stdClass();
        $out->id = $id;
        foreach (get_object_vars($node) as $key => $value) {
            if ('id' !== $key) {
                $out->{$key} = $value;
            }
        }

        return $out;
    }

    /**
     * Columns hold only Columns' Column children, and a Column only sits in
     * a Columns element.
     */
    private static function assert_placement(string $type, object $parent): void
    {
        $parent_type = (string) ($parent->data->type ?? '');

        if (self::COLUMN === $type && self::COLUMNS !== $parent_type) {
            throw new \InvalidArgumentException(esc_html('A Column can only go directly inside a Columns element.'));
        }
        if (self::COLUMNS === $parent_type && self::COLUMN !== $type) {
            throw new \InvalidArgumentException(esc_html('A Columns element can only hold Column elements.'));
        }
    }

    /**
     * Deep merge for update(); `$base` is a detached copy, so it is edited
     * in place.
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

    /** @return array{0:object,1:int} the parent node and the position in its children */
    private static function locate(object $doc, string $id): array
    {
        $target = self::parse_id($id);
        $stack  = [$doc->root];
        while ($stack) {
            $node = array_pop($stack);
            foreach ($node->children as $position => $child) {
                if (is_object($child) && ($child->id ?? null) === $target) {
                    return [$node, $position];
                }
                $stack[] = $child;
            }
        }

        throw new \InvalidArgumentException(esc_html("No node with id {$id}."));
    }

    private static function require_node(object $doc, string $id, bool $allow_root = false): object
    {
        if ($allow_root && ctype_digit($id) && (int) $id === $doc->root->id) {
            return $doc->root;
        }
        [$parent, $position] = self::locate($doc, $id);

        return $parent->children[$position];
    }

    private static function find(object $node, int $id): ?object
    {
        if (($node->id ?? null) === $id) {
            return $node;
        }
        foreach ($node->children ?? [] as $child) {
            if (is_object($child) && null !== ($found = self::find($child, $id))) {
                return $found;
            }
        }

        return null;
    }

    /** The root's id: 1 in Breakdance documents, 0 in Oxygen 6 ones. */
    private static function root_id(object $doc): int
    {
        return is_int($doc->root->id ?? null) ? $doc->root->id : 1;
    }

    private static function parse_id(string $id): int
    {
        if (! ctype_digit($id)) {
            throw new \InvalidArgumentException(esc_html("No node with id {$id}."));
        }

        return (int) $id;
    }

    /** The next free id: the stored counter, never below one past the largest id. */
    private static function next_id(object $doc): int
    {
        $ids = [];
        self::collect_ids([$doc->root], $ids);
        $max = [] === $ids ? 1 : max(array_keys($ids));

        return max(is_int($doc->_nextNodeId ?? null) ? $doc->_nextNodeId : 0, $max + 1);
    }

    /**
     * Every integer id in a list of nodes, as keys; a repeated id is refused.
     *
     * @param array<int,mixed> $nodes
     * @param array<int,true>  $ids
     */
    private static function collect_ids(array $nodes, array &$ids): void
    {
        foreach ($nodes as $node) {
            if (! is_object($node) && ! is_array($node)) {
                continue;
            }
            $node = (object) $node;
            if (isset($node->id) && is_int($node->id)) {
                if (isset($ids[$node->id])) {
                    throw new \InvalidArgumentException(esc_html("Node id {$node->id} is used twice."));
                }
                $ids[$node->id] = true;
            }
            if (isset($node->children) && is_array($node->children)) {
                self::collect_ids($node->children, $ids);
            }
        }
    }

    private static function clamp_index(?int $index, int $count): int
    {
        if (null === $index) {
            return $count;
        }
        if ($index < 0 || $index > $count) {
            throw new \InvalidArgumentException(esc_html("index must be between 0 and {$count}."));
        }

        return $index;
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
        if (is_object($value)) {
            $out = new \stdClass();
            foreach (get_object_vars($value) as $key => $item) {
                $out->{$key} = self::to_object($item);
            }

            return $out;
        }
        if (is_array($value)) {
            if ([] === $value || array_is_list($value)) {
                return array_map([self::class, 'to_object'], $value);
            }

            return self::to_object((object) $value);
        }

        return $value;
    }

    /** A deep copy, so edits never reach the caller's objects. */
    private static function copy(object $doc): object
    {
        return self::to_object($doc);
    }
}
