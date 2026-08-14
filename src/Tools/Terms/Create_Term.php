<?php

namespace WPMCP\Tools\Terms;

use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Create a taxonomy term (a category, tag, or any registered custom
 * taxonomy term), snapshot-first so the creation can be rolled back.
 *
 * The snapshot is keyed by (taxonomy, slug) rather than by the term id,
 * which does not exist yet: see Snapshot::capture_term(). The captured state
 * is "nothing owned this slug", so rolling back deletes whatever now does.
 *
 * The slug is derived from the name when not supplied, and derived BEFORE
 * the write, because the snapshot key has to match the slug the term will
 * actually get. Letting wp_insert_term() derive it internally would produce
 * a snapshot keyed to a slug that never existed, and a rollback that quietly
 * did nothing.
 */
class Create_Term
{
    public function handle(array $args): array
    {
        $taxonomy = Term_Support::require_taxonomy($args);
        $name     = trim((string) ($args['name'] ?? ''));

        if ('' === $name) {
            throw new \InvalidArgumentException('A term name is required.');
        }

        $slug = (string) ($args['slug'] ?? '');
        $slug = '' !== $slug ? sanitize_title($slug) : sanitize_title($name);

        if (get_term_by('slug', $slug, $taxonomy) instanceof \WP_Term) {
            throw new \InvalidArgumentException(sprintf(
                'A term with slug "%s" already exists in "%s". Use update-term to change it.',
                $slug,
                $taxonomy
            ));
        }

        $parent = (int) ($args['parent'] ?? 0);
        if ($parent > 0 && ! get_term($parent, $taxonomy) instanceof \WP_Term) {
            throw new \InvalidArgumentException(sprintf('Parent term %d does not exist in "%s".', $parent, $taxonomy));
        }
        if ($parent > 0 && ! is_taxonomy_hierarchical($taxonomy)) {
            throw new \InvalidArgumentException(sprintf('Taxonomy "%s" is not hierarchical, so a parent cannot be set.', $taxonomy));
        }

        $description = (string) ($args['description'] ?? '');

        $result = Safe_Mutation::run(
            [
                'object_type' => 'term',
                'object_id'   => Snapshot::term_key($taxonomy, $slug),
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'create-term',
                'args'        => $args,
            ],
            static function () use ($taxonomy, $name, $slug, $parent, $description): int {
                $created = wp_insert_term($name, $taxonomy, [
                    'slug'        => $slug,
                    'parent'      => $parent,
                    'description' => $description,
                ]);

                if (is_wp_error($created)) {
                    throw new \RuntimeException($created->get_error_message());
                }

                return (int) $created['term_id'];
            }
        );

        $term = get_term((int) $result['result'], $taxonomy);

        return [
            'operation_id' => $result['operation_id'],
            'term'         => $term instanceof \WP_Term ? Term_Support::shape($term) : null,
        ];
    }
}
