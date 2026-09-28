<?php

namespace WPMCP\Tools\Meta;

use WPMCP\Tools\Content\Content_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: return a post's meta, either the full map or a single key.
 * Protected meta (a leading underscore, or is_protected_meta()) is always
 * skipped, matching the same rule Get_Post already applies to its own meta
 * map: this is a generic, agent-facing tool, so internal/plugin-private
 * postmeta (caches, Elementor data, etc.) should not leak through it. Reads
 * have nothing to roll back, so this never touches Safe_Mutation.
 */
class Get_Post_Meta
{
    public function handle(array $args): array
    {
        $post_id = (int) ($args['post_id'] ?? 0);
        $post    = $post_id ? get_post($post_id) : null;
        if (! $post || ! Content_Guard::is_agent_readable_post_type((string) $post->post_type)) {
            throw new \InvalidArgumentException('Post not found');
        }

        $key = isset($args['key']) ? (string) $args['key'] : null;

        if (null !== $key) {
            $meta = [];
            if (! $this->is_protected($key)) {
                $meta[ $key ] = get_post_meta($post_id, $key, true);
            }
            return ['post_id' => $post_id, 'meta' => $meta];
        }

        $meta_raw = get_post_meta($post_id);
        $meta     = [];
        foreach ((array) $meta_raw as $meta_key => $values) {
            if ($this->is_protected((string) $meta_key)) {
                continue;
            }
            $meta[ $meta_key ] = 1 === count($values) ? maybe_unserialize($values[0]) : array_map('maybe_unserialize', $values);
        }

        return ['post_id' => $post_id, 'meta' => $meta];
    }

    private function is_protected(string $key): bool
    {
        return '_' === substr($key, 0, 1) || is_protected_meta($key, 'post');
    }
}
