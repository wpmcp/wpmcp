<?php

namespace WPMCP\Tools\Blocks;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Pattern insertion (issue #56): parse a registered pattern's content and
 * splice its real blocks (pure-whitespace filler nodes dropped) into a post
 * starting AT the given path, in order. Same guards as every surgical tool
 * (expected_hash freshness + round-trip integrity), snapshot-first.
 */
class Insert_Pattern
{
    public function handle(array $args): array
    {
        [$id, , $blocks] = Block_Tree::read_for_edit($args);
        $path = Block_Tree::normalize_path($args['path'] ?? null);

        $name    = (string) ($args['name'] ?? '');
        $pattern = \WP_Block_Patterns_Registry::get_instance()->get_registered($name);
        if (! $pattern) {
            throw new \InvalidArgumentException(sprintf(
                'Pattern "%s" is not registered. Use list-patterns to discover available pattern names.',
                $name
            ));
        }

        $nodes = array_values(array_filter(
            parse_blocks((string) ($pattern['content'] ?? '')),
            static fn (array $b) => null !== $b['blockName'] || '' !== trim((string) ($b['innerHTML'] ?? ''))
        ));
        if ([] === $nodes) {
            throw new \InvalidArgumentException(sprintf('Pattern "%s" contains no blocks to insert.', $name));
        }

        foreach ($nodes as $offset => $node) {
            $at                       = $path;
            $at[ count($at) - 1 ]    += $offset;
            $blocks                   = Block_Tree::insert($blocks, $at, $node);
        }

        return Block_Tree::write($id, $blocks, 'insert-pattern', $args) + [
            'inserted' => count($nodes),
            'path'     => $path,
        ];
    }
}
