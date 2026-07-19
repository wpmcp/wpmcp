<?php

namespace WPMCP\Tools\Blocks;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Surgical duplication (issue #56): deep-copy the block at a path and
 * insert the copy immediately after it within the same parent. Returns the
 * new copy's path.
 */
class Duplicate_Block
{
    public function handle(array $args): array
    {
        [$id, , $blocks] = Block_Tree::read_for_edit($args);
        $path = Block_Tree::normalize_path($args['path'] ?? null);

        $node = Block_Tree::get($blocks, $path);

        $new_path                            = $path;
        $new_path[ count($new_path) - 1 ]   += 1;

        $blocks = Block_Tree::insert($blocks, $new_path, $node);

        return Block_Tree::write($id, $blocks, 'duplicate-block', $args) + ['new_path' => $new_path];
    }
}
