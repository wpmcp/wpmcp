<?php

namespace WPMCP\Tools\Cron;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Schedule a WP-Cron event: a recurring event (wp_schedule_event) when a
 * 'recurrence' is given, or a one-off single event (wp_schedule_single_event)
 * otherwise. A recurrence is validated against wp_get_schedules() before the
 * write, so an unknown interval is rejected rather than silently producing a
 * dead event WordPress will never run.
 *
 * Scheduling (adding/duplicating) a core-critical hook whose recurrence
 * WordPress itself manages is refused (Core_Hooks::PROTECTED): re-adding it
 * could double-run version checks or transient cleanup. Unscheduling those
 * same hooks is intentionally NOT restricted (disabling wp_version_check is a
 * legitimate hardening step) and is undoable via Unschedule_Event's snapshot.
 *
 * The write routes through Safe_Mutation with object_type 'option' and
 * object_id 'cron' (the WP cron schedule lives in the 'cron' wp_option),
 * reusing the existing option snapshot/rollback path with no safety-core
 * change, so the whole cron array is restored on rollback-operation and the
 * scheduled event is undoable regardless of what else was scheduled.
 */
class Schedule_Event
{
    public function handle(array $args): array
    {
        $hook = isset($args['hook']) ? (string) $args['hook'] : '';
        if ('' === $hook) {
            throw new \InvalidArgumentException('A hook is required.');
        }

        if (Core_Hooks::is_protected($hook)) {
            throw new \RuntimeException(sprintf('Refusing to schedule protected core hook "%s".', esc_html($hook)));
        }

        $recurrence   = isset($args['recurrence']) ? (string) $args['recurrence'] : '';
        $event_args   = array_values((array) ($args['args'] ?? []));
        $default_time = time() + MINUTE_IN_SECONDS;
        $timestamp    = isset($args['timestamp']) ? (int) $args['timestamp'] : $default_time;

        if ('' !== $recurrence && ! isset(wp_get_schedules()[ $recurrence ])) {
            throw new \InvalidArgumentException(sprintf('Unknown recurrence "%s".', esc_html($recurrence)));
        }

        $out = Safe_Mutation::run(
            [
                'object_type' => 'option',
                'object_id'   => 'cron',
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'schedule-event',
                'args'        => $args,
            ],
            function () use ($hook, $recurrence, $timestamp, $event_args): void {
                if ('' !== $recurrence) {
                    $scheduled = wp_schedule_event($timestamp, $recurrence, $hook, $event_args);
                } else {
                    $scheduled = wp_schedule_single_event($timestamp, $hook, $event_args);
                }
                if (false === $scheduled) {
                    throw new \RuntimeException(sprintf('Failed to schedule event for hook "%s".', esc_html($hook)));
                }
            }
        );

        return [
            'hook'         => $hook,
            'timestamp'    => $timestamp,
            'recurrence'   => '' !== $recurrence ? $recurrence : null,
            'operation_id' => $out['operation_id'],
            'recoverable'  => true,
        ];
    }
}
