<?php

namespace WPMCP\Tools\Cron;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: enumerate the scheduled WP-Cron events (from the cron array) and
 * the available cron schedules (wp_get_schedules()).
 *
 * Each event reports its hook, next-run timestamp, recurrence key (the
 * schedule name for a recurring event, or null for a one-off single event),
 * the resolved interval in seconds when recurring, and the callback args.
 * An optional 'hook' argument narrows the result to a single hook's events.
 *
 * Reads have nothing to roll back, so this never touches Safe_Mutation.
 */
class List_Cron_Events
{
    public function handle(array $args): array
    {
        $filter    = isset($args['hook']) ? (string) $args['hook'] : '';
        $schedules = wp_get_schedules();
        $cron      = _get_cron_array();

        $events = [];
        if (is_array($cron)) {
            foreach ($cron as $timestamp => $hooks) {
                if (! is_array($hooks)) {
                    continue;
                }
                foreach ($hooks as $hook => $instances) {
                    if ('' !== $filter && $hook !== $filter) {
                        continue;
                    }
                    foreach ((array) $instances as $instance) {
                        $schedule = $instance['schedule'] ?? false;
                        $events[] = [
                            'hook'      => $hook,
                            'timestamp' => (int) $timestamp,
                            'schedule'  => $schedule ?: null,
                            'interval'  => isset($instance['interval']) ? (int) $instance['interval'] : null,
                            'args'      => array_values((array) ($instance['args'] ?? [])),
                        ];
                    }
                }
            }
        }

        return [
            'events'    => $events,
            'schedules' => $schedules,
        ];
    }
}
