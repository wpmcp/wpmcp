<?php

namespace WPMCP\Tools\Blocks;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Surgical removal (issue #56): delete the single block at a path (nested
 * removals also drop the parent's matching innerContent marker so the
 * container wrapper survives intact). Snapshot-first and fully restorable
 * via rollback-operation.
 */
class Remove_Block
{
    public function handle(array $args): array
    {
        [$id, , $blocks] = Block_Tree::read_for_edit($args);
        $path = Block_Tree::normalize_path($args['path'] ?? null);

        $blocks = Block_Tree::remove($blocks, $path);

        return Block_Tree::write($id, $blocks, 'remove-block', $args) + ['path' => $path];
    }
}
