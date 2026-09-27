<?php

namespace WPMCP\Tools\Terms;

use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Write or delete a term meta value, snapshot-first.
 *
 * Term meta is where SEO plugins, category images and builder settings for
 * an archive live, so it is a genuine write surface rather than a curiosity.
 * The whole term (fields plus its full meta map) is captured, matching the
 * post path's "full row, not a hand-picked subset" stance, so a rollback is
 * exact even for meta that this write added.
 *
 * Passing value:null deletes the key. That is distinct from writing an empty
 * string, which some plugins treat as "explicitly set to nothing", so the
 * two are kept separable rather than collapsed.
 */
class Set_Term_Meta
{
    public function handle(array $args): array
    {
        $taxonomy = Term_Support::require_taxonomy($args);
        $term     = Term_Support::require_term($args, $taxonomy);
        $term_id  = (int) $term->term_id;

        $key = (string) ($args['key'] ?? '');
        if ('' === $key) {
            throw new \InvalidArgumentException('A meta key is required.');
        }

        if (is_protected_meta($key, 'term')) {
            throw new \InvalidArgumentException(sprintf('Meta key "%s" is protected and cannot be written.', esc_html($key)));
        }

        $delete = ! array_key_exists('value', $args) || null === $args['value'];
        $value  = $delete ? null : $args['value'];

        $result = Safe_Mutation::run(
            [
                'object_type' => 'term',
                'object_id'   => Snapshot::term_key($taxonomy, (string) $term->slug),
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'set-term-meta',
                'args'        => $args,
            ],
            static function () use ($term_id, $key, $value, $delete): bool {
                if ($delete) {
                    delete_term_meta($term_id, $key);
                    return true;
                }
                update_term_meta($term_id, $key, $value);
                return true;
            }
        );

        return [
            'operation_id' => $result['operation_id'],
            'term_id'      => $term_id,
            'taxonomy'     => $taxonomy,
            'key'          => $key,
            'deleted'      => $delete,
            'value'        => $delete ? null : get_term_meta($term_id, $key, true),
        ];
    }
}
