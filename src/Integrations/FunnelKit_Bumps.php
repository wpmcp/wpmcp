<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\FunnelKit_Funnel_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * FunnelKit order bumps (issue #663, the rest of #500), as ops on the theme
 * dispatcher pair beside the funnel ops, so they add no top-level tools.
 *
 *  - list-funnelkit-order-bumps: a checkout step's bumps in order;
 *  - get-funnelkit-order-bump: one bump;
 *  - create-funnelkit-order-bump, update-funnelkit-order-bump (title and
 *    on or off), set- and remove-funnelkit-order-bump-products, and
 *    delete-funnelkit-order-bump.
 *
 * Order bumps come with Funnel Builder Pro (its order bump module, or the
 * older stand-alone order bump add-on); both load WFOB_Common, so the ops
 * need FunnelKit and that class (filterable through
 * wpmcp_funnelkit_bumps_active) and answer funnelkit_pro_inactive without it.
 *
 * Storage, as Funnel Builder Pro keeps it: a bump is a wfob_bump post with
 * _bwf_in_funnel naming its funnel, listed in the funnel's steps JSON under
 * its checkout step's substeps.wc_order_bump, in display order. Its products
 * are entries of _wfob_selected_products keyed by a wfob_ key, each with a
 * product id, quantity, discount_type and discount_amount (the checkout's
 * discount types and formula); its behaviour (add to the cart, or replace a
 * cart item) is in _wfob_product_settings and its position on the checkout
 * in _wfob_settings.
 *
 * Every write goes through FunnelKit's own REST routes in-process, as the
 * funnel writes do (FunnelKit_Writes::rest()), so FunnelKit's own access
 * check runs and its own save logic writes. Each is snapshotted first as
 * the whole funnel (FunnelKit_Funnel_Snapshot, whose step list covers
 * substeps, so a bump post and its meta come back at their own id), and a
 * bump the write created is deleted again on undo. Creates and updates take
 * dry_run; deletes are destructive and need confirm:true.
 *
 * Bumps hold no customer data. Reads run at manage_woocommerce and list
 * bumps in every status, as FunnelKit's own checkout screen does.
 */
final class FunnelKit_Bumps
{
    public const POST_TYPE = 'wfob_bump';

    /** The substep group FunnelKit lists a checkout's bumps under. */
    private const GROUP = 'wc_order_bump';

    /** Most products one set-funnelkit-order-bump-products call may name. */
    private const MAX_PRODUCTS = 20;

    /** Whether the order bump module is loaded, filterable through wpmcp_funnelkit_bumps_active. */
    public static function is_active(): bool
    {
        return (bool) apply_filters('wpmcp_funnelkit_bumps_active', class_exists('WFOB_Common'));
    }

    /**
     * @param callable $requires FunnelKit's own presence check.
     * @return array<string,array<string,mixed>>
     */
    public static function operations(callable $requires): array
    {
        $gate = static function () use ($requires) {
            $out = $requires();
            return true === $out ? Ops_Status_Packs::presence(self::is_active(), 'funnelkit_pro_inactive', 'FunnelKit Funnel Builder Pro') : $out;
        };
        $id       = [ 'type' => 'integer', 'minimum' => 1 ];
        $dry_run  = [ 'type' => 'boolean' ];
        $base     = [ 'tier' => 'pro', 'capability' => 'manage_woocommerce', 'requires' => $gate ];
        $by_bump  = static fn (array $args): ?array => ! empty($args['dry_run']) ? null : [ 'object_type' => FunnelKit_Funnel_Snapshot::TYPE, 'object_id' => self::location((int) $args['bump_id'])[0] ];
        $by_step  = static fn (array $args): ?array => ! empty($args['dry_run']) ? null : [ 'object_type' => FunnelKit_Funnel_Snapshot::TYPE, 'object_id' => FunnelKit_Writes::checkout_funnel((int) $args['step_id']) ];
        $bump_arg = [ 'type' => 'object', 'properties' => [ 'bump_id' => $id ], 'required' => [ 'bump_id' ] ];

        return [
            'list-funnelkit-order-bumps'           => $base + [
                'mode'         => 'read',
                'description'  => 'A FunnelKit checkout step\'s order bumps in display order: title, status, position, behaviour and products with the price a buyer pays',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'step_id' => $id ], 'required' => [ 'step_id' ] ],
                'validate'     => static fn (array $args): ?array => FunnelKit_Writes::checkout_refusal((int) $args['step_id'], false),
                'handler'      => static fn (array $args): array => self::list_bumps((int) $args['step_id']),
            ],
            'get-funnelkit-order-bump'             => $base + [
                'mode'         => 'read',
                'description'  => 'One FunnelKit order bump: its checkout step, title, status, position, behaviour and products with the price a buyer pays',
                'input_schema' => $bump_arg,
                'validate'     => static fn (array $args): ?array => self::bump_refusal((int) $args['bump_id'], false),
                'handler'      => static fn (array $args): array => self::view((int) $args['bump_id']),
            ],
            'create-funnelkit-order-bump'          => $base + [
                'mode'         => 'write',
                'description'  => 'Add an order bump, with no products yet, to the end of a FunnelKit checkout step\'s bumps. dry_run previews. Snapshotted',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'step_id' => $id, 'title' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ], 'dry_run' => $dry_run ],
                    'required'   => [ 'step_id', 'title' ],
                ],
                'validate'     => static fn (array $args): ?array => FunnelKit_Writes::checkout_refusal((int) $args['step_id'], true),
                'snapshot'     => $by_step,
                'handler'      => static fn (array $args, array $context): array => FunnelKit_Writes::voiding($context, static fn (): array => self::create($args)),
            ],
            'update-funnelkit-order-bump'          => $base + [
                'mode'         => 'write',
                'description'  => 'Rename a FunnelKit order bump or switch it on (enabled true) or off; fields not passed are kept. dry_run previews. Snapshotted',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'bump_id' => $id, 'title' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ], 'enabled' => [ 'type' => 'boolean' ], 'dry_run' => $dry_run ],
                    'required'   => [ 'bump_id' ],
                ],
                'validate'     => static fn (array $args): ?array => self::bump_refusal((int) $args['bump_id'], true) ?? (isset($args['title']) || isset($args['enabled']) ? null : [
                    'code'    => 'nothing_to_update',
                    'message' => 'Pass title, enabled or both.',
                    'data'    => [ 'bump_id' => (int) $args['bump_id'] ],
                ]),
                'snapshot'     => $by_bump,
                'handler'      => static fn (array $args, array $context): array => FunnelKit_Writes::voiding($context, static fn (): array => self::update($args)),
            ],
            'set-funnelkit-order-bump-products'    => $base + [
                'mode'         => 'write',
                'description'  => 'Add products to a FunnelKit order bump, or change the quantity and discount of ones already on it (the checkout\'s discount types). Answers each line\'s effective price; dry_run previews. Snapshotted',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'bump_id'  => $id,
                        'products' => [
                            'type'     => 'array',
                            'minItems' => 1,
                            'maxItems' => self::MAX_PRODUCTS,
                            'items'    => [
                                'type'       => 'object',
                                'properties' => [
                                    'product_id'      => $id,
                                    'quantity'        => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 10000 ],
                                    'discount_type'   => [ 'type' => 'string', 'enum' => FunnelKit_Writes::DISCOUNT_TYPES ],
                                    'discount_amount' => [ 'type' => 'number', 'minimum' => 0 ],
                                ],
                                'required'   => [ 'product_id' ],
                            ],
                        ],
                        'dry_run'  => $dry_run,
                    ],
                    'required'   => [ 'bump_id', 'products' ],
                ],
                'validate'     => static fn (array $args): ?array => self::bump_refusal((int) $args['bump_id'], true) ?? FunnelKit_Writes::products_refusal((array) $args['products']),
                'snapshot'     => $by_bump,
                'handler'      => static fn (array $args, array $context): array => FunnelKit_Writes::voiding($context, static fn (): array => self::set_products($args)),
            ],
            'remove-funnelkit-order-bump-products' => $base + [
                'mode'         => 'destructive',
                'description'  => 'Remove products from a FunnelKit order bump by product id. Snapshotted and reversible',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'bump_id'     => $id,
                        'product_ids' => [ 'type' => 'array', 'items' => $id, 'minItems' => 1, 'maxItems' => self::MAX_PRODUCTS, 'uniqueItems' => true ],
                    ],
                    'required'   => [ 'bump_id', 'product_ids' ],
                ],
                'validate'     => static fn (array $args): ?array => self::bump_refusal((int) $args['bump_id'], true) ?? self::removal_refusal((int) $args['bump_id'], (array) $args['product_ids']),
                'snapshot'     => $by_bump,
                'handler'      => static fn (array $args, array $context): array => FunnelKit_Writes::voiding($context, static fn (): array => self::remove_products((int) $args['bump_id'], (array) $args['product_ids'])),
            ],
            'delete-funnelkit-order-bump'          => $base + [
                'mode'         => 'destructive',
                'description'  => 'Delete a FunnelKit order bump from its checkout. Snapshotted; rollback brings it back at its own id and place',
                'input_schema' => $bump_arg,
                'validate'     => static fn (array $args): ?array => self::bump_refusal((int) $args['bump_id'], true),
                'snapshot'     => $by_bump,
                'handler'      => static fn (array $args, array $context): array => FunnelKit_Writes::voiding($context, static fn (): array => self::delete((int) $args['bump_id'])),
            ],
        ];
    }

    // ---------------------------------------------------------------- refusals

    /** @return array{code:string,message:string,data:array<string,mixed>}|null */
    private static function bump_refusal(int $bump_id, bool $write): ?array
    {
        $post = get_post($bump_id);
        if (! $post instanceof \WP_Post || self::POST_TYPE !== $post->post_type) {
            return [ 'code' => 'bump_not_found', 'message' => 'No FunnelKit order bump has that id.', 'data' => [ 'bump_id' => $bump_id ] ];
        }
        if ($write && 0 === self::location($bump_id)[0]) {
            return [ 'code' => 'bump_not_in_funnel', 'message' => 'This order bump is not on a checkout step of a FunnelKit funnel, so it cannot be snapshotted with one.', 'data' => [ 'bump_id' => $bump_id ] ];
        }
        return null;
    }

    /**
     * @param int[] $product_ids
     * @return array{code:string,message:string,data:array<string,mixed>}|null
     */
    private static function removal_refusal(int $bump_id, array $product_ids): ?array
    {
        $on      = array_map('intval', array_column(self::stored($bump_id), 'id'));
        $missing = array_values(array_diff(array_map('intval', $product_ids), $on));
        return [] === $missing ? null : [
            'code'    => 'product_not_on_bump',
            'message' => 'Some of these products are not on the order bump.',
            'data'    => [ 'product_ids' => $missing ],
        ];
    }

    // ---------------------------------------------------------------- reads

    /** @return array<string,mixed> */
    private static function list_bumps(int $step_id): array
    {
        $funnel_id = FunnelKit_Writes::checkout_funnel($step_id);
        return [
            'step_id'   => $step_id,
            'funnel_id' => $funnel_id ?: null,
            'currency'  => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : null,
            'bumps'     => array_map([ self::class, 'view' ], self::bump_ids($funnel_id, $step_id)),
        ];
    }

    /** @return array<string,mixed> */
    private static function view(int $bump_id): array
    {
        $post               = get_post($bump_id);
        [ $funnel, $step ]  = self::location($bump_id);
        $settings           = get_post_meta($bump_id, '_wfob_settings', true);
        $settings           = is_array($settings) ? $settings : [];
        $product_settings   = get_post_meta($bump_id, '_wfob_product_settings', true);
        $action             = is_array($product_settings) ? (string) ($product_settings['bump_action_type'] ?? '1') : '1';
        $position           = $settings['order_bump_position_hooks'] ?? null;

        return [
            'bump_id'   => $bump_id,
            'step_id'   => $step ?: null,
            'funnel_id' => $funnel ?: null,
            'title'     => $post instanceof \WP_Post ? sanitize_text_field($post->post_title) : null,
            'status'    => $post instanceof \WP_Post && 'publish' === $post->post_status ? 'active' : 'inactive',
            'position'  => is_string($position) && '' !== $position ? sanitize_key($position) : null,
            'priority'  => isset($settings['priority']) ? (int) $settings['priority'] : ($post instanceof \WP_Post ? (int) $post->menu_order : 0),
            'behaviour' => '2' === $action ? 'replace' : 'add',
            'products'  => FunnelKit_Writes::lines(self::stored($bump_id)),
        ];
    }

    // ---------------------------------------------------------------- writes

    /** @return array<string,mixed> */
    private static function create(array $args): array
    {
        $step_id   = (int) $args['step_id'];
        $funnel_id = FunnelKit_Writes::checkout_funnel($step_id);
        $title     = sanitize_text_field((string) $args['title']);
        $before    = self::bump_ids($funnel_id, $step_id);
        if (! empty($args['dry_run'])) {
            return [ 'dry_run' => true, 'would_create' => [ 'step_id' => $step_id, 'title' => $title, 'position' => count($before) + 1 ] ];
        }

        $data  = FunnelKit_Writes::rest('POST', sprintf('/funnelkit-app/funnels/%d/steps/%d/substeps', $funnel_id, $step_id), [ 'type' => self::GROUP, 'title' => $title ]);
        $added = array_values(array_diff(self::bump_ids($funnel_id, $step_id), $before));
        if ([] === $added) {
            FunnelKit_Writes::refuse('funnelkit_refused', FunnelKit_Writes::message($data, 'FunnelKit did not add the order bump.'));
        }
        return [ 'bump' => self::view($added[0]) ];
    }

    /** @return array<string,mixed> */
    private static function update(array $args): array
    {
        $bump_id = (int) $args['bump_id'];
        $current = self::view($bump_id);
        $body    = [];
        $changes = [];
        if (isset($args['title'])) {
            $body['title']    = sanitize_text_field((string) $args['title']);
            $changes['title'] = [ $current['title'], $body['title'] ];
        }
        if (isset($args['enabled'])) {
            $body['status']    = (bool) $args['enabled'];
            $changes['status'] = [ $current['status'], $body['status'] ? 'active' : 'inactive' ];
        }
        if (! empty($args['dry_run'])) {
            return [ 'dry_run' => true, 'bump_id' => $bump_id, 'changes' => $changes ];
        }
        FunnelKit_Writes::rest('POST', sprintf('/funnelkit-app/funnel-bump/save-state/%d', $bump_id), [], $body);
        clean_post_cache($bump_id);
        return [ 'bump' => self::view($bump_id) ];
    }

    /** @return array<string,mixed> */
    private static function set_products(array $args): array
    {
        $bump_id = (int) $args['bump_id'];
        $stored  = self::stored($bump_id);
        $on      = array_map('intval', array_column($stored, 'id'));
        $new     = [];
        foreach ((array) $args['products'] as $line) {
            if (! in_array((int) $line['product_id'], $on, true)) {
                $new[] = (int) $line['product_id'];
            }
        }

        if (! empty($args['dry_run'])) {
            $preview = $stored;
            foreach ($new as $product_id) {
                $preview[ 'new_' . $product_id ] = [ 'id' => $product_id, 'quantity' => 1, 'discount_type' => 'percent_discount_sale', 'discount_amount' => 0 ];
            }
            return [ 'dry_run' => true, 'added' => $new, 'products' => FunnelKit_Writes::lines(FunnelKit_Writes::apply_lines($preview, (array) $args['products'])) ];
        }

        if ([] !== $new) {
            FunnelKit_Writes::rest('POST', sprintf('/funnelkit-app/funnel-bump/%d/products', $bump_id), [], [ 'products' => $new ]);
            $stored  = self::stored($bump_id);
            $missing = array_values(array_diff($new, array_map('intval', array_column($stored, 'id'))));
            if ([] !== $missing) {
                FunnelKit_Writes::refuse('funnelkit_refused', 'FunnelKit did not add every product to the order bump.', [ 'product_ids' => $missing ]);
            }
        }

        $products = [];
        foreach (FunnelKit_Writes::apply_lines($stored, (array) $args['products']) as $key => $entry) {
            // As FunnelKit's bump product editor sends them: the entry's bump
            // key as id and its product id as key, swapped back to store.
            $products[] = [ 'id' => (string) $key, 'key' => (int) $entry['id'] ] + array_diff_key($entry, [ 'id' => true ]);
        }
        FunnelKit_Writes::rest('POST', sprintf('/funnelkit-app/funnel-bump/%d/products/save-layout', $bump_id), [], [ 'products' => $products, 'settings' => self::settings($bump_id) ]);

        return [ 'added' => $new, 'products' => FunnelKit_Writes::lines(self::stored($bump_id)) ];
    }

    /** @return array<string,mixed> */
    private static function remove_products(int $bump_id, array $product_ids): array
    {
        $ids  = array_map('intval', $product_ids);
        $keys = [];
        foreach (self::stored($bump_id) as $key => $entry) {
            if (in_array((int) $entry['id'], $ids, true)) {
                $keys[] = (string) $key;
            }
        }
        FunnelKit_Writes::rest('DELETE', sprintf('/funnelkit-app/funnel-bump/%d/products', $bump_id), [], [ 'product_key' => $keys ]);
        $left = self::stored($bump_id);
        if ([] !== array_intersect($keys, array_map('strval', array_keys($left)))) {
            FunnelKit_Writes::refuse('funnelkit_refused', 'FunnelKit did not remove every product from the order bump.');
        }
        return [ 'removed' => $ids, 'products' => FunnelKit_Writes::lines($left) ];
    }

    /** @return array<string,mixed> */
    private static function delete(int $bump_id): array
    {
        [ $funnel_id, $step_id ] = self::location($bump_id);
        $data = FunnelKit_Writes::rest('DELETE', sprintf('/funnelkit-app/funnels/%d/steps/%d/substeps/%d', $funnel_id, $step_id, $bump_id), [ 'type' => self::GROUP ]);
        $left = self::bump_ids($funnel_id, $step_id);
        if (in_array($bump_id, $left, true)) {
            FunnelKit_Writes::refuse('funnelkit_refused', FunnelKit_Writes::message($data, 'FunnelKit did not delete the order bump.'));
        }
        return [ 'deleted' => true, 'bump_id' => $bump_id, 'step_id' => $step_id, 'bump_ids' => $left ];
    }

    // ---------------------------------------------------------------- helpers

    /**
     * The bump's settings as its save route expects them back, so a product
     * save keeps its behaviour and position: _wfob_settings (or FunnelKit's
     * defaults) with the _wfob_product_settings fields beside them, each
     * replace product carrying the label the route reads.
     *
     * @return array<string,mixed>
     */
    private static function settings(int $bump_id): array
    {
        $settings = is_callable([ 'WFOB_Common', 'get_setting_data' ]) ? call_user_func([ 'WFOB_Common', 'get_setting_data' ], $bump_id) : get_post_meta($bump_id, '_wfob_settings', true);
        $settings = is_array($settings) ? $settings : [];
        $product  = is_callable([ 'WFOB_Common', 'get_product_settings' ]) ? call_user_func([ 'WFOB_Common', 'get_product_settings' ], $bump_id) : get_post_meta($bump_id, '_wfob_product_settings', true);
        $product  = (is_array($product) ? $product : []) + [ 'bump_action_type' => '1', 'bump_replace_type' => 'specific', 'selected_replace_product' => [] ];

        $replace = $product['selected_replace_product'];
        if (is_array($replace) && isset($replace['id'])) {
            $replace = [ $replace ];
        }
        if (is_array($replace)) {
            $replace = array_values(array_map(static fn ($item): array => (array) $item + [ 'label' => (string) (((array) $item)['product'] ?? '') ], array_filter($replace, static fn ($item): bool => is_array($item) || is_object($item))));
        }

        return array_merge($settings, [
            'bump_action_type'         => (string) $product['bump_action_type'],
            'bump_replace_type'        => (string) $product['bump_replace_type'],
            'selected_replace_product' => $replace,
        ]);
    }

    /**
     * The bump's product entries keyed by their bump key, each with its
     * product id under 'id', as FunnelKit stores them.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function stored(int $bump_id): array
    {
        $stored = get_post_meta($bump_id, '_wfob_selected_products', true);
        $out    = [];
        foreach (is_array($stored) ? $stored : [] as $key => $entry) {
            $entry = is_object($entry) ? get_object_vars($entry) : $entry;
            if (is_array($entry) && (int) ($entry['id'] ?? 0) > 0) {
                $out[ (string) $key ] = $entry;
            }
        }
        return $out;
    }

    /**
     * [funnel id, checkout step id] of the funnel step that lists a bump,
     * from its _bwf_in_funnel and that funnel's steps JSON; zeros when none
     * lists it.
     *
     * @return array{0:int,1:int}
     */
    private static function location(int $bump_id): array
    {
        $funnel_id = (int) get_post_meta($bump_id, '_bwf_in_funnel', true);
        foreach (self::raw_steps($funnel_id) as $step) {
            if (in_array($bump_id, self::group($step), true)) {
                return [ $funnel_id, (int) ($step['id'] ?? 0) ];
            }
        }
        return [ 0, 0 ];
    }

    /** @return int[] a checkout step's bump ids in order. */
    private static function bump_ids(int $funnel_id, int $step_id): array
    {
        foreach (self::raw_steps($funnel_id) as $step) {
            if ((int) ($step['id'] ?? 0) === $step_id) {
                return self::group($step);
            }
        }
        return [];
    }

    /** @return int[] */
    private static function group(array $step): array
    {
        $ids = is_array($step['substeps'] ?? null) ? (array) ($step['substeps'][ self::GROUP ] ?? []) : [];
        return array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
    }

    /** @return array<int,array<string,mixed>> */
    private static function raw_steps(int $funnel_id): array
    {
        $row   = $funnel_id > 0 ? FunnelKit_Funnel_Snapshot::row($funnel_id) : null;
        $steps = null === $row ? null : json_decode((string) $row['steps'], true);
        return is_array($steps) ? array_values(array_filter($steps, 'is_array')) : [];
    }
}
