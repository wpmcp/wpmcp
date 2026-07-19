<?php

namespace WPMCP\Tools\Blocks;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Surgical reorder (issue #56): move the block at from_path to to_index
 * among its own siblings. Cross-parent moves are intentionally not
 * supported (compose remove-block + add-block for that); same-parent
 * reordering keeps the container's wrapper markup untouched.
 */
class Move_Block
{
    public function handle(array $args): array
    {
        [$id, , $blocks] = Block_Tree::read_for_edit($args);
        $from_path = Block_Tree::normalize_path($args['from_path'] ?? null, 'from_path');
        if (! isset($args['to_index']) || ! is_numeric($args['to_index'])) {
            throw new \InvalidArgumentException('"to_index" (integer position within the same parent) is required.');
        }

        $blocks = Block_Tree::reorder($blocks, $from_path, (int) $args['to_index']);

        return Block_Tree::write($id, $blocks, 'move-block', $args) + [
            'from_path' => $from_path,
            'to_index'  => (int) $args['to_index'],
        ];
    }
}
