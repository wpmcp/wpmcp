<?php

namespace WPMCP\Tools\Terms;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read a single term by id or slug, including its meta and its ancestor
 * chain.
 *
 * The ancestors are included because a term's name alone is ambiguous in a
 * hierarchical taxonomy: three different "Shoes" categories under Men, Women
 * and Kids are indistinguishable without the path, and an agent that picks
 * the wrong one files content in the wrong place.
 */
class Get_Term
{
    public function handle(array $args): array
    {
        $taxonomy = Term_Support::require_taxonomy($args);
        $term     = Term_Support::require_term($args, $taxonomy);

        $out = Term_Support::shape($term, true);

        $ancestors = get_ancestors((int) $term->term_id, $taxonomy, 'taxonomy');
        $path      = [];
        foreach (array_reverse(is_array($ancestors) ? $ancestors : []) as $ancestor_id) {
            $ancestor = get_term((int) $ancestor_id, $taxonomy);
            if ($ancestor instanceof \WP_Term) {
                $path[] = ['term_id' => (int) $ancestor->term_id, 'name' => (string) $ancestor->name];
            }
        }
        $out['ancestors'] = $path;

        return ['term' => $out];
    }
}
