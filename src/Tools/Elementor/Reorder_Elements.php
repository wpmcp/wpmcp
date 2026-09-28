<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Reorder the children of one parent (or the top level) to an explicit id
 * order (issue #58). The order must be an exact permutation of the current
 * children - a missing, foreign, or duplicated id refuses the whole
 * operation before any write. Hash-guarded and snapshot-first.
 */
class Reorder_Elements
{
    public function handle(array $args)
    {
        $order = is_array($args['order'] ?? null) ? array_map('strval', $args['order']) : [];
        if ([] === $order) {
            return new \WP_Error('missing_order', 'A non-empty "order" array of element ids is required.');
        }

        $read = Element_Tree::read_for_edit($args);
        if (is_wp_error($read)) {
            return $read;
        }
        [$post_id, $elements] = $read;

        $parent_id = (string) ($args['parent_id'] ?? '');

        if ('' === $parent_id) {
            $reordered = $this->reordered($elements, $order);
            if (is_wp_error($reordered)) {
                return $reordered;
            }
            $elements = $reordered;
        } else {
            $parent = Elementor_Page_Data::find($elements, $parent_id);
            if (null === $parent) {
                return new \WP_Error('parent_not_found', "No element found with id '{$parent_id}'.");
            }
            $children  = is_array($parent['elements'] ?? null) ? $parent['elements'] : [];
            $reordered = $this->reordered($children, $order);
            if (is_wp_error($reordered)) {
                return $reordered;
            }
            $this->replace_children($elements, $parent_id, $reordered);
        }

        $out = Element_Tree::write($post_id, $elements, 'reorder-elements', $args);
        if (is_wp_error($out)) {
            return $out;
        }

        return $out + ['parent_id' => $parent_id, 'order' => $order];
    }

    /** @return array|\WP_Error the siblings rearranged to $order. */
    private function reordered(array $siblings, array $order)
    {
        $current = array_map(static fn ($el) => (string) ($el['id'] ?? ''), $siblings);

        $sorted_current = $current;
        $sorted_order   = $order;
        sort($sorted_current);
        sort($sorted_order);

        if ($sorted_current !== $sorted_order) {
            return new \WP_Error(
                'invalid_order',
                sprintf(
                    'The "order" list must be an exact permutation of the current children [%s]; got [%s]. Nothing was written.',
                    implode(', ', $current),
                    implode(', ', $order)
                )
            );
        }

        $by_id = [];
        foreach ($siblings as $sibling) {
            $by_id[ (string) ($sibling['id'] ?? '') ] = $sibling;
        }

        return array_values(array_map(static fn (string $id) => $by_id[ $id ], $order));
    }

    private function replace_children(array &$elements, string $parent_id, array $children): bool
    {
        foreach ($elements as &$item) {
            if (($item['id'] ?? null) === $parent_id) {
                $item['elements'] = $children;
                return true;
            }
            if (! empty($item['elements']) && is_array($item['elements'])) {
                if ($this->replace_children($item['elements'], $parent_id, $children)) {
                    return true;
                }
            }
        }

        return false;
    }
}
