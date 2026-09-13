<?php

namespace WPMCP\Tools\Terms;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * List terms in a taxonomy, with search, pagination and an empty-terms
 * filter.
 *
 * hide_empty defaults to FALSE, the opposite of get_terms()'s own default.
 * An agent asking "what categories exist" means all of them; core's default
 * would silently omit every unused term and lead the agent to create a
 * duplicate of one that already exists.
 */
class List_Terms
{
    public const MAX_PER_PAGE = 200;

    public function handle(array $args): array
    {
        $taxonomy = Term_Support::require_taxonomy($args);

        $per_page = (int) ($args['per_page'] ?? 50);
        $per_page = min(self::MAX_PER_PAGE, max(1, $per_page));
        $page     = max(1, (int) ($args['page'] ?? 1));

        $query = [
            'taxonomy'   => $taxonomy,
            'hide_empty' => (bool) ($args['hide_empty'] ?? false),
            'number'     => $per_page,
            'offset'     => ($page - 1) * $per_page,
            'orderby'    => in_array($args['orderby'] ?? 'name', ['name', 'slug', 'count', 'term_id'], true)
                ? (string) ($args['orderby'] ?? 'name')
                : 'name',
            'order'      => 'DESC' === strtoupper((string) ($args['order'] ?? 'ASC')) ? 'DESC' : 'ASC',
        ];

        $search = (string) ($args['search'] ?? '');
        if ('' !== $search) {
            $query['search'] = $search;
        }

        if (array_key_exists('parent', $args)) {
            $query['parent'] = (int) $args['parent'];
        }

        $terms = get_terms($query);
        if (is_wp_error($terms)) {
            throw new \RuntimeException(esc_html($terms->get_error_message()));
        }

        // The total is fetched separately rather than counted from the page,
        // so a caller can tell "50 results, more to come" from "exactly 50".
        $count_query = $query;
        unset($count_query['number'], $count_query['offset'], $count_query['orderby'], $count_query['order']);
        $count_query['fields'] = 'count';
        $total = get_terms($count_query);

        return [
            'taxonomy' => $taxonomy,
            'terms'    => array_map(
                static fn(\WP_Term $term): array => Term_Support::shape($term),
                is_array($terms) ? $terms : []
            ),
            'page'     => $page,
            'per_page' => $per_page,
            'total'    => is_wp_error($total) ? null : (int) $total,
        ];
    }
}
