<?php

// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional (matches the rest of WPMCP\Safety).
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional (matches the rest of WPMCP\Safety).

namespace WPMCP\Safety;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Snapshot of one queued Action Scheduler action before it is canceled
 * (issue #702). The object id is the action id.
 *
 * Everything is read and written through Action Scheduler's own store
 * (ActionScheduler::store()) and its public scheduling functions, never SQL,
 * so it works the same on the database store and on the posts store.
 *
 * The image holds what is needed to queue the same work again: hook, args,
 * group, priority, the status it had and its schedule (kind, the time it was
 * due and the interval or cron expression). Only a pending action is
 * captured with a schedule: Action Scheduler replaces the schedule of a
 * canceled action with a placeholder, so a canceled record cannot say what
 * it was.
 *
 * The store has no call that reopens a canceled action, so the undo queues
 * the same action again, pending, on the same schedule, and the canceled
 * record keeps its id. The image also counts the identical pending actions
 * (same hook, args and group, this one included) there were before the
 * cancel (issue #954). The undo is skipped, with a warning, when the action
 * is not canceled any more (it was queued again, deleted or run) or when at
 * least that many identical pending actions are already there, so a double
 * undo never queues a duplicate while the cancel of one of two identical
 * actions is still undone. A past due time is queued as is: the queue runs
 * it next.
 */
final class Action_Scheduler_Snapshot
{
    public const TYPE = 'action_scheduler';

    /** Action Scheduler's status words, spelled out so a missing plugin never reaches its classes. */
    public const PENDING = 'pending';

    public const CANCELED = 'canceled';

    /** Schedule kinds an image records. */
    public const KINDS = [ 'single', 'async', 'interval', 'cron' ];

    public static function capture(string $key): array
    {
        $id = max(0, (int) $key);
        return [
            'object_type' => self::TYPE,
            'object_id'   => (string) $id,
            'data'        => [ 'id' => $id, 'before' => self::image($id) ],
        ];
    }

    /** The status of an action, or null when the store has no such action. */
    public static function status(int $id): ?string
    {
        if ($id <= 0 || ! class_exists('ActionScheduler') || ! \ActionScheduler::is_initialized()) {
            return null;
        }
        try {
            $status = (string) \ActionScheduler::store()->get_status($id);
        } catch (\Throwable $e) {
            return null;
        }
        return '' === $status ? null : $status;
    }

    /**
     * What a pending action is, read live through the store.
     *
     * @return array<string, mixed>
     */
    public static function image(int $id): array
    {
        $status = self::status($id);
        if (null === $status) {
            return [ 'exists' => false ];
        }
        $store  = \ActionScheduler::store();
        $action = $store->fetch_action($id);
        $image  = [
            'exists' => true,
            'status' => $status,
            'hook'   => (string) $action->get_hook(),
        ];
        if (self::PENDING !== $status) {
            return $image;
        }
        $schedule = $action->get_schedule();
        $due      = 0;
        try {
            $due = (int) $store->get_date($id)->getTimestamp();
        } catch (\Throwable $e) {
            $due = 0;
        }
        $image['args']     = (array) $action->get_args();
        $image['group']    = (string) $action->get_group();
        $image['priority'] = method_exists($action, 'get_priority') ? (int) $action->get_priority() : 10;
        $image['due']      = $due;
        $image['twins']    = self::count_identical($image);
        if ($schedule instanceof \ActionScheduler_CronSchedule) {
            $image['kind'] = 'cron';
            $image['cron'] = (string) $schedule->get_recurrence();
        } elseif ($schedule instanceof \ActionScheduler_IntervalSchedule) {
            $image['kind']     = 'interval';
            $image['interval'] = (int) $schedule->get_recurrence();
        } elseif ($schedule instanceof \ActionScheduler_NullSchedule) {
            $image['kind'] = 'async';
        } else {
            $image['kind'] = 'single';
        }
        return $image;
    }

    /**
     * Queue an action again from an image, through Action Scheduler's
     * public scheduling functions. Returns the new action id, 0 when the
     * store refused it.
     *
     * @param array<string, mixed> $image
     */
    public static function requeue(array $image): int
    {
        $hook     = (string) ($image['hook'] ?? '');
        $args     = (array) ($image['args'] ?? []);
        $group    = (string) ($image['group'] ?? '');
        $priority = (int) ($image['priority'] ?? 10);
        $due      = (int) ($image['due'] ?? 0);
        $kind     = (string) ($image['kind'] ?? 'single');
        if ('' === $hook || ! in_array($kind, self::KINDS, true)) {
            return 0;
        }
        if ($due <= 0) {
            $due = time();
        }
        switch ($kind) {
            case 'async':
                $new = as_enqueue_async_action($hook, $args, $group, false, $priority);
                break;
            case 'interval':
                $new = as_schedule_recurring_action($due, (int) ($image['interval'] ?? 0), $hook, $args, $group, false, $priority);
                break;
            case 'cron':
                $new = as_schedule_cron_action($due, (string) ($image['cron'] ?? ''), $hook, $args, $group, false, $priority);
                break;
            default:
                $new = as_schedule_single_action($due, $hook, $args, $group, false, $priority);
        }
        return (int) $new;
    }

    /**
     * The id of a pending action that is the same work as the image, or 0.
     *
     * @param array<string, mixed> $image
     */
    public static function find_identical(array $image): int
    {
        $found = \ActionScheduler::store()->query_actions([
            'hook'     => (string) ($image['hook'] ?? ''),
            'args'     => (array) ($image['args'] ?? []),
            'group'    => (string) ($image['group'] ?? ''),
            'status'   => self::PENDING,
            'per_page' => 1,
        ]);
        return is_array($found) && [] !== $found ? (int) reset($found) : 0;
    }

    /**
     * How many pending actions are the same work as the image (issue #954).
     *
     * @param array<string, mixed> $image
     */
    public static function count_identical(array $image): int
    {
        try {
            return (int) \ActionScheduler::store()->query_actions([
                'hook'   => (string) ($image['hook'] ?? ''),
                'args'   => (array) ($image['args'] ?? []),
                'group'  => (string) ($image['group'] ?? ''),
                'status' => self::PENDING,
            ], 'count');
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Undo the cancel. Returns a warning naming what happened (the new id,
     * or why nothing was done), or null.
     *
     * @throws Mutation_Failed When the current user may not restore it, or the store refused.
     */
    public static function restore(array $snapshot): ?string
    {
        $data   = (array) ($snapshot['data'] ?? []);
        $id     = (int) ($data['id'] ?? 0);
        $before = (array) ($data['before'] ?? []);
        if ($id <= 0 || empty($before['exists']) || self::PENDING !== ($before['status'] ?? '')) {
            return null;
        }
        if (! current_user_can('manage_options')) {
            throw new Mutation_Failed(esc_html(sprintf('Rollback refused: restoring Action Scheduler action %d needs manage_options.', $id)));
        }
        if (! class_exists('ActionScheduler') || ! \ActionScheduler::is_initialized()) {
            throw new Mutation_Failed(esc_html(sprintf('Rollback refused: Action Scheduler is not available, so action %d cannot be queued again.', $id)));
        }

        $now = self::status($id);
        if (self::CANCELED !== $now) {
            return sprintf('Action Scheduler action %d is %s now, not canceled, so it was left as it is.', $id, null === $now ? 'gone' : $now);
        }
        // An image from before issue #954 has no count: it held one action.
        $twins = max(1, (int) ($before['twins'] ?? 1));
        $same  = self::find_identical($before);
        if ($same > 0 && self::count_identical($before) >= $twins) {
            return sprintf('Action Scheduler action %d was not queued again: an identical pending action (%d) already exists.', $id, $same);
        }
        $new = self::requeue($before);
        if ($new <= 0) {
            throw new Mutation_Failed(esc_html(sprintf('Rollback failed: Action Scheduler would not queue action %d again.', $id)));
        }
        return sprintf('Action Scheduler action %d was queued again as pending action %d on its old schedule; the canceled record keeps id %d, since the store cannot reopen it.', $id, $new, $id);
    }
}
