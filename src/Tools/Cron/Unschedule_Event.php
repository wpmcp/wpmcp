<?php

namespace WPMCP\Tools\Cron;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Unschedule WP-Cron events for a hook: a single specific occurrence
 * (wp_unschedule_event, when a 'timestamp' and matching 'args' are given) or
 * every event for the hook (wp_clear_scheduled_hook) otherwise.
 *
 * Unscheduling is deliberately unrestricted, including for core hooks:
 * disabling a core cron job (e.g. wp_version_check) is a legitimate hardening
 * step. It is made safe by being undoable: the write routes through
 * Safe_Mutation on the 'cron' option (the WP cron schedule lives there), so
 * rollback-operation restores the entire prior cron array and an accidental
 * clear of a core hook is one-click recoverable. No safety-core change: this
 * reuses the existing 'option' snapshot object type.
 */
class Unschedule_Event
{
    public function handle(array $args): array
    {
        $hook = isset($args['hook']) ? (string) $args['hook'] : '';
        if ('' === $hook) {
            throw new \InvalidArgumentException('A hook is required.');
        }

        $has_timestamp = isset($args['timestamp']);
        $timestamp     = $has_timestamp ? (int) $args['timestamp'] : 0;
        $event_args    = array_values((array) ($args['args'] ?? []));

        $out = Safe_Mutation::run(
            [
                'object_type' => 'option',
                'object_id'   => 'cron',
                'object_ref'  => $hook,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'unschedule-event',
                'args'        => $args,
            ],
            function () use ($hook, $has_timestamp, $timestamp, $event_args): int {
                if ($has_timestamp) {
                    return false === wp_unschedule_event($timestamp, $hook, $event_args) ? 0 : 1;
                }
                return (int) wp_clear_scheduled_hook($hook, $event_args);
            }
        );

        return [
            'hook'         => $hook,
            'cleared'      => $out['result'],
            'operation_id' => $out['operation_id'],
            'recoverable'  => true,
        ];
    }
}
