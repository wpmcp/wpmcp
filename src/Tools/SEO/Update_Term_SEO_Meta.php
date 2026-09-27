<?php

namespace WPMCP\Tools\SEO;

use WPMCP\Safety\Safe_Mutation;
use WPMCP\Tools\Terms\Term_Support;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Set a taxonomy term's SEO fields (issue #67) with the same input schema
 * update-seo-meta takes for posts, translated to the active plugin's term
 * storage by Term_SEO.
 *
 * Snapshot-first through Safe_Mutation. What is captured depends on where
 * the plugin keeps term SEO: the whole `wpseo_taxonomy_meta` option for
 * Yoast, or the term (row plus full meta map) for the term-meta plugins.
 * Either way rollback-operation restores the prior values exactly through
 * the existing option and term restore paths.
 *
 * Unsupported combinations are answered, not thrown: on a plugin with no
 * mapped term storage nothing is written and no snapshot is taken, and
 * requested fields the plugin does not keep on terms come back in
 * `skipped_fields` while the rest are written. `unsupported_fields` keeps
 * the meaning it has on the read: every field the plugin lacks on terms.
 */
class Update_Term_SEO_Meta
{
    public function handle(array $args): array
    {
        $taxonomy = Term_Support::require_taxonomy($args);
        $term     = Term_Support::require_term($args, $taxonomy);
        $term_id  = (int) $term->term_id;

        if (! current_user_can('edit_term', $term_id)) {
            throw new \RuntimeException(
                'You do not have permission to edit term ' . (int) $term_id . '.'
            );
        }

        $requested = array_intersect_key($args, array_flip(Term_SEO::FIELDS));
        if ([] === $requested) {
            throw new \InvalidArgumentException('At least one SEO field is required.');
        }

        $base = ['term_id' => $term_id, 'taxonomy' => $taxonomy];

        $supported = Term_SEO::supported_fields();
        if ([] === $supported) {
            return array_merge($base, Term_SEO::unsupported(), [
                'written'        => [],
                'skipped_fields' => array_keys($requested),
            ]);
        }

        $writable    = array_intersect_key($requested, array_flip($supported));
        $skipped     = array_values(array_diff(array_keys($requested), $supported));

        if ([] === $writable) {
            return array_merge($base, Term_SEO::get($term), [
                'written'        => [],
                'skipped_fields' => $skipped,
            ]);
        }

        $target = Term_SEO::snapshot_target($term);

        $out = Safe_Mutation::run(
            [
                'object_type' => $target['object_type'],
                'object_id'   => $target['object_id'],
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'update-term-seo-meta',
                'args'        => $args,
            ],
            static function () use ($term, $writable): void {
                Term_SEO::update($term, $writable);
            }
        );

        return array_merge($base, Term_SEO::get($term), [
            'written'        => array_keys($writable),
            'skipped_fields' => $skipped,
            'operation_id'   => $out['operation_id'],
            'recoverable'    => true,
        ]);
    }
}
