<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Plugin_Table_Rows_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Hustle (issue #898) on the plugin-data pair, free tier and read-only, so
 * it adds no top-level tools. Ops are prefixed hustle- because the pair is
 * shared.
 *
 * Hustle 7 keeps a module (popup, slide-in, embed or social share) as a row
 * of its own hustle_modules table (module_id, module_name, module_type,
 * active, module_mode) with its data as JSON rows of hustle_modules_meta
 * (content: title, sub_title, main_content and the call to action;
 * settings: triggers and the start and end schedule; visibility: the
 * display conditions). Views and conversions are counted in
 * hustle_tracking, one row per module, page and action with a counter
 * (Hustle_Tracking_Model).
 *
 * hustle-list-modules lists modules with their switch, triggers, schedule
 * and counts; hustle-get-module reads one with its content, schedule and
 * display conditions. Only the content, settings, visibility and
 * shortcode_id rows are read: the emails, integrations_settings and
 * provider rows (form fields, API keys) are never selected, and neither is
 * the tracking table's ip column, so nothing needs masking.
 *
 * Writes are not part of this pack yet: Hustle caches module rows in the
 * object cache and keeps derived schedule flags beside the settings, so an
 * undoable write needs its own snapshot kind first (follow-up to #898).
 *
 * Presence is filterable (wpmcp_hustle_active, default: HUSTLE_BASE_FILE is
 * defined).
 */
final class Hustle_Pack
{
    public const CAP = 'hustle_edit_module';

    public const MAX_PAGE_SIZE = 100;

    public const PLUGIN_FILES = [ 'wordpress-popup/popover.php', 'hustle/opt-in.php' ];

    public const TYPES = [ 'popup', 'slidein', 'embedded', 'social_sharing' ];

    /** The meta rows this pack reads; every other row stays unread. */
    private const META_KEYS = [ 'content', 'settings', 'visibility', 'shortcode_id' ];

    /** The content fields an agent edits copy in. */
    private const CONTENT_KEYS = [
        'title', 'sub_title', 'main_content', 'feature_image', 'background_image', 'show_cta', 'cta_label', 'cta_url',
        'cta_target', 'cta_two_label', 'cta_two_url', 'cta_two_target', 'show_never_see_link', 'never_see_link_text',
    ];

    private const CONVERSIONS = [ 'conversion', 'optin_conversion', 'cta_conversion', 'cta_1_conversion', 'cta_2_conversion' ];

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_hustle_active', defined('HUSTLE_BASE_FILE'));
    }

    /** @return true|array{code: string, message: string, data: array} */
    public static function presence()
    {
        if (self::active()) {
            return true;
        }
        $installed = false;
        foreach (self::PLUGIN_FILES as $file) {
            $installed = $installed || (defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/' . $file));
        }
        $url = admin_url('plugins.php');

        return [
            'code'    => $installed ? 'plugin_inactive' : 'plugin_missing',
            'message' => sprintf(
                'Hustle is %s on this site. Install and activate Hustle (%s), then try again.',
                $installed ? 'installed but not active' : 'not installed',
                $url
            ),
            'data'    => [ 'plugin' => 'wordpress-popup', 'plugins_url' => $url ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $base = [
            'mode'       => 'read',
            'capability' => 'edit_posts',
            'requires'   => static fn () => self::presence(),
        ];

        return [
            'hustle-list-modules' => $base + [
                'description'  => 'List Hustle popups, slide-ins and embeds: id, name, type, mode, active, title, triggers, schedule dates, views, conversions. Filter by type, active, search. page, per_page (max 100)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'type'     => [ 'type' => 'string', 'enum' => self::TYPES ],
                        'active'   => [ 'type' => 'boolean' ],
                        'search'   => [ 'type' => 'string', 'maxLength' => 200 ],
                        'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
                        'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE_SIZE ],
                    ],
                ],
                'validate'     => static fn (): ?array => self::refusal(),
                'handler'      => static fn (array $args): array => self::list_modules($args),
            ],
            'hustle-get-module'   => $base + [
                'description'  => 'Read one Hustle module: content (title, text, call to action), triggers, schedule, display conditions, views and conversions by kind',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'module_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'module_id' ] ],
                'validate'     => static fn (array $args): ?array => self::refusal() ?? self::module_refusal((int) $args['module_id']),
                'handler'      => static fn (array $args): array => [ 'module' => self::view(self::module_row((int) $args['module_id']), true) ],
            ],
        ];
    }

    // ------------------------------------------------------------ refusals

    private static function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . $name;
    }

    private static function refusal(): ?array
    {
        if (! current_user_can('manage_options') && ! current_user_can(self::CAP)) {
            return [ 'code' => 'operation_denied', 'message' => sprintf('This needs the %s capability (Hustle grants it to administrators).', self::CAP), 'data' => [ 'reason' => 'capability', 'capability' => self::CAP ] ];
        }
        if (! Plugin_Table_Rows_Snapshot::table_exists(self::table('hustle_modules'))) {
            return [ 'code' => 'tables_missing', 'message' => 'Hustle has not created its modules table yet. Open Hustle in wp-admin once, then try again.', 'data' => [] ];
        }
        return null;
    }

    private static function module_refusal(int $id): ?array
    {
        return null === self::module_row($id) ? [ 'code' => 'module_not_found', 'message' => sprintf('%d is not a Hustle module.', $id), 'data' => [ 'module_id' => $id ] ] : null;
    }

    // ------------------------------------------------------------ reads

    /** @return array<string, mixed>|null */
    private static function module_row(int $id): ?array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Hustle's own table; it has no public getter that skips its admin checks.
        $row = $wpdb->get_row($wpdb->prepare('SELECT module_id, module_name, module_type, active, module_mode FROM %i WHERE module_id = %d', self::table('hustle_modules'), $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * The read meta rows of the given modules, decoded.
     *
     * @param int[] $ids
     * @return array<int, array<string, mixed>>
     */
    private static function metas(array $ids): array
    {
        global $wpdb;
        if ([] === $ids) {
            return [];
        }
        $in   = implode(',', array_fill(0, count($ids), '%d'));
        $keys = implode(',', array_fill(0, count(self::META_KEYS), '%s'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in and $keys hold only placeholders.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT module_id, meta_key, meta_value FROM %i WHERE module_id IN ($in) AND meta_key IN ($keys)", self::table('hustle_modules_meta'), ...array_merge($ids, self::META_KEYS)), ARRAY_A);
        $out  = [];
        foreach ((array) $rows as $row) {
            $value = (string) $row['meta_value'];
            $json  = json_decode($value, true);
            if (! is_array($json)) {
                $json = json_decode(stripslashes($value), true);
            }
            $out[ (int) $row['module_id'] ][ (string) $row['meta_key'] ] = is_array($json) ? $json : $value;
        }
        return $out;
    }

    /**
     * Views and conversions per module and action, summed as Hustle sums
     * them.
     *
     * @param int[] $ids
     * @return array<int, array<string, int>>
     */
    private static function counts(array $ids): array
    {
        global $wpdb;
        $table = self::table('hustle_tracking');
        if ([] === $ids || ! Plugin_Table_Rows_Snapshot::table_exists($table)) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '%d'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds only %d placeholders.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT module_id, action, SUM(counter) AS total FROM %i WHERE module_id IN ($in) GROUP BY module_id, action", $table, ...$ids), ARRAY_A);
        $out  = [];
        foreach ((array) $rows as $row) {
            $out[ (int) $row['module_id'] ][ (string) $row['action'] ] = (int) $row['total'];
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private static function schedule(array $settings): array
    {
        $s       = is_array($settings['schedule'] ?? null) ? $settings['schedule'] : [];
        $enabled = '1' === (string) ($settings['is_schedule'] ?? '0');
        $at      = static fn (string $side): ?string => $enabled && '1' !== (string) ($s[ 'not_schedule_' . $side ] ?? '1')
            ? trim(sprintf('%s %s:%s %s', $s[ $side . '_date' ] ?? '', $s[ $side . '_hour' ] ?? '', $s[ $side . '_minute' ] ?? '', $s[ $side . '_meridiem_offset' ] ?? ''))
            : null;
        return [
            'enabled'  => $enabled,
            'start'    => $at('start'),
            'end'      => $at('end'),
            'timezone' => 'custom' === ($s['time_to_use'] ?? 'server') ? (string) ($s['custom_timezone'] ?? '') : 'server',
        ];
    }

    /** @return array<string, mixed> */
    private static function view(array $row, bool $full, ?array $meta = null, ?array $counts = null): array
    {
        $id       = (int) $row['module_id'];
        $meta     = $meta ?? (self::metas([ $id ])[ $id ] ?? []);
        $counts   = $counts ?? (self::counts([ $id ])[ $id ] ?? []);
        $content  = is_array($meta['content'] ?? null) ? $meta['content'] : [];
        $settings = is_array($meta['settings'] ?? null) ? $meta['settings'] : [];
        $triggers = (array) ($settings['triggers']['trigger'] ?? []);
        $out      = [
            'id'          => $id,
            'name'        => (string) $row['module_name'],
            'type'        => (string) $row['module_type'],
            'mode'        => (string) $row['module_mode'],
            'active'      => 1 === (int) $row['active'],
            'title'       => (string) ($content['title'] ?? ''),
            'triggers'    => array_values(array_map('strval', $triggers)),
            'schedule'    => self::schedule($settings),
            'views'       => (int) ($counts['view'] ?? 0),
            'conversions' => array_sum(array_intersect_key($counts, array_flip(self::CONVERSIONS))),
        ];
        if ($full) {
            $out['content']           = array_intersect_key($content, array_flip(self::CONTENT_KEYS));
            $out['trigger_settings']  = is_array($settings['triggers'] ?? null) ? $settings['triggers'] : [];
            $out['visibility']        = is_array($meta['visibility'] ?? null) ? $meta['visibility'] : [];
            $out['conversions_by']    = array_intersect_key($counts, array_flip(self::CONVERSIONS));
            $shortcode                = $meta['shortcode_id'] ?? '';
            $out['shortcode']         = is_string($shortcode) && '' !== $shortcode ? sprintf('[wd_hustle id="%s" type="%s"]', $shortcode, $out['type']) : null;
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private static function list_modules(array $args): array
    {
        global $wpdb;
        $where  = [ '1=1' ];
        $values = [ self::table('hustle_modules') ];
        if (isset($args['type'])) {
            $where[]  = 'module_type = %s';
            $values[] = (string) $args['type'];
        }
        if (isset($args['active'])) {
            $where[]  = 'active = %d';
            $values[] = $args['active'] ? 1 : 0;
        }
        if (isset($args['search']) && '' !== trim((string) $args['search'])) {
            $where[]  = 'module_name LIKE %s';
            $values[] = '%' . $wpdb->esc_like(trim((string) $args['search'])) . '%';
        }
        $per_page = (int) ($args['per_page'] ?? 20);
        $offset   = ((int) ($args['page'] ?? 1) - 1) * $per_page;
        $sql      = implode(' AND ', $where);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $sql joins fixed clauses with placeholders only.
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE $sql", ...$values));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- as above; the values carry every placeholder.
        $rows   = (array) $wpdb->get_results($wpdb->prepare("SELECT module_id, module_name, module_type, active, module_mode FROM %i WHERE $sql ORDER BY module_name ASC, module_id ASC LIMIT %d OFFSET %d", ...array_merge($values, [ $per_page, $offset ])), ARRAY_A);
        $ids    = array_map(static fn (array $r): int => (int) $r['module_id'], $rows);
        $metas  = self::metas($ids);
        $counts = self::counts($ids);

        return [
            'modules' => array_map(static fn (array $r): array => self::view($r, false, $metas[ (int) $r['module_id'] ] ?? [], $counts[ (int) $r['module_id'] ] ?? []), $rows),
            'total'   => $total,
        ];
    }
}
