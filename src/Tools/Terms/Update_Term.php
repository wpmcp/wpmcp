<?php

namespace WPMCP\Tools\Terms;

use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Update a taxonomy term's name, slug, description or parent, snapshot-first.
 *
 * The snapshot is keyed by the term's CURRENT slug, not the incoming one: a
 * rename changes the key, and capturing under the new slug would record "no
 * term owned this" and turn the undo into a delete of the renamed term.
 *
 * A term cannot be reparented onto itself or onto one of its own
 * descendants. WordPress does not stop this, and the result is a cycle in
 * wp_term_taxonomy that makes the taxonomy unrenderable, so it is refused
 * here rather than discovered later on a broken archive page.
 */
class Update_Term
{
    public function handle(array $args): array
    {
        $taxonomy = Term_Support::require_taxonomy($args);
        $term     = Term_Support::require_term($args, $taxonomy);
        $term_id  = (int) $term->term_id;

        $fields = [];

        if (array_key_exists('name', $args)) {
            $name = trim((string) $args['name']);
            if ('' === $name) {
                throw new \InvalidArgumentException('A term name cannot be empty.');
            }
            $fields['name'] = $name;
        }

        if (array_key_exists('slug', $args)) {
            $slug = sanitize_title((string) $args['slug']);
            if ('' === $slug) {
                throw new \InvalidArgumentException('A term slug cannot be empty.');
            }
            $holder = get_term_by('slug', $slug, $taxonomy);
            if ($holder instanceof \WP_Term && (int) $holder->term_id !== $term_id) {
                throw new \InvalidArgumentException(sprintf('Slug "%s" is already used by term %d.', esc_html($slug), (int) $holder->term_id));
            }
            $fields['slug'] = $slug;
        }

        if (array_key_exists('description', $args)) {
            $fields['description'] = (string) $args['description'];
        }

        if (array_key_exists('parent', $args)) {
            $parent = (int) $args['parent'];
            if ($parent > 0) {
                if (! is_taxonomy_hierarchical($taxonomy)) {
                    throw new \InvalidArgumentException(sprintf('Taxonomy "%s" is not hierarchical, so a parent cannot be set.', esc_html($taxonomy)));
                }
                if ($parent === $term_id) {
                    throw new \InvalidArgumentException('A term cannot be its own parent.');
                }
                if (! get_term($parent, $taxonomy) instanceof \WP_Term) {
                    throw new \InvalidArgumentException(sprintf('Parent term %d does not exist in "%s".', (int) $parent, esc_html($taxonomy)));
                }
                if (in_array($term_id, get_ancestors($parent, $taxonomy, 'taxonomy'), true)) {
                    throw new \InvalidArgumentException(sprintf(
                        'Term %d is an ancestor of %d; reparenting there would create a cycle.',
                        (int) $term_id,
                        (int) $parent
                    ));
                }
            }
            $fields['parent'] = $parent;
        }

        if ([] === $fields) {
            throw new \InvalidArgumentException('Provide at least one of name, slug, description or parent.');
        }

        $result = Safe_Mutation::run(
            [
                'object_type' => 'term',
                'object_id'   => Snapshot::term_key($taxonomy, (string) $term->slug),
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'update-term',
                'args'        => $args,
            ],
            static function () use ($term_id, $taxonomy, $fields): bool {
                $updated = wp_update_term($term_id, $taxonomy, $fields);
                if (is_wp_error($updated)) {
                    throw new \RuntimeException(esc_html($updated->get_error_message()));
                }
                return true;
            }
        );

        $fresh = get_term($term_id, $taxonomy);

        return [
            'operation_id' => $result['operation_id'],
            'term'         => $fresh instanceof \WP_Term ? Term_Support::shape($fresh) : null,
        ];
    }
}
