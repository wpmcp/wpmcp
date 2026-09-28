<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * FunnelKit funnels and steps (issue #356): paid-tier reads on the theme
 * dispatcher pair, so they add no top-level tools.
 *  - list-funnelkit-funnels: funnels newest first, with a step summary;
 *  - get-funnelkit-funnel: one funnel's steps in order, each with its type,
 *    linked post, status, linked product ids and basic counts.
 *
 * Both run at manage_woocommerce and declare a 'requires' check, so while
 * FunnelKit is not loaded they stay documented in list-operations
 * (dependency_met:false) and answer funnelkit_inactive. Presence is
 * filterable through wpmcp_funnelkit_active.
 *
 * Storage, as FunnelKit Funnel Builder 3.16 keeps it: funnels are rows of
 * {prefix}bwf_funnels whose steps column is the ordered step list as JSON
 * ([{"type":"landing","id":12}, ...]); each step is a post of its own type;
 * checkout products live in the checkout's _wfacp_selected_products; an
 * upsell step lists its offers in _funnel_steps, each offer's products in
 * its _wfocu_setting. Counts come from the tables FunnelKit's own canvas
 * reads: session views and conversions in wfco_report_views, orders per
 * checkout in wfacp_stats and submissions per optin in bwf_optin_entries.
 *
 * Issue #365, checked against the free plugin's source: the free plugin
 * writes wfacp_stats and bwf_optin_entries itself, but records no views
 * (its WFCO_Model_Report_views shim forwards to the paid add-on's
 * WFFN_Report_Views and drops the write otherwise), so views, and the
 * landing and optin thank you conversions that are view rows too, need that
 * add-on. Offers need the upsell add-on (WFOCU_Core, the class the free
 * plugin guards its own upsell code on). The offer list is read the way the
 * free plugin reaches it, most authoritative first: the add-on's own
 * WFOCU_Core()->funnels->get_funnel_steps(); else the upsell step's
 * _funnel_steps meta, which the free plugin reads back directly when it
 * imports an upsell template; else the offers whose _funnel_id names the
 * step, the link the free plugin follows from an offer to its upsell step.
 * offers_source says which answered. Each offer's views and accepts come
 * from wfocu_event (action 2 viewed, 4 accepted), as the canvas counts them.
 *
 * A value that cannot be read is null (or, for a list, left out or empty)
 * and carries two siblings: *_unavailable, the reason in prose, and
 * *_unavailable_reason, a stable code (see REASONS) a client can branch on.
 *
 * Answers are built from chosen fields only. The stats tables sit next to
 * customer data (optin emails and form data, contact and order ids), and
 * only COUNT and SUM results ever leave them.
 */
final class FunnelKit_Pack
{
    public const DEFAULT_PER_PAGE = 20;
    public const MAX_PER_PAGE     = 50;

    /** Step type => the post type FunnelKit stores that step as. */
    private const STEP_POST_TYPES = [
        'landing'     => 'wffn_landing',
        'optin'       => 'wffn_optin',
        'optin_ty'    => 'wffn_oty',
        'wc_checkout' => 'wfacp_checkout',
        'wc_upsells'  => 'wfocu_funnel',
        'wc_thankyou' => 'wffn_ty',
    ];

    /**
     * Step type => [view type, conversion type] in wfco_report_views (null
     * where FunnelKit records none there).
     */
    private const VIEW_TYPES = [
        'landing'     => [ 2, 3 ],
        'wc_checkout' => [ 4, null ],
        'wc_thankyou' => [ 5, null ],
        'optin'       => [ 8, null ],
        'optin_ty'    => [ 10, 11 ],
    ];

    /** Reason code => its prose; %s is a table name. */
    private const REASONS = [
        'pro_addon_inactive'        => 'recorded only by the Funnel Builder Pro add-on, which is not active',
        'upsell_addon_inactive'     => 'upsell add-on not active',
        'table_missing'             => '%s table missing',
        'not_tracked_for_step_type' => 'FunnelKit does not track this for the step type',
        'counted_per_offer'         => 'FunnelKit counts upsells per offer, not per step',
        'step_has_no_post_id'       => 'step has no post id',
        'step_post_missing'         => 'the step post is missing or not of the step\'s type',
        'no_offer_list_stored'      => 'no offer list stored for the upsell step',
    ];

    /** Most offers a fallback lookup reads for one upsell step. */
    private const MAX_OFFERS = 100;

    /** @return array<string,array<string,mixed>> */
    public static function operations(): array
    {
        $requires = static fn () => Ops_Status_Packs::presence(self::is_active(), 'funnelkit_inactive', 'FunnelKit');

        return [
            'list-funnelkit-funnels' => [
                'mode'         => 'read',
                'tier'         => 'pro',
                'capability'   => 'manage_woocommerce',
                'description'  => 'List FunnelKit funnels newest first: id, title, description, created, step count and step types. search matches the title; page and per_page (max 50) page it',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'search'   => [ 'type' => 'string' ],
                        'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
                        'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE ],
                    ],
                ],
                'requires'     => $requires,
                'handler'      => static fn (array $args): array => self::list_funnels($args),
            ],
            'get-funnelkit-funnel'   => [
                'mode'         => 'read',
                'tier'         => 'pro',
                'capability'   => 'manage_woocommerce',
                'description'  => 'One FunnelKit funnel with its steps in order: type, linked post id and type, title, status, linked product ids, upsell offers, and views and conversions where FunnelKit records them. No customer data',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
                    'required'   => [ 'id' ],
                ],
                'requires'     => $requires,
                'validate'     => static fn (array $args): ?array => null === self::funnel_row((int) $args['id'])
                    ? [ 'code' => 'funnel_not_found', 'message' => 'No FunnelKit funnel has that id.', 'data' => [ 'id' => (int) $args['id'] ] ]
                    : null,
                'handler'      => static fn (array $args): array => self::get_funnel((int) $args['id']),
            ],
        ];
    }

    /** Whether FunnelKit is loaded, filterable through wpmcp_funnelkit_active. */
    public static function is_active(): bool
    {
        return (bool) apply_filters('wpmcp_funnelkit_active', defined('WFFN_VERSION'));
    }

    /** Whether the paid add-on that records step views is loaded, filterable through wpmcp_funnelkit_views_recorded. */
    private static function views_recorded(): bool
    {
        return (bool) apply_filters('wpmcp_funnelkit_views_recorded', class_exists('WFFN_Report_Views'));
    }

    /** Whether the upsell add-on is loaded, filterable through wpmcp_funnelkit_upsells_active. */
    private static function upsells_active(): bool
    {
        return (bool) apply_filters('wpmcp_funnelkit_upsells_active', class_exists('WFOCU_Core'));
    }

    private static function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . $name;
    }

    /**
     * A reason as [code, prose].
     *
     * @return array{0:string,1:string}
     */
    private static function reason(string $code, string $table = ''): array
    {
        return [ $code, sprintf(self::REASONS[ $code ], $table) ];
    }

    /**
     * $out with $field's *_unavailable prose and *_unavailable_reason code.
     *
     * @param array<string,mixed>      $out
     * @param array{0:string,1:string} $reason
     * @return array<string,mixed>
     */
    private static function mark(array $out, string $field, array $reason): array
    {
        $out[ $field . '_unavailable' ]        = $reason[1];
        $out[ $field . '_unavailable_reason' ] = $reason[0];
        return $out;
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private static function list_funnels(array $args): array
    {
        global $wpdb;

        $page     = max(1, (int) ($args['page'] ?? 1));
        $per_page = min(self::MAX_PER_PAGE, max(1, (int) ($args['per_page'] ?? self::DEFAULT_PER_PAGE)));
        $out      = [ 'funnels' => [], 'total' => 0, 'page' => $page, 'per_page' => $per_page ];

        $table = self::table('bwf_funnels');
        if (! Ops_Status_Packs::table_exists($table)) {
            return self::mark($out, 'funnels', self::reason('table_missing', 'bwf_funnels'));
        }

        $search = trim((string) ($args['search'] ?? ''));
        $like   = '%' . $wpdb->esc_like($search) . '%';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- FunnelKit's own table has no WP API.
        if ('' === $search) {
            $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $table));
            $rows  = $wpdb->get_results($wpdb->prepare('SELECT id, title, `desc`, date_added, steps FROM %i ORDER BY id DESC LIMIT %d OFFSET %d', $table, $per_page, ($page - 1) * $per_page), ARRAY_A);
        } else {
            $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE title LIKE %s', $table, $like));
            $rows  = $wpdb->get_results($wpdb->prepare('SELECT id, title, `desc`, date_added, steps FROM %i WHERE title LIKE %s ORDER BY id DESC LIMIT %d OFFSET %d', $table, $like, $per_page, ($page - 1) * $per_page), ARRAY_A);
        }
        // phpcs:enable

        foreach ((array) $rows as $row) {
            $steps            = self::steps($row['steps'] ?? null);
            $out['funnels'][] = self::funnel_summary($row) + [
                'step_count' => count($steps),
                'step_types' => array_column($steps, 'type'),
            ];
        }
        $out['total'] = $total;

        return $out;
    }

    /** @return array<string,mixed>|null the raw funnel row, or null when there is none. */
    private static function funnel_row(int $id): ?array
    {
        global $wpdb;

        $table = self::table('bwf_funnels');
        if ($id < 1 || ! Ops_Status_Packs::table_exists($table)) {
            return null;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- FunnelKit's own table has no WP API.
        $row = $wpdb->get_row($wpdb->prepare('SELECT id, title, `desc`, date_added, steps FROM %i WHERE id = %d', $table, $id), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed> */
    private static function get_funnel(int $id): array
    {
        $row   = (array) self::funnel_row($id);
        $steps = self::steps($row['steps'] ?? null);

        $counts = self::counts($steps);

        $out = [];
        foreach ($steps as $index => $step) {
            $no_id = self::reason('step_has_no_post_id');
            $out[] = self::step($index + 1, $step['type'], $step['id'], $counts[ $step['id'] ] ?? self::counts_row(null, $no_id, null, $no_id));
        }

        return self::funnel_summary($row) + [
            'store_checkout' => $id === (int) get_option('_bwf_global_funnel', 0),
            'step_count'     => count($steps),
            'steps'          => $out,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array{id:int,title:string,description:string,created:?string}
     */
    private static function funnel_summary(array $row): array
    {
        return [
            'id'          => (int) ($row['id'] ?? 0),
            'title'       => sanitize_text_field((string) ($row['title'] ?? '')),
            'description' => sanitize_textarea_field((string) ($row['desc'] ?? '')),
            'created'     => self::created($row['date_added'] ?? null),
        ];
    }

    /** FunnelKit stamps date_added in site time (current_time('mysql')); report it as UTC. */
    private static function created($date): ?string
    {
        if (! is_string($date) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $date) || str_starts_with($date, '0000')) {
            return null;
        }
        return Ops_Status_Packs::iso_datetime(get_gmt_from_date($date));
    }

    /**
     * The ordered step list from the steps column, as WFFN_Funnel reads it:
     * JSON whose entries carry a type and an id.
     *
     * @return array<int,array{type:string,id:int}>
     */
    private static function steps($json): array
    {
        $decoded = is_string($json) ? json_decode($json, true) : null;
        if (! is_array($decoded)) {
            return [];
        }

        $steps = [];
        foreach ($decoded as $entry) {
            if (! is_array($entry) || ! is_string($entry['type'] ?? null)) {
                continue;
            }
            $steps[] = [ 'type' => sanitize_key($entry['type']), 'id' => max(0, (int) ($entry['id'] ?? 0)) ];
        }
        return $steps;
    }

    /**
     * @param array<string,mixed> $counts
     * @return array<string,mixed>
     */
    private static function step(int $position, string $type, int $post_id, array $counts): array
    {
        $post     = $post_id > 0 ? get_post($post_id) : null;
        $expected = self::STEP_POST_TYPES[ $type ] ?? null;
        $own      = $post instanceof \WP_Post && null !== $expected && $expected === $post->post_type;

        $out = [
            'position'    => $position,
            'type'        => $type,
            'post_id'     => $post_id > 0 ? $post_id : null,
            'post_type'   => $post instanceof \WP_Post ? $post->post_type : null,
            'title'       => $post instanceof \WP_Post ? sanitize_text_field($post->post_title) : null,
            'status'      => self::status($post),
            'product_ids' => [],
            'counts'      => $counts,
        ];

        if (! in_array($type, [ 'wc_checkout', 'wc_upsells' ], true)) {
            return $out;
        }
        if (! $own) {
            $why = self::reason('step_post_missing');
            $out = self::mark($out, 'product_ids', $why);
            return 'wc_upsells' === $type ? self::mark($out, 'offers', $why) : $out;
        }
        if ('wc_checkout' === $type) {
            $out['product_ids'] = self::checkout_products($post_id);
            return $out;
        }

        $found = self::upsells_active() ? self::offers($post_id) : self::reason('upsell_addon_inactive');
        if (! isset($found['source'])) {
            return self::mark(self::mark($out, 'product_ids', $found), 'offers', $found);
        }
        $out['product_ids']   = array_values(array_unique(array_merge([], ...array_column($found['offers'], 'product_ids'))));
        $out['offers_source'] = $found['source'];
        $out['offers']        = $found['offers'];

        return $out;
    }

    /** active (published), inactive (any other status) or missing (no post). */
    private static function status($post): string
    {
        if (! $post instanceof \WP_Post) {
            return 'missing';
        }
        return 'publish' === $post->post_status ? 'active' : 'inactive';
    }

    /**
     * Product (or variation) ids a checkout sells, from
     * _wfacp_selected_products: entries keyed by a hash, each with an id.
     *
     * @return int[]
     */
    private static function checkout_products(int $checkout_id): array
    {
        return self::product_ids(get_post_meta($checkout_id, '_wfacp_selected_products', true));
    }

    /** @return int[] */
    private static function product_ids($products): array
    {
        if (is_object($products)) {
            $products = get_object_vars($products);
        }
        if (! is_array($products)) {
            return [];
        }

        $ids = [];
        foreach ($products as $product) {
            if (is_object($product)) {
                $product = get_object_vars($product);
            }
            $id = is_array($product) ? (int) ($product['id'] ?? 0) : 0;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * An upsell step's offers in order, each with the products its
     * _wfocu_setting sells and its views and accepts. The reason, as
     * [code, prose], when no offer list is found.
     *
     * @return array{source:string,offers:array<int,array<string,mixed>>}|array{0:string,1:string}
     */
    private static function offers(int $upsell_id)
    {
        $found = self::offer_rows($upsell_id);
        if (null === $found) {
            return self::reason('no_offer_list_stored');
        }

        $types  = self::offer_post_types();
        $offers = [];
        foreach ($found[1] as $row) {
            if (is_object($row)) {
                $row = get_object_vars($row);
            }
            $id = is_array($row) ? (int) ($row['id'] ?? 0) : 0;
            if ($id < 1) {
                continue;
            }
            $post     = get_post($id);
            $own      = $post instanceof \WP_Post && in_array($post->post_type, $types, true);
            $setting  = $own ? get_post_meta($id, '_wfocu_setting', true) : null;
            $products = is_object($setting) ? ($setting->products ?? null) : (is_array($setting) ? ($setting['products'] ?? null) : null);

            $offers[ $id ] = [
                'post_id'     => $id,
                'type'        => is_string($row['type'] ?? null) ? sanitize_key($row['type']) : null,
                'title'       => $post instanceof \WP_Post ? sanitize_text_field($post->post_title) : null,
                'status'      => self::status($post),
                'product_ids' => self::product_ids($products),
            ];
        }

        $counts = self::offer_counts(array_keys($offers));
        foreach ($offers as $id => $offer) {
            $offers[ $id ]['counts'] = is_array($counts)
                ? [ 'views' => $counts[ $id ][0] ?? 0, 'conversions' => $counts[ $id ][1] ?? 0 ]
                : self::counts_row(null, self::reason('table_missing', 'wfocu_event'), null, self::reason('table_missing', 'wfocu_event'));
        }

        return [ 'source' => $found[0], 'offers' => array_values($offers) ];
    }

    /**
     * The raw offer list of an upsell step and where it came from, or null
     * when no source has one.
     *
     * @return array{0:string,1:array<int|string,mixed>}|null
     */
    private static function offer_rows(int $upsell_id): ?array
    {
        $api = self::addon_offer_rows($upsell_id);
        if (null !== $api) {
            return [ 'addon_api', $api ];
        }

        $meta = get_post_meta($upsell_id, '_funnel_steps', true);
        if (is_array($meta)) {
            return [ 'funnel_steps_meta', $meta ];
        }

        $ids = get_posts([
            'post_type'        => self::offer_post_types(),
            'post_status'      => 'any',
            'fields'           => 'ids',
            'numberposts'      => self::MAX_OFFERS,
            'orderby'          => [ 'menu_order' => 'ASC', 'ID' => 'ASC' ],
            'meta_key'         => '_funnel_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- the link FunnelKit itself keeps from an offer to its upsell step.
            'meta_value'       => (string) $upsell_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- as above.
            'no_found_rows'    => true,
        ]);
        if ([] === $ids) {
            return null;
        }
        return [ 'offer_parent_meta', array_map(static fn ($id): array => [ 'id' => (int) $id ], $ids) ];
    }

    /**
     * The offer list as the upsell add-on's own API answers it, or null when
     * the API is not there or does not answer with a list.
     *
     * @return array<int|string,mixed>|null
     */
    private static function addon_offer_rows(int $upsell_id): ?array
    {
        if (! function_exists('WFOCU_Core')) {
            return null;
        }
        try {
            $core    = WFOCU_Core();
            $funnels = is_object($core) && (isset($core->funnels) || method_exists($core, '__get')) ? $core->funnels : null;
            $rows    = is_object($funnels) && method_exists($funnels, 'get_funnel_steps') ? $funnels->get_funnel_steps($upsell_id) : null;
        } catch (\Throwable $e) {
            return null;
        }
        return is_array($rows) ? $rows : null;
    }

    /** @return string[] the post types an offer may be stored as. */
    private static function offer_post_types(): array
    {
        $types = [ 'wfocu_offer' ];
        if (is_callable([ 'WFOCU_Common', 'get_offer_post_type_slug' ])) {
            $slug = call_user_func([ 'WFOCU_Common', 'get_offer_post_type_slug' ]);
            if (is_string($slug) && '' !== $slug) {
                $types[] = sanitize_key($slug);
            }
        }
        return array_values(array_unique($types));
    }

    /**
     * [views, accepts] per offer id from wfocu_event, or null when the table
     * is absent. Only aggregates are selected.
     *
     * @param int[] $ids
     * @return array<int,array{0:int,1:int}>|null
     */
    private static function offer_counts(array $ids): ?array
    {
        global $wpdb;

        $table = self::table('wfocu_event');
        if (! Ops_Status_Packs::table_exists($table)) {
            return null;
        }
        if ([] === $ids) {
            return [];
        }

        $in = implode(',', array_map('intval', $ids));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the upsell add-on's own table; $in is a list of integers.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT object_id, SUM(action_type_id = 2) AS views, SUM(action_type_id = 4) AS accepts FROM %i WHERE object_id IN ({$in}) AND action_type_id IN (2, 4) GROUP BY object_id", $table), ARRAY_A);

        $out = [];
        foreach ((array) $rows as $row) {
            $out[ (int) $row['object_id'] ] = [ (int) $row['views'], (int) $row['accepts'] ];
        }
        return $out;
    }

    /**
     * Views and conversions per step id, as FunnelKit's funnel canvas counts
     * them. Only aggregates are selected.
     *
     * @param array<int,array{type:string,id:int}> $steps
     * @return array<int,array<string,int|string|null>>
     */
    private static function counts(array $steps): array
    {
        global $wpdb;

        $by_type = [];
        foreach ($steps as $step) {
            if ($step['id'] > 0) {
                $by_type[ $step['type'] ][] = $step['id'];
            }
        }
        $all = array_values(array_unique(array_merge([], ...array_values($by_type))));
        if ([] === $all) {
            return [];
        }

        $sessions      = [];
        $views_missing = self::reason('pro_addon_inactive');
        $views_table   = self::table('wfco_report_views');
        if (! self::views_recorded()) {
            $sessions = null;
        } elseif (! Ops_Status_Packs::table_exists($views_table)) {
            $sessions      = null;
            $views_missing = self::reason('table_missing', 'wfco_report_views');
        } else {
            $in = implode(',', array_map('intval', $all));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- FunnelKit's own table; $in is a list of integers.
            $rows = $wpdb->get_results($wpdb->prepare("SELECT object_id, type, SUM(no_of_sessions) AS n FROM %i WHERE object_id IN ({$in}) GROUP BY object_id, type", $views_table), ARRAY_A);
            foreach ((array) $rows as $row) {
                $sessions[ (int) $row['object_id'] ][ (int) $row['type'] ] = (int) $row['n'];
            }
        }

        $orders  = self::count_by('wfacp_stats', 'wfacp_id', $by_type['wc_checkout'] ?? []);
        $entries = self::count_by('bwf_optin_entries', 'step_id', $by_type['optin'] ?? []);

        // A view row type's session count for a step id, as [value, reason].
        $view = static function (int $id, ?int $type, array $untracked) use ($sessions, $views_missing): array {
            if (null === $type) {
                return [ null, $untracked ];
            }
            return null === $sessions ? [ null, $views_missing ] : [ $sessions[ $id ][ $type ] ?? 0, null ];
        };

        $out = [];
        foreach ($steps as $step) {
            $id = $step['id'];
            if ($id < 1) {
                continue;
            }
            $type      = $step['type'];
            $untracked = self::reason('wc_upsells' === $type ? 'counted_per_offer' : 'not_tracked_for_step_type');
            [ $view_type, $convert_type ] = self::VIEW_TYPES[ $type ] ?? [ null, null ];

            [ $views, $views_why ] = $view($id, $view_type, $untracked);
            if ('wc_checkout' === $type || 'optin' === $type) {
                [ $counted, $table ]        = 'optin' === $type ? [ $entries, 'bwf_optin_entries' ] : [ $orders, 'wfacp_stats' ];
                [ $conversions, $conv_why ] = null === $counted ? [ null, self::reason('table_missing', $table) ] : [ $counted[ $id ] ?? 0, null ];
            } else {
                [ $conversions, $conv_why ] = $view($id, $convert_type, $untracked);
            }

            $out[ $id ] = self::counts_row($views, $views_why, $conversions, $conv_why);
        }
        return $out;
    }

    /**
     * A step's counts, with a *_unavailable reason and code beside each null.
     *
     * @param array{0:string,1:string}|null $views_why
     * @param array{0:string,1:string}|null $conv_why
     * @return array<string,int|string|null>
     */
    private static function counts_row(?int $views, ?array $views_why, ?int $conversions, ?array $conv_why): array
    {
        $row = [ 'views' => $views, 'conversions' => $conversions ];
        if (null !== $views_why) {
            $row = self::mark($row, 'views', $views_why);
        }
        if (null !== $conv_why) {
            $row = self::mark($row, 'conversions', $conv_why);
        }
        return $row;
    }

    /**
     * Row counts per id in one of FunnelKit's stats tables, or null when the
     * table is absent.
     *
     * @param int[] $ids
     * @return array<int,int>|null
     */
    private static function count_by(string $name, string $column, array $ids): ?array
    {
        global $wpdb;

        $table = self::table($name);
        if ([] === $ids || ! Ops_Status_Packs::table_exists($table)) {
            return [] === $ids ? [] : null;
        }

        $in = implode(',', array_map('intval', $ids));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- FunnelKit's own table; $in is a list of integers and $column a literal.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT %i AS id, COUNT(*) AS n FROM %i WHERE %i IN ({$in}) GROUP BY %i", $column, $table, $column, $column), ARRAY_A);

        $out = [];
        foreach ((array) $rows as $row) {
            $out[ (int) $row['id'] ] = (int) $row['n'];
        }
        return $out;
    }
}
