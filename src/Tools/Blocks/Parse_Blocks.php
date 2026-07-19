<?php

namespace WPMCP\Tools\Blocks;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read/utility: parse block markup, either passed directly as 'blocks' or
 * read from an existing post's post_content via 'id', into the block tree
 * produced by parse_blocks(). Each node reports blockName, attrs,
 * innerBlocks (recursively normalized the same way), and an innerHTML
 * summary rather than the raw, often-duplicated innerContent array.
 */
class Parse_Blocks
{
    public function handle(array $args): array
    {
        $markup = $this->resolve_markup($args);

        $parsed = parse_blocks($markup);

        return [
            'blocks'       => array_map([$this, 'normalize'], $parsed),
            // Freshness token for the surgical block tools (issue #56): pass
            // this back as expected_hash so a later write can prove the
            // content has not changed since this read.
            'content_hash' => hash('sha256', $markup),
        ];
    }

    private function resolve_markup(array $args): string
    {
        if (isset($args['blocks'])) {
            return (string) $args['blocks'];
        }

        if (isset($args['id'])) {
            $post = get_post((int) $args['id']);
            if (! $post) {
                throw new \InvalidArgumentException('Post not found.');
            }
            return (string) $post->post_content;
        }

        throw new \InvalidArgumentException('Either "id" or "blocks" is required.');
    }

    private function normalize(array $block): array
    {
        return [
            'blockName'    => $block['blockName'],
            'attrs'        => is_array($block['attrs'] ?? null) ? $block['attrs'] : [],
            'innerBlocks'  => array_map([$this, 'normalize'], $block['innerBlocks'] ?? []),
            'innerHTML'    => (string) ($block['innerHTML'] ?? ''),
            // Preserved (not just innerHTML) so this tree round-trips losslessly
            // through Serialize_Blocks: innerContent holds string fragments
            // interleaved with null markers showing exactly where each inner
            // block slots back in, which a flattened innerHTML summary cannot
            // reconstruct on its own.
            'innerContent' => array_values($block['innerContent'] ?? []),
        ];
    }
}
