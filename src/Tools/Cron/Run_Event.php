<?php

namespace WPMCP\Tools\Cron;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Fire a scheduled cron hook now via do_action(), for debugging scheduled
 * jobs without waiting for the next run.
 *
 * Two guards, both on the safe side:
 *  1. Disabled by default. A site must opt in with
 *     add_filter('wpmcp_enable_run_cron_event', '__return_true') before any
 *     hook can be fired, matching the disabled-by-default posture of the
 *     other high-blast-radius write tools (update-option, db/fs writes):
 *     running a hook triggers whatever its registered callbacks do, an
 *     unbounded and irreversible side effect.
 *  2. Only a hook that is actually present in the cron array can be fired,
 *     never an arbitrary string, so this cannot be used to invoke random
 *     code paths WordPress was not already going to run unattended.
 *
 * The callback args are ALWAYS the ones stored in the scheduled event, never
 * caller-supplied: a caller must not be able to invoke a hook with arbitrary
 * parameters. Any 'args' passed by the caller is ignored.
 *
 * NOT routed through Safe_Mutation and does NOT touch the safety core: firing
 * a hook is a side effect with no before-image to restore (the same reasoning
 * documented for clear-cache), so it is not snapshotted or rolled back.
 */
class Run_Event
{
    public static function is_enabled(): bool
    {
        return (bool) apply_filters('wpmcp_enable_run_cron_event', false);
    }

    public function handle(array $args): array
    {
        $hook = isset($args['hook']) ? (string) $args['hook'] : '';
        if ('' === $hook) {
            throw new \InvalidArgumentException('A hook is required.');
        }

        if (! self::is_enabled()) {
            throw new \RuntimeException('Running cron events is disabled. Enable it with the wpmcp_enable_run_cron_event filter.');
        }

        $stored_args = self::scheduled_args($hook);
        if (null === $stored_args) {
            throw new \RuntimeException('Hook "' . esc_html($hook) . '" is not scheduled; refusing to run it.');
        }

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- fires an already-scheduled cron hook on request; this tool does not define a hook of its own.
        do_action_ref_array($hook, $stored_args);

        return [
            'hook' => $hook,
            'ran'  => true,
        ];
    }

    /**
     * Return the stored callback args for the first scheduled occurrence of
     * $hook (already re-indexed), or null if the hook is not in the cron
     * array. A scheduled event with no args yields an empty array, which is a
     * valid "found, no args" result distinct from the null "not scheduled".
     */
    private static function scheduled_args(string $hook): ?array
    {
        $cron = _get_cron_array();
        if (! is_array($cron)) {
            return null;
        }
        foreach ($cron as $hooks) {
            if (isset($hooks[ $hook ]) && is_array($hooks[ $hook ])) {
                $instance = reset($hooks[ $hook ]);
                return array_values((array) ($instance['args'] ?? []));
            }
        }
        return null;
    }
}
