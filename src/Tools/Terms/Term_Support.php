<?php

namespace WPMCP\Tools\Terms;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Shared validation and shaping for the taxonomy term tools.
 *
 * Every term write funnels its taxonomy through require_taxonomy() so an
 * unregistered or misspelled taxonomy fails with a message naming what IS
 * available, rather than through WordPress's generic invalid_taxonomy
 * WP_Error. An agent that gets told "categories, post_tag" back can correct
 * itself in one turn; one that gets "Invalid taxonomy" usually cannot.
 */
class Term_Support
{
    /**
     * @throws \InvalidArgumentException When the taxonomy is missing or not registered.
     */
    public static function require_taxonomy(array $args): string
    {
        $taxonomy = sanitize_key((string) ($args['taxonomy'] ?? ''));

        if ('' === $taxonomy) {
            throw new \InvalidArgumentException('A taxonomy is required.');
        }

        if (! taxonomy_exists($taxonomy)) {
            $known = get_taxonomies([], 'names');
            throw new \InvalidArgumentException(sprintf(
                'Unknown taxonomy "%s". Registered taxonomies: %s',
                $taxonomy,
                implode(', ', array_values(is_array($known) ? $known : []))
            ));
        }

        return $taxonomy;
    }

    /**
     * Resolve a term from either a term_id or a slug, both scoped to the
     * taxonomy. Accepting the slug matters because an agent that just created
     * a term by name knows its slug but not its id, and forcing a lookup
     * round-trip for every follow-up write is the kind of friction that makes
     * a tool go unused.
     *
     * @throws \InvalidArgumentException When neither identifier resolves.
     */
    public static function require_term(array $args, string $taxonomy): \WP_Term
    {
        $term_id = (int) ($args['term_id'] ?? 0);

        if ($term_id > 0) {
            $term = get_term($term_id, $taxonomy);
            if ($term instanceof \WP_Term) {
                return $term;
            }
            throw new \InvalidArgumentException(sprintf('No term %d in taxonomy "%s".', $term_id, $taxonomy));
        }

        $slug = (string) ($args['slug'] ?? '');
        if ('' !== $slug) {
            $term = get_term_by('slug', $slug, $taxonomy);
            if ($term instanceof \WP_Term) {
                return $term;
            }
            throw new \InvalidArgumentException(sprintf('No term with slug "%s" in taxonomy "%s".', $slug, $taxonomy));
        }

        throw new \InvalidArgumentException('Provide either term_id or slug.');
    }

    /** The public shape of a term in every tool's response. */
    public static function shape(\WP_Term $term, bool $with_meta = false): array
    {
        $out = [
            'term_id'     => (int) $term->term_id,
            'name'        => (string) $term->name,
            'slug'        => (string) $term->slug,
            'taxonomy'    => (string) $term->taxonomy,
            'description' => (string) $term->description,
            'parent'      => (int) $term->parent,
            'count'       => (int) $term->count,
            'link'        => (string) (get_term_link($term) instanceof \WP_Error ? '' : get_term_link($term)),
        ];

        if ($with_meta) {
            $meta = get_term_meta((int) $term->term_id);
            $out['meta'] = is_array($meta) ? array_map(
                static fn($values) => array_map('maybe_unserialize', (array) $values),
                $meta
            ) : [];
        }

        return $out;
    }
}
