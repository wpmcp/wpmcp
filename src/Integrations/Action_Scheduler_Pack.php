<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Action_Scheduler_Snapshot;
use WPMCP\Tools\Cron\Core_Hooks;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Action Scheduler (issue #702) on the plugin-data pair, free tier, so it
 * adds no top-level tool. Action Scheduler ships inside WooCommerce and many
 * other plugins, and also as a standalone plugin: "my store emails did not
 * send" is usually a stuck queue. Ops are prefixed actionscheduler- because
 * the pair is shared.
 *
 * Everything goes through Action Scheduler's own store, ActionScheduler::
 * store() (query_actions, fetch_action, get_status, get_date, cancel_action),
 * its logger and its queue runner, never SQL, so the database store and the
 * posts store behave the same.
 *
 * Reads: actionscheduler-list-actions (filters hook, group, status, and a
 * scheduled date with a comparison; page and per_page; newest first unless
 * order says otherwise) and actionscheduler-get-action (one action with its
 * args and its log entries). Action args can carry personal data and
 * secrets, so a list shows every email address and IP address masked and
 * every secret-shaped value (a token, key, password or signature, found by
 * its key or by its look) hidden; get-action returns the args in full except
 * those secret-shaped values, which stay hidden.
 *
 * Writes, off until a site opts in with wpmcp_enable_action_scheduler_write
 * (a queue action runs plugin code, so even a cancel is a decision):
 *  - actionscheduler-cancel-action cancels one pending action. Undoable:
 *    the action is snapshotted first (Action_Scheduler_Snapshot) and
 *    rollback-operation queues it again, pending, on its old schedule.
 *  - actionscheduler-run-action runs one pending action now through the
 *    queue runner, whatever its schedule says. NOT undoable (what its hook
 *    does, such as sending mail or calling a service, stays done), so it is
 *    reported recoverable:false and needs confirm:true.
 * Both refuse WP MCP's own hooks (Core_Hooks::is_internal, issue #563), the
 * same ones schedule-event refuses, and both take manage_options, the
 * capability Action Scheduler's admin screen takes.
 *
 * Presence is filterable (wpmcp_action_scheduler_active); without it the ops
 * stay documented in list-operations (dependency_met:false) and answer
 * plugin_missing or plugin_inactive.
 */
final class Action_Scheduler_Pack
{
    public const CAPABILITY = 'manage_options';

    public const OPT_IN_FILTER = 'wpmcp_enable_action_scheduler_write';

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    /** The highest page a list answers, so the offset stays bounded on a store with millions of rows. */
    public const MAX_PAGE = 1000;

    /** The most bytes of masked args one list row carries; get-action returns them whole. */
    public const MAX_LIST_ARGS_BYTES = 2048;

    /** The most log entries one get-action returns (the newest). */
    public const MAX_LOG_ENTRIES = 100;

    /** Plugins that carry Action Scheduler, for the missing versus inactive answer. */
    public const PLUGIN_FILES = [ 'action-scheduler/action-scheduler.php', 'woocommerce/woocommerce.php' ];

    /** What the tool says for a status; Action Scheduler calls running "in-progress". */
    private const STATUS_OUT = [ 'in-progress' => 'running' ];

    private const STATUSES = [ 'pending', 'complete', 'failed', 'canceled', 'running' ];

    private const DATE_COMPARES = [ '<', '<=', '>', '>=', '=' ];

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_action_scheduler_active', class_exists('ActionScheduler') && function_exists('as_get_scheduled_actions'));
    }

    /** Whether the store is ready to answer (Action Scheduler sets it up on init). */
    private static function ready(): bool
    {
        return class_exists('ActionScheduler') && \ActionScheduler::is_initialized();
    }

    /**
     * @return true|array{code: string, message: string, data: array}
     */
    public static function presence()
    {
        $url = admin_url('plugins.php');
        if (! self::active()) {
            $installed = false;
            foreach (self::PLUGIN_FILES as $file) {
                $installed = $installed || (defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/' . $file));
            }
            return [
                'code'    => $installed ? 'plugin_inactive' : 'plugin_missing',
                'message' => sprintf(
                    'Action Scheduler is %s on this site. Install and activate Action Scheduler (bundled with WooCommerce and many plugins; %s), then try again.',
                    $installed ? 'not loaded' : 'not installed',
                    $url
                ),
                'data'    => [ 'plugin' => 'action-scheduler', 'plugins_url' => $url ],
            ];
        }
        if (! self::ready()) {
            return [
                'code'    => 'action_scheduler_not_ready',
                'message' => 'Action Scheduler is loaded but its data store is not initialized yet on this request, so its queue cannot be read. Try again.',
                'data'    => [ 'plugin' => 'action-scheduler' ],
            ];
        }
        return true;
    }

    // ------------------------------------------------------------------
    // Catalog
    // ------------------------------------------------------------------

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $id    = [ 'type' => 'integer', 'minimum' => 1 ];
        $one   = [ 'type' => 'object', 'properties' => [ 'action_id' => $id ], 'required' => [ 'action_id' ] ];
        $base  = [
            'capability' => self::CAPABILITY,
            'requires'   => static fn () => self::presence(),
        ];
        $write = $base + [
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_action_scheduler_write', false),
            'opt_in_filter'      => self::OPT_IN_FILTER,
        ];

        return [
            'actionscheduler-list-actions'  => $base + [
                'mode'         => 'read',
                'description'  => 'List Action Scheduler queue actions (WooCommerce and many plugins use it): id, hook, status, group, schedule, scheduled time (UTC; for a finished action the last attempt) and args with emails, IPs and tokens MASKED. Filters: hook, group, status (pending, complete, failed, canceled, running), date with date_compare (< <= > >= =, default <=); order asc or desc by date (default desc); page (max 1000) and per_page (default 20, max 100); args over 2 KB are cut to a preview (args_truncated), get-action returns them whole; total is an exact count. Masks emails and IPv4 only. Personal data',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'hook'         => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 191 ],
                        'group'        => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 255 ],
                        'status'       => [ 'type' => 'string', 'enum' => self::STATUSES ],
                        'date'         => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 40 ],
                        'date_compare' => [ 'type' => 'string', 'enum' => self::DATE_COMPARES ],
                        'order'        => [ 'type' => 'string', 'enum' => [ 'asc', 'desc' ] ],
                        'page'         => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE ],
                        'per_page'     => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE ],
                    ],
                ],
                'validate'     => static fn (array $args): ?array => self::list_refusal($args),
                'handler'      => static fn (array $args): array => self::list_actions($args),
            ],
            'actionscheduler-get-action'    => $base + [
                'mode'         => 'read',
                'description'  => 'One Action Scheduler action in full: hook, status, group, priority, schedule, scheduled time (UTC), args (tokens, keys and passwords hidden) and its log entries (the newest 100, oldest first), which say why a run failed. Personal data',
                'input_schema' => $one,
                'validate'     => static fn (array $args): ?array => self::exists_refusal((int) $args['action_id']),
                'handler'      => static fn (array $args): array => [ 'action' => self::detail((int) $args['action_id']) ],
            ],
            'actionscheduler-cancel-action' => $write + [
                'mode'         => 'write',
                'description'  => 'Cancel one pending Action Scheduler action so it never runs. Refuses an action that is not pending and WP MCP\'s own hooks. Off unless ' . self::OPT_IN_FILTER . '. Undo: rollback-operation queues it again, pending, on its old schedule (the canceled record keeps its id)',
                'input_schema' => $one,
                'validate'     => static fn (array $args): ?array => self::pending_refusal((int) $args['action_id'], 'cancel'),
                'snapshot'     => static fn (array $args): array => [
                    'object_type' => Action_Scheduler_Snapshot::TYPE,
                    'object_id'   => (string) (int) $args['action_id'],
                ],
                'handler'      => static fn (array $args): array => self::cancel((int) $args['action_id']),
            ],
            'actionscheduler-run-action'    => $write + [
                'mode'            => 'destructive',
                'description'     => 'Run one pending Action Scheduler action now through the queue runner, whatever its schedule. Its hook\'s callbacks execute (mail, API calls, syncs) and that CANNOT be undone: recoverable:false, and it needs confirm:true after a preview. Refuses an action that is not pending and WP MCP\'s own hooks. Off unless ' . self::OPT_IN_FILTER,
                'input_schema'    => $one,
                'validate'        => static fn (array $args): ?array => self::pending_refusal((int) $args['action_id'], 'run'),
                'confirm_preview' => static fn (array $args): array => self::run_preview((int) $args['action_id']),
                'handler'         => static fn (array $args): array => self::run((int) $args['action_id']),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Refusals
    // ------------------------------------------------------------------

    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    private static function list_refusal(array $args): ?array
    {
        if (isset($args['date']) && null === self::parse_date((string) $args['date'])) {
            return self::refusal('invalid_date', sprintf('"%s" is not a date. Use YYYY-MM-DD or YYYY-MM-DD HH:MM:SS (UTC), or a unix timestamp.', (string) $args['date']), [ 'date' => (string) $args['date'] ]);
        }
        if (isset($args['date_compare']) && ! isset($args['date'])) {
            return self::refusal('date_required', 'date_compare needs a date to compare with.', []);
        }
        return null;
    }

    private static function exists_refusal(int $id): ?array
    {
        if (null === Action_Scheduler_Snapshot::status($id)) {
            return self::refusal('action_not_found', sprintf('Action Scheduler has no action %d.', $id), [ 'action_id' => $id ]);
        }
        return null;
    }

    private static function pending_refusal(int $id, string $verb): ?array
    {
        $missing = self::exists_refusal($id);
        if (null !== $missing) {
            return $missing;
        }
        $hook = (string) \ActionScheduler::store()->fetch_action($id)->get_hook();
        if (Core_Hooks::is_internal($hook)) {
            return self::refusal('internal_hook', sprintf('Refusing to %s action %d: "%s" is one of WP MCP\'s own hooks and moves on its own schedule.', $verb, $id, $hook), [ 'action_id' => $id, 'hook' => $hook ]);
        }
        $status = (string) Action_Scheduler_Snapshot::status($id);
        if (Action_Scheduler_Snapshot::PENDING !== $status) {
            return self::refusal(
                'action_not_pending',
                sprintf('Action %d is %s: only a pending action can be %s.', $id, self::STATUS_OUT[ $status ] ?? $status, 'run' === $verb ? 'run now' : 'canceled'),
                [ 'action_id' => $id, 'status' => self::STATUS_OUT[ $status ] ?? $status ]
            );
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    /** A date argument as a UTC DateTime, or null when it is not one. */
    private static function parse_date(string $value): ?\DateTime
    {
        $value = trim($value);
        if (1 === preg_match('/^\d{9,11}$/', $value)) {
            return as_get_datetime_object((int) $value);
        }
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?(Z|[+\-]\d{2}:?\d{2})?)?$/', $value)) {
            return null;
        }
        try {
            $date = new \DateTime($value, new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            return null;
        }
        return $date->setTimezone(new \DateTimeZone('UTC'));
    }

    private static function status_out(string $status): string
    {
        return self::STATUS_OUT[ $status ] ?? $status;
    }

    private static function iso(?\DateTime $date): ?string
    {
        return null === $date ? null : gmdate('Y-m-d\TH:i:s\Z', $date->getTimestamp());
    }

    /** The schedule of an action in words: type and, for a recurring one, how often. */
    private static function schedule_of(\ActionScheduler_Action $action): array
    {
        $schedule = $action->get_schedule();
        if ($schedule instanceof \ActionScheduler_CronSchedule) {
            return [ 'type' => 'cron', 'cron' => (string) $schedule->get_recurrence() ];
        }
        if ($schedule instanceof \ActionScheduler_IntervalSchedule) {
            return [ 'type' => 'interval', 'interval_seconds' => (int) $schedule->get_recurrence() ];
        }
        if ($schedule instanceof \ActionScheduler_NullSchedule) {
            return [ 'type' => 'async' ];
        }
        if ($schedule instanceof \ActionScheduler_CanceledSchedule) {
            return [ 'type' => 'canceled' ];
        }
        return [ 'type' => 'single' ];
    }

    /**
     * When an action is or was due, as ISO 8601 UTC, or null (issue #955).
     *
     * The store answers the date it ran for anything but a pending action,
     * and a canceled action never ran, so the store gives a zero date. A
     * canceled action reports the date its schedule still holds; any other
     * zero date falls back the same way, else null.
     */
    private static function scheduled_at($store, int $id, \ActionScheduler_Action $action, string $status): ?string
    {
        $stored = null;
        if (Action_Scheduler_Snapshot::CANCELED !== $status) {
            try {
                $stored = $store->get_date($id);
            } catch (\Throwable $e) {
                $stored = null;
            }
            if ($stored instanceof \DateTime && $stored->getTimestamp() > 0) {
                return self::iso($stored);
            }
        }
        $planned = $action->get_schedule();
        $date    = null;
        if (is_object($planned) && method_exists($planned, 'get_date')) {
            try {
                $date = $planned->get_date();
            } catch (\Throwable $e) {
                $date = null;
            }
        }
        return $date instanceof \DateTime && $date->getTimestamp() > 0 ? self::iso($date) : null;
    }

    /**
     * The facts of one action, with args as the caller's masking asks.
     *
     * @return array<string, mixed>
     */
    private static function view(int $id, bool $mask_personal): array
    {
        $store  = \ActionScheduler::store();
        $action = $store->fetch_action($id);
        $status = (string) Action_Scheduler_Snapshot::status($id);
        $when   = self::scheduled_at($store, $id, $action, $status);
        $args   = (array) $action->get_args();
        return [
            'id'           => $id,
            'hook'         => (string) $action->get_hook(),
            'status'       => self::status_out($status),
            'group'        => (string) $action->get_group(),
            'priority'     => method_exists($action, 'get_priority') ? (int) $action->get_priority() : null,
            'schedule'     => self::schedule_of($action),
            'scheduled_at' => $when,
            'args'         => $mask_personal ? self::mask_args($args) : self::hide_secrets($args),
        ];
    }

    /** @return array<string, mixed> */
    private static function list_actions(array $args): array
    {
        $per_page = max(1, min(self::MAX_PER_PAGE, (int) ($args['per_page'] ?? self::DEFAULT_PER_PAGE)));
        $page     = max(1, min(self::MAX_PAGE, (int) ($args['page'] ?? 1)));
        $query    = [];
        foreach ([ 'hook', 'group' ] as $key) {
            if (isset($args[ $key ])) {
                $query[ $key ] = (string) $args[ $key ];
            }
        }
        if (isset($args['status'])) {
            $status          = (string) $args['status'];
            $query['status'] = 'running' === $status ? \ActionScheduler_Store::STATUS_RUNNING : $status;
        }
        if (isset($args['date'])) {
            $query['date']         = self::parse_date((string) $args['date']);
            $query['date_compare'] = (string) ($args['date_compare'] ?? '<=');
        }
        $store = \ActionScheduler::store();
        $total = (int) $store->query_actions($query, 'count');
        $ids   = (array) $store->query_actions($query + [
            'per_page' => $per_page,
            'offset'   => ($page - 1) * $per_page,
            'orderby'  => 'date',
            'order'    => 'asc' === ($args['order'] ?? 'desc') ? 'ASC' : 'DESC',
        ]);
        $rows  = [];
        foreach ($ids as $id) {
            $rows[] = self::cap_args(self::view((int) $id, true));
        }
        return [
            'actions'     => $rows,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'args_masked' => true,
            'store'       => get_class($store),
        ];
    }

    /**
     * A list row with its args cut to MAX_LIST_ARGS_BYTES: a larger payload
     * becomes its first bytes as a preview, with args_truncated and the size.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function cap_args(array $row): array
    {
        $json = (string) wp_json_encode($row['args']);
        $row['args_truncated'] = false;
        if (strlen($json) > self::MAX_LIST_ARGS_BYTES) {
            $row['args']           = [ 'preview' => mb_strcut($json, 0, self::MAX_LIST_ARGS_BYTES, 'UTF-8') ];
            $row['args_truncated'] = true;
            $row['args_bytes']     = strlen($json);
        }
        return $row;
    }

    /** @return array<string, mixed> */
    private static function detail(int $id): array
    {
        $action         = self::view($id, false);
        $entries        = (array) \ActionScheduler::logger()->get_logs($id);
        $entries        = array_slice($entries, -self::MAX_LOG_ENTRIES);
        $action['logs'] = [];
        foreach ($entries as $entry) {
            $action['logs'][] = [
                'date'    => self::iso($entry->get_date()),
                'message' => self::mask_text((string) $entry->get_message(), false),
            ];
        }
        $action['store'] = get_class(\ActionScheduler::store());
        return $action;
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    private static function cancel(int $id): array
    {
        $hook = (string) \ActionScheduler::store()->fetch_action($id)->get_hook();
        \ActionScheduler::store()->cancel_action($id);
        return [
            'action_id' => $id,
            'hook'      => $hook,
            'status'    => self::status_out((string) Action_Scheduler_Snapshot::status($id)),
            'note'      => 'Undo with rollback-operation: it queues the action again on its old schedule.',
        ];
    }

    private static function run_preview(int $id): array
    {
        $view = self::view($id, true);
        return [
            'message' => sprintf(
                'Action Scheduler will run action %d (hook "%s", group "%s") now. Whatever its callbacks do (send mail, call a service, change data) happens for real and CANNOT be undone. Call again with confirm:true to run it.',
                $id,
                $view['hook'],
                '' === $view['group'] ? 'none' : $view['group']
            ),
            'data'    => [
                'action_id'    => $id,
                'hook'         => $view['hook'],
                'group'        => $view['group'],
                'scheduled_at' => $view['scheduled_at'],
                'args'         => $view['args'],
            ],
        ];
    }

    private static function run(int $id): array
    {
        $before = count((array) \ActionScheduler::logger()->get_logs($id));
        \ActionScheduler::runner()->process_action($id, 'WP MCP');
        $status = (string) Action_Scheduler_Snapshot::status($id);
        $logs   = array_slice((array) \ActionScheduler::logger()->get_logs($id), $before);
        $lines  = [];
        foreach ($logs as $entry) {
            $lines[] = self::mask_text((string) $entry->get_message(), false);
        }
        return [
            'action_id' => $id,
            'ran'       => Action_Scheduler_Snapshot::PENDING !== $status,
            'status'    => self::status_out($status),
            'log'       => $lines,
            'note'      => 'Not undoable: what the hook did stays done.',
        ];
    }

    // ------------------------------------------------------------------
    // Masking
    // ------------------------------------------------------------------

    /** Words that mark a key as naming a secret, found anywhere in the key. */
    private const SECRET_WORDS = 'secret|token|passw|pwd|api[_\-]?key|apikey|auth|nonce|licen[sc]e|signature|credential|private[_\-]?key|bearer|cookie|salt';

    /** What a hidden value reads as. */
    public const HIDDEN = '[redacted]';

    /** The deepest nesting of JSON or serialized strings inside strings that is opened. */
    private const MAX_DEPTH = 6;

    /** Whether a key names a secret: secret, token, password, api key, auth, nonce, license and the like, or a bare "key". */
    public static function is_secret_key(string $key): bool
    {
        $spaced = (string) preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $key);
        $norm   = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', $spaced));
        return 1 === preg_match('/(' . self::SECRET_WORDS . ')/', $norm) || 1 === preg_match('/(^|_)key($|_)/', $norm);
    }

    /**
     * A text with its secrets hidden and, when $personal, every email and IP
     * address masked (j***@example.com, 203.0.*.*). Works on free text:
     * "client_secret=abc", "Bearer ...", token-looking values and the
     * serialized form s:8:"password";s:7:"hunter2";.
     */
    public static function mask_text(string $text, bool $personal = true): string
    {
        if ($personal) {
            $text = Activity_Log_Pack::mask_text($text);
        }
        $words = self::SECRET_WORDS;
        $text  = (string) preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=\-]{8,}/i', '$1 ***', $text);
        // s:8:"password";s:7:"hunter2"; : the value of a secret-named key.
        $text = (string) preg_replace('/(s:\d+:"[^"]*(?:' . $words . ')[^"]*";)s:\d+:"[^"]*";/i', '$1s:3:"***";', $text);
        // key=value, key: value, "key":"value", with the key containing a secret word.
        $text = (string) preg_replace('/([A-Za-z0-9_\-]*(?:' . $words . ')[A-Za-z0-9_\-]*["\']?\s*[=:]\s*["\']?)[^\s&"\',;}\]]+/i', '$1***', $text);
        $text = (string) preg_replace('/\b(?:sk|pk|rk|ghp|gho|ghs|xox[abp])[_-][A-Za-z0-9_\-]{12,}/', '***', $text);
        $text = (string) preg_replace('/(?<![A-Za-z0-9_\-])(?=[A-Za-z0-9_\-]*\d)(?=[A-Za-z0-9_\-]*[A-Za-z])[A-Za-z0-9_\-]{32,}(?![A-Za-z0-9_\-])/', '***', $text);
        return $text;
    }

    /**
     * Args for a list: secret-named keys hidden, and every string, JSON or
     * serialized string included, with its emails, IPs and token-looking
     * values masked, at any depth.
     */
    public static function mask_args(array $args): array
    {
        return self::walk($args, true, 0);
    }

    /** Args for a single read: secrets hidden the same way, emails and the rest as stored. */
    public static function hide_secrets(array $args): array
    {
        return self::walk($args, false, 0);
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private static function walk(array $value, bool $personal, int $depth): array
    {
        foreach ($value as $key => $item) {
            if (is_string($key) && self::is_secret_key($key)) {
                $value[ $key ] = self::HIDDEN;
            } elseif (is_array($item)) {
                $value[ $key ] = self::walk($item, $personal, $depth);
            } elseif (is_string($item)) {
                $value[ $key ] = self::mask_string($item, $personal, $depth);
            }
        }
        return $value;
    }

    /**
     * One string: a JSON or serialized payload is opened, masked by key and
     * written back in the same form; anything else is masked as text. A
     * serialized object is never built (allowed_classes false), and a
     * payload that does not open is masked as text.
     */
    private static function mask_string(string $text, bool $personal, int $depth): string
    {
        $trim = ltrim($text);
        if ($depth < self::MAX_DEPTH && '' !== $trim) {
            if (in_array($trim[0], [ '{', '[' ], true)) {
                $decoded = json_decode($trim, true);
                if (is_array($decoded)) {
                    return (string) wp_json_encode(self::walk($decoded, $personal, $depth + 1));
                }
            } elseif (1 === preg_match('/^(?:a|O|C|s|i|b|d):/', $trim) && 1 !== preg_match('/(?:^|[;{}])[OC]:\d+:"/', $trim)) {
                // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- reads a stored arg to mask it; allowed_classes false builds no object.
                $decoded = @unserialize($trim, [ 'allowed_classes' => false ]);
                if (is_array($decoded)) {
                    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- writes the masked copy back in the form it was stored.
                    return serialize(self::walk($decoded, $personal, $depth + 1));
                }
            }
        }
        return self::mask_text($text, $personal);
    }
}
