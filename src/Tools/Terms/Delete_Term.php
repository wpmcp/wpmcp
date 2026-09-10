<?php

namespace WPMCP\Tools\Terms;

use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Delete a taxonomy term, snapshot-first so it can be restored with its
 * original term_id and term_taxonomy_id, which is what reattaches the posts
 * that were filed under it (see Rollback_Service::resurrect_term()).
 *
 * Deleting a term does NOT delete the posts in it. WordPress reassigns them
 * to the taxonomy's default term where one exists (uncategorised, for
 * categories) and otherwise simply unfiles them, so the response reports how
 * many objects were affected rather than leaving that invisible.
 *
 * A taxonomy's default term is refused: deleting it leaves WordPress with
 * nowhere to put newly orphaned posts, and core silently recreates it, so
 * the "delete" neither succeeds nor fails honestly.
 */
class Delete_Term
{
    public function handle(array $args): array
    {
        $taxonomy = Term_Support::require_taxonomy($args);
        $term     = Term_Support::require_term($args, $taxonomy);
        $term_id  = (int) $term->term_id;

        $default = (int) get_option('default_' . $taxonomy, 0);
        if ($default > 0 && $default === $term_id) {
            throw new \InvalidArgumentException(sprintf(
                'Term %d is the default term for "%s" and cannot be deleted. Change the default first.',
                (int) $term_id,
                esc_html($taxonomy)
            ));
        }

        $affected = (int) $term->count;

        $result = Safe_Mutation::run(
            [
                'object_type' => 'term',
                'object_id'   => Snapshot::term_key($taxonomy, (string) $term->slug),
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'delete-term',
                'args'        => $args,
            ],
            static function () use ($term_id, $taxonomy): bool {
                $deleted = wp_delete_term($term_id, $taxonomy);
                if (is_wp_error($deleted)) {
                    throw new \RuntimeException(esc_html($deleted->get_error_message()));
                }
                if (false === $deleted) {
                    throw new \RuntimeException('The term could not be deleted.');
                }
                return true;
            }
        );

        return [
            'operation_id'     => $result['operation_id'],
            'deleted'          => true,
            'term_id'          => $term_id,
            'taxonomy'         => $taxonomy,
            'objects_affected' => $affected,
        ];
    }
}
