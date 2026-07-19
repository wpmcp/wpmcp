<?php

namespace WPMCP\Tools\Blocks;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Shared engine for the surgical per-block tools (issue #56). Not a tool
 * itself: it loads a post's block tree under three guards, applies pure
 * structural operations addressed by path, and writes the result back
 * through Safe_Mutation.
 *
 * Targeting model: a path is an array of zero-based indexes into the tree
 * exactly as parse-blocks reports it (including freeform whitespace filler
 * nodes), each subsequent index descending into innerBlocks.
 *
 * Guards, all BEFORE any snapshot or write:
 *  1. the post must exist;
 *  2. the caller must pass expected_hash (sha256 of post_content, as
 *     returned by parse-blocks) and it must still match — a stale hash
 *     means the content changed between read and write, so the paths the
 *     caller computed can no longer be trusted;
 *  3. the content must round-trip byte-identically through
 *     parse_blocks()/serialize_blocks(). If it does not, a surgical edit
 *     would silently rewrite the bytes of blocks it never touched, so the
 *     edit is refused outright. This guard is also what makes the
 *     untouched-blocks-stay-byte-identical guarantee hold: when the whole
 *     document round-trips cleanly, every unmodified node re-serializes to
 *     exactly its original bytes.
 *
 * Nested inserts/removals keep the parent's innerContent marker list (the
 * null placeholders interleaved with HTML fragments) in sync with
 * innerBlocks, which is what preserves container wrapper markup.
 */
class Block_Tree
{
    /**
     * Load a post's block tree for a surgical edit, enforcing the
     * existence, freshness, and round-trip guards.
     *
     * @return array{0:int,1:string,2:array} [post id, current content, parsed blocks]
     */
    public static function read_for_edit(array $args): array
    {
        $id   = (int) ($args['id'] ?? 0);
        $post = $id ? get_post($id) : null;
        if (! $post) {
            throw new \InvalidArgumentException('Post not found.');
        }
        $content  = (string) $post->post_content;
        $expected = (string) ($args['expected_hash'] ?? '');
        if ('' === $expected) {
            throw new \InvalidArgumentException(
                '"expected_hash" is required: read the post with parse-blocks first and pass back its content_hash.'
            );
        }
        if (! hash_equals(hash('sha256', $content), $expected)) {
            throw new \InvalidArgumentException(
                'Stale expected_hash: the post content has changed since it was read, so block paths can no '
                . 'longer be trusted. Re-read with parse-blocks and retry.'
            );
        }
        $blocks = parse_blocks($content);
        if (serialize_blocks($blocks) !== $content) {
            throw new \InvalidArgumentException(
                'Content does not round-trip cleanly through parse/serialize, so a surgical edit would corrupt '
                . 'the bytes of untouched blocks. Refusing; use update-blocks to replace the full content instead.'
            );
        }
        return [$id, $content, $blocks];
    }

    /**
     * Serialize the modified tree and write it back snapshot-first. The
     * verify step re-reads the STORED content and requires it to be exactly
     * the markup we serialized; anything else (a filter mangling the save,
     * a failed write) rolls back automatically.
     */
    public static function write(int $id, array $blocks, string $tool_name, array $args): array
    {
        $new = serialize_blocks($blocks);
        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => $tool_name,
                'args'        => $args,
            ],
            function () use ($id, $new) {
                wp_update_post(['ID' => $id, 'post_content' => wp_slash($new)]);
                return true;
            },
            function () use ($id, $new) {
                clean_post_cache($id);
                return (string) get_post($id)->post_content === $new;
            }
        );
        return [
            'operation_id' => $out['operation_id'],
            'id'           => $id,
            'content_hash' => hash('sha256', $new),
        ];
    }

    /** Validate and normalize a path argument to a non-empty list of ints. */
    public static function normalize_path($path, string $arg_name = 'path'): array
    {
        if (! is_array($path) || [] === $path) {
            throw new \InvalidArgumentException(
                sprintf('A non-empty "%s" (array of integer block indexes) is required.', $arg_name)
            );
        }
        $out = [];
        foreach ($path as $segment) {
            if (! is_int($segment) && ! (is_string($segment) && ctype_digit($segment))) {
                throw new \InvalidArgumentException(
                    sprintf('Invalid "%s": every segment must be a non-negative integer index.', $arg_name)
                );
            }
            $out[] = (int) $segment;
        }
        return $out;
    }

    /** Return the node at $path, or throw a structured path error. */
    public static function get(array $blocks, array $path): array
    {
        $node = null;
        $list = $blocks;
        foreach ($path as $depth => $index) {
            if ($index < 0 || $index >= count($list)) {
                throw self::path_error($path, $depth, count($list));
            }
            $node = $list[ $index ];
            $list = is_array($node['innerBlocks'] ?? null) ? $node['innerBlocks'] : [];
        }
        return $node;
    }

    /** Replace the node at $path with $node. */
    public static function replace(array $blocks, array $path, array $node): array
    {
        $index = $path[0];
        if ($index < 0 || $index >= count($blocks)) {
            throw self::path_error($path, count($path) - count($path), count($blocks));
        }
        if (1 === count($path)) {
            $blocks[ $index ] = $node;
            return $blocks;
        }
        $blocks[ $index ]['innerBlocks'] = self::replace(
            is_array($blocks[ $index ]['innerBlocks'] ?? null) ? $blocks[ $index ]['innerBlocks'] : [],
            array_slice($path, 1),
            $node
        );
        return $blocks;
    }

    /**
     * Insert $node so that it ends up AT $path. The final segment may equal
     * the current sibling count (append). Nested inserts require the parent
     * to already have at least one inner block, because splitting a
     * childless container's flat innerHTML cannot be done generically.
     */
    public static function insert(array $blocks, array $path, array $node): array
    {
        $index = $path[0];
        if (1 === count($path)) {
            if ($index < 0 || $index > count($blocks)) {
                throw self::path_error($path, 0, count($blocks) + 1);
            }
            array_splice($blocks, $index, 0, [$node]);
            return $blocks;
        }
        if ($index < 0 || $index >= count($blocks)) {
            throw self::path_error($path, 0, count($blocks));
        }
        if (2 === count($path)) {
            $blocks[ $index ] = self::insert_child($blocks[ $index ], $path[1], $node, $path);
            return $blocks;
        }
        $blocks[ $index ]['innerBlocks'] = self::insert(
            is_array($blocks[ $index ]['innerBlocks'] ?? null) ? $blocks[ $index ]['innerBlocks'] : [],
            array_slice($path, 1),
            $node
        );
        return $blocks;
    }

    /** Remove the node at $path. */
    public static function remove(array $blocks, array $path): array
    {
        $index = $path[0];
        if ($index < 0 || $index >= count($blocks)) {
            throw self::path_error($path, 0, count($blocks));
        }
        if (1 === count($path)) {
            array_splice($blocks, $index, 1);
            return $blocks;
        }
        if (2 === count($path)) {
            $blocks[ $index ] = self::remove_child($blocks[ $index ], $path[1], $path);
            return $blocks;
        }
        $blocks[ $index ]['innerBlocks'] = self::remove(
            is_array($blocks[ $index ]['innerBlocks'] ?? null) ? $blocks[ $index ]['innerBlocks'] : [],
            array_slice($path, 1)
        );
        return $blocks;
    }

    /**
     * Move the node at $from_path to $to_index among its own siblings.
     * Marker lists are untouched: the null placeholders are positional
     * slots filled by innerBlocks in order, so reordering innerBlocks is
     * sufficient and keeps the parent's wrapper HTML fixed.
     */
    public static function reorder(array $blocks, array $from_path, int $to_index): array
    {
        $index = $from_path[0];
        if (1 === count($from_path)) {
            if ($index < 0 || $index >= count($blocks)) {
                throw self::path_error($from_path, 0, count($blocks));
            }
            if ($to_index < 0 || $to_index >= count($blocks)) {
                throw new \InvalidArgumentException(sprintf(
                    'Invalid "to_index" %d: the target parent has %d block(s).',
                    $to_index,
                    count($blocks)
                ));
            }
            $node = $blocks[ $index ];
            array_splice($blocks, $index, 1);
            array_splice($blocks, $to_index, 0, [$node]);
            return $blocks;
        }
        if ($index < 0 || $index >= count($blocks)) {
            throw self::path_error($from_path, 0, count($blocks));
        }
        $inner = is_array($blocks[ $index ]['innerBlocks'] ?? null) ? $blocks[ $index ]['innerBlocks'] : [];
        $blocks[ $index ]['innerBlocks'] = self::reorder($inner, array_slice($from_path, 1), $to_index);
        return $blocks;
    }

    /**
     * Splice $node into $parent at inner index $at, inserting a matching
     * null marker into innerContent so the container's wrapper HTML stays
     * intact around the new child.
     */
    private static function insert_child(array $parent, int $at, array $node, array $full_path): array
    {
        $inner = is_array($parent['innerBlocks'] ?? null) ? $parent['innerBlocks'] : [];
        $count = count($inner);
        if (0 === $count) {
            throw new \InvalidArgumentException(
                'Cannot insert into a container with no existing inner blocks: its wrapper HTML cannot be split '
                . 'safely. Use update-block on the parent (or update-blocks) instead.'
            );
        }
        if ($at < 0 || $at > $count) {
            throw self::path_error($full_path, count($full_path) - 1, $count + 1);
        }
        $markers = self::marker_positions($parent, $count);
        $pos     = $at < $count ? $markers[ $at ] : $markers[ $count - 1 ] + 1;

        $content = array_values($parent['innerContent']);
        array_splice($content, $pos, 0, [null]);
        array_splice($inner, $at, 0, [$node]);

        $parent['innerBlocks']  = $inner;
        $parent['innerContent'] = $content;
        return $parent;
    }

    /** Remove the child at inner index $at along with its innerContent marker. */
    private static function remove_child(array $parent, int $at, array $full_path): array
    {
        $inner = is_array($parent['innerBlocks'] ?? null) ? $parent['innerBlocks'] : [];
        $count = count($inner);
        if ($at < 0 || $at >= $count) {
            throw self::path_error($full_path, count($full_path) - 1, $count);
        }
        $markers = self::marker_positions($parent, $count);

        $content = array_values($parent['innerContent']);
        array_splice($content, $markers[ $at ], 1);
        array_splice($inner, $at, 1);

        $parent['innerBlocks']  = $inner;
        $parent['innerContent'] = $content;
        return $parent;
    }

    /**
     * Positions of the null markers in a parent's innerContent, which must
     * match its inner block count one-for-one (guaranteed for any tree that
     * passed the round-trip guard).
     *
     * @return int[]
     */
    private static function marker_positions(array $parent, int $inner_count): array
    {
        $content   = array_values(is_array($parent['innerContent'] ?? null) ? $parent['innerContent'] : []);
        $positions = [];
        foreach ($content as $i => $fragment) {
            if (null === $fragment) {
                $positions[] = $i;
            }
        }
        if (count($positions) !== $inner_count) {
            throw new \InvalidArgumentException(
                'Malformed block tree: the container\'s innerContent markers do not match its inner blocks.'
            );
        }
        return $positions;
    }

    private static function path_error(array $path, int $depth, int $valid_count): \InvalidArgumentException
    {
        return new \InvalidArgumentException(sprintf(
            'Invalid block path [%s]: no block at segment %d (parent has %d position(s)). '
            . 'Re-read with parse-blocks to get current paths.',
            implode(',', $path),
            $depth,
            $valid_count
        ));
    }
}
