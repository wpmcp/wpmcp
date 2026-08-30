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
        Snapshot_Store::prune(Snapshot_Store::history_limit());
        $result = $mutation();
        if ($verify && ! $verify($result)) {
            self::restore($snapshot);
            throw new Mutation_Failed('Verification failed; change rolled back.');
        }
        return ['operation_id' => $operation_id, 'result' => $result];
    }

    public static function restore(array $snapshot): void
    {
        Rollback_Service::apply_snapshot($snapshot);
    }
}
