<?php

namespace WPMCP\Integrations;

use WPMCP\MCP\Registrar;
use WPMCP\Safety\Save_Filters;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Thrive Automator automations (issue #830), paid-tier ops on the
 * plugin-data pair, so they add no top-level tool.
 *
 * Thrive Automator 10.6 keeps each automation as a tve_automation post
 * (never registered as a post type): the title, post_status publish (on)
 * or draft (off), and post_content holding a serialized list of steps, each
 * ['id', 'type' => triggers|filters|delay|actions, 'data' => [id => item]].
 * A trigger or action item names its class by 'key' and keeps its settings
 * under 'extra_data' as [field id => ['value' => ..., 'subfield' => ...]].
 * Triggers, actions, filters and apps are registered in memory on init
 * (Trigger::get(), Action::get(), Filter::get(), App::get()).
 *
 *  - thrive-automator-list-automations: id, title, on or off, the trigger
 *    and action names and whether every one is still available.
 *  - thrive-automator-get-automation: one automation's steps (triggers,
 *    filters, delays, actions) with their settings. Credentials never
 *    leave: secret-shaped keys, webhook header values, incoming webhook
 *    ids and the path and query of a webhook URL are redacted.
 *  - thrive-automator-list-catalog: the triggers, filters, actions and
 *    apps this site's Thrive Automator offers (names, no settings).
 *  - thrive-automator-enable-automation and -disable-automation: set the
 *    status the way Thrive's own toggle does (publish or draft, with its
 *    scheduled-trigger events cleared and set again). An enabled
 *    automation runs its actions (email, webhooks, user and post changes)
 *    on its next trigger, so enabling is destructive and needs
 *    confirm:true after a preview of what it would run. Thrive's own post
 *    hooks are held off around the write, so the automation's own status
 *    change never fires another automation's post published or updated
 *    trigger. Each write is a post snapshot, undone with
 *    rollback-operation; the undo resets the scheduled events through
 *    after_post_restored(). Both are off until the site opts in through
 *    wpmcp_enable_thrive_automator_write.
 *
 * Every op runs at manage_options, Thrive Automator's own admin capability.
 * Without the plugin the ops answer plugin_missing or plugin_inactive
 * naming it. Presence is filterable (wpmcp_thrive_automator_active,
 * default: TAP_VERSION is defined and the Automation class is loaded).
 */
final class Thrive_Automator_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    public const ENABLE_FILTER = 'wpmcp_enable_thrive_automator_write';

    /** Thrive Automator's automation post type (Automation::POST_TYPE). */
    public const POST_TYPE = 'tve_automation';

    private const AUTOMATION = '\Thrive\Automator\Items\Automation';

    private const HOOKS = 'Thrive\Automator\Hooks';

    private const MAX_PER_PAGE = 100;

    private const STEP_TYPES = [ 'triggers', 'filters', 'delay', 'actions' ];

    /** Item keys that are editor display data, not settings. */
    private const NOISE = [ 'image', 'interface_fields', 'search_keywords', 'summary_text', 'filterable_fields', 'matching_actions', 'access_url', 'description', 'provided_params', 'requires_params', 'fields', 'is_top_level', 'has_access', 'hook', 'priority' ];

    /** Keys whose value identifies an incoming webhook (its URL), so it is a credential. */
    private const WEBHOOK_SECRETS = [ 'webhook_id', 'webhook_hash' ];

    /** Whether Thrive Automator is loaded (filterable). */
    public static function plugin_active(): bool
    {
        return (bool) apply_filters('wpmcp_thrive_automator_active', defined('TAP_VERSION') && class_exists(self::AUTOMATION));
    }

    /** Whether Thrive Automator is usable and this install may run the paid tier. */
    public static function active(): bool
    {
        return Registrar::tier_permitted('pro') && self::plugin_active();
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        // The literal name, not ENABLE_FILTER, so the hook stays greppable.
        $enabled = (bool) apply_filters('wpmcp_enable_thrive_automator_write', false);
        $base    = [
            'tier'       => 'pro',
            'capability' => 'manage_options',
            'requires'   => static fn () => self::plugin_active() ? true : self::refusal(),
        ];
        $one     = [
            'type'                 => 'object',
            'properties'           => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
            'required'             => [ 'id' ],
            'additionalProperties' => false,
        ];
        $write   = $base + [
            'enabled_by_default' => $enabled,
            'opt_in_filter'      => self::ENABLE_FILTER,
            'input_schema'       => $one,
            'snapshot'           => static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => (int) $args['id'] ],
        ];

        return [
            'thrive-automator-list-automations'   => $base + [
                'mode'         => 'read',
                'description'  => 'List Thrive Automator automations: id, title, active, trigger and action names, and whether all are still available. Filter by status',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'status'   => [ 'type' => 'string', 'enum' => [ 'active', 'inactive' ] ],
                        'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE ],
                        'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
                    ],
                ],
                'handler'      => static fn (array $args): array => self::list_automations($args),
            ],
            'thrive-automator-get-automation'     => $base + [
                'mode'         => 'read',
                'description'  => 'Read one Thrive Automator automation: its triggers, filters, delays and actions with their settings (credentials and webhook URLs redacted)',
                'input_schema' => $one,
                'validate'     => static fn (array $args): ?array => self::missing_refusal((int) $args['id']),
                'handler'      => static fn (array $args): array => [ 'automation' => self::view((int) $args['id'], true) ],
            ],
            'thrive-automator-list-catalog'       => $base + [
                'mode'         => 'read',
                'description'  => 'Triggers, filters, actions and apps this site\'s Thrive Automator offers (ids, names, app). Optional kind and app',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'kind'   => [ 'type' => 'string', 'enum' => [ 'triggers', 'filters', 'actions', 'apps' ] ],
                        'app'    => [ 'type' => 'string', 'maxLength' => 100 ],
                    ],
                ],
                'handler'      => static fn (array $args): array => self::catalog($args),
            ],
            'thrive-automator-enable-automation'  => $write + [
                'mode'            => 'destructive',
                'description'     => 'Turn a Thrive Automator automation on: its actions (email, webhooks, user changes) run on its next trigger. Needs confirm:true after a preview. Undo: rollback-operation. Off unless ' . self::ENABLE_FILTER,
                'confirm_preview' => static fn (array $args): array => self::enable_preview((int) $args['id']),
                'validate'        => static fn (array $args): ?array => self::missing_refusal((int) $args['id']) ?? self::state_refusal((int) $args['id'], true),
                'handler'         => static fn (array $args): array => self::set_status((int) $args['id'], 'publish'),
            ],
            'thrive-automator-disable-automation' => $write + [
                'mode'        => 'write',
                'description' => 'Turn a Thrive Automator automation off (status draft); its settings stay. Undo: rollback-operation. Off unless ' . self::ENABLE_FILTER,
                'validate'    => static fn (array $args): ?array => self::missing_refusal((int) $args['id']) ?? self::state_refusal((int) $args['id'], false),
                'handler'     => static fn (array $args): array => self::set_status((int) $args['id'], 'draft'),
            ],
        ];
    }

    /**
     * plugin_missing or plugin_inactive naming Thrive Automator and the plugins screen.
     *
     * @return array{code: string, message: string, data: array<string, string>}
     */
    private static function refusal(): array
    {
        $refusal         = Plugin_Refusal::make('plugin_missing', 'Thrive Automator');
        $refusal['code'] = 'inactive' === $refusal['data']['state'] ? 'plugin_inactive' : 'plugin_missing';
        return $refusal;
    }

    // -----------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------

    private static function list_automations(array $args): array
    {
        $status = (string) ($args['status'] ?? '');
        $per    = (int) ($args['per_page'] ?? 50);
        $page   = (int) ($args['page'] ?? 1);
        $query  = new \WP_Query(
            [
                'post_type'           => self::POST_TYPE,
                'post_status'         => 'active' === $status ? 'publish' : ( 'inactive' === $status ? [ 'draft', 'pending', 'private', 'future' ] : [ 'publish', 'draft', 'pending', 'private', 'future' ] ),
                'posts_per_page'      => $per,
                'paged'               => $page,
                'orderby'             => 'ID',
                'order'               => 'ASC',
                'no_found_rows'       => false,
                'ignore_sticky_posts' => true,
            ]
        );
        $rows = [];
        foreach ($query->posts as $post) {
            $rows[] = self::view((int) $post->ID, false);
        }
        return [
            'automations' => $rows,
            'total'       => (int) $query->found_posts,
            'page'        => $page,
            'per_page'    => $per,
        ];
    }

    /** One automation: a summary row, or ($full) every step with its settings. */
    private static function view(int $id, bool $full): array
    {
        $post   = get_post($id);
        $steps  = self::steps($post);
        $out    = [
            'id'       => $id,
            'title'    => (string) $post->post_title,
            'active'   => 'publish' === $post->post_status,
            'status'   => (string) $post->post_status,
            'modified' => mysql_to_rfc3339((string) $post->post_modified_gmt),
        ];
        $names  = [ 'triggers' => [], 'actions' => [] ];
        $usable = true;
        foreach ($steps as $step) {
            if (! isset($names[ $step['type'] ])) {
                continue;
            }
            foreach ((array) $step['data'] as $item) {
                $key = is_array($item) ? (string) ($item['key'] ?? '') : '';
                if ('' === $key) {
                    continue;
                }
                $name                    = self::item_name($step['type'], $key);
                $usable                  = $usable && null !== $name;
                $names[ $step['type'] ][] = [ 'key' => $key, 'name' => $name ];
            }
        }
        $out['triggers'] = $names['triggers'];
        $out['actions']  = $names['actions'];
        $out['valid']    = $usable;
        if (! $full) {
            $out['steps'] = count($steps);
            return $out;
        }
        $out['steps'] = [];
        foreach ($steps as $step) {
            $items = [];
            foreach ((array) $step['data'] as $item_id => $item) {
                if (! is_array($item) || [] === $item) {
                    continue;
                }
                $items[] = [ 'id' => (string) $item_id ] + self::item($step['type'], $item);
            }
            $out['steps'][] = [ 'id' => (string) $step['id'], 'type' => $step['type'], 'items' => $items ];
        }
        $out['note'] = 'Credentials are never returned: secret-shaped settings, webhook header values, incoming webhook ids and webhook URL paths read [redacted].';
        return $out;
    }

    /** One stored step item as returned, settings scrubbed. */
    private static function item(string $type, array $item): array
    {
        foreach (self::NOISE as $key) {
            unset($item[ $key ]);
        }
        $out = [];
        if ('triggers' === $type || 'actions' === $type) {
            $key        = (string) ($item['key'] ?? '');
            $name       = self::item_name($type, $key);
            $out        = [ 'key' => $key, 'name' => $name, 'available' => null !== $name ];
            $out['app'] = isset($item['app_id']) ? (string) $item['app_id'] : null;
            unset($item['key'], $item['name'], $item['app_id'], $item['app_name']);
        } elseif ('filters' === $type) {
            $key = (string) ($item['filter'] ?? '');
            $out = [ 'filter' => $key, 'name' => self::item_name('filters', $key) ];
            unset($item['filter'], $item['name']);
        }
        return $out + [ 'settings' => self::scrub($item) ];
    }

    /**
     * The automation's steps from its post_content: a list of
     * ['id', 'type', 'data'] with a known type, objects never revived.
     *
     * @return list<array{id: string, type: string, data: array}>
     */
    private static function steps(\WP_Post $post): array
    {
        $raw   = (string) $post->post_content;
        $steps = is_serialized($raw) ? @unserialize($raw, [ 'allowed_classes' => false ]) : null; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Thrive stores the steps serialized; objects are never revived and a damaged string reads as no steps.
        $out   = [];
        foreach (is_array($steps) ? $steps : [] as $step) {
            if (! is_array($step) || ! in_array($step['type'] ?? '', self::STEP_TYPES, true)) {
                continue;
            }
            $out[] = [ 'id' => (string) ($step['id'] ?? ''), 'type' => (string) $step['type'], 'data' => is_array($step['data'] ?? null) ? $step['data'] : [] ];
        }
        return $out;
    }

    /** The display name of a registered trigger, action or filter, or null when this site no longer has it. */
    private static function item_name(string $type, string $key): ?string
    {
        $class = self::registry($type)[ $key ] ?? null;
        if (! is_string($class) || ! method_exists($class, 'get_name')) {
            return null;
        }
        return wp_strip_all_tags((string) $class::get_name());
    }

    /**
     * Thrive's in-memory registry for a step type: id => class name.
     *
     * @return array<string, string>
     */
    private static function registry(string $type): array
    {
        $map   = [ 'triggers' => 'Trigger', 'actions' => 'Action', 'filters' => 'Filter', 'apps' => 'App' ];
        $class = isset($map[ $type ]) ? '\Thrive\Automator\Items\\' . $map[ $type ] : '';
        if ('' === $class || ! class_exists($class) || ! method_exists($class, 'get')) {
            return [];
        }
        $out = [];
        foreach ((array) $class::get() as $id => $item) {
            $item = is_object($item) ? get_class($item) : $item;
            if (is_string($item)) {
                $out[ (string) $id ] = $item;
            }
        }
        return $out;
    }

    private static function catalog(array $args): array
    {
        $kinds = isset($args['kind']) ? [ (string) $args['kind'] ] : [ 'triggers', 'filters', 'actions', 'apps' ];
        $app   = isset($args['app']) ? (string) $args['app'] : null;
        $out   = [];
        foreach ($kinds as $kind) {
            $rows = [];
            foreach (self::registry($kind) as $id => $class) {
                $row = [ 'id' => $id, 'name' => self::call_string($class, 'get_name') ];
                if ('filters' !== $kind) {
                    $row['description'] = self::call_string($class, 'get_description');
                }
                if ('triggers' === $kind || 'actions' === $kind) {
                    $row['app_id']    = self::call_string($class, 'get_app_id');
                    $row['available'] = method_exists($class, 'has_access') ? (bool) $class::has_access() : true;
                }
                if (null !== $app && ( 'filters' === $kind || ( 'apps' === $kind ? $id : ( $row['app_id'] ?? '' ) ) !== $app )) {
                    continue;
                }
                $rows[] = $row;
            }
            $out[ $kind ] = $rows;
        }
        return $out;
    }

    private static function call_string(string $class, string $method): string
    {
        return method_exists($class, $method) ? wp_strip_all_tags((string) $class::$method()) : '';
    }

    /**
     * $value with every credential replaced by REDACTED, at any depth:
     * secret-shaped keys (api_key, token, password...), incoming webhook ids,
     * every webhook header value, the value of a {key, value} pair whose key
     * is secret-shaped, and a webhook URL cut to its scheme and host.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function scrub($value)
    {
        if (! is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $key => $item) {
            $name = (string) $key;
            if (is_string($key) && ( in_array($name, self::WEBHOOK_SECRETS, true) || ( 'key' !== $name && self::secret_key($name) ) )) {
                $out[ $key ] = Ops_Status_Packs::REDACTED;
                continue;
            }
            if ('url_webhook' === $name && is_array($item) && isset($item['value'])) {
                $item['value'] = self::url_origin($item['value']);
            }
            if ('webhook_headers' === $name && is_array($item) && is_array($item['value'] ?? null)) {
                foreach ($item['value'] as $i => $pair) {
                    if (is_array($pair) && array_key_exists('value', $pair)) {
                        $item['value'][ $i ]['value'] = Ops_Status_Packs::REDACTED;
                    }
                }
            }
            $out[ $key ] = self::scrub($item);
        }
        if (isset($out['key'], $out['value']) && is_string($out['key']) && self::secret_key($out['key'])) {
            $out['value'] = Ops_Status_Packs::REDACTED;
        }
        return $out;
    }

    private static function secret_key(string $key): bool
    {
        return Ops_Status_Packs::REDACTED === (Ops_Status_Packs::redact([ $key => 1 ])[ $key ] ?? null);
    }

    /** @param mixed $url */
    private static function url_origin($url): string
    {
        $parts = is_string($url) ? wp_parse_url($url) : false;
        if (! is_array($parts) || empty($parts['host'])) {
            return Ops_Status_Packs::REDACTED;
        }
        $origin = ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . ( isset($parts['port']) ? ':' . $parts['port'] : '' );
        return '' === trim((string) ( $parts['path'] ?? '' ), '/') && empty($parts['query']) ? $origin : $origin . '/' . Ops_Status_Packs::REDACTED;
    }

    // -----------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------

    /** @return array{message: string, data: array<string, mixed>} */
    private static function enable_preview(int $id): array
    {
        $view    = self::view($id, false);
        $actions = array_map(static fn (array $a): string => (string) ( $a['name'] ?? $a['key'] ), $view['actions']);
        return [
            'message' => sprintf(
                'Thrive Automator will turn on automation %d ("%s"). From its next trigger it runs its actions for real: %s. Undo with rollback-operation turns it off again, but anything it ran meanwhile stays done. Call again with confirm:true to turn it on.',
                $id,
                $view['title'],
                [] === $actions ? 'none' : implode(', ', $actions)
            ),
            'data'    => [
                'id'       => $id,
                'title'    => $view['title'],
                'triggers' => $view['triggers'],
                'actions'  => $view['actions'],
                'valid'    => $view['valid'],
            ],
        ];
    }

    private static function set_status(int $id, string $status): array
    {
        self::sync_events($id, false);
        $out = self::without_post_hooks(static fn () => Save_Filters::set_post_status($id, $status, true));
        if (is_wp_error($out)) {
            throw new Operation_Refused('update_failed', $out->get_error_message());
        }
        clean_post_cache($id);
        self::sync_events($id, true);

        $view         = self::view($id, false);
        $view['note'] = 'publish' === $status
            ? 'On: Thrive Automator runs it from its next trigger. Undo with rollback-operation.'
            : 'Off: its settings are kept. Undo with rollback-operation.';
        return [ 'automation' => $view ];
    }

    /**
     * Thrive's own scheduled-trigger bookkeeping around a status change, as
     * Automation::save() does it: clear before, set again after (only a
     * published automation is scheduled).
     */
    private static function sync_events(int $id, bool $schedule): void
    {
        $class  = self::AUTOMATION;
        $method = $schedule ? 'schedule_events' : 'unschedule_events';
        if (class_exists($class) && method_exists($class, $method)) {
            $class::$method($id);
        }
    }

    /**
     * Run $write with Thrive Automator's post published and post updated
     * listeners held off, so an automation's own status change is never
     * taken for a site post that another automation reacts to.
     *
     * @return mixed
     */
    private static function without_post_hooks(callable $write)
    {
        $held = [];
        foreach ([ [ 'transition_post_status', 'launch_post_status_hooks', 10, 3 ], [ 'post_updated', 'launch_post_updated_hook', 1, 3 ] ] as [ $hook, $method, $priority, $args ]) {
            $callback = [ self::HOOKS, $method ];
            if (false !== has_action($hook, $callback) && remove_action($hook, $callback, $priority)) {
                $held[] = [ $hook, $callback, $priority, $args ];
            }
        }
        try {
            return $write();
        } finally {
            foreach ($held as [ $hook, $callback, $priority, $args ]) {
                add_action($hook, $callback, $priority, $args);
            }
        }
    }

    /**
     * Rollback refresher (wpmcp_rollback_post_restored): an undo that put an
     * automation's status back resets Thrive's scheduled-trigger events for
     * it, as the forward write did.
     */
    public static function after_post_restored($post_id): void
    {
        $post_id = (int) $post_id;
        if ($post_id <= 0 || self::POST_TYPE !== get_post_type($post_id)) {
            return;
        }
        self::sync_events($post_id, false);
        self::sync_events($post_id, true);
    }

    // -----------------------------------------------------------------
    // Checks
    // -----------------------------------------------------------------

    public static function missing_refusal(int $id): ?array
    {
        $post = $id > 0 ? get_post($id) : null;
        if ($post instanceof \WP_Post && self::POST_TYPE === $post->post_type && 'trash' !== $post->post_status && 'auto-draft' !== $post->post_status) {
            return null;
        }
        return [ 'code' => 'automation_not_found', 'message' => sprintf('No Thrive Automator automation with id %d.', $id), 'data' => [ 'id' => $id ] ];
    }

    /** Refusal when the automation is already in the asked state, or null. */
    private static function state_refusal(int $id, bool $want_active): ?array
    {
        $active = 'publish' === get_post_status($id);
        if ($active !== $want_active) {
            return null;
        }
        return [
            'code'    => $want_active ? 'automation_already_active' : 'automation_already_inactive',
            'message' => sprintf('Thrive Automator automation %d is already %s.', $id, $want_active ? 'on' : 'off'),
            'data'    => [ 'id' => $id ],
        ];
    }
}
