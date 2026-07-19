<?php

namespace WPMCP\Tools\Blocks;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Surgical update (issue #56): rewrite one block's attributes and/or inner
 * HTML in place, leaving every other block byte-identical. "attrs" is a
 * full replacement (read the block first via parse-blocks); "inner_html"
 * only applies to leaf blocks — a container's content lives in its inner
 * blocks, which should be targeted by their own paths.
 */
class Update_Block
{
    public function handle(array $args): array
    {
        [$id, , $blocks] = Block_Tree::read_for_edit($args);
        $path = Block_Tree::normalize_path($args['path'] ?? null);
        $node = Block_Tree::get($blocks, $path);

        $attrs      = $args['attrs'] ?? null;
        $inner_html = $args['inner_html'] ?? null;
        if (null === $attrs && null === $inner_html) {
            throw new \InvalidArgumentException('At least one of "attrs" or "inner_html" is required.');
        }
        if (null === $node['blockName']) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid block path [%s]: the target is freeform content with no block name; it cannot be '
                . 'updated surgically.',
                implode(',', $path)
            ));
        }
        if (null !== $attrs) {
            if (! is_array($attrs)) {
                throw new \InvalidArgumentException('"attrs" must be an object of block attributes.');
            }
            $node['attrs'] = $attrs;
        }
        if (null !== $inner_html) {
            if (! empty($node['innerBlocks'])) {
                throw new \InvalidArgumentException(
                    'Refusing "inner_html" on a block that has innerBlocks: it would destroy the nested '
                    . 'structure. Target the inner block by its own path instead.'
                );
            }
            $node['innerHTML']    = (string) $inner_html;
            $node['innerContent'] = [ (string) $inner_html ];
        }

        $blocks = Block_Tree::replace($blocks, $path, $node);

        return Block_Tree::write($id, $blocks, 'update-block', $args) + ['path' => $path];
    }
}
