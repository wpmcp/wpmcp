<?php

namespace WPMCP\Safety;

use WPMCP\Pro\Gate;

if (! defined('ABSPATH')) {
    exit;
}

class Safe_Mutation
{
    public static function run(array $context, callable $mutation, ?callable $verify = null): array
    {
        $operation_id = wp_generate_uuid4();
        $snapshot     = Snapshot::capture($context['object_type'], (int) $context['object_id']);
        Snapshot_Store::save(
            $operation_id,
            $context['session_id'],
            $snapshot,
            $context['tool_name'],
            hash('sha256', wp_json_encode($context['args'] ?? []))
        );
        Snapshot_Store::prune(Gate::history_limit());
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
