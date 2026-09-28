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
 * A count whose table is absent, or that FunnelKit does not record for the
 * step type, is null.
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

    private static function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . $name;
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
            return $out;
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
            $out[] = self::step($index + 1, $step['type'], $step['id'], $counts[ $step['id'] ] ?? null);
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
     * @param array{views:?int,conversions:?int}|null $counts
     * @return array<string,mixed>
     */
    private static function step(int $position, string $type, int $post_id, ?array $counts): array
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
            'counts'      => $counts ?? [ 'views' => null, 'conversions' => null ],
        ];

        if ($own && 'wc_checkout' === $type) {
            $out['product_ids'] = self::checkout_products($post_id);
        }
        if ($own && 'wc_upsells' === $type) {
            $out['offers']      = self::offers($post_id);
            $out['product_ids'] = array_values(array_unique(array_merge([], ...array_column($out['offers'], 'product_ids'))));
        }

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
     * An upsell step's offers in order, from its _funnel_steps, each with
     * the products its _wfocu_setting sells.
     *
     * @return array<int,array{post_id:int,type:string,title:?string,status:string,product_ids:int[]}>
     */
    private static function offers(int $upsell_id): array
    {
        $rows = get_post_meta($upsell_id, '_funnel_steps', true);
        if (! is_array($rows)) {
            return [];
        }

        $offers = [];
        foreach ($rows as $row) {
            $id = is_array($row) ? (int) ($row['id'] ?? 0) : 0;
            if ($id < 1) {
                continue;
            }
            $post     = get_post($id);
            $own      = $post instanceof \WP_Post && 'wfocu_offer' === $post->post_type;
            $setting  = $own ? get_post_meta($id, '_wfocu_setting', true) : null;
            $products = is_object($setting) ? ($setting->products ?? null) : (is_array($setting) ? ($setting['products'] ?? null) : null);

            $offers[] = [
                'post_id'     => $id,
                'type'        => is_string($row['type'] ?? null) ? sanitize_key($row['type']) : 'upsell',
                'title'       => $post instanceof \WP_Post ? sanitize_text_field($post->post_title) : null,
                'status'      => self::status($post),
                'product_ids' => self::product_ids($products),
            ];
        }
        return $offers;
    }

    /**
     * Views and conversions per step id, as FunnelKit's funnel canvas counts
     * them. Only aggregates are selected.
     *
     * @param array<int,array{type:string,id:int}> $steps
     * @return array<int,array{views:?int,conversions:?int}>
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

        $views_table = self::table('wfco_report_views');
        $sessions    = [];
        if (Ops_Status_Packs::table_exists($views_table)) {
            $in = implode(',', array_map('intval', $all));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- FunnelKit's own table; $in is a list of integers.
            $rows = $wpdb->get_results($wpdb->prepare("SELECT object_id, type, SUM(no_of_sessions) AS n FROM %i WHERE object_id IN ({$in}) GROUP BY object_id, type", $views_table), ARRAY_A);
            foreach ((array) $rows as $row) {
                $sessions[ (int) $row['object_id'] ][ (int) $row['type'] ] = (int) $row['n'];
            }
        } else {
            $views_table = null;
        }

        $orders  = self::count_by(self::table('wfacp_stats'), 'wfacp_id', $by_type['wc_checkout'] ?? []);
        $entries = self::count_by(self::table('bwf_optin_entries'), 'step_id', $by_type['optin'] ?? []);

        $out = [];
        foreach ($steps as $step) {
            $id = $step['id'];
            if ($id < 1) {
                continue;
            }
            [ $view_type, $convert_type ] = self::VIEW_TYPES[ $step['type'] ] ?? [ null, null ];

            $views = null;
            if (null !== $views_table && null !== $view_type) {
                $views = $sessions[ $id ][ $view_type ] ?? 0;
            }

            $conversions = null;
            if ('wc_checkout' === $step['type']) {
                $conversions = null === $orders ? null : ($orders[ $id ] ?? 0);
            } elseif ('optin' === $step['type']) {
                $conversions = null === $entries ? null : ($entries[ $id ] ?? 0);
            } elseif (null !== $views_table && null !== $convert_type) {
                $conversions = $sessions[ $id ][ $convert_type ] ?? 0;
            }

            $out[ $id ] = [ 'views' => $views, 'conversions' => $conversions ];
        }
        return $out;
    }

    /**
     * Row counts per id in one of FunnelKit's stats tables, or null when the
     * table is absent.
     *
     * @param int[] $ids
     * @return array<int,int>|null
     */
    private static function count_by(string $table, string $column, array $ids): ?array
    {
        global $wpdb;

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
