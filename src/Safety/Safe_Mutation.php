<?php

namespace WPMCP\Safety;

if (! defined('ABSPATH')) {
    exit;
}

class Safe_Mutation
{
    /**
     * $context accepts two additive, optional keys beyond the ones already
     * documented at call sites:
     *  - 'operation_id': lets a caller that must act on the operation_id
     *    BEFORE the mutation runs (e.g. Delete_Media backing up files
     *    before the unlink that Safe_Mutation triggers) supply its own
     *    pre-generated UUID instead of the one this method would otherwise
     *    generate internally.
     *  - 'extra_snapshot_data': an array merged additively into the
     *    captured snapshot's 'data' key before it is persisted, for a
     *    caller that needs to attach extra recovery info (e.g. a file
     *    backup manifest) alongside the normal object snapshot.
     * Neither key is set by any existing caller, so omitting them is
     * byte-for-byte identical to the previous behavior.
     */
    public static function run(array $context, callable $mutation, ?callable $verify = null): array
    {
        // A post another user is editing is refused before anything is
        // captured or written (issue #452, Edit_Lock).
        $post_id = 'post' === $context['object_type'] && is_numeric($context['object_id']) ? (int) $context['object_id'] : 0;
        Edit_Lock::assert_writable($post_id);

        $operation_id = $context['operation_id'] ?? wp_generate_uuid4();
        $snapshot     = Snapshot::capture($context['object_type'], $context['object_id']);
        if (! empty($context['extra_snapshot_data']) && is_array($context['extra_snapshot_data'])) {
            $snapshot['data'] = array_merge($snapshot['data'], $context['extra_snapshot_data']);
        }
        Snapshot_Store::save(
            $operation_id,
            $context['session_id'],
            $snapshot,
            $context['tool_name'],
            hash('sha256', wp_json_encode($context['args'] ?? []))
        );
        // Noted only now that the snapshot is persisted: observers wrapping
        // the call (MCP\Request_Log rows, issue #134) link to this id as an
        // undo point, so it must never be advertised before it exists.
        Operation_Context::note($operation_id);
        Snapshot_Store::prune();

        // Terms the write creates as a side effect (wp_set_object_terms()
        // inserts a term for every name it does not find) are no part of the
        // object snapshot, so they are noted as they appear and added to the
        // undo point, which removes them again (issue #588, see
        // Term_Restore::remove_created()).
        $created_terms = [];
        $note_term     = static function ($term_id, $tt_id, $taxonomy) use (&$created_terms): void {
            unset($tt_id);
            $created_terms[] = ['term_id' => (int) $term_id, 'taxonomy' => (string) $taxonomy];
        };
        add_action('created_term', $note_term, 10, 3);
        try {
            $result = $mutation();
        } finally {
            remove_action('created_term', $note_term, 10);
            if ([] !== $created_terms) {
                $snapshot['data'][ Term_Restore::CREATED_KEY ] = $created_terms;
                Snapshot_Store::update_snapshot($operation_id, $snapshot);
            }
        }
        if ($verify && ! $verify($result)) {
            self::restore($snapshot);
            throw new Mutation_Failed('Verification failed; change rolled back.');
        }
        Edit_Lock::note_written($post_id);
        return ['operation_id' => $operation_id, 'result' => $result];
    }

    public static function restore(array $snapshot): void
    {
        Rollback_Service::apply_snapshot($snapshot);
    }
}
