<?php

namespace WPMCP\Tools\Blocks;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Surgical insert (issue #56): add exactly one block, given as block
 * markup, so that it lands AT the given path (the final path segment may
 * equal the sibling count to append). Snapshot-first via Block_Tree::write;
 * requires expected_hash freshness proof like every surgical tool.
 */
class Add_Block
{
    public function handle(array $args): array
    {
        [$id, , $blocks] = Block_Tree::read_for_edit($args);
        $path = Block_Tree::normalize_path($args['path'] ?? null);
        $node = $this->single_block((string) ($args['markup'] ?? ''));

        $blocks = Block_Tree::insert($blocks, $path, $node);

        return Block_Tree::write($id, $blocks, 'add-block', $args) + ['path' => $path];
    }

    /** Parse markup that must contain exactly one real block. */
    private function single_block(string $markup): array
    {
        $parsed = array_values(array_filter(
            parse_blocks($markup),
            static fn (array $b) => null !== $b['blockName'] || '' !== trim((string) ($b['innerHTML'] ?? ''))
        ));
        if (1 !== count($parsed) || null === $parsed[0]['blockName']) {
            throw new \InvalidArgumentException(
                '"markup" must contain exactly one block (a single "<!-- wp:... -->" delimited block). '
                . 'To insert several blocks, call add-block once per block or use insert-pattern.'
            );
        }
        return $parsed[0];
    }
}
