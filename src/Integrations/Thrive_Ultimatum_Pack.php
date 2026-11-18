<?php

namespace WPMCP\Integrations;

use WPMCP\MCP\Registrar;
use WPMCP\Safety\Plugin_Table_Rows_Snapshot;
use WPMCP\Safety\Post_Creation_Snapshot;
use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Thrive Ultimatum countdown campaigns (issue #828) on the plugin-data pair,
 * paid-tier, so they add no top-level tools. Ops are prefixed ultimatum-.
 *
 * Thrive Ultimatum (10.9.1.4 read; TVE_Ult_Const is its constants class,
 * loaded from its main file) keeps a campaign as:
 *  - a tve_ult_campaign post, always post_status publish, with the metas
 *    tve_ult_campaign_type (absolute, rolling, evergreen), tve_ult_rolling_type,
 *    tve_ult_status (running or paused), tve_ult_campaign_settings (the
 *    schedule array), tve_campaign_order (its admin list is ordered by it,
 *    so a campaign without it is not listed) and the lockdown metas;
 *  - its timeline in {prefix}tve_ult_events: one 'start' row whose days and
 *    hours are the campaign length, 'time' rows that switch design states
 *    along it and 'conv' rows for conversion events;
 *  - its designs and their states in {prefix}tve_ult_designs (the editor
 *    template in the serialized tcb_fields);
 *  - its display settings in {prefix}tve_ult_settings_campaign, one row with
 *    show_options and hide_options as JSON {"tabs":[{"options":[...]},...]}
 *    in the tab order of the display settings screen;
 *  - impressions and conversions in {prefix}tve_ult_event_log.
 * Evergreen campaigns also keep per-visitor state ({prefix}tve_ult_emails,
 * cookies); nothing here reads it.
 *
 * Reads: ultimatum-list-campaigns and ultimatum-get-campaign (schedule,
 * display rules by tab, designs, timeline, counts).
 *
 * Writes (off until the site opts in with wpmcp_enable_thrive_ultimatum_write,
 * since a running campaign shows on the site): create-campaign (paused),
 * update-campaign-schedule, update-display-rules, pause-campaign and
 * resume-campaign. Each stores what the plugin's own save stores
 * (tve_ult_save_campaign(), tve_ult_save_start_event(), the display
 * settings save and tve_ult_save_campaign_status(), whose checks
 * resume-campaign makes first) and is undoable: metas as a post snapshot,
 * the timeline and the display settings as plugin_table_rows images keyed
 * by the campaign, and a created campaign trashed again.
 *
 * Every op takes manage_options.
 */
final class Thrive_Ultimatum_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads and writes Thrive Ultimatum's own tables live; it has no cached API for them.

    public const CAPABILITY = 'manage_options';

    public const OPT_IN_FILTER = 'wpmcp_enable_thrive_ultimatum_write';

    public const POST_TYPE = 'tve_ult_campaign';

    public const PLUGIN_FILE = 'thrive-ultimatum/thrive-ultimatum.php';

    private const TYPES = [ 'absolute', 'rolling', 'evergreen' ];

    private const ROLLING_TYPES = [ 'daily', 'weekly', 'monthly', 'yearly' ];

    private const TRIGGERS = [ '', 'first', 'url', 'conversion', 'webhook', 'promotion' ];

    /** The display settings tabs in the order the plugin stores them (Thrive_Display_Settings_Manager). */
    private const TABS = [ 'other_screens', 'taxonomy_terms', 'posts', 'pages', 'page_templates', 'post_types', 'taxonomy_archives', 'others' ];

    /** Schedule keys stored as strings of integers, as the admin screen saves them. */
    private const INT_KEYS = [ 'duration', 'repeat', 'days_duration', 'hours_duration', 'minutes_duration', 'seconds_duration' ];

    private const META = [
        'type'     => 'tve_ult_campaign_type',
        'rolling'  => 'tve_ult_rolling_type',
        'status'   => 'tve_ult_status',
        'settings' => 'tve_ult_campaign_settings',
        'order'    => 'tve_campaign_order',
        'linked'   => 'tve_campaign_linked_to',
        'lockdown' => 'tve_campaign_lockdown',
        'lockdown_settings' => 'tve_campaign_lockdown_settings',
    ];

    /** Whether Thrive Ultimatum is loaded (filterable) and this install may run the paid tier. */
    public static function active(): bool
    {
        return Registrar::tier_permitted('pro')
            && (bool) apply_filters('wpmcp_thrive_ultimatum_active', class_exists('TVE_Ult_Const', false));
    }

    /** @return true|array{code: string, message: string, data: array} */
    public static function presence()
    {
        if (self::active()) {
            return true;
        }
        $refusal         = Plugin_Refusal::make('plugin_missing', 'Thrive Ultimatum', self::PLUGIN_FILE);
        $refusal['code'] = 'inactive' === ($refusal['data']['state'] ?? '') ? 'plugin_inactive' : 'plugin_missing';
        return $refusal;
    }

    public static function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'tve_ult_' . $name;
    }

    // ------------------------------------------------------------------
    // Catalog
    // ------------------------------------------------------------------

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $id       = [ 'type' => 'integer', 'minimum' => 1 ];
        $one      = [ 'type' => 'object', 'properties' => [ 'campaign_id' => $id ], 'required' => [ 'campaign_id' ] ];
        $base     = [
            'tier'       => 'pro',
            'capability' => self::CAPABILITY,
            'requires'   => static fn () => self::presence(),
        ];
        $write    = $base + [
            'mode'               => 'write',
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_thrive_ultimatum_write', false),
            'opt_in_filter'      => self::OPT_IN_FILTER,
        ];
        $campaign = static fn (array $args): ?array => self::campaign_refusal((int) $args['campaign_id']);
        $post     = static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => (int) $args['campaign_id'] ];
        $schedule = self::schedule_schema();
        $rules    = self::rules_schema();

        return [
            'ultimatum-list-campaigns'           => $base + [
                'mode'         => 'read',
                'description'  => 'List Thrive Ultimatum countdown campaigns in the plugin order: id, title, type (absolute, rolling, evergreen), status (running or paused), design count, impressions and conversions. Filters: status, type',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'status' => [ 'type' => 'string', 'enum' => [ 'running', 'paused' ] ],
                        'type'   => [ 'type' => 'string', 'enum' => self::TYPES ],
                    ],
                ],
                'handler'      => static fn (array $args): array => self::list_campaigns($args),
            ],
            'ultimatum-get-campaign'             => $base + [
                'mode'         => 'read',
                'description'  => 'One Thrive Ultimatum campaign: schedule settings, display rules (show and hide, by tab), designs with template and state count, timeline events, lockdown pages and counts. Visitor state is never returned',
                'input_schema' => $one,
                'validate'     => $campaign,
                'handler'      => static fn (array $args): array => [ 'campaign' => self::campaign((int) $args['campaign_id'], true) ],
            ],
            'ultimatum-create-campaign'          => $write + [
                'self_snapshotting' => true,
                'description'       => 'Create a Thrive Ultimatum campaign, paused, listed last. Optional type (absolute, rolling with rolling_type, evergreen) and schedule (as update-campaign-schedule). Designs are made in its editor. Undo: rollback-session',
                'input_schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'title'        => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ],
                        'type'         => [ 'type' => 'string', 'enum' => self::TYPES ],
                        'rolling_type' => [ 'type' => 'string', 'enum' => self::ROLLING_TYPES ],
                        'schedule'     => $schedule,
                    ],
                    'required'   => [ 'title' ],
                ],
                'validate'          => static fn (array $args): ?array => isset($args['type']) ? self::schedule_refusal(self::merged_settings([], (array) ($args['schedule'] ?? [])), (string) $args['type'], (string) ($args['rolling_type'] ?? '')) : null,
                'handler'           => static fn (array $args, array $context): array => self::create($args, $context),
            ],
            'ultimatum-update-campaign-schedule' => $write + [
                'self_snapshotting' => true,
                'description'       => 'Change a Thrive Ultimatum campaign schedule: type, rolling_type, and schedule keys (start and end date and time, end_after days or occurrences, end_never, duration, repeat, evergreen durations and trigger); only the keys given change. The timeline length follows, as in the plugin. Undo: rollback-session',
                'input_schema'      => [
                    'type'          => 'object',
                    'properties'    => [
                        'campaign_id'  => $id,
                        'type'         => [ 'type' => 'string', 'enum' => self::TYPES ],
                        'rolling_type' => [ 'type' => 'string', 'enum' => self::ROLLING_TYPES ],
                        'schedule'     => $schedule,
                    ],
                    'required'      => [ 'campaign_id' ],
                    'minProperties' => 2,
                ],
                'validate'          => static fn (array $args): ?array => $campaign($args) ?? self::update_schedule_refusal($args),
                'handler'           => static fn (array $args, array $context): array => self::update_schedule($args, $context),
            ],
            'ultimatum-update-display-rules'     => $write + [
                'description'  => 'Set where a Thrive Ultimatum campaign shows: show and/or hide, each by tab (other_screens such as front_page or all_post, taxonomy_terms, posts, pages, page_templates, post_types, taxonomy_archives, others for logged_in, logged_out or direct URLs). A side given replaces that side; one left out is kept. Undo: rollback-operation',
                'input_schema' => [
                    'type'          => 'object',
                    'properties'    => [ 'campaign_id' => $id, 'show' => $rules, 'hide' => $rules ],
                    'required'      => [ 'campaign_id' ],
                    'minProperties' => 2,
                ],
                'validate'     => static fn (array $args): ?array => $campaign($args) ?? self::rules_refusal($args),
                'snapshot'     => static fn (array $args): array => [
                    'object_type' => Plugin_Table_Rows_Snapshot::TYPE,
                    'object_id'   => Plugin_Table_Rows_Snapshot::key('tve_ult_display', 'settings_campaign', [ (int) $args['campaign_id'] ]),
                ],
                'handler'      => static fn (array $args): array => self::update_rules($args),
            ],
            'ultimatum-pause-campaign'           => $write + [
                'description'  => 'Pause a Thrive Ultimatum campaign: it stops showing. Undo: rollback-operation',
                'input_schema' => $one,
                'validate'     => $campaign,
                'snapshot'     => $post,
                'handler'      => static fn (array $args): array => self::set_status((int) $args['campaign_id'], 'paused'),
            ],
            'ultimatum-resume-campaign'          => $write + [
                'description'  => 'Start (run) a paused Thrive Ultimatum campaign after the checks the plugin makes: a type and schedule, an end not past, display rules, a design with a template, lockdown pages. Undo: rollback-operation',
                'input_schema' => $one,
                'validate'     => static fn (array $args): ?array => $campaign($args) ?? self::ready_refusal((int) $args['campaign_id']),
                'snapshot'     => $post,
                'handler'      => static fn (array $args): array => self::set_status((int) $args['campaign_id'], 'running'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function schedule_schema(): array
    {
        $count = [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 100000 ];
        $date  = [
            'type'                 => 'object',
            'properties'           => [
                'date' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 40 ],
                'time' => [ 'type' => 'string', 'pattern' => '^\d{1,2}:\d{2}$' ],
            ],
            'additionalProperties' => false,
        ];

        return [
            'type'                 => 'object',
            'properties'           => [
                'start'            => $date,
                'end'              => $date,
                'end_after'        => $count,
                'end_never'        => [ 'type' => 'boolean' ],
                'duration'         => $count,
                'repeat'           => $count,
                'repeatOn'         => [ 'type' => 'array', 'items' => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 31 ], 'maxItems' => 31 ],
                'days_duration'    => $count,
                'hours_duration'   => $count,
                'minutes_duration' => $count,
                'seconds_duration' => $count,
                'evergreen_repeat' => [ 'type' => 'boolean' ],
                'real'             => [ 'type' => 'boolean' ],
                'realtime'         => [ 'type' => 'string', 'pattern' => '^\d{1,2}:\d{2}$' ],
                'trigger'          => [
                    'type'                 => 'object',
                    'properties'           => [
                        'type' => [ 'type' => 'string', 'enum' => self::TRIGGERS ],
                        'ids'  => [ 'type' => 'array', 'items' => [ 'type' => 'integer', 'minimum' => 1 ], 'maxItems' => 200 ],
                    ],
                    'additionalProperties' => false,
                ],
            ],
            'additionalProperties' => false,
            'minProperties'        => 1,
        ];
    }

    /** @return array<string, mixed> */
    private static function rules_schema(): array
    {
        $ids = [ 'type' => 'array', 'items' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 2000 ], 'maxItems' => 500 ];
        return [
            'type'                 => 'object',
            'properties'           => array_fill_keys(self::TABS, $ids),
            'additionalProperties' => false,
        ];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @return array{code: string, message: string, data: array} */
    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    private static function campaign_refusal(int $id): ?array
    {
        $post = get_post($id);
        if ($post instanceof \WP_Post && self::POST_TYPE === $post->post_type && 'trash' !== $post->post_status) {
            return null;
        }
        return self::refusal('campaign_not_found', sprintf('Thrive Ultimatum has no campaign %d.', $id), [ 'campaign_id' => $id ]);
    }

    /** @return array<string, mixed> */
    private static function settings(int $id): array
    {
        $settings = get_post_meta($id, self::META['settings'], true);
        if (is_array($settings) && [] !== $settings) {
            return $settings;
        }
        // tve_ult_get_campaign_settings(): the defaults a campaign without saved settings reads.
        return [
            'duration'         => 1,
            'repeat'           => 1,
            'evergreen_repeat' => 0,
            'real'             => 0,
            'realtime'         => '00:00',
            'start'            => [ 'date' => '', 'time' => '00:00' ],
            'end'              => [ 'date' => '', 'time' => '00:00' ],
            'trigger'          => [ 'type' => '', 'ids' => '' ],
        ];
    }

    /**
     * The stored settings with the given schedule keys applied, typed the
     * way the admin screen saves them (integers as strings, flags as 0 or 1).
     *
     * @return array<string, mixed>
     */
    private static function merged_settings(array $settings, array $schedule): array
    {
        foreach ([ 'start', 'end' ] as $key) {
            if (isset($schedule[ $key ])) {
                $settings[ $key ] = array_merge(is_array($settings[ $key ] ?? null) ? $settings[ $key ] : [ 'date' => '', 'time' => '00:00' ], (array) $schedule[ $key ]);
            }
        }
        if (isset($schedule['end_after'])) {
            $settings['end'] = (string) (int) $schedule['end_after'];
        }
        if (! empty($schedule['end_never'])) {
            $settings['end'] = '';
        }
        foreach (self::INT_KEYS as $key) {
            if (isset($schedule[ $key ])) {
                $settings[ $key ] = (string) (int) $schedule[ $key ];
            }
        }
        foreach ([ 'evergreen_repeat', 'real' ] as $key) {
            if (isset($schedule[ $key ])) {
                $settings[ $key ] = $schedule[ $key ] ? 1 : 0;
            }
        }
        if (isset($schedule['realtime'])) {
            $settings['realtime'] = (string) $schedule['realtime'];
        }
        if (isset($schedule['repeatOn'])) {
            $settings['repeatOn'] = array_values(array_map('intval', (array) $schedule['repeatOn']));
        }
        if (isset($schedule['trigger'])) {
            $trigger = array_merge(is_array($settings['trigger'] ?? null) ? $settings['trigger'] : [ 'type' => '', 'ids' => '' ], (array) $schedule['trigger']);
            if (isset($schedule['trigger']['ids'])) {
                $trigger['ids'] = [] === $schedule['trigger']['ids'] ? '' : array_values(array_map('intval', (array) $schedule['trigger']['ids']));
            }
            $settings['trigger'] = $trigger;
        }
        $settings += [ 'trigger' => [ 'type' => '', 'ids' => '' ] ];
        return $settings;
    }

    private static function timestamp(array $point): ?int
    {
        $date = trim((string) ($point['date'] ?? ''));
        if ('' === $date) {
            return null;
        }
        $time = strtotime($date . ' ' . (string) ($point['time'] ?? '00:00'));
        return false === $time ? null : $time;
    }

    /** A schedule the plugin could not run, or null. */
    private static function schedule_refusal(array $settings, string $type, string $rolling): ?array
    {
        if ('absolute' === $type) {
            $start = self::timestamp((array) ($settings['start'] ?? []));
            $end   = is_array($settings['end'] ?? null) ? self::timestamp($settings['end']) : null;
            if (null === $start || null === $end) {
                return self::refusal('invalid_schedule', 'An absolute campaign needs a start and an end date the plugin can read (for example "1 January 2030" and "00:00").');
            }
            if ($end <= $start) {
                return self::refusal('invalid_schedule', 'The end of an absolute campaign must come after its start.');
            }
        }
        if ('rolling' === $type) {
            if (! in_array($rolling, self::ROLLING_TYPES, true)) {
                return self::refusal('invalid_schedule', 'A rolling campaign needs rolling_type: daily, weekly, monthly or yearly.');
            }
            if (null === self::timestamp((array) ($settings['start'] ?? []))) {
                return self::refusal('invalid_schedule', 'A rolling campaign needs a start date the plugin can read.');
            }
        }
        if (in_array($type, [ 'rolling', 'evergreen' ], true) && (int) ($settings['duration'] ?? 0) < 1 && (int) ($settings['days_duration'] ?? 0) + (int) ($settings['hours_duration'] ?? 0) < 1) {
            return self::refusal('invalid_schedule', 'The campaign needs a duration of at least 1.');
        }
        return null;
    }

    private static function update_schedule_refusal(array $args): ?array
    {
        $id   = (int) $args['campaign_id'];
        $type = (string) ($args['type'] ?? get_post_meta($id, self::META['type'], true));
        if ('' === $type) {
            return self::refusal('invalid_schedule', 'The campaign has no type yet: give type (absolute, rolling or evergreen).');
        }
        $rolling = (string) ($args['rolling_type'] ?? get_post_meta($id, self::META['rolling'], true));
        return self::schedule_refusal(self::merged_settings(self::settings($id), (array) ($args['schedule'] ?? [])), $type, $rolling);
    }

    /** The start event length the way tve_ult_save_start_event() works it out: [days, hours]. */
    private static function length(string $type, string $rolling, array $settings): array
    {
        switch ($type) {
            case 'absolute':
                $start = self::timestamp((array) ($settings['start'] ?? [])) ?? 0;
                $end   = is_array($settings['end'] ?? null) ? (self::timestamp($settings['end']) ?? $start) : $start;
                $diff  = abs($end - $start);
                $days  = (int) floor($diff / DAY_IN_SECONDS);
                return [ $days, (int) round(($diff - $days * DAY_IN_SECONDS) / HOUR_IN_SECONDS) ];
            case 'rolling':
                return 'daily' === $rolling ? [ 0, (int) ($settings['duration'] ?? 0) ] : [ (int) ($settings['duration'] ?? 0), 0 ];
            case 'evergreen':
                return [ (int) ($settings['days_duration'] ?? ($settings['duration'] ?? 0)), (int) ($settings['hours_duration'] ?? 0) ];
        }
        return [ 0, 0 ];
    }

    /**
     * Save the start event and drop the 'time' events at or past the new
     * length, as tve_ult_save_campaign() does through
     * tve_ult_save_start_event() and tve_ult_clear_timeline().
     */
    private static function write_timeline(int $id, string $type, string $rolling, array $settings): void
    {
        global $wpdb;
        $table = self::table('events');
        if (! Plugin_Table_Rows_Snapshot::table_exists($table)) {
            throw new Operation_Refused('ultimatum_tables_missing', 'Thrive Ultimatum\'s events table is missing; deactivate and reactivate Thrive Ultimatum so it creates its tables.');
        }
        [$days, $hours] = self::length($type, $rolling, $settings);
        $start          = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE campaign_id = %d AND type = %s ORDER BY id LIMIT 1', $table, $id, 'start'));
        $ok             = null === $start
            ? $wpdb->insert($table, [ 'campaign_id' => $id, 'days' => $days, 'hours' => $hours, 'type' => 'start' ])
            : $wpdb->update($table, [ 'days' => $days, 'hours' => $hours ], [ 'id' => (int) $start ]);
        if (false === $ok) {
            throw new Operation_Refused('write_failed', 'Could not save the campaign start event: ' . $wpdb->last_error);
        }
        $length = $days * 24 + $hours;
        if ($length > 0) {
            $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE campaign_id = %d AND days * 24 + hours >= %d AND type = %s', $table, $id, $length, 'time'));
        }
    }

    private static function snapshotted(string $type, $object_id, array $context, array $args, callable $write): string
    {
        $out = Safe_Mutation::run(
            [
                'object_type' => $type,
                'object_id'   => $object_id,
                'session_id'  => (string) $context['session_id'],
                'tool_name'   => (string) $context['tool_name'],
                'args'        => [ 'operation' => (string) $context['operation'], 'args' => $args ],
            ],
            static function () use ($write): bool {
                $write();
                return true;
            }
        );
        return (string) $out['operation_id'];
    }

    /** Stored display options as the plugin reads them: tab name => the ids it counts as chosen. */
    private static function decode_rules(?string $json): array
    {
        $out     = array_fill_keys(self::TABS, []);
        $options = null === $json ? null : json_decode(stripcslashes($json), true);
        if (! is_array($options) || ! is_array($options['tabs'] ?? null)) {
            return $out;
        }
        foreach (self::TABS as $index => $tab) {
            foreach ((array) ($options['tabs'][ $index ]['options'] ?? []) as $option) {
                if (is_array($option)) {
                    // Thrive_Ult_Campaign_Options::getTabSavedOptions(): a checked option, or any direct URL.
                    if (! empty($option['isChecked']) || 'direct_url' === ($option['type'] ?? '')) {
                        $out[ $tab ][] = (string) ($option['id'] ?? '');
                    }
                } elseif (is_scalar($option)) {
                    $out[ $tab ][] = (string) $option;
                }
            }
        }
        return $out;
    }

    /** @return array{show: array, hide: array} */
    private static function rules(int $id): array
    {
        global $wpdb;
        $table = self::table('settings_campaign');
        $row   = Plugin_Table_Rows_Snapshot::table_exists($table)
            ? $wpdb->get_row($wpdb->prepare('SELECT show_options, hide_options FROM %i WHERE campaign_id = %d ORDER BY id DESC LIMIT 1', $table, $id), ARRAY_A)
            : null;
        return [
            'show' => self::decode_rules(is_array($row) ? $row['show_options'] : null),
            'hide' => self::decode_rules(is_array($row) ? $row['hide_options'] : null),
        ];
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    private static function count(string $table, string $where, array $params): int
    {
        global $wpdb;
        if (! Plugin_Table_Rows_Snapshot::table_exists(self::table($table))) {
            return 0;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where is a fixed condition built here from placeholders.
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE {$where}", self::table($table), ...$params));
    }

    /** @return array<string, mixed> */
    private static function campaign(int $id, bool $full): array
    {
        $post = get_post($id);
        $view = [
            'id'           => $id,
            'title'        => $post instanceof \WP_Post ? $post->post_title : '',
            'type'         => (string) get_post_meta($id, self::META['type'], true),
            'rolling_type' => (string) get_post_meta($id, self::META['rolling'], true),
            'status'       => (string) get_post_meta($id, self::META['status'], true),
            'order'        => (int) get_post_meta($id, self::META['order'], true),
            'designs'      => self::count('designs', 'post_parent = %d AND parent_id = 0 AND post_status = %s', [ $id, 'publish' ]),
            'impressions'  => self::count('event_log', 'campaign_id = %d AND type = %d', [ $id, 1 ]),
            'conversions'  => self::count('event_log', 'campaign_id = %d AND type = %d', [ $id, 2 ]),
            'lockdown'     => (bool) get_post_meta($id, self::META['lockdown'], true),
        ];
        if (! $full) {
            return $view;
        }
        $lockdown = get_post_meta($id, self::META['lockdown_settings'], true);
        unset($view['designs']);
        return $view + [
            'linked_to'         => (int) get_post_meta($id, self::META['linked'], true) ?: null,
            'schedule'          => self::settings($id),
            'display_rules'     => self::rules($id),
            'designs'           => self::designs($id),
            'timeline'          => self::timeline($id),
            'lockdown_settings' => is_array($lockdown) ? $lockdown : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function designs(int $id): array
    {
        global $wpdb;
        if (! Plugin_Table_Rows_Snapshot::table_exists(self::table('designs'))) {
            return [];
        }
        $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT id, post_type, post_title, tcb_fields, parent_id FROM %i WHERE post_parent = %d AND post_status = %s ORDER BY id', self::table('designs'), $id, 'publish'), ARRAY_A);
        $out  = [];
        foreach ($rows as $row) {
            if (0 !== (int) $row['parent_id']) {
                continue;
            }
            $fields = maybe_unserialize((string) $row['tcb_fields']);
            $states = 0;
            foreach ($rows as $child) {
                $states += (int) $child['parent_id'] === (int) $row['id'] ? 1 : 0;
            }
            $out[] = [
                'id'       => (int) $row['id'],
                'type'     => (string) $row['post_type'],
                'title'    => (string) $row['post_title'],
                'template' => is_array($fields) && isset($fields['tpl']) ? (string) $fields['tpl'] : null,
                'states'   => $states,
            ];
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function timeline(int $id): array
    {
        global $wpdb;
        if (! Plugin_Table_Rows_Snapshot::table_exists(self::table('events'))) {
            return [];
        }
        $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT id, type, days, hours, actions FROM %i WHERE campaign_id = %d ORDER BY days DESC, hours DESC, id ASC', self::table('events'), $id), ARRAY_A);
        $out  = [];
        foreach ($rows as $row) {
            $actions = maybe_unserialize((string) $row['actions']);
            $out[]   = [
                'id'      => (int) $row['id'],
                'type'    => (string) $row['type'],
                'days'    => (int) $row['days'],
                'hours'   => (int) $row['hours'],
                'actions' => is_array($actions) ? count($actions) : 0,
            ];
        }
        // The start event is the campaign length; list it first, then the rest as the plugin orders them.
        usort($out, static fn (array $a, array $b): int => ('start' === $b['type']) <=> ('start' === $a['type']));
        return $out;
    }

    /** @return array<string, mixed> */
    private static function list_campaigns(array $args): array
    {
        $query = [
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_key'       => self::META['order'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- the plugin orders its campaign list by this meta (tve_ult_get_campaigns()).
            'orderby'        => 'meta_value_num',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ];
        $meta  = [];
        if (isset($args['status'])) {
            $meta[] = [ 'key' => self::META['status'], 'value' => (string) $args['status'] ];
        }
        if (isset($args['type'])) {
            $meta[] = [ 'key' => self::META['type'], 'value' => (string) $args['type'] ];
        }
        if ([] !== $meta) {
            $query['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- campaigns are few; status and type are metas.
        }
        $ids = array_map('intval', (array) get_posts($query));

        return [
            'campaigns' => array_map(static fn (int $id): array => self::campaign($id, false), $ids),
            'total'     => count($ids),
        ];
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    /** @return array{result: array, operation_ids: string[]} */
    private static function create(array $args, array $context): array
    {
        global $wpdb;
        $order = (int) $wpdb->get_var($wpdb->prepare('SELECT MAX(CAST(pm.meta_value AS SIGNED)) FROM %i pm JOIN %i p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = %s', $wpdb->postmeta, $wpdb->posts, self::META['order'], self::POST_TYPE));
        $count = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE post_type = %s', $wpdb->posts, self::POST_TYPE));
        $id    = wp_insert_post([
            'post_type'   => self::POST_TYPE,
            'post_title'  => wp_slash((string) $args['title']),
            'post_status' => 'publish',
            'post_author' => get_current_user_id(),
        ], true);
        if (is_wp_error($id) || 0 === (int) $id) {
            throw new Operation_Refused('insert_failed', 'Thrive Ultimatum could not create the campaign.');
        }
        $id  = (int) $id;
        $ops = [ Post_Creation_Snapshot::record((string) $context['tool_name'], [ $id ], [ 'operation' => $context['operation'], 'args' => $args ], (string) $context['session_id']) ];
        update_post_meta($id, self::META['order'], $count > 0 ? $order + 1 : 0);
        update_post_meta($id, self::META['status'], 'paused');
        if (isset($args['type'])) {
            $type     = (string) $args['type'];
            $rolling  = 'rolling' === $type ? (string) ($args['rolling_type'] ?? '') : '';
            $settings = self::merged_settings(self::settings($id), (array) ($args['schedule'] ?? []));
            update_post_meta($id, self::META['type'], $type);
            update_post_meta($id, self::META['rolling'], $rolling);
            update_post_meta($id, self::META['settings'], $settings);
            $ops[] = self::snapshotted(Plugin_Table_Rows_Snapshot::TYPE, Plugin_Table_Rows_Snapshot::key('tve_ult_events', 'events', [ $id ]), $context, $args, static fn () => self::write_timeline($id, $type, $rolling, $settings));
        }
        return [ 'result' => [ 'campaign' => self::campaign($id, true) ], 'operation_ids' => $ops ];
    }

    /** @return array{result: array, operation_ids: string[]} */
    private static function update_schedule(array $args, array $context): array
    {
        $id       = (int) $args['campaign_id'];
        $type     = (string) ($args['type'] ?? get_post_meta($id, self::META['type'], true));
        $rolling  = 'rolling' === $type ? (string) ($args['rolling_type'] ?? get_post_meta($id, self::META['rolling'], true)) : '';
        $settings = self::merged_settings(self::settings($id), (array) ($args['schedule'] ?? []));
        $ops      = [
            self::snapshotted('post', $id, $context, $args, static function () use ($id, $type, $rolling, $settings): void {
                update_post_meta($id, self::META['type'], $type);
                update_post_meta($id, self::META['rolling'], $rolling);
                update_post_meta($id, self::META['settings'], $settings);
            }),
        ];
        $ops[]    = self::snapshotted(Plugin_Table_Rows_Snapshot::TYPE, Plugin_Table_Rows_Snapshot::key('tve_ult_events', 'events', [ $id ]), $context, $args, static fn () => self::write_timeline($id, $type, $rolling, $settings));
        return [ 'result' => [ 'campaign' => self::campaign($id, true) ], 'operation_ids' => $ops ];
    }

    private static function rules_refusal(array $args): ?array
    {
        if (! isset($args['show']) && ! isset($args['hide'])) {
            return self::refusal('invalid_display_rule', 'Give show, hide or both.');
        }
        if (! Plugin_Table_Rows_Snapshot::table_exists(self::table('settings_campaign'))) {
            return self::refusal('ultimatum_tables_missing', 'Thrive Ultimatum\'s display settings table is missing; deactivate and reactivate Thrive Ultimatum so it creates its tables.');
        }
        foreach ([ 'show', 'hide' ] as $side) {
            foreach ((array) ($args[ $side ] ?? []) as $tab => $ids) {
                foreach ((array) $ids as $value) {
                    // The plugin runs stripcslashes() over the stored JSON before decoding it, so a quote or backslash would not read back.
                    if (1 === preg_match('/["\\\\]/', (string) $value)) {
                        return self::refusal('invalid_display_rule', sprintf('The %s rule "%s" (%s) holds a quote or backslash, which Thrive Ultimatum cannot read back.', $side, $tab, (string) $value));
                    }
                }
            }
        }
        return null;
    }

    private static function encode_rules(array $rules): string
    {
        $tabs = [];
        foreach (self::TABS as $tab) {
            $tabs[] = [ 'options' => array_values(array_map('strval', (array) ($rules[ $tab ] ?? []))) ];
        }
        return (string) wp_json_encode([ 'tabs' => $tabs ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Store the display settings row the way Thrive_Ult_Campaign_Options::save() does: delete, then insert. */
    private static function update_rules(array $args): array
    {
        global $wpdb;
        $id    = (int) $args['campaign_id'];
        $table = self::table('settings_campaign');
        $old   = $wpdb->get_row($wpdb->prepare('SELECT description, show_options, hide_options FROM %i WHERE campaign_id = %d ORDER BY id DESC LIMIT 1', $table, $id), ARRAY_A);
        $row   = [
            'campaign_id'  => $id,
            'description'  => is_array($old) ? $old['description'] : null,
            'show_options' => isset($args['show']) ? self::encode_rules((array) $args['show']) : (is_array($old) ? $old['show_options'] : self::encode_rules([])),
            'hide_options' => isset($args['hide']) ? self::encode_rules((array) $args['hide']) : (is_array($old) ? $old['hide_options'] : self::encode_rules([])),
        ];
        if (false === $wpdb->delete($table, [ 'campaign_id' => $id ], [ '%d' ]) || false === $wpdb->insert($table, $row)) {
            throw new Operation_Refused('write_failed', 'Could not save the display rules: ' . $wpdb->last_error);
        }
        return [ 'campaign_id' => $id, 'display_rules' => self::rules($id) ];
    }

    private static function set_status(int $id, string $status): array
    {
        update_post_meta($id, self::META['status'], $status);
        return [ 'campaign' => self::campaign($id, false) ];
    }

    private static function now(): int
    {
        // tve_ult_current_time('timestamp'): site time in the plugin's own timezone setting.
        $offset = function_exists('tve_ult_gmt_offset') ? tve_ult_gmt_offset() : get_option('tve_ult_gmt_offset', false);
        if (false === $offset || '' === $offset) {
            $offset = get_option('gmt_offset');
        }
        return time() + (int) round((float) $offset * HOUR_IN_SECONDS);
    }

    /** Whether any show rule is chosen (Thrive_Ult_Campaign_Options::checkForAnyOptionChecked()). */
    private static function any_rule(int $id): bool
    {
        foreach (self::rules($id)['show'] as $ids) {
            if ([] !== $ids) {
                return true;
            }
        }
        return false;
    }

    /** The checks tve_ult_save_campaign_status() makes before it runs a campaign, as a refusal or null. */
    private static function ready_refusal(int $id): ?array
    {
        global $wpdb;
        $type     = (string) get_post_meta($id, self::META['type'], true);
        $settings = get_post_meta($id, self::META['settings'], true);
        $trigger  = is_array($settings) ? (string) ($settings['trigger']['type'] ?? '') : '';
        $lockdown = (bool) get_post_meta($id, self::META['lockdown'], true);
        $pages    = (array) get_post_meta($id, self::META['lockdown_settings'], true);
        $reason   = null;

        if ('' === $type || ! is_array($settings) || [] === $settings) {
            $reason = [ 'settings_error', 'it has no type and schedule yet (update-campaign-schedule)' ];
        } elseif ('evergreen' === $type && '' === (string) get_post_meta($id, self::META['linked'], true) && '' === $trigger) {
            $reason = [ 'evergreen_linked', 'an evergreen campaign needs a trigger (schedule.trigger.type) or a campaign linked to it' ];
        } elseif ('absolute' === $type && null !== ($end = is_array($settings['end'] ?? null) ? self::timestamp($settings['end']) : null) && self::now() > $end) {
            $reason = [ 'end_date_in_past', 'its end date has passed' ];
        } elseif ('absolute' === $type && ! is_array($settings['end'] ?? null)) {
            $reason = [ 'settings_error', 'it has no end date' ];
        } elseif (! self::any_rule($id)) {
            $reason = [ 'display_settings_error', 'it has no display rules (update-display-rules)' ];
        } elseif ($lockdown && (empty($pages['preaccess']) || empty($pages['expired']) || empty($pages['promotion']))) {
            $reason = [ 'lockdown', 'lockdown is on but its pre-access, expired and promotion pages are not all set' ];
        } elseif (! $lockdown && 'promotion' === $trigger) {
            $reason = [ 'invalid_trigger', 'the promotion trigger needs lockdown' ];
        } else {
            $tcb = Plugin_Table_Rows_Snapshot::table_exists(self::table('designs'))
                ? (array) $wpdb->get_col($wpdb->prepare('SELECT tcb_fields FROM %i WHERE post_parent = %d AND parent_id = 0 AND post_status = %s', self::table('designs'), $id, 'publish'))
                : [];
            if ([] === $tcb) {
                $reason = [ 'design_error', 'it has no design; add one in the Thrive Ultimatum dashboard' ];
            } elseif ([] === array_filter($tcb, static fn ($fields): bool => ! empty(maybe_unserialize((string) $fields)['tpl'] ?? null))) {
                $reason = [ 'design_tpl_error', 'none of its designs has a template chosen' ];
            } elseif ('conversion' === $trigger && ! function_exists('tve_leads_get_groups')) {
                $reason = [ 'invalid_leads_trigger', 'the conversion trigger needs Thrive Leads' ];
            }
        }
        if (null === $reason) {
            return null;
        }
        return self::refusal('campaign_not_ready', sprintf('Thrive Ultimatum would not start campaign %d: %s.', $id, $reason[1]), [ 'campaign_id' => $id, 'reason' => $reason[0] ]);
    }
}
