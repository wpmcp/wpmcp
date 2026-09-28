<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Tree view and editor for a Beaver Builder layout: the flat node map it
 * stores in `_fl_builder_data` / `_fl_builder_draft`. Pure array work, so it
 * runs without the Beaver Builder plugin loaded.
 *
 * The stored format is an array keyed by node id, each value a stdClass with
 * `node` (the same id), `type` (row, column-group, column or module),
 * `parent` (a node id, or null for a top-level node), `position` (0-based
 * order among its siblings) and `settings` (a stdClass; a module's
 * `settings->type` is its module slug, such as rich-text or photo). Nodes may
 * carry more properties (template_id, template_node_id, global, version);
 * those are kept as they are.
 *
 * Nodes are addressed by their id, which Beaver Builder keeps stable, so the
 * tree read returns ids and every operation takes ids. An edit only touches
 * the nodes it changes plus the `position` of their siblings, and new nodes
 * are appended to the map, so every other node serializes exactly as before.
 */
class Beaver_Builder_Nodes
{
    public const TYPES = ['row', 'column-group', 'column', 'module'];

    /** Node properties the tree derives from its own shape. */
    private const STRUCTURAL = ['node', 'type', 'parent', 'position', 'settings'];

    /**
     * Nested view: each node as {node, type, settings, ...extra, children}.
     *
     * @param array<string,object> $nodes
     * @return array<int,array<string,mixed>>
     */
    public static function tree(array $nodes, ?string $parent = null): array
    {
        $out = [];
        foreach (self::child_ids($nodes, $parent) as $id) {
            $node = $nodes[$id];
            $item = [
                'node'     => (string) $id,
                'type'     => (string) ($node->type ?? ''),
                'settings' => $node->settings ?? new \stdClass(),
            ];
            foreach (get_object_vars($node) as $key => $value) {
                if (! in_array($key, self::STRUCTURAL, true)) {
                    $item[$key] = $value;
                }
            }
            $children = self::tree($nodes, (string) $id);
            if ([] !== $children) {
                $item['children'] = $children;
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * Build a node map from a tree (the shape tree() returns, or new node
     * specs without ids). Ids that are present are kept.
     *
     * @param mixed $tree
     * @return array<string,object>
     */
    public static function from_tree($tree): array
    {
        if (! is_array($tree) || ! array_is_list($tree)) {
            throw new \InvalidArgumentException(esc_html('Beaver Builder content must be a JSON array of top-level nodes.'));
        }

        $nodes = [];
        foreach ($tree as $position => $spec) {
            self::insert_spec($nodes, $spec, null, (int) $position);
        }

        return $nodes;
    }

    /**
     * Merge settings into a node; a null value removes that key.
     *
     * @param array<string,object> $nodes
     * @param array<string,mixed>  $settings
     * @return array<string,object>
     */
    public static function update(array $nodes, string $id, array $settings): array
    {
        $nodes = self::copy($nodes);
        $node  = self::require_node($nodes, $id);

        if ([] === $settings) {
            throw new \InvalidArgumentException(esc_html('Provide attrs: the settings to merge.'));
        }

        $current = isset($node->settings) && is_object($node->settings) ? $node->settings : new \stdClass();
        foreach ($settings as $key => $value) {
            if (null === $value) {
                unset($current->{$key});
            } else {
                $current->{$key} = self::to_stored($value);
            }
        }
        if ('module' === $node->type && ! is_string($current->type ?? null)) {
            throw new \InvalidArgumentException(esc_html('A module keeps its settings.type (the module slug).'));
        }
        $node->settings = $current;

        return $nodes;
    }

    /**
     * Insert a new node (with optional children) under `to` ('' for top
     * level) at `index` (append when null).
     *
     * @param array<string,object> $nodes
     * @param array<string,mixed>  $spec
     * @return array{0:array<string,object>,1:string} nodes, new node id
     */
    public static function add(array $nodes, string $to, ?int $index, array $spec): array
    {
        $nodes  = self::copy($nodes);
        $parent = '' === $to ? null : self::require_node($nodes, $to)->node;
        $count  = count(self::child_ids($nodes, $parent));
        $index  = self::clamp_index($index, $count);

        $id = self::insert_spec($nodes, $spec, $parent, $count);
        $nodes = self::place($nodes, $id, $parent, $index);

        return [$nodes, $id];
    }

    /**
     * Remove a node and everything under it.
     *
     * @param array<string,object> $nodes
     * @return array<string,object>
     */
    public static function remove(array $nodes, string $id): array
    {
        $nodes  = self::copy($nodes);
        $parent = self::require_node($nodes, $id)->parent;

        foreach (self::subtree_ids($nodes, $id) as $gone) {
            unset($nodes[$gone]);
        }

        return self::renumber($nodes, self::parent_key($parent));
    }

    /**
     * Move a node (with its subtree) under `to` ('' for top level) at its
     * final `index` among the new siblings (append when null).
     *
     * @param array<string,object> $nodes
     * @return array<string,object>
     */
    public static function move(array $nodes, string $id, string $to, ?int $index): array
    {
        $nodes  = self::copy($nodes);
        $node   = self::require_node($nodes, $id);
        $parent = '' === $to ? null : self::require_node($nodes, $to)->node;

        if (null !== $parent && in_array($parent, self::subtree_ids($nodes, $id), true)) {
            throw new \InvalidArgumentException(esc_html("Cannot move node {$id} inside itself."));
        }
        self::assert_placement($node, null === $parent ? null : $nodes[$parent]);

        $old_parent   = self::parent_key($node->parent);
        $node->parent = $parent;
        $nodes        = self::renumber($nodes, $old_parent, $id);

        $count = count(self::child_ids($nodes, $parent)) - 1;

        return self::place($nodes, $id, $parent, self::clamp_index($index, $count));
    }

    /**
     * Stable order of a parent's children: by position, then map order.
     *
     * @param array<string,object> $nodes
     * @return string[]
     */
    public static function child_ids(array $nodes, ?string $parent): array
    {
        $ids   = [];
        $order = 0;
        foreach ($nodes as $id => $node) {
            if (is_object($node) && self::parent_key($node->parent ?? null) === $parent) {
                $ids[] = [(int) ($node->position ?? 0), $order, (string) $id];
            }
            $order++;
        }
        usort($ids, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_column($ids, 2);
    }

    /**
     * A deep copy, so edits never reach the caller's objects.
     *
     * @param array<string,object> $nodes
     * @return array<string,object>
     */
    public static function copy(array $nodes): array
    {
        return self::deep_clone($nodes);
    }

    // ---------------------------------------------------------------- internals

    /** @param array<string,object> $nodes */
    private static function insert_spec(array &$nodes, $spec, ?string $parent, int $position): string
    {
        if (! is_array($spec) && ! is_object($spec)) {
            throw new \InvalidArgumentException(esc_html('Each node must be an object: {type, settings?, children?}.'));
        }
        $spec = (array) $spec;
        $type = $spec['type'] ?? null;
        if (! in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException(esc_html('Node type must be row, column-group, column or module.'));
        }

        $settings = $spec['settings'] ?? [];
        if (! is_array($settings) && ! is_object($settings)) {
            throw new \InvalidArgumentException(esc_html('Node settings must be an object.'));
        }
        $settings = self::to_stored((object) $settings);
        if ('module' === $type && (! is_string($settings->type ?? null) || '' === $settings->type)) {
            throw new \InvalidArgumentException(esc_html('A module needs settings.type (the module slug, such as rich-text).'));
        }

        $id = isset($spec['node']) ? (string) $spec['node'] : self::new_id($nodes);
        if (! preg_match('/^[a-z0-9]{1,32}$/i', $id) || isset($nodes[$id])) {
            throw new \InvalidArgumentException(esc_html("Node id {$id} is invalid or already used."));
        }

        $node           = new \stdClass();
        $node->node     = $id;
        $node->type     = $type;
        $node->parent   = $parent;
        $node->position = $position;
        $node->settings = $settings;
        foreach ($spec as $key => $value) {
            if (! in_array($key, self::STRUCTURAL, true) && 'children' !== $key) {
                $node->{$key} = self::to_stored($value);
            }
        }
        self::assert_placement($node, null === $parent ? null : $nodes[$parent]);
        $nodes[$id] = $node;

        $children = $spec['children'] ?? [];
        if (! is_array($children) || ! array_is_list($children)) {
            throw new \InvalidArgumentException(esc_html('children must be an array of nodes.'));
        }
        foreach ($children as $i => $child) {
            self::insert_spec($nodes, $child, $id, (int) $i);
        }

        return $id;
    }

    /**
     * Where each node type may sit: rows at the top level, column groups in
     * a row or a column (nested columns), columns in a column group, modules
     * in a column, a row, a container module or at the top level (Beaver
     * Builder's container modules, such as Box, go in all of those).
     */
    private static function assert_placement(object $node, ?object $parent): void
    {
        $parent_type = null === $parent ? null : (string) $parent->type;
        $allowed     = [
            'row'          => [null],
            'column-group' => ['row', 'column'],
            'column'       => ['column-group'],
            'module'       => [null, 'row', 'column', 'module'],
        ];

        if (! in_array($parent_type, $allowed[$node->type] ?? [], true)) {
            throw new \InvalidArgumentException(esc_html(sprintf(
                'A %s cannot go %s.',
                $node->type,
                null === $parent_type ? 'at the top level' : "inside a {$parent_type}"
            )));
        }
    }

    /**
     * Put an already-parented node at `index` among its siblings and renumber
     * them 0..n-1.
     *
     * @param array<string,object> $nodes
     * @return array<string,object>
     */
    private static function place(array $nodes, string $id, ?string $parent, int $index): array
    {
        $siblings = array_values(array_diff(self::child_ids($nodes, $parent), [$id]));
        array_splice($siblings, $index, 0, [$id]);
        foreach ($siblings as $position => $sibling) {
            if ($nodes[$sibling]->position !== $position) {
                $nodes[$sibling]->position = $position;
            }
        }

        return $nodes;
    }

    /**
     * Renumber a parent's children 0..n-1, optionally leaving one out.
     *
     * @param array<string,object> $nodes
     * @return array<string,object>
     */
    private static function renumber(array $nodes, ?string $parent, ?string $skip = null): array
    {
        $position = 0;
        foreach (self::child_ids($nodes, $parent) as $id) {
            if ($id === $skip) {
                continue;
            }
            if ($nodes[$id]->position !== $position) {
                $nodes[$id]->position = $position;
            }
            $position++;
        }

        return $nodes;
    }

    /**
     * @param array<string,object> $nodes
     * @return string[] the node and all its descendants
     */
    private static function subtree_ids(array $nodes, string $id): array
    {
        $ids = [$id];
        foreach (self::child_ids($nodes, $id) as $child) {
            $ids = array_merge($ids, self::subtree_ids($nodes, $child));
        }

        return $ids;
    }

    /** @param array<string,object> $nodes */
    private static function require_node(array $nodes, string $id): object
    {
        if ('' === $id || ! isset($nodes[$id]) || ! is_object($nodes[$id])) {
            throw new \InvalidArgumentException(esc_html("No node with id {$id}."));
        }

        return $nodes[$id];
    }

    /** @param mixed $parent */
    private static function parent_key($parent): ?string
    {
        return null === $parent || '' === $parent || false === $parent ? null : (string) $parent;
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
     * A node id in Beaver Builder's own shape: 12 characters of [0-9a-z],
     * never all digits, unique in this layout.
     *
     * @param array<string,object> $nodes
     */
    private static function new_id(array $nodes): string
    {
        $alphabet = '0123456789abcdefghijklmnopqrstuvwxyz';
        do {
            $id = '';
            for ($i = 0; $i < 12; $i++) {
                $id .= $alphabet[wp_rand(0, 35)];
            }
        } while (ctype_digit($id) || isset($nodes[$id]));

        return $id;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function deep_clone($value)
    {
        if (is_object($value)) {
            $value = clone $value;
            foreach (get_object_vars($value) as $key => $item) {
                $value->{$key} = self::deep_clone($item);
            }
        } elseif (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::deep_clone($item);
            }
        }

        return $value;
    }

    /**
     * Convert decoded input to the shape Beaver Builder stores: objects
     * (JSON objects, associative arrays) become stdClass, lists stay arrays,
     * the way json_decode() without assoc builds them.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function to_stored($value)
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
            $out   = new \stdClass();
            foreach ($value as $key => $item) {
                $out->{$key} = self::to_stored($item);
            }

            return $out;
        }
        if (is_array($value)) {
            if ([] === $value || array_is_list($value)) {
                return array_map([self::class, 'to_stored'], $value);
            }

            return self::to_stored((object) $value);
        }

        return $value;
    }
}
