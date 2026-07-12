<?php

namespace WPMCP\Safety;

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
        $result = $mutation();
        if ($verify && ! $verify($result)) {
            self::restore($snapshot);
            throw new Mutation_Failed('Verification failed; change rolled back.');
        }
        return ['operation_id' => $operation_id, 'result' => $result];
    }

    /** Temporary; extracted into Rollback_Service in Task 7. */
    public static function restore(array $snapshot): void
    {
        if ('post' === $snapshot['object_type'] && $snapshot['data']['post']) {
            wp_update_post(array_merge(['ID' => $snapshot['object_id']], $snapshot['data']['post']));
        }
        foreach ($snapshot['data']['meta'] as $key => $values) {
            delete_post_meta($snapshot['object_id'], $key);
            foreach ((array) $values as $v) {
                add_post_meta($snapshot['object_id'], $key, maybe_unserialize($v));
            }
        }
    }
}
