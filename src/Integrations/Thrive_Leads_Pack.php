<?php

namespace WPMCP\Integrations;

use WPMCP\MCP\Registrar;
use WPMCP\Safety\Plugin_Table_Rows_Snapshot;
use WPMCP\Tools\Content\Readable_Posts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Thrive Leads (issue #825) on the plugin-data pair, paid tier, so it adds
 * no top-level tools. Ops are prefixed thriveleads- because the pair is
 * shared.
 *
 * Thrive Leads 10.9.2 keeps a lead group as a `tve_lead_group` post (order in
 * the tve_group_order meta), and each opt-in form of a group as a
 * `tve_form_type` post under it (its type, lightbox, slide_in, ribbon and so
 * on, in the tve_form_type meta; display_status and display_on_mobile meta
 * switch it on desktop and mobile, unset meaning on). A form's designs are
 * rows of {prefix}tve_leads_form_variations (key, post_parent the form, the
 * Thrive Architect markup in content, trigger, trigger_config serialized,
 * display_frequency in days, position and display_animation). A group's
 * display rules are one row of {prefix}tve_leads_group_options (show and
 * hide JSON). Daily impressions and conversions are summed into
 * {prefix}tve_leads_form_summary. Shortcode forms are `tve_lead_shortcode`
 * posts.
 *
 * Reads never return a design's markup or its editor fields (they can name
 * the email service a form posts to), and no email service setting or key
 * is read at all. Nothing read is visitor data: stats are counts only.
 *
 * Writes are off until a site opts in with wpmcp_enable_thrive_leads_write:
 *  - update-form-settings changes one design's trigger, trigger config,
 *    display frequency, position or animation, validated the way Thrive
 *    Leads offers them per form type, under a 'plugin_table_rows' image of
 *    that row (kind tve_leads_variations);
 *  - toggle-form switches a form on desktop and mobile (post meta), under a
 *    post snapshot;
 *  - update-lead-group-display-rules replaces a group's show or hide rules
 *    as Thrive_Leads_Group_Options::save() does (delete, insert), under a
 *    'plugin_table_rows' image keyed by the group (kind tve_leads_options),
 *    so the undo also removes a row the write added.
 * Design markup edits go through the Thrive Architect builder ops (#640).
 *
 * Every op takes Thrive Leads' own product capability (tve-use-tl) or
 * manage_options. Presence is filterable (wpmcp_thrive_leads_active,
 * default: TVE_LEADS_VERSION is defined).
 */
final class Thrive_Leads_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    public const GROUP = 'tve_lead_group';

    public const FORM = 'tve_form_type';

    public const SHORTCODE = 'tve_lead_shortcode';

    public const OPT_IN_FILTER = 'wpmcp_enable_thrive_leads_write';

    public const CAP = 'tve-use-tl';

    public const PLUGIN_FILE = 'thrive-leads/thrive-leads.php';

    public const MAX_PAGE_SIZE = 100;

    private const STATUSES = [ 'publish', 'draft', 'pending', 'private' ];

    /** Trigger => the form types it applies to (TVE_Leads_Trigger_*::$applies_to), '*' for all. */
    private const TRIGGERS = [
        'page_load'      => '*',
        'time'           => [ 'ribbon', 'lightbox', 'slide_in', 'screen_filler', 'shortcode' ],
        'scroll_element' => [ 'lightbox', 'slide_in', 'screen_filler' ],
        'scroll_percent' => [ 'lightbox', 'slide_in', 'screen_filler', 'ribbon' ],
        'click'          => [ 'lightbox', 'slide_in' ],
        'exit_intent'    => [ 'lightbox', 'screen_filler' ],
        'viewport'       => [ 'post_footer', 'php_insert', 'shortcode', 'in_content' ],
        'page_bottom'    => [ 'ribbon', 'lightbox', 'slide_in', 'shortcode', 'widget' ],
    ];

    /** Trigger => the trigger_config keys it reads. */
    private const TRIGGER_KEYS = [
        'time'           => [ 's', 'exi' ],
        'scroll_percent' => [ 'p', 'exi' ],
        'scroll_element' => [ 'i', 'c', 'exi' ],
        'click'          => [ 'i', 'c' ],
        'exit_intent'    => [ 'm', 'ms' ],
    ];

    /** Form type => positions (tve_leads_get_available_positions). */
    private const POSITIONS = [
        'slide_in'   => [ 'bot_left', 'bot_right', 'top_left', 'top_right' ],
        'ribbon'     => [ 'above', 'top', 'bottom' ],
        'in_content' => [ '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '10' ],
    ];

    /** TVE_Leads_Animation_Abstract::$available. */
    private const ANIMATIONS = [
        'instant', 'zoom_in', 'zoom_out', 'rotational', 'slide_top', 'slide_bot', 'slide_left', 'slide_right',
        '3d_slit', '3d_flip_horizontal', '3d_flip_vertical', '3d_sign', '3d_rotate_bottom', '3d_rotate_left',
        'blur', 'make_way', 'slip_from_top', 'bounce_in', 'bounce_in_down', 'bounce_in_left', 'bounce_in_right', 'bounce_in_up',
    ];

    private const FORM_TYPES = [ 'in_content', 'lightbox', 'post_footer', 'ribbon', 'screen_filler', 'greedy_ribbon', 'slide_in', 'widget', 'php_insert' ];

    public static function active(): bool
    {
        return Registrar::tier_permitted('pro') && self::loaded();
    }

    private static function loaded(): bool
    {
        return (bool) apply_filters('wpmcp_thrive_leads_active', defined('TVE_LEADS_VERSION'));
    }

    /** @return true|array{code: string, message: string, data: array} */
    public static function presence()
    {
        if (self::loaded()) {
            return true;
        }
        $refusal = Plugin_Refusal::make('plugin_missing', 'Thrive Leads', self::PLUGIN_FILE);
        if ('inactive' === ($refusal['data']['state'] ?? '')) {
            $refusal['code'] = 'plugin_inactive';
        }
        return $refusal;
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $id    = [ 'type' => 'integer', 'minimum' => 1 ];
        $date  = [ 'type' => 'string', 'maxLength' => 10 ];
        $page  = [
            'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
            'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE_SIZE ],
        ];
        $rules = [ 'type' => 'object', 'properties' => [ 'tabs' => [ 'type' => 'array' ] ], 'required' => [ 'tabs' ] ];
        $post  = static fn (string $access): array => [ 'type' => 'post', 'own_type' => true, 'access' => $access ];
        $base  = [
            'tier'       => 'pro',
            'capability' => 'edit_posts',
            'requires'   => static fn () => self::presence(),
        ];
        $cap   = [ 'validate' => static fn (): ?array => self::cap_refusal() ];
        $read  = $base + [ 'mode' => 'read' ];
        $write = $base + [
            'mode'               => 'write',
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_thrive_leads_write', false),
            'opt_in_filter'      => self::OPT_IN_FILTER,
        ];
        $one   = [ 'type' => 'object', 'properties' => [ 'id' => $id ], 'required' => [ 'id' ] ];

        return [
            'thriveleads-list-lead-groups'                => $read + $cap + [
                'description'  => 'List Thrive Leads lead groups in display order: id, title, status, forms, has_display_rules, impressions, conversions, conversion_rate',
                'input_schema' => [ 'type' => 'object', 'properties' => (object) [] ],
                'handler'      => static fn (): array => self::list_groups(),
            ],
            'thriveleads-get-lead-group'                  => $read + [
                'objects'      => [ 'id' => $post('read') ],
                'description'  => 'Read one Thrive Leads lead group: its forms and its display rules (show and hide, as stored)',
                'input_schema' => $one,
                'validate'     => static fn (array $args): ?array => self::cap_refusal() ?? self::group_refusal((int) $args['id']),
                'handler'      => static fn (array $args): array => [ 'lead_group' => self::view_group(get_post((int) $args['id']), true) ],
            ],
            'thriveleads-list-forms'                      => $read + $cap + [
                'objects'      => [ 'group_id' => $post('read') ],
                'description'  => 'List Thrive Leads opt-in forms: id, title, group, type, desktop and mobile display, designs. Filter by group_id, type. page, per_page (max 100)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'group_id' => $id, 'type' => [ 'type' => 'string', 'enum' => self::FORM_TYPES ] ] + $page,
                ],
                'handler'      => static fn (array $args): array => self::list_forms($args),
            ],
            'thriveleads-get-form'                        => $read + [
                'objects'      => [ 'id' => $post('read') ],
                'description'  => 'Read one Thrive Leads form: type, desktop and mobile display, each design\'s trigger, trigger_config, display_frequency, position, animation and counts (no design markup), and the allowed values',
                'input_schema' => $one,
                'validate'     => static fn (array $args): ?array => self::cap_refusal() ?? self::form_refusal((int) $args['id']),
                'handler'      => static fn (array $args): array => [ 'form' => self::view_form(get_post((int) $args['id']), true) ],
            ],
            'thriveleads-list-shortcodes'                 => $read + $cap + [
                'description'  => 'List Thrive Leads shortcode forms with the shortcode to paste, content locking and design count',
                'input_schema' => [ 'type' => 'object', 'properties' => (object) [] ],
                'handler'      => static fn (): array => self::list_shortcodes(),
            ],
            'thriveleads-get-conversion-stats'            => $read + [
                'objects'      => [ 'group_id' => $post('read'), 'form_id' => $post('read') ],
                'description'  => 'Thrive Leads impressions, conversions and conversion rate, in total and per form. Filter by group_id, form_id, date_from, date_to (YYYY-MM-DD)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'group_id' => $id, 'form_id' => $id, 'date_from' => $date, 'date_to' => $date ],
                ],
                'validate'     => static fn (array $args): ?array => self::cap_refusal() ?? self::date_refusal($args),
                'handler'      => static fn (array $args): array => self::stats($args),
            ],
            'thriveleads-update-form-settings'            => $write + [
                'objects'      => [ 'id' => $post('write') ],
                'description'  => 'Change a Thrive Leads form design\'s trigger, trigger_config, display_frequency (days, 0 always), position, display_animation. variation_key needed when the form has several designs. Undo: rollback-operation',
                'input_schema' => [
                    'type'                 => 'object',
                    'properties'           => [
                        'id'                => $id,
                        'variation_key'     => $id,
                        'trigger'           => [ 'type' => 'string', 'enum' => array_keys(self::TRIGGERS) ],
                        'trigger_config'    => [ 'type' => 'object' ],
                        'display_frequency' => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 365 ],
                        'position'          => [ 'type' => 'string', 'maxLength' => 32 ],
                        'display_animation' => [ 'type' => 'string', 'enum' => self::ANIMATIONS ],
                    ],
                    'required'             => [ 'id' ],
                    'minProperties'        => 2,
                    'additionalProperties' => false,
                ],
                'validate'     => static fn (array $args): ?array => self::cap_refusal() ?? self::settings_refusal($args),
                'snapshot'     => static fn (array $args): array => [
                    'object_type' => Plugin_Table_Rows_Snapshot::TYPE,
                    'object_id'   => Plugin_Table_Rows_Snapshot::key('tve_leads_variations', 'form_variations', [ (int) self::resolve_variation($args) ]),
                ],
                'handler'      => static fn (array $args): array => self::update_settings($args),
            ],
            'thriveleads-toggle-form'                     => $write + [
                'objects'      => [ 'id' => $post('write') ],
                'description'  => 'Switch a Thrive Leads form on or off: desktop and/or mobile (booleans). Undo: rollback-operation',
                'input_schema' => [
                    'type'                 => 'object',
                    'properties'           => [ 'id' => $id, 'desktop' => [ 'type' => 'boolean' ], 'mobile' => [ 'type' => 'boolean' ] ],
                    'required'             => [ 'id' ],
                    'additionalProperties' => false,
                ],
                'validate'     => static fn (array $args): ?array => self::cap_refusal() ?? self::toggle_refusal($args),
                'snapshot'     => static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => (int) $args['id'] ],
                'handler'      => static fn (array $args): array => self::toggle($args),
            ],
            'thriveleads-update-lead-group-display-rules' => $write + [
                'objects'      => [ 'id' => $post('write') ],
                'description'  => 'Replace a Thrive Leads lead group\'s display rules: show and/or hide, each {tabs:[{options:[...]}]} as get-lead-group returns them; the one not given is kept. Undo: rollback-operation',
                'input_schema' => [
                    'type'                 => 'object',
                    'properties'           => [ 'id' => $id, 'show' => $rules, 'hide' => $rules ],
                    'required'             => [ 'id' ],
                    'minProperties'        => 2,
                    'additionalProperties' => false,
                ],
                'validate'     => static fn (array $args): ?array => self::cap_refusal() ?? self::rules_refusal($args),
                'snapshot'     => static fn (array $args): array => [
                    'object_type' => Plugin_Table_Rows_Snapshot::TYPE,
                    'object_id'   => Plugin_Table_Rows_Snapshot::key('tve_leads_options', 'group_options', [ (int) $args['id'] ]),
                ],
                'handler'      => static fn (array $args): array => self::update_rules($args),
            ],
        ];
    }

    // ------------------------------------------------------------ refusals

    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    /** Thrive Leads' own product capability, or manage_options. */
    public static function can(): bool
    {
        return current_user_can('manage_options') || current_user_can(self::CAP);
    }

    private static function cap_refusal(): ?array
    {
        return self::can() ? null : self::refusal('operation_denied', 'This needs the tve-use-tl capability (Thrive Leads access) or manage_options.', [ 'reason' => 'capability', 'capability' => self::CAP ]);
    }

    private static function is_type($post, string $type): bool
    {
        return $post instanceof \WP_Post && $type === $post->post_type && 'trash' !== $post->post_status;
    }

    private static function group_refusal(int $id): ?array
    {
        return self::is_type(get_post($id), self::GROUP) ? null : self::refusal('lead_group_not_found', sprintf('%d is not a Thrive Leads lead group.', $id), [ 'id' => $id ]);
    }

    private static function form_refusal(int $id): ?array
    {
        return self::is_type(get_post($id), self::FORM) ? null : self::refusal('form_not_found', sprintf('%d is not a Thrive Leads opt-in form.', $id), [ 'id' => $id ]);
    }

    private static function date_refusal(array $args): ?array
    {
        foreach ([ 'date_from', 'date_to' ] as $key) {
            if (isset($args[ $key ]) && 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $args[ $key ])) {
                return self::refusal('invalid_date', sprintf('%s must be a date as YYYY-MM-DD.', $key), [ $key => (string) $args[ $key ] ]);
            }
        }
        return null;
    }

    private static function toggle_refusal(array $args): ?array
    {
        $refused = self::form_refusal((int) $args['id']);
        if (null !== $refused) {
            return $refused;
        }
        if (! isset($args['desktop']) && ! isset($args['mobile'])) {
            return self::refusal('toggle_required', 'Give desktop, mobile or both (true shows the form, false hides it).');
        }
        return null;
    }

    private static function rules_refusal(array $args): ?array
    {
        $refused = self::group_refusal((int) $args['id']);
        if (null !== $refused) {
            return $refused;
        }
        foreach ([ 'show', 'hide' ] as $key) {
            if (! array_key_exists($key, $args)) {
                continue;
            }
            $rules = $args[ $key ];
            $ok    = is_array($rules) && isset($rules['tabs']) && is_array($rules['tabs']) && array_is_list($rules['tabs']);
            foreach ($ok ? $rules['tabs'] : [] as $tab) {
                $ok = $ok && is_array($tab) && is_array($tab['options'] ?? null);
            }
            if (! $ok || strlen((string) wp_json_encode($rules)) > 200000) {
                return self::refusal('invalid_display_rules', sprintf('%s must be {"tabs":[{"options":[...]}, ...]}, as get-lead-group returns it.', $key));
            }
        }
        return null;
    }

    private static function settings_refusal(array $args): ?array
    {
        $id      = (int) $args['id'];
        $refused = self::form_refusal($id);
        if (null !== $refused) {
            return $refused;
        }
        $keys = array_column(self::variations($id), 'key');
        if (isset($args['variation_key'])) {
            if (! in_array((int) $args['variation_key'], $keys, true)) {
                return self::refusal('variation_not_found', sprintf('%d is not a published design of form %d.', (int) $args['variation_key'], $id), [ 'variation_keys' => $keys ]);
            }
        } elseif (1 !== count($keys)) {
            return [] === $keys
                ? self::refusal('variation_not_found', sprintf('Form %d has no published design.', $id))
                : self::refusal('variation_required', sprintf('Form %d has several designs; give variation_key.', $id), [ 'variation_keys' => $keys ]);
        }
        $type    = self::form_type($id);
        $current = self::variation_row(self::resolve_variation($args));
        $trigger = (string) ($args['trigger'] ?? ($current['trigger'] ?? 'page_load'));
        $applies = self::TRIGGERS[ $trigger ] ?? [];
        if ('*' !== $applies && ! in_array($type, (array) $applies, true)) {
            return self::refusal('trigger_not_supported', sprintf('The %s trigger does not apply to form type "%s".', $trigger, $type), [ 'triggers' => self::triggers_for($type) ]);
        }
        if (isset($args['trigger_config']) && null === self::clean_config($trigger, (array) $args['trigger_config'])) {
            return self::refusal('invalid_trigger_config', sprintf('trigger_config for %s takes only %s (s, p, ms: whole numbers; exi, m: booleans; i, c: CSS ids or classes).', $trigger, implode(', ', self::TRIGGER_KEYS[ $trigger ] ?? []) ?: 'nothing'), [ 'keys' => self::TRIGGER_KEYS[ $trigger ] ?? [] ]);
        }
        if (isset($args['position']) && ! in_array((string) $args['position'], self::POSITIONS[ $type ] ?? [], true)) {
            return self::refusal('position_not_supported', sprintf('Form type "%s" takes %s.', $type, isset(self::POSITIONS[ $type ]) ? 'position ' . implode(', ', self::POSITIONS[ $type ]) : 'no position'), [ 'positions' => self::POSITIONS[ $type ] ?? [] ]);
        }
        return null;
    }

    /**
     * A trigger_config cleaned the way Thrive Leads stores it, or null when
     * it names a key the trigger does not read or a value out of shape.
     *
     * @return array<string, int|string>|null
     */
    private static function clean_config(string $trigger, array $config): ?array
    {
        $allowed = self::TRIGGER_KEYS[ $trigger ] ?? [];
        $out     = [];
        foreach ($config as $key => $value) {
            $key = (string) $key;
            if (! in_array($key, $allowed, true) || is_array($value) || is_object($value)) {
                return null;
            }
            if (in_array($key, [ 's', 'p', 'ms' ], true)) {
                if (! is_numeric($value) || (int) $value < 0 || (int) $value > ('p' === $key ? 100 : 86400)) {
                    return null;
                }
                $out[ $key ] = (int) $value;
            } elseif (in_array($key, [ 'exi', 'm' ], true)) {
                $out[ $key ] = ! empty($value) && 'false' !== $value ? '1' : '';
            } else {
                if (1 !== preg_match('/^[A-Za-z0-9_\-,.# ]{0,200}$/', (string) $value)) {
                    return null;
                }
                $out[ $key ] = (string) $value;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------ data

    private static function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'tve_leads_' . $name;
    }

    private static function form_type(int $id): string
    {
        return (string) get_post_meta($id, 'tve_form_type', true);
    }

    /** @return string[] */
    private static function triggers_for(string $type): array
    {
        return array_keys(array_filter(self::TRIGGERS, static fn ($applies): bool => '*' === $applies || in_array($type, (array) $applies, true)));
    }

    /**
     * The published designs of a form (top-level states only), oldest first;
     * the first one is the control.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function variations(int $parent): array
    {
        global $wpdb;
        $table = self::table('form_variations');
        if (! Plugin_Table_Rows_Snapshot::table_exists($table)) {
            return [];
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Thrive Leads' own table, no WordPress API.
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT `key`, `post_title`, `post_status`, `trigger`, `trigger_config`, `display_frequency`, `position`, `display_animation`, `date_modified`, (`content` IS NOT NULL AND `content` <> '') AS has_design FROM %i WHERE `post_parent` = %d AND `post_status` = 'publish' AND (`parent_id` = 0 OR `parent_id` IS NULL) ORDER BY `key` ASC", $table, $parent), ARRAY_A);
        return array_map(static function (array $row): array {
            $row['key'] = (int) $row['key'];
            return $row;
        }, $rows);
    }

    /** @return array<string, mixed>|null */
    private static function variation_row(int $key): ?array
    {
        global $wpdb;
        if ($key < 1) {
            return null;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Thrive Leads' own table, no WordPress API.
        $row = $wpdb->get_row($wpdb->prepare('SELECT `key`, `post_parent`, `trigger`, `trigger_config` FROM %i WHERE `key` = %d', self::table('form_variations'), $key), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** The design an update-form-settings call acts on, or 0. */
    private static function resolve_variation(array $args): int
    {
        if (isset($args['variation_key'])) {
            return (int) $args['variation_key'];
        }
        $keys = array_column(self::variations((int) $args['id']), 'key');
        return 1 === count($keys) ? (int) $keys[0] : 0;
    }

    /** @return array<string, mixed> */
    private static function config($raw): array
    {
        $config = maybe_unserialize((string) $raw);
        return is_array($config) ? $config : [];
    }

    /**
     * Summed counts from the daily summary table, keyed by $by (form_type_id,
     * main_group_id or variation_key), or one total row when $by is ''.
     *
     * @return array<int|string, array{impressions: int, conversions: int, unique_visitors: int}>
     */
    private static function counts(string $by, array $where = []): array
    {
        global $wpdb;
        $table = self::table('form_summary');
        if (! Plugin_Table_Rows_Snapshot::table_exists($table)) {
            return [];
        }
        $sql    = 'SELECT ' . ('' === $by ? "'all'" : '%i') . ' AS k, SUM(`impression_count`) AS i, SUM(`conversion_count`) AS c, SUM(`unique_visitor_count`) AS u FROM %i WHERE 1=1';
        $params = '' === $by ? [ $table ] : [ $by, $table ];
        foreach ([ 'main_group_id', 'form_type_id', 'variation_key' ] as $column) {
            if (! empty($where[ $column ])) {
                $sql     .= ' AND %i = %d';
                $params[] = $column;
                $params[] = (int) $where[ $column ];
            }
        }
        if (! empty($where['date_from'])) {
            $sql     .= ' AND `date` >= %s';
            $params[] = (string) $where['date_from'];
        }
        if (! empty($where['date_to'])) {
            $sql     .= ' AND `date` <= %s';
            $params[] = (string) $where['date_to'];
        }
        if ('' !== $by) {
            $sql     .= ' GROUP BY %i';
            $params[] = $by;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Thrive Leads' own summary table; $sql holds only %i, %d and %s placeholders.
        $rows = (array) $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
        $out  = [];
        foreach ($rows as $row) {
            if (null === $row['i'] && null === $row['c']) {
                continue;
            }
            $out[ $row['k'] ] = [ 'impressions' => (int) $row['i'], 'conversions' => (int) $row['c'], 'unique_visitors' => (int) $row['u'] ];
        }
        return $out;
    }

    private static function rate(int $impressions, int $conversions): float
    {
        return $impressions > 0 ? round($conversions * 100 / $impressions, 2) : 0.0;
    }

    /** @return array<string, mixed> */
    private static function with_counts(array $counts): array
    {
        $i = (int) ($counts['impressions'] ?? 0);
        $c = (int) ($counts['conversions'] ?? 0);
        return [ 'impressions' => $i, 'conversions' => $c, 'conversion_rate' => self::rate($i, $c) ];
    }

    /** @return array<string, mixed>|null the stored row of a group's display rules */
    private static function rules_row(int $group): ?array
    {
        global $wpdb;
        $table = self::table('group_options');
        if (! Plugin_Table_Rows_Snapshot::table_exists($table)) {
            return null;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Thrive Leads' own table, no WordPress API.
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE `group` = %d ORDER BY `id` DESC LIMIT 1', $table, $group), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private static function decode_rules($json)
    {
        $rules = json_decode(stripcslashes((string) $json), true);
        return is_array($rules) ? $rules : null;
    }

    // ------------------------------------------------------------ views

    /** @return array<int, \WP_Post> */
    private static function forms_of(int $group): array
    {
        return Readable_Posts::get_posts([ 'post_type' => self::FORM, 'post_parent' => $group, 'post_status' => self::STATUSES, 'posts_per_page' => self::MAX_PAGE_SIZE, 'orderby' => 'ID', 'order' => 'ASC' ]);
    }

    /** @return array<string, mixed> */
    private static function view_group(\WP_Post $post, bool $full): array
    {
        $id    = (int) $post->ID;
        $rules = self::rules_row($id);
        $forms = self::forms_of($id);
        $out   = [
            'id'                => $id,
            'title'             => (string) $post->post_title,
            'status'            => (string) $post->post_status,
            'order'             => (int) get_post_meta($id, 'tve_group_order', true),
            'forms'             => count($forms),
            'has_display_rules' => null !== $rules,
        ] + self::with_counts(self::counts('', [ 'main_group_id' => $id ])['all'] ?? []);
        if ($full) {
            $out['forms']         = array_map(static fn (\WP_Post $f): array => self::view_form($f, false), $forms);
            $out['display_rules'] = null === $rules ? null : [
                'description' => (string) ($rules['description'] ?? ''),
                'show'        => self::decode_rules($rules['show_group_options'] ?? ''),
                'hide'        => self::decode_rules($rules['hide_group_options'] ?? ''),
            ];
        }
        return $out;
    }

    private static function flag(int $id, string $key): bool
    {
        $value = (string) get_post_meta($id, $key, true);
        return '' === $value || 1 === (int) $value;
    }

    /** @return array<string, mixed> */
    private static function view_form(\WP_Post $post, bool $full): array
    {
        $id         = (int) $post->ID;
        $type       = self::form_type($id);
        $variations = self::variations($id);
        $out        = [
            'id'         => $id,
            'title'      => (string) $post->post_title,
            'group_id'   => (int) $post->post_parent,
            'type'       => $type,
            'status'     => (string) $post->post_status,
            'desktop'    => self::flag($id, 'display_status'),
            'mobile'     => self::flag($id, 'display_on_mobile'),
            'variations' => count($variations),
        ];
        if (! $full) {
            return $out;
        }
        $counts             = self::counts('variation_key', [ 'form_type_id' => $id ]);
        $out['variations']  = [];
        foreach ($variations as $i => $v) {
            $out['variations'][] = self::view_variation($v, 0 === $i, $counts[ $v['key'] ] ?? []);
        }
        $out['allowed'] = [
            'triggers'   => self::triggers_for($type),
            'positions'  => self::POSITIONS[ $type ] ?? [],
            'animations' => self::ANIMATIONS,
        ];
        return $out;
    }

    /** @return array<string, mixed> */
    private static function view_variation(array $v, bool $control, array $counts): array
    {
        return [
            'key'               => (int) $v['key'],
            'title'             => (string) $v['post_title'],
            'is_control'        => $control,
            'has_design'        => ! empty($v['has_design']),
            'trigger'           => '' === (string) $v['trigger'] ? 'page_load' : (string) $v['trigger'],
            'trigger_config'    => self::config($v['trigger_config']),
            'display_frequency' => (int) $v['display_frequency'],
            'position'          => (string) $v['position'],
            'display_animation' => (string) $v['display_animation'],
            'modified'          => (string) $v['date_modified'],
        ] + self::with_counts($counts);
    }

    /** @return array<string, mixed> */
    private static function list_groups(): array
    {
        $groups = Readable_Posts::get_posts([ 'post_type' => self::GROUP, 'post_status' => self::STATUSES, 'posts_per_page' => self::MAX_PAGE_SIZE, 'orderby' => 'ID', 'order' => 'ASC' ]);
        $out    = array_map(static fn (\WP_Post $p): array => self::view_group($p, false), $groups);
        usort($out, static fn (array $a, array $b): int => [ $a['order'], $a['id'] ] <=> [ $b['order'], $b['id'] ]);
        return [ 'lead_groups' => $out ];
    }

    /** @return array<string, mixed> */
    private static function list_forms(array $args): array
    {
        $query = [
            'post_type'      => self::FORM,
            'post_status'    => self::STATUSES,
            'posts_per_page' => (int) ($args['per_page'] ?? 50),
            'paged'          => (int) ($args['page'] ?? 1),
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ];
        if (isset($args['group_id'])) {
            $query['post_parent'] = (int) $args['group_id'];
        }
        if (isset($args['type'])) {
            $query['meta_key']   = 'tve_form_type'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- the form type is post meta in Thrive Leads.
            $query['meta_value'] = (string) $args['type']; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- as above.
        }
        $q = Readable_Posts::query($query);
        return [ 'forms' => array_map(static fn (\WP_Post $p): array => self::view_form($p, false), $q->posts), 'total' => (int) $q->found_posts ];
    }

    /** @return array<string, mixed> */
    private static function list_shortcodes(): array
    {
        $posts = Readable_Posts::get_posts([ 'post_type' => self::SHORTCODE, 'post_status' => self::STATUSES, 'posts_per_page' => self::MAX_PAGE_SIZE, 'orderby' => 'ID', 'order' => 'ASC' ]);
        return [
            'shortcodes' => array_map(static function (\WP_Post $p): array {
                $id   = (int) $p->ID;
                $lock = 1 === (int) get_post_meta($id, 'tve_content_locking', true);
                return [
                    'id'              => $id,
                    'title'           => (string) $p->post_title,
                    'status'          => (string) $p->post_status,
                    'content_locking' => $lock,
                    'shortcode'       => $lock ? "[thrive_lead_lock id='" . $id . "']Hidden Content[/thrive_lead_lock]" : "[thrive_leads id='" . $id . "']",
                    'variations'      => count(self::variations($id)),
                ];
            }, $posts),
        ];
    }

    /** @return array<string, mixed> */
    private static function stats(array $args): array
    {
        $where = [
            'main_group_id' => (int) ($args['group_id'] ?? 0),
            'form_type_id'  => (int) ($args['form_id'] ?? 0),
            'date_from'     => (string) ($args['date_from'] ?? ''),
            'date_to'       => (string) ($args['date_to'] ?? ''),
        ];
        $total = self::counts('', $where)['all'] ?? [];
        $forms = [];
        foreach (self::counts('form_type_id', $where) as $form => $counts) {
            $post    = get_post((int) $form);
            $forms[] = [
                'form_id'  => (int) $form,
                'title'    => $post instanceof \WP_Post ? (string) $post->post_title : '',
                'group_id' => $post instanceof \WP_Post ? (int) $post->post_parent : 0,
                'type'     => self::form_type((int) $form),
            ] + self::with_counts($counts);
        }
        return [
            'totals' => self::with_counts($total) + [ 'unique_visitors' => (int) ($total['unique_visitors'] ?? 0) ],
            'forms'  => $forms,
            'from'   => '' === $where['date_from'] ? null : $where['date_from'],
            'to'     => '' === $where['date_to'] ? null : $where['date_to'],
        ];
    }

    // ------------------------------------------------------------ writes

    /** @return array<string, mixed> */
    private static function update_settings(array $args): array
    {
        global $wpdb;
        $key     = self::resolve_variation($args);
        $current = (array) self::variation_row($key);
        $fields  = [];
        $trigger = (string) ($current['trigger'] ?? 'page_load');
        if (isset($args['trigger'])) {
            $fields['trigger'] = (string) $args['trigger'];
            if ($trigger !== $fields['trigger'] && ! isset($args['trigger_config'])) {
                // A new trigger starts from its own defaults, as Thrive Leads' editor does.
                $fields['trigger_config'] = serialize([]); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Thrive Leads stores trigger_config serialized.
            }
            $trigger = $fields['trigger'];
        }
        if (isset($args['trigger_config'])) {
            $fields['trigger_config'] = serialize((array) self::clean_config($trigger, (array) $args['trigger_config'])); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- as above.
        }
        foreach ([ 'display_frequency', 'position', 'display_animation' ] as $column) {
            if (array_key_exists($column, $args)) {
                $fields[ $column ] = 'display_frequency' === $column ? (int) $args[ $column ] : (string) $args[ $column ];
            }
        }
        $fields['date_modified'] = gmdate('Y-m-d H:i:s');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Thrive Leads' own table, no WordPress API; snapshotted by the dispatcher first.
        if (false === $wpdb->update(self::table('form_variations'), $fields, [ 'key' => $key ])) {
            throw new Operation_Refused('update_failed', 'Thrive Leads could not save the form settings: ' . $wpdb->last_error);
        }
        $row = null;
        foreach (self::variations((int) $args['id']) as $i => $v) {
            if ($v['key'] === $key) {
                $row = self::view_variation($v, 0 === $i, []);
            }
        }
        return [ 'form_id' => (int) $args['id'], 'variation' => $row ];
    }

    /** @return array<string, mixed> */
    private static function toggle(array $args): array
    {
        $id = (int) $args['id'];
        if (isset($args['desktop'])) {
            update_post_meta($id, 'display_status', $args['desktop'] ? '1' : '0');
        }
        if (isset($args['mobile'])) {
            update_post_meta($id, 'display_on_mobile', $args['mobile'] ? '1' : '0');
        }
        clean_post_cache($id);
        return [ 'form' => self::view_form(get_post($id), false) ];
    }

    /** @return array<string, mixed> */
    private static function update_rules(array $args): array
    {
        global $wpdb;
        $group = (int) $args['id'];
        $table = self::table('group_options');
        if (! Plugin_Table_Rows_Snapshot::table_exists($table)) {
            throw new Operation_Refused('table_missing', 'Thrive Leads has not created its display settings table.');
        }
        $old = self::rules_row($group);
        $row = [
            'group'              => $group,
            'description'        => (string) ($old['description'] ?? ''),
            'show_group_options' => array_key_exists('show', $args) ? (string) wp_json_encode($args['show']) : (string) ($old['show_group_options'] ?? '{"tabs":[]}'),
            'hide_group_options' => array_key_exists('hide', $args) ? (string) wp_json_encode($args['hide']) : (string) ($old['hide_group_options'] ?? '{"tabs":[]}'),
        ];
        // Thrive_Leads_Group_Options::save(): drop the group's row, insert the new one.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Thrive Leads' own table, no WordPress API; snapshotted by the dispatcher first.
        $deleted = $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE `group` = %d', $table, $group));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- as above.
        if (false === $deleted || false === $wpdb->insert($table, $row)) {
            throw new Operation_Refused('update_failed', 'Thrive Leads could not save the display rules: ' . $wpdb->last_error);
        }
        return [ 'lead_group' => self::view_group(get_post($group), true) ];
    }
}
