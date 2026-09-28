<?php

namespace WPMCP\Tools\SEO;

use WPMCP\Tools\Terms\Term_Support;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read a taxonomy term's SEO fields (issue #67) in the neutral vocabulary
 * get-seo-meta uses for posts, through Term_SEO. Plugins with no mapped term
 * storage answer with a structured `supported: false`, and fields a plugin
 * does not keep on terms are listed in `unsupported_fields`, never errors.
 *
 * Paid tier per the issue (term-level SEO is extended vocabulary); the tier
 * is declared on the Ability and enforced centrally in the Registrar.
 */
class Get_Term_SEO_Meta
{
    public function handle(array $args): array
    {
        $taxonomy = Term_Support::require_taxonomy($args);
        $term     = Term_Support::require_term($args, $taxonomy);

        // A private taxonomy's terms are not public data: edit_posts on the
        // ability does not reach them unless the caller can edit the term.
        if (! is_taxonomy_viewable($taxonomy) && ! current_user_can('edit_term', (int) $term->term_id)) {
            throw new \RuntimeException(
                'You do not have permission to read term ' . (int) $term->term_id . '.'
            );
        }

        return array_merge(
            [
                'term_id'  => (int) $term->term_id,
                'taxonomy' => $taxonomy,
            ],
            Term_SEO::get($term)
        );
    }
}
