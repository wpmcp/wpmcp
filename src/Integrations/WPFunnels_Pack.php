<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Post_Creation_Snapshot;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Save_Filters;
use WPMCP\Tools\Content\Readable_Posts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * WPFunnels funnels, steps and checkout products (issue #834): paid-tier ops
 * on the theme dispatcher pair beside FunnelKit, so they add no top-level
 * tools. Funnels and steps answer in the shape the FunnelKit reads use (a
 * funnel's id, title, step_count and step_types; a step's position, type,
 * post_id, post_type, title, status and product_ids), so a client sees one
 * funnel model for both plugins.
 *
 * Storage, as WPFunnels 2.8.4 keeps it: a funnel is a `wpfunnels` post whose
 * _steps_order meta is the ordered step list ([{id, step_type, name}, ...])
 * the canvas save works out from its node graph (_steps is the same list
 * unordered, read as a fallback); each step is a `wpfunnel_steps` post with
 * _funnel_id and _step_type (landing, checkout, upsell, downsell, thankyou,
 * custom); a checkout step's products are its _wpfnl_checkout_products list
 * ([{id, quantity, ...}, ...]). _wpfnl_funnel_type says whether the funnel
 * sells (wc), teaches (lms) or collects leads (lead).
 *
 * Every op runs at manage_options, the capability WPFunnels' own REST and
 * admin screens require (Wpfnl_functions::wpfnl_rest_check_manager_permissions).
 *
 * Writes are off until the site opts in with wpmcp_enable_wpfunnels_write:
 *  - create-wpfunnels-funnel inserts an empty funnel the way
 *    Wpfnl_Funnel_Store_Data::create() does, recorded for rollback
 *    (Post_Creation_Snapshot: the undo moves it to the trash);
 *  - update-wpfunnels-funnel renames a funnel and, with status, publishes
 *    or drafts it together with every step, as WPFunnels' own status switch
 *    does (Wpfnl_Funnel::update_funnel_status), under one post snapshot per
 *    post changed, so rollback-session puts every one back;
 *  - set-wpfunnels-checkout-products sets the products of a checkout step
 *    with their quantities, under a post snapshot of the step. A product
 *    already on the checkout keeps the other settings stored with it.
 * Adding and reordering steps are left out: WPFunnels works out the step
 * order from its canvas node graph (funnel_data) each time the canvas is
 * saved, so a step order written outside it would not hold.
 *
 * Funnels carry no customer data, so nothing needs masking. Presence is
 * filterable (wpmcp_wpfunnels_active, default: WPFNL_VERSION is defined).
 */
final class WPFunnels_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    public const FUNNEL = 'wpfunnels';

    public const STEP = 'wpfunnel_steps';

    public const PRODUCTS = '_wpfnl_checkout_products';

    public const OPT_IN_FILTER = 'wpmcp_enable_wpfunnels_write';

    public const PLUGIN_FILE = 'wpfunnels/wpfnl.php';

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 50;

    /** Most products one set-wpfunnels-checkout-products call may name. */
    private const MAX_PRODUCTS = 50;

    /** The funnel statuses WPFunnels' own status switch sets. */
    private const STATUSES = [ 'publish', 'draft' ];

    /** The post types a checkout product may be (WPFunnels looks them up with wc_get_product()). */
    private const PRODUCT_TYPES = [ 'product', 'product_variation' ];

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_wpfunnels_active', defined('WPFNL_VERSION'));
    }

    /** @return true|array{code: string, message: string, data: array} */
    public static function presence()
    {
        if (self::active()) {
            return true;
        }
        $installed = defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/' . self::PLUGIN_FILE);
        return Plugin_Refusal::make($installed ? 'plugin_inactive' : 'plugin_missing', 'WPFunnels', self::PLUGIN_FILE);
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $id     = [ 'type' => 'integer', 'minimum' => 1 ];
        $base   = [
            'tier'       => 'pro',
            'capability' => 'manage_options',
            'requires'   => static fn () => self::presence(),
        ];
        $write  = $base + [
            'mode'               => 'write',
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_wpfunnels_write', false),
            'opt_in_filter'      => self::OPT_IN_FILTER,
        ];
        $funnel = static fn (string $access): array => [ 'type' => 'post', 'own_type' => true, 'access' => $access ];

        return [
            'list-wpfunnels-funnels'          => $base + [
                'mode'         => 'read',
                'description'  => 'List WPFunnels funnels newest first: id, title, status, funnel_type, created, step count and step types. search matches the title, status filters; page and per_page (max 50)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'search'   => [ 'type' => 'string', 'maxLength' => 200 ],
                        'status'   => [ 'type' => 'string', 'enum' => self::STATUSES ],
                        'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
                        'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE ],
                    ],
                ],
                'handler'      => static fn (array $args): array => self::list_funnels($args),
            ],
            'get-wpfunnels-funnel'            => $base + [
                'mode'         => 'read',
                'objects'      => [ 'id' => $funnel('read') ],
                'description'  => 'One WPFunnels funnel with its steps in order: type, post id, title, status and, for a checkout, its product ids',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => $id ], 'required' => [ 'id' ] ],
                'validate'     => static fn (array $args): ?array => self::funnel_refusal((int) $args['id']),
                'handler'      => static fn (array $args): array => self::view_funnel(get_post((int) $args['id']), true),
            ],
            'get-wpfunnels-step'              => $base + [
                'mode'         => 'read',
                'objects'      => [ 'id' => $funnel('read') ],
                'description'  => 'One WPFunnels step: type, funnel id, position in the funnel, title, status, url and, for a checkout, its product ids',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => $id ], 'required' => [ 'id' ] ],
                'validate'     => static fn (array $args): ?array => self::step_refusal((int) $args['id']),
                'handler'      => static fn (array $args): array => self::view_step_full((int) $args['id']),
            ],
            'get-wpfunnels-checkout-products' => $base + [
                'mode'         => 'read',
                'objects'      => [ 'step_id' => $funnel('read') ],
                'description'  => 'A WPFunnels checkout step\'s products: product id, title, quantity and price',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'step_id' => $id ], 'required' => [ 'step_id' ] ],
                'validate'     => static fn (array $args): ?array => self::checkout_refusal((int) $args['step_id']),
                'handler'      => static fn (array $args): array => self::checkout_view((int) $args['step_id']),
            ],
            'create-wpfunnels-funnel'         => $write + [
                'self_snapshotting' => true,
                'description'       => 'Create an empty WPFunnels funnel (add its steps on the WPFunnels canvas). Undo: rollback-session trashes it',
                'input_schema'      => [
                    'type'                 => 'object',
                    'properties'           => [ 'title' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ] ],
                    'required'             => [ 'title' ],
                    'additionalProperties' => false,
                ],
                'validate'          => static fn (): ?array => post_type_exists(self::FUNNEL) ? null : self::refusal('post_type_missing', 'WPFunnels has not registered its funnel post type.'),
                'handler'           => static fn (array $args, array $context): array => self::create($args, $context),
            ],
            'update-wpfunnels-funnel'         => $write + [
                'self_snapshotting' => true,
                'objects'           => [ 'id' => $funnel('write') ],
                'description'       => 'Rename a WPFunnels funnel and/or set its status (publish or draft, applied to every step too, as WPFunnels does). Undo: rollback-session',
                'input_schema'      => [
                    'type'                 => 'object',
                    'properties'           => [
                        'id'     => $id,
                        'title'  => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ],
                        'status' => [ 'type' => 'string', 'enum' => self::STATUSES ],
                    ],
                    'required'             => [ 'id' ],
                    'minProperties'        => 2,
                    'additionalProperties' => false,
                ],
                'validate'          => static fn (array $args): ?array => self::funnel_refusal((int) $args['id']),
                'handler'           => static fn (array $args, array $context): array => self::update($args, $context),
            ],
            'set-wpfunnels-checkout-products' => $write + [
                'objects'      => [
                    'step_id'               => $funnel('write'),
                    'products.*.product_id' => [ 'type' => 'post', 'access' => 'read' ],
                ],
                'description'  => 'Set the products of a WPFunnels checkout step, in order, with quantities; replaces the list. A product already there keeps its other settings. Undo: rollback-operation',
                'input_schema' => [
                    'type'                 => 'object',
                    'properties'           => [
                        'step_id'  => $id,
                        'products' => [
                            'type'     => 'array',
                            'minItems' => 1,
                            'maxItems' => self::MAX_PRODUCTS,
                            'items'    => [
                                'type'                 => 'object',
                                'properties'           => [
                                    'product_id' => $id,
                                    'quantity'   => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 1000 ],
                                ],
                                'required'             => [ 'product_id' ],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                    'required'             => [ 'step_id', 'products' ],
                    'additionalProperties' => false,
                ],
                'validate'     => static fn (array $args): ?array => self::checkout_refusal((int) $args['step_id']) ?? self::products_refusal((array) $args['products']),
                'snapshot'     => static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => (int) $args['step_id'] ],
                'handler'      => static fn (array $args): array => self::set_products((int) $args['step_id'], (array) $args['products']),
            ],
        ];
    }

    // ------------------------------------------------------------ refusals

    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    private static function is_a(int $id, string $type): bool
    {
        $post = $id > 0 ? get_post($id) : null;
        return $post instanceof \WP_Post && $type === $post->post_type && 'trash' !== $post->post_status;
    }

    private static function funnel_refusal(int $id): ?array
    {
        return self::is_a($id, self::FUNNEL) ? null : self::refusal('funnel_not_found', sprintf('%d is not a WPFunnels funnel.', $id), [ 'id' => $id ]);
    }

    private static function step_refusal(int $id): ?array
    {
        return self::is_a($id, self::STEP) ? null : self::refusal('step_not_found', sprintf('%d is not a WPFunnels step.', $id), [ 'id' => $id ]);
    }

    private static function checkout_refusal(int $id): ?array
    {
        $refused = self::step_refusal($id);
        if (null !== $refused) {
            return $refused;
        }
        $type = (string) get_post_meta($id, '_step_type', true);
        return 'checkout' === $type ? null : self::refusal('not_a_checkout_step', sprintf('Step %d has type "%s"; only a checkout step has products.', $id, $type), [ 'id' => $id, 'type' => $type ]);
    }

    private static function products_refusal(array $products): ?array
    {
        $seen = [];
        foreach ($products as $entry) {
            $pid  = (int) ($entry['product_id'] ?? 0);
            $post = get_post($pid);
            if (! $post instanceof \WP_Post || ! in_array($post->post_type, self::PRODUCT_TYPES, true) || 'trash' === $post->post_status) {
                return self::refusal('product_not_found', sprintf('%d is not a WooCommerce product.', $pid), [ 'product_id' => $pid ]);
            }
            if (isset($seen[ $pid ])) {
                return self::refusal('duplicate_product', sprintf('Product %d is listed twice.', $pid), [ 'product_id' => $pid ]);
            }
            $seen[ $pid ] = true;
        }
        return null;
    }

    // ------------------------------------------------------------ reads

    /**
     * The funnel's ordered step list: _steps_order as the canvas save left
     * it, else _steps, keeping only entries whose step post still exists
     * (as Wpfnl_Funnel_Store_Data::set_steps_order() does).
     *
     * @return array<int, array{id: int, type: string}>
     */
    private static function steps(int $funnel_id): array
    {
        $list = get_post_meta($funnel_id, '_steps_order', true);
        if (! is_array($list) || [] === $list) {
            $list = get_post_meta($funnel_id, '_steps', true);
        }
        $steps = [];
        foreach (is_array($list) ? $list : [] as $entry) {
            $sid = is_array($entry) ? (int) ($entry['id'] ?? 0) : 0;
            if (! self::is_a($sid, self::STEP)) {
                continue;
            }
            $steps[] = [ 'id' => $sid, 'type' => sanitize_key((string) ($entry['step_type'] ?? get_post_meta($sid, '_step_type', true))) ];
        }
        return $steps;
    }

    /** @return array<string, mixed> */
    private static function view_funnel(\WP_Post $post, bool $full): array
    {
        $steps = self::steps((int) $post->ID);
        $out   = [
            'id'          => (int) $post->ID,
            'title'       => sanitize_text_field((string) $post->post_title),
            'status'      => (string) $post->post_status,
            'funnel_type' => sanitize_key((string) get_post_meta($post->ID, '_wpfnl_funnel_type', true)),
            'created'     => Ops_Status_Packs::iso_datetime((string) $post->post_date_gmt),
            'step_count'  => count($steps),
            'step_types'  => array_column($steps, 'type'),
        ];
        if ($full) {
            $out['steps'] = [];
            foreach ($steps as $index => $step) {
                $out['steps'][] = self::view_step($step['id'], $index + 1, $step['type']);
            }
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private static function view_step(int $id, ?int $position, string $type = ''): array
    {
        $post = get_post($id);
        $type = '' !== $type ? $type : sanitize_key((string) get_post_meta($id, '_step_type', true));
        return [
            'position'    => $position,
            'type'        => $type,
            'post_id'     => $id,
            'post_type'   => self::STEP,
            'title'       => sanitize_text_field((string) $post->post_title),
            'status'      => (string) $post->post_status,
            'product_ids' => 'checkout' === $type ? array_column(self::stored_products($id), 'id') : [],
        ];
    }

    /** @return array<string, mixed> */
    private static function view_step_full(int $id): array
    {
        $funnel   = (int) get_post_meta($id, '_funnel_id', true);
        $position = null;
        if (self::is_a($funnel, self::FUNNEL)) {
            foreach (self::steps($funnel) as $index => $step) {
                if ($step['id'] === $id) {
                    $position = $index + 1;
                }
            }
        }
        $link = get_permalink($id);
        return self::view_step($id, $position) + [
            'funnel_id' => self::is_a($funnel, self::FUNNEL) ? $funnel : null,
            'url'       => is_string($link) ? $link : '',
            'modified'  => Ops_Status_Packs::iso_datetime((string) get_post($id)->post_modified_gmt),
        ];
    }

    /**
     * The step's stored products as [id, quantity, the rest], skipping
     * entries with no product id.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function stored_products(int $step_id): array
    {
        $stored = get_post_meta($step_id, self::PRODUCTS, true);
        $out    = [];
        foreach (is_array($stored) ? $stored : [] as $entry) {
            if (is_array($entry) && (int) ($entry['id'] ?? 0) > 0) {
                $out[] = [ 'id' => (int) $entry['id'] ] + $entry;
            }
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private static function checkout_view(int $step_id): array
    {
        $products = [];
        foreach (self::stored_products($step_id) as $entry) {
            $pid     = (int) $entry['id'];
            $post    = get_post($pid);
            $product = function_exists('wc_get_product') ? wc_get_product($pid) : null;
            $row     = [
                'product_id' => $pid,
                'title'      => $product ? sanitize_text_field((string) $product->get_name()) : ($post instanceof \WP_Post ? sanitize_text_field((string) $post->post_title) : null),
                'quantity'   => max(1, (int) ($entry['quantity'] ?? 1)),
                'price'      => $product ? (string) $product->get_price() : null,
            ];
            if (null === $row['title']) {
                $row['missing'] = true;
            }
            $products[] = $row;
        }
        return [ 'step_id' => $step_id, 'products' => $products ];
    }

    /** @return array<string, mixed> */
    private static function list_funnels(array $args): array
    {
        $page     = max(1, (int) ($args['page'] ?? 1));
        $per_page = min(self::MAX_PER_PAGE, max(1, (int) ($args['per_page'] ?? self::DEFAULT_PER_PAGE)));
        $query    = [
            'post_type'      => self::FUNNEL,
            'post_status'    => isset($args['status']) ? [ (string) $args['status'] ] : self::STATUSES,
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'ID',
            'order'          => 'DESC',
        ];
        $search   = trim((string) ($args['search'] ?? ''));
        if ('' !== $search) {
            $query['s'] = $search;
        }
        $q = Readable_Posts::query($query);
        return [
            'funnels'  => array_map(static fn (\WP_Post $p): array => self::view_funnel($p, false), $q->posts),
            'total'    => (int) $q->found_posts,
            'page'     => $page,
            'per_page' => $per_page,
        ];
    }

    // ------------------------------------------------------------ writes

    /**
     * The funnel type Wpfnl_Funnel_Store_Data::create() stamps from the
     * general settings, or '' when they name none.
     */
    private static function new_funnel_type(): string
    {
        $settings = get_option('_wpfunnels_general_settings');
        $type     = is_array($settings) ? (string) ($settings['funnel_type'] ?? '') : '';
        if ('' === $type) {
            return '';
        }
        if (in_array($type, [ 'sales', 'woocommerce' ], true)) {
            return class_exists('WooCommerce') ? 'wc' : 'lms';
        }
        return 'lead';
    }

    /** @return array<string, mixed> */
    private static function create(array $args, array $context): array
    {
        $id = wp_insert_post([
            'post_type'   => self::FUNNEL,
            'post_status' => 'publish',
            'post_title'  => wp_slash(wp_strip_all_tags((string) $args['title'])),
            'post_author' => get_current_user_id(),
        ], true);
        if (is_wp_error($id) || 0 === (int) $id) {
            throw new Operation_Refused('insert_failed', is_wp_error($id) ? $id->get_error_message() : 'WPFunnels could not create the funnel.');
        }
        $id = (int) $id;
        $op = Post_Creation_Snapshot::record((string) $context['tool_name'], [ $id ], [ 'operation' => $context['operation'], 'args' => $args ], (string) $context['session_id']);
        $type = self::new_funnel_type();
        if ('' !== $type) {
            update_post_meta($id, '_wpfnl_funnel_type', $type);
        }
        clean_post_cache($id);

        return [ 'result' => [ 'funnel' => self::view_funnel(get_post($id), true) ], 'operation_ids' => [ $op ] ];
    }

    /**
     * One post change under its own snapshot, in the call's session.
     *
     * @param array<string, mixed> $fields
     */
    private static function change_post(int $id, array $fields, array $args, array $context): string
    {
        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $id,
                'session_id'  => (string) $context['session_id'],
                'tool_name'   => (string) $context['tool_name'],
                'args'        => [ 'operation' => (string) $context['operation'], 'args' => $args + [ 'post_id' => $id ] ],
            ],
            static function () use ($id, $fields): bool {
                $done = Save_Filters::update_post([ 'ID' => $id ] + $fields, true);
                if (is_wp_error($done)) {
                    throw new Operation_Refused('update_failed', $done->get_error_message());
                }
                clean_post_cache($id);
                return true;
            }
        );
        return (string) $out['operation_id'];
    }

    /** @return array<string, mixed> */
    private static function update(array $args, array $context): array
    {
        $id     = (int) $args['id'];
        $fields = [];
        if (array_key_exists('title', $args)) {
            $fields['post_title'] = wp_slash(wp_strip_all_tags((string) $args['title']));
        }
        if (array_key_exists('status', $args)) {
            $fields['post_status'] = (string) $args['status'];
        }
        $ops = [ self::change_post($id, $fields, $args, $context) ];
        if (isset($fields['post_status'])) {
            foreach (self::steps($id) as $step) {
                if (get_post_status($step['id']) !== $fields['post_status']) {
                    $ops[] = self::change_post($step['id'], [ 'post_status' => $fields['post_status'] ], $args, $context);
                }
            }
        }

        return [ 'result' => [ 'funnel' => self::view_funnel(get_post($id), true) ], 'operation_ids' => $ops ];
    }

    /** @return array<string, mixed> */
    private static function set_products(int $step_id, array $products): array
    {
        $existing = [];
        foreach (self::stored_products($step_id) as $entry) {
            $existing[ (int) $entry['id'] ] = $entry;
        }
        $list = [];
        foreach ($products as $entry) {
            $pid    = (int) $entry['product_id'];
            $row    = $existing[ $pid ] ?? [];
            $row    = [ 'id' => $pid, 'quantity' => (string) max(1, (int) ($entry['quantity'] ?? ($row['quantity'] ?? 1))) ] + $row;
            $list[] = $row;
        }
        update_post_meta($step_id, self::PRODUCTS, $list);
        clean_post_cache($step_id);

        return self::checkout_view($step_id);
    }
}
