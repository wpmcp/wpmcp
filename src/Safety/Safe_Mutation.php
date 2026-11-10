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
     *
     * Two more optional keys, for the ledger (issues #723 and #728):
     *  - 'object_ref': what the Audit Log and list-operations name as the
     *    changed object when object_id does not say it (a plugin file for an
     *    'active_plugins' write, a file path, a cron hook). Without it a
     *    string object_id is the label (Snapshot_Store::object_ref()).
     *  - 'state_probe': a callable returning a comparable picture of the
     *    state the write changes (e.g. the active plugin lists). It is read
     *    once after the snapshot is saved; when the write then fails (it
     *    throws, its verify step fails, or the request dies mid-write) and
     *    the probe reads the same as before, nothing changed, so the undo
     *    point is voided instead of being left in the ledger as a mutation
     *    that never happened. A failure that did change the probed state
     *    keeps its undo point, as before.
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
            hash('sha256', wp_json_encode($context['args'] ?? [])),
            (string) ($context['object_ref'] ?? '')
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
        $probe     = isset($context['state_probe']) && is_callable($context['state_probe']) ? $context['state_probe'] : null;
        $before    = null !== $probe ? $probe() : null;
        $in_flight = null !== $probe;
        if ($in_flight) {
            // A request that dies inside the write (a fatal error, the PHP
            // time limit) never reaches the catch below; the shutdown check
            // voids its undo point too when the write had not landed.
            register_shutdown_function(static function () use (&$in_flight, &$created_terms, $probe, $before, $operation_id): void {
                if ($in_flight) {
                    self::void_if_unchanged($operation_id, $probe, $before, $created_terms);
                }
            });
        }

        add_action('created_term', $note_term, 10, 3);
        try {
            $result = $mutation();
        } catch (\Throwable $e) {
            $in_flight = false;
            remove_action('created_term', $note_term, 10);
            if (null !== $probe) {
                self::void_if_unchanged($operation_id, $probe, $before, $created_terms);
            }
            throw $e;
        } finally {
            $in_flight = false;
            remove_action('created_term', $note_term, 10);
            if ([] !== $created_terms && null !== Snapshot_Store::id_for_operation($operation_id)) {
                $snapshot['data'][ Term_Restore::CREATED_KEY ] = $created_terms;
                Snapshot_Store::update_snapshot($operation_id, $snapshot, (string) ($context['object_ref'] ?? ''));
            }
        }
        if ($verify && ! $verify($result)) {
            self::restore($snapshot);
            if (null !== $probe) {
                self::void_if_unchanged($operation_id, $probe, $before, $created_terms);
            }
            throw new Mutation_Failed('Verification failed; change rolled back.');
        }
        Edit_Lock::note_written($post_id);
        return ['operation_id' => $operation_id, 'result' => $result];
    }

    /**
     * Drop the undo point of a write that failed without changing anything
     * (issue #728): the probed state reads as it did before the write and
     * the write created no terms on the side. The id is also withdrawn from
     * Operation_Context, so no request row links to an undo point that is
     * gone. Any doubt (the probe throws) keeps the undo point.
     *
     * @param array<int, array{term_id: int, taxonomy: string}> $created_terms
     */
    private static function void_if_unchanged(string $operation_id, callable $probe, $before, array $created_terms): void
    {
        if ([] !== $created_terms) {
            return;
        }
        try {
            $unchanged = $probe() === $before;
        } catch (\Throwable $e) {
            return;
        }
        if ($unchanged) {
            Snapshot_Store::delete_operation($operation_id);
            Operation_Context::forget($operation_id);
        }
    }

    public static function restore(array $snapshot): void
    {
        Rollback_Service::apply_snapshot($snapshot);
    }
}
