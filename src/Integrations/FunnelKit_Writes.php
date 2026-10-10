<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\FunnelKit_Funnel_Snapshot;
use WPMCP\Safety\Snapshot_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * FunnelKit funnel, step and checkout product writes (issue #500), plus the
 * checkout product read, as ops on the theme dispatcher pair beside the
 * funnel reads in FunnelKit_Pack, so they add no top-level tools.
 *
 * Every write goes through FunnelKit's own REST controllers (the
 * funnelkit-app routes its admin screens call), dispatched in-process with
 * rest_do_request(), so FunnelKit's own permission check, argument handling
 * and save logic run, its hooks fire, and nothing is written to its storage
 * by hand. FunnelKit lets a user change funnels only when its own access
 * check passes (administrators, and roles with manage_options unless a
 * site's wffn_user_access_capabilities filter says otherwise); when it
 * refuses, the op answers funnelkit_access_denied. On top of that every op
 * runs at manage_woocommerce, like the reads.
 *
 * Covered here, all on the free Funnel Builder: create, rename and delete a
 * funnel; add, reorder, enable or disable and delete a step; publish all
 * steps; set and remove checkout products with quantity and discount.
 * Order bumps, upsell offers and A/B experiments live in Funnel Builder Pro
 * and its add-ons, which are not part of this slice.
 *
 * Each write is snapshotted first as a whole-funnel image
 * (FunnelKit_Funnel_Snapshot: the bwf_funnels row, its bwf_funnelmeta rows
 * and every step post with its meta), so rollback-operation puts back
 * exactly the funnel that was there. Creating a funnel records a creation
 * row after the funnel exists, and its rollback deletes the funnel again.
 *
 * Creates and updates accept dry_run:true, which validates, reports what
 * would change (a checkout's effective line prices included) and writes
 * nothing. Deletes are destructive ops and need confirm:true.
 *
 * Prices: FunnelKit stores a checkout product as an entry of
 * _wfacp_selected_products with a quantity, a discount_type
 * (percent_discount_sale, percent_discount_reg, fixed_discount_sale,
 * fixed_discount_reg) and a discount_amount, and at checkout discounts the
 * unit price with WFACP_Common::calculate_discount() from the product's
 * regular price and current price; a zero discount leaves the price alone.
 * The effective price reported here is worked out the same way (through
 * that same function when FunnelKit is loaded), unit price times quantity
 * for the line. A variable product has no single price until the buyer
 * picks a variation, so its prices are null with a reason.
 */
final class FunnelKit_Writes
{
    /** Step types the free Funnel Builder can add. */
    public const STEP_TYPES = [ 'landing', 'wc_checkout', 'wc_thankyou', 'optin', 'optin_ty' ];

    /** The discount types FunnelKit's checkout product editor offers. */
    public const DISCOUNT_TYPES = [ 'percent_discount_sale', 'percent_discount_reg', 'fixed_discount_sale', 'fixed_discount_reg' ];

    /** Most products one set-funnelkit-checkout-products call may name. */
    private const MAX_PRODUCTS = 50;

    private const TOOL = 'theme-write';

    /** @return array<string,array<string,mixed>> */
    public static function operations(callable $requires): array
    {
        $funnel_target = static fn (array $args): ?array => ! empty($args['dry_run']) ? null : [ 'object_type' => FunnelKit_Funnel_Snapshot::TYPE, 'object_id' => (int) ($args['funnel_id'] ?? $args['id']) ];
        $step_target   = static fn (array $args): ?array => ! empty($args['dry_run']) ? null : [ 'object_type' => FunnelKit_Funnel_Snapshot::TYPE, 'object_id' => self::checkout_funnel((int) $args['step_id']) ];
        $id            = [ 'type' => 'integer', 'minimum' => 1 ];
        $dry_run       = [ 'type' => 'boolean' ];
        $step_ref      = [
            'type'       => 'object',
            'properties' => [ 'funnel_id' => $id, 'step_id' => $id ],
            'required'   => [ 'funnel_id', 'step_id' ],
        ];
        $base          = [
            'tier'       => 'pro',
            'capability' => 'manage_woocommerce',
            'requires'   => $requires,
        ];

        return [
            'get-funnelkit-checkout-products'    => $base + [
                'mode'         => 'read',
                'description'  => 'A FunnelKit checkout step\'s products: quantity, discount, and the unit and line price a buyer pays after the discount',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'step_id' => $id ], 'required' => [ 'step_id' ] ],
                'validate'     => static fn (array $args): ?array => self::checkout_refusal((int) $args['step_id'], false),
                'handler'      => static fn (array $args): array => self::checkout_view((int) $args['step_id']),
            ],
            'create-funnelkit-funnel'            => $base + [
                'mode'              => 'write',
                'description'       => 'Create an empty FunnelKit funnel. dry_run previews. Rollback deletes it',
                'input_schema'      => [
                    'type'       => 'object',
                    'properties' => [ 'title' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ], 'dry_run' => $dry_run ],
                    'required'   => [ 'title' ],
                ],
                'self_snapshotting' => true,
                'handler'           => static fn (array $args, array $context): array => self::create_funnel($args, (string) $context['session_id']),
            ],
            'update-funnelkit-funnel'            => $base + [
                'mode'         => 'write',
                'description'  => 'Rename a FunnelKit funnel or change its description; fields not passed are kept. dry_run previews. Snapshotted',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'          => $id,
                        'title'       => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ],
                        'description' => [ 'type' => 'string', 'maxLength' => 2000 ],
                        'dry_run'     => $dry_run,
                    ],
                    'required'   => [ 'id' ],
                ],
                'validate'     => static fn (array $args): ?array => self::funnel_refusal((int) $args['id']),
                'snapshot'     => $funnel_target,
                'handler'      => static fn (array $args, array $context): array => self::voiding($context, static fn (): array => self::update_funnel($args)),
            ],
            'delete-funnelkit-funnel'            => $base + [
                'mode'         => 'destructive',
                'description'  => 'Delete a FunnelKit funnel and its step pages. Snapshotted; rollback brings them back at their own ids',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => $id ], 'required' => [ 'id' ] ],
                'validate'     => static fn (array $args): ?array => self::funnel_refusal((int) $args['id']),
                'snapshot'     => $funnel_target,
                'handler'      => static fn (array $args, array $context): array => self::voiding($context, static fn (): array => self::delete_funnel((int) $args['id'])),
            ],
            'add-funnelkit-step'                 => $base + [
                'mode'         => 'write',
                'description'  => 'Add a step (landing, wc_checkout, wc_thankyou, optin, optin_ty) at the end of a FunnelKit funnel. dry_run previews. Snapshotted',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'funnel_id' => $id,
                        'type'      => [ 'type' => 'string', 'enum' => self::STEP_TYPES ],
                        'title'     => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ],
                        'dry_run'   => $dry_run,
                    ],
                    'required'   => [ 'funnel_id', 'type', 'title' ],
                ],
                'validate'     => static fn (array $args): ?array => self::funnel_refusal((int) $args['funnel_id']),
                'snapshot'     => $funnel_target,
                'handler'      => static fn (array $args, array $context): array => self::voiding($context, static fn (): array => self::add_step($args)),
            ],
            'reorder-funnelkit-steps'            => $base + [
                'mode'         => 'write',
                'description'  => 'Reorder a FunnelKit funnel\'s steps: step_ids lists every step id once, in the new order. dry_run previews. Snapshotted',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'funnel_id' => $id,
                        'step_ids'  => [ 'type' => 'array', 'items' => $id, 'minItems' => 1, 'maxItems' => 100, 'uniqueItems' => true ],
                        'dry_run'   => $dry_run,
                    ],
                    'required'   => [ 'funnel_id', 'step_ids' ],
                ],
                'validate'     => static fn (array $args): ?array => self::funnel_refusal((int) $args['funnel_id']) ?? self::order_refusal((int) $args['funnel_id'], (array) $args['step_ids']),
                'snapshot'     => $funnel_target,
                'handler'      => static fn (array $args, array $context): array => self::voiding($context, static fn (): array => self::reorder_steps($args)),
            ],
            'set-funnelkit-step-status'          => $base + [
                'mode'         => 'write',
                'description'  => 'Enable (publish) or disable (draft) one FunnelKit funnel step. dry_run previews. Snapshotted',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => $step_ref['properties'] + [ 'enabled' => [ 'type' => 'boolean' ], 'dry_run' => $dry_run ],
                    'required'   => [ 'funnel_id', 'step_id', 'enabled' ],
                ],
                'validate'     => static fn (array $args): ?array => self::step_refusal((int) $args['funnel_id'], (int) $args['step_id']),
                'snapshot'     => $funnel_target,
                'handler'      => static fn (array $args, array $context): array => self::voiding($context, static fn (): array => self::set_step_status($args)),
            ],
            'delete-funnelkit-step'              => $base + [
                'mode'         => 'destructive',
                'description'  => 'Delete one FunnelKit funnel step and its page. Snapshotted; rollback brings it back at its own id and place',
                'input_schema' => $step_ref,
                'validate'     => static fn (array $args): ?array => self::step_refusal((int) $args['funnel_id'], (int) $args['step_id']),
                'snapshot'     => $funnel_target,
                'handler'      => static fn (array $args, array $context): array => self::voiding($context, static fn (): array => self::delete_step((int) $args['funnel_id'], (int) $args['step_id'])),
            ],
            'publish-funnelkit-steps'            => $base + [
                'mode'         => 'write',
                'description'  => 'Publish every step of a FunnelKit funnel. dry_run lists the drafts it would publish. Snapshotted',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'funnel_id' => $id, 'dry_run' => $dry_run ], 'required' => [ 'funnel_id' ] ],
                'validate'     => static fn (array $args): ?array => self::funnel_refusal((int) $args['funnel_id']),
                'snapshot'     => $funnel_target,
                'handler'      => static fn (array $args, array $context): array => self::voiding($context, static fn (): array => self::publish_steps($args)),
            ],
            'set-funnelkit-checkout-products'    => $base + [
                'mode'         => 'write',
                'description'  => 'Add products to a FunnelKit checkout step, or change the quantity and discount of ones already on it (discount_type percent_discount_sale, percent_discount_reg, fixed_discount_sale or fixed_discount_reg). Answers each line\'s effective price; dry_run previews them and writes nothing. Snapshotted',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'step_id'  => $id,
                        'products' => [
                            'type'     => 'array',
                            'minItems' => 1,
                            'maxItems' => self::MAX_PRODUCTS,
                            'items'    => [
                                'type'       => 'object',
                                'properties' => [
                                    'product_id'      => $id,
                                    'quantity'        => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 10000 ],
                                    'discount_type'   => [ 'type' => 'string', 'enum' => self::DISCOUNT_TYPES ],
                                    'discount_amount' => [ 'type' => 'number', 'minimum' => 0 ],
                                ],
                                'required'   => [ 'product_id' ],
                            ],
                        ],
                        'dry_run'  => $dry_run,
                    ],
                    'required'   => [ 'step_id', 'products' ],
                ],
                'validate'     => static fn (array $args): ?array => self::checkout_refusal((int) $args['step_id'], true) ?? self::products_refusal((array) $args['products']),
                'snapshot'     => $step_target,
                'handler'      => static fn (array $args, array $context): array => self::voiding($context, static fn (): array => self::set_checkout_products($args)),
            ],
            'remove-funnelkit-checkout-products' => $base + [
                'mode'         => 'destructive',
                'description'  => 'Remove products from a FunnelKit checkout step by product id. Snapshotted and reversible',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'step_id'     => $id,
                        'product_ids' => [ 'type' => 'array', 'items' => $id, 'minItems' => 1, 'maxItems' => self::MAX_PRODUCTS, 'uniqueItems' => true ],
                    ],
                    'required'   => [ 'step_id', 'product_ids' ],
                ],
                'validate'     => static fn (array $args): ?array => self::checkout_refusal((int) $args['step_id'], true) ?? self::removal_refusal((int) $args['step_id'], (array) $args['product_ids']),
                'snapshot'     => $step_target,
                'handler'      => static fn (array $args, array $context): array => self::voiding($context, static fn (): array => self::remove_checkout_products((int) $args['step_id'], (array) $args['product_ids'])),
            ],
        ];
    }

    // ---------------------------------------------------------------- refusals

    /** @return array{code:string,message:string,data:array<string,mixed>}|null */
    private static function funnel_refusal(int $funnel_id): ?array
    {
        return null === FunnelKit_Funnel_Snapshot::row($funnel_id)
            ? [ 'code' => 'funnel_not_found', 'message' => 'No FunnelKit funnel has that id.', 'data' => [ 'id' => $funnel_id ] ]
            : null;
    }

    /** @return array{code:string,message:string,data:array<string,mixed>}|null */
    private static function step_refusal(int $funnel_id, int $step_id): ?array
    {
        $refusal = self::funnel_refusal($funnel_id);
        if (null !== $refusal) {
            return $refusal;
        }
        return null === self::step_type($funnel_id, $step_id)
            ? [ 'code' => 'step_not_found', 'message' => 'That step is not in this FunnelKit funnel.', 'data' => [ 'funnel_id' => $funnel_id, 'step_id' => $step_id ] ]
            : null;
    }

    /**
     * @param int[] $step_ids
     * @return array{code:string,message:string,data:array<string,mixed>}|null
     */
    private static function order_refusal(int $funnel_id, array $step_ids): ?array
    {
        $current = array_column(self::steps($funnel_id), 'id');
        $given   = array_map('intval', $step_ids);
        $sorted  = $given;
        sort($sorted);
        $expected = $current;
        sort($expected);
        if ($sorted === $expected) {
            return null;
        }
        return [
            'code'    => 'step_ids_mismatch',
            'message' => 'step_ids must list every step of the funnel exactly once.',
            'data'    => [ 'step_ids' => $current ],
        ];
    }

    /**
     * A checkout step that is not there, or, for a write, is not in a funnel.
     *
     * @return array{code:string,message:string,data:array<string,mixed>}|null
     */
    private static function checkout_refusal(int $step_id, bool $write): ?array
    {
        $post = get_post($step_id);
        if (! $post instanceof \WP_Post || 'wfacp_checkout' !== $post->post_type) {
            return [ 'code' => 'checkout_not_found', 'message' => 'No FunnelKit checkout step has that id.', 'data' => [ 'step_id' => $step_id ] ];
        }
        if ($write && 0 === self::checkout_funnel($step_id)) {
            return [ 'code' => 'checkout_not_in_funnel', 'message' => 'This checkout step is not in a FunnelKit funnel, so it cannot be snapshotted with one.', 'data' => [ 'step_id' => $step_id ] ];
        }
        return null;
    }

    /**
     * @param array<int,array<string,mixed>> $products
     * @return array{code:string,message:string,data:array<string,mixed>}|null
     */
    private static function products_refusal(array $products): ?array
    {
        $seen = [];
        foreach ($products as $line) {
            $product_id = (int) $line['product_id'];
            if (isset($seen[ $product_id ])) {
                return [ 'code' => 'duplicate_product', 'message' => 'A product is named more than once.', 'data' => [ 'product_id' => $product_id ] ];
            }
            $seen[ $product_id ] = true;
            $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
            if (! $product instanceof \WC_Product || 'publish' !== get_post_status($product->is_type('variation') ? $product->get_parent_id() : $product_id)) {
                return [ 'code' => 'product_not_found', 'message' => 'No published WooCommerce product has that id.', 'data' => [ 'product_id' => $product_id ] ];
            }
            $type = (string) ($line['discount_type'] ?? '');
            if (str_starts_with($type, 'percent_') && (float) ($line['discount_amount'] ?? 0) > 100) {
                return [ 'code' => 'invalid_discount', 'message' => 'A percentage discount cannot exceed 100.', 'data' => [ 'product_id' => $product_id ] ];
            }
        }
        return null;
    }

    /**
     * @param int[] $product_ids
     * @return array{code:string,message:string,data:array<string,mixed>}|null
     */
    private static function removal_refusal(int $step_id, array $product_ids): ?array
    {
        $on = array_map('intval', array_column(self::stored_products($step_id), 'id'));
        $missing = array_values(array_diff(array_map('intval', $product_ids), $on));
        return [] === $missing ? null : [
            'code'    => 'product_not_on_checkout',
            'message' => 'Some of these products are not on the checkout step.',
            'data'    => [ 'product_ids' => $missing ],
        ];
    }

    // ---------------------------------------------------------------- funnels

    /** @return array<string,mixed> */
    private static function create_funnel(array $args, string $session_id): array
    {
        $title = sanitize_text_field((string) $args['title']);
        if (! empty($args['dry_run'])) {
            return [ 'result' => [ 'dry_run' => true, 'would_create' => [ 'title' => $title, 'steps' => [] ] ], 'operation_ids' => [] ];
        }

        $before = self::max_funnel_id();
        $data   = self::rest('POST', '/funnelkit-app/funnels', [ 'funnel_name' => $title ]);
        $id     = self::created_funnel_id($data, $before);
        if ($id < 1) {
            self::refuse('funnelkit_refused', self::message($data, 'FunnelKit did not create the funnel.'));
        }
        $operation_id = FunnelKit_Funnel_Snapshot::record_creation($id, self::TOOL, [ 'operation' => 'create-funnelkit-funnel', 'args' => $args ], $session_id);

        return [ 'result' => [ 'funnel' => self::funnel_view($id) ], 'operation_ids' => [ $operation_id ] ];
    }

    /** @return array<string,mixed> */
    private static function update_funnel(array $args): array
    {
        $id      = (int) $args['id'];
        $row     = (array) FunnelKit_Funnel_Snapshot::row($id);
        $title   = isset($args['title']) ? sanitize_text_field((string) $args['title']) : (string) $row['title'];
        $desc    = isset($args['description']) ? sanitize_textarea_field((string) $args['description']) : (string) $row['desc'];
        $changes = [ 'title' => [ (string) $row['title'], $title ], 'description' => [ (string) $row['desc'], $desc ] ];
        if (! empty($args['dry_run'])) {
            return [ 'dry_run' => true, 'changes' => $changes ];
        }
        // FunnelKit's update clears the description when none is sent, so the
        // current one is always sent along.
        self::rest('POST', sprintf('/funnelkit-app/funnels/%d', $id), [ 'title' => $title, 'description' => $desc ]);
        return [ 'funnel' => self::funnel_view($id) ];
    }

    /** @return array<string,mixed> */
    private static function delete_funnel(int $id): array
    {
        $steps = self::steps($id);
        $data  = self::rest('DELETE', '/funnelkit-app/funnels', [ 'id' => (string) $id ]);
        if (null !== FunnelKit_Funnel_Snapshot::row($id)) {
            self::refuse('funnelkit_refused', self::message($data, 'FunnelKit did not delete the funnel.'));
        }
        return [ 'deleted' => true, 'id' => $id, 'deleted_step_ids' => array_column($steps, 'id') ];
    }

    // ---------------------------------------------------------------- steps

    /** @return array<string,mixed> */
    private static function add_step(array $args): array
    {
        $funnel_id = (int) $args['funnel_id'];
        $title     = sanitize_text_field((string) $args['title']);
        if (! empty($args['dry_run'])) {
            return [ 'dry_run' => true, 'would_add' => [ 'type' => $args['type'], 'title' => $title, 'position' => count(self::steps($funnel_id)) + 1 ] ];
        }
        $before = array_column(self::steps($funnel_id), 'id');
        $data   = self::rest('POST', sprintf('/funnelkit-app/funnels/%d/steps', $funnel_id), [ 'type' => (string) $args['type'], 'title' => $title ]);
        $added  = array_values(array_diff(array_column(self::steps($funnel_id), 'id'), $before));
        if ([] === $added) {
            self::refuse('funnelkit_refused', self::message($data, 'FunnelKit did not add the step.'));
        }
        return [ 'step_id' => $added[0], 'funnel' => self::funnel_view($funnel_id) ];
    }

    /** @return array<string,mixed> */
    private static function reorder_steps(array $args): array
    {
        $funnel_id = (int) $args['funnel_id'];
        $types     = array_column(self::steps($funnel_id), 'type', 'id');
        $order     = array_map(static fn ($id): array => [ 'type' => $types[ (int) $id ], 'id' => (int) $id ], (array) $args['step_ids']);
        if (! empty($args['dry_run'])) {
            return [ 'dry_run' => true, 'order' => $order ];
        }
        $row = (array) FunnelKit_Funnel_Snapshot::row($funnel_id);
        self::rest('POST', sprintf('/funnelkit-app/funnels/%d', $funnel_id), [
            'title'       => (string) $row['title'],
            'description' => (string) $row['desc'],
            'steps'       => (string) wp_json_encode($order),
        ]);
        return [ 'funnel' => self::funnel_view($funnel_id) ];
    }

    /** @return array<string,mixed> */
    private static function set_step_status(array $args): array
    {
        $funnel_id = (int) $args['funnel_id'];
        $step_id   = (int) $args['step_id'];
        $enabled   = (bool) $args['enabled'];
        if (! empty($args['dry_run'])) {
            return [ 'dry_run' => true, 'step_id' => $step_id, 'status' => [ self::status($step_id), $enabled ? 'active' : 'inactive' ] ];
        }
        self::rest('POST', sprintf('/funnelkit-app/funnels/%d/steps/%d', $funnel_id, $step_id), [ 'type' => (string) self::step_type($funnel_id, $step_id), 'new_status' => $enabled ? 1 : 0 ]);
        clean_post_cache($step_id);
        return [ 'step_id' => $step_id, 'status' => self::status($step_id) ];
    }

    /** @return array<string,mixed> */
    private static function delete_step(int $funnel_id, int $step_id): array
    {
        $data = self::rest('DELETE', sprintf('/funnelkit-app/funnels/%d/steps/%d', $funnel_id, $step_id), [ 'type' => (string) self::step_type($funnel_id, $step_id) ]);
        if (null !== self::step_type($funnel_id, $step_id)) {
            self::refuse('funnelkit_refused', self::message($data, 'FunnelKit did not delete the step.'));
        }
        return [ 'deleted' => true, 'step_id' => $step_id, 'funnel' => self::funnel_view($funnel_id) ];
    }

    /** @return array<string,mixed> */
    private static function publish_steps(array $args): array
    {
        $funnel_id = (int) $args['funnel_id'];
        $steps     = self::steps($funnel_id);
        $drafts    = array_values(array_filter(array_column($steps, 'id'), static fn (int $id): bool => 'inactive' === self::status($id)));
        if (! empty($args['dry_run'])) {
            return [ 'dry_run' => true, 'would_publish' => $drafts ];
        }
        if ([] !== $steps) {
            self::rest('POST', sprintf('/funnelkit-app/funnels/%d/publish-all', $funnel_id), [ 'steps' => (string) wp_json_encode($steps) ]);
        }
        foreach ($drafts as $id) {
            clean_post_cache($id);
        }
        return [ 'published' => $drafts, 'funnel' => self::funnel_view($funnel_id) ];
    }

    // ---------------------------------------------------------------- checkout products

    /** @return array<string,mixed> */
    private static function set_checkout_products(array $args): array
    {
        $step_id = (int) $args['step_id'];
        $stored  = self::stored_products($step_id);
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
            return [ 'dry_run' => true, 'added' => $new, 'products' => self::lines(self::apply_lines($preview, (array) $args['products'])) ];
        }

        if ([] !== $new) {
            self::rest('POST', sprintf('/funnelkit-app/funnel-checkout/%d/products', $step_id), [], [ 'products' => $new ]);
            $stored = self::stored_products($step_id);
            $added  = array_map('intval', array_column($stored, 'id'));
            if ([] !== array_diff($new, $added)) {
                self::refuse('funnelkit_refused', 'FunnelKit did not add every product to the checkout.', [ 'product_ids' => array_values(array_diff($new, $added)) ]);
            }
        }

        $final    = self::apply_lines($stored, (array) $args['products']);
        $products = [];
        foreach ($final as $key => $entry) {
            // FunnelKit's product editor sends each entry with its checkout key
            // as id and its product id as key, and swaps them back to store.
            $products[] = [ 'id' => (string) $key, 'key' => (int) $entry['id'] ] + array_diff_key($entry, [ 'id' => true ]);
        }
        $settings = get_post_meta($step_id, '_wfacp_selected_products_settings', true);
        $settings = is_array($settings) ? $settings : [];
        $settings += [ 'add_to_cart_setting' => '1' ];
        self::rest('POST', sprintf('/funnelkit-app/funnel-checkout/%d/products/save-layout', $step_id), [], [ 'products' => $products, 'settings' => $settings ]);

        return [ 'added' => $new, 'products' => self::lines(self::stored_products($step_id)) ];
    }

    /** @return array<string,mixed> */
    private static function remove_checkout_products(int $step_id, array $product_ids): array
    {
        $keys = [];
        foreach (self::stored_products($step_id) as $key => $entry) {
            if (in_array((int) $entry['id'], array_map('intval', $product_ids), true)) {
                $keys[] = (string) $key;
            }
        }
        self::rest('DELETE', sprintf('/funnelkit-app/funnel-checkout/%d/products', $step_id), [], [ 'product_key' => $keys ]);
        $left = self::stored_products($step_id);
        if ([] !== array_intersect($keys, array_map('strval', array_keys($left)))) {
            self::refuse('funnelkit_refused', 'FunnelKit did not remove every product from the checkout.');
        }
        return [ 'removed' => array_map('intval', $product_ids), 'products' => self::lines($left) ];
    }

    /** @return array<string,mixed> */
    private static function checkout_view(int $step_id): array
    {
        $settings = get_post_meta($step_id, '_wfacp_selected_products_settings', true);
        return [
            'step_id'             => $step_id,
            'funnel_id'           => self::checkout_funnel($step_id) ?: null,
            'currency'            => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : null,
            'add_to_cart_setting' => is_array($settings) && isset($settings['add_to_cart_setting']) ? (string) $settings['add_to_cart_setting'] : null,
            'products'            => self::lines(self::stored_products($step_id)),
        ];
    }

    /**
     * Stored entries with each requested line's quantity and discount laid
     * over the entry for its product.
     *
     * @param array<string,array<string,mixed>> $stored
     * @param array<int,array<string,mixed>>    $lines
     * @return array<string,array<string,mixed>>
     */
    private static function apply_lines(array $stored, array $lines): array
    {
        foreach ($lines as $line) {
            foreach ($stored as $key => $entry) {
                if ((int) $entry['id'] !== (int) $line['product_id']) {
                    continue;
                }
                foreach ([ 'quantity', 'discount_type', 'discount_amount' ] as $field) {
                    if (isset($line[ $field ])) {
                        $stored[ $key ][ $field ] = 'quantity' === $field ? (int) $line[ $field ] : $line[ $field ];
                    }
                }
            }
        }
        return $stored;
    }

    /**
     * The checkout's product entries keyed by their checkout key, each with
     * its product id under 'id', as FunnelKit stores them.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function stored_products(int $step_id): array
    {
        $stored = get_post_meta($step_id, '_wfacp_selected_products', true);
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
     * Product lines with the price a buyer pays.
     *
     * @param array<string,array<string,mixed>> $entries
     * @return array<int,array<string,mixed>>
     */
    private static function lines(array $entries): array
    {
        $out = [];
        foreach ($entries as $key => $entry) {
            $product_id = (int) $entry['id'];
            $product    = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
            $quantity   = max(1, (int) ($entry['quantity'] ?? 1));
            $type       = (string) ($entry['discount_type'] ?? 'percent_discount_sale');
            $amount     = (float) ($entry['discount_amount'] ?? 0);
            $line       = [
                'key'             => str_starts_with((string) $key, 'new_') ? null : (string) $key,
                'product_id'      => $product_id,
                'title'           => $product instanceof \WC_Product ? sanitize_text_field($product->get_name()) : null,
                'quantity'        => $quantity,
                'discount_type'   => $type,
                'discount_amount' => $amount,
                'regular_price'   => null,
                'price'           => null,
                'unit_price'      => null,
                'line_total'      => null,
            ];
            if (! $product instanceof \WC_Product) {
                $line['unit_price_unavailable'] = 'the product no longer exists';
            } elseif ($product->is_type('variable')) {
                $line['unit_price_unavailable'] = 'a variable product is priced by the variation the buyer picks';
            } else {
                $regular = (float) $product->get_regular_price('edit');
                $price   = (float) $product->get_price('edit');
                $unit    = self::discounted($regular, $price, $type, $amount);
                $line    = array_merge($line, [
                    'regular_price' => $regular,
                    'price'         => $price,
                    'unit_price'    => $unit,
                    'line_total'    => round($unit * $quantity, function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 2),
                ]);
            }
            $out[] = $line;
        }
        return $out;
    }

    /**
     * The unit price after a checkout discount, as FunnelKit applies it: no
     * discount leaves the current price; otherwise WFACP_Common's own
     * calculate_discount() when FunnelKit is loaded, else the same formula.
     */
    public static function discounted(float $regular, float $price, string $type, float $amount): float
    {
        if (0.0 === $amount) {
            return $price;
        }
        if (is_callable([ 'WFACP_Common', 'calculate_discount' ])) {
            $out = call_user_func([ 'WFACP_Common', 'calculate_discount' ], [
                'wfacp_product_rp'      => $regular,
                'wfacp_product_p'       => $price,
                'wfacp_discount_amount' => $amount,
                'wfacp_discount_type'   => $type,
            ]);
            if (is_numeric($out)) {
                return (float) $out;
            }
        }
        switch ($type) {
            case 'fixed_discount_reg':
                $out = $regular - $amount;
                break;
            case 'fixed_discount_sale':
                $out = $price - $amount;
                break;
            case 'percent_discount_reg':
                $out = $regular - ($amount / 100) * $regular;
                break;
            case 'percent_discount_sale':
                $out = $price - ($amount / 100) * $price;
                break;
            default:
                $out = $price;
        }
        return max(0.0, $out);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Dispatch one request to FunnelKit's own REST routes in-process and
     * answer its data. A refusal by FunnelKit becomes an Operation_Error.
     *
     * @param array<string,mixed>      $params
     * @param array<string,mixed>|null $body JSON body, for the checkout routes that read it.
     * @return array<string,mixed>
     */
    private static function rest(string $method, string $route, array $params = [], ?array $body = null): array
    {
        $request = new \WP_REST_Request($method, $route);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        if (null !== $body) {
            $request->set_header('content-type', 'application/json');
            $request->set_body((string) wp_json_encode($body));
        }

        FunnelKit_Funnel_Snapshot::forget_cached_funnel();
        $response = rest_do_request($request);
        FunnelKit_Funnel_Snapshot::forget_cached_funnel();
        $status   = $response->get_status();
        $data     = $response->get_data();
        $data     = is_array($data) ? $data : (is_object($data) ? get_object_vars($data) : []);

        if (401 === $status || 403 === $status) {
            self::refuse('funnelkit_access_denied', 'FunnelKit refused this change: its own funnel access check does not let the current user edit funnels.', [ 'status' => $status ]);
        }
        if (404 === $status && 'rest_no_route' === ($data['code'] ?? '')) {
            self::refuse('funnelkit_api_unavailable', 'FunnelKit\'s funnel API is not available on this site.', [ 'route' => $route ]);
        }
        if ($status >= 400) {
            self::refuse('funnelkit_refused', self::message($data, 'FunnelKit refused the change.'), [ 'status' => $status ]);
        }
        return $data;
    }

    /**
     * Run a snapshotted write. When FunnelKit refuses it and the funnel is
     * exactly as it was, the undo point the dispatcher took is voided; a
     * refusal after part of the change landed keeps it.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private static function voiding(array $context, callable $write): array
    {
        $funnel_id = (int) ($context['target']['object_id'] ?? 0);
        $before    = $funnel_id > 0 ? FunnelKit_Funnel_Snapshot::capture($funnel_id) : null;
        try {
            return $write();
        } catch (Operation_Error $e) {
            $operation_id = (string) ($context['operation_id'] ?? '');
            if ('' !== $operation_id && null !== $before && FunnelKit_Funnel_Snapshot::capture($funnel_id) === $before) {
                Snapshot_Store::delete_operation($operation_id);
            }
            throw $e;
        }
    }

    /** Refuse on the dispatcher's top-level error channel. */
    private static function refuse(string $code, string $message, array $data = []): void
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught by the dispatcher and returned as a JSON error, never rendered.
        throw new Operation_Error($code, $message, $data);
    }

    /** FunnelKit's own message from a response, or the fallback. */
    private static function message(array $data, string $fallback): string
    {
        foreach ([ 'message', 'msg' ] as $key) {
            if (is_string($data[ $key ] ?? null) && '' !== $data[ $key ]) {
                return $fallback . ' FunnelKit said: ' . sanitize_text_field($data[ $key ]);
            }
        }
        return $fallback;
    }

    /** The id of the funnel a create answered, or the newest one past $before. */
    private static function created_funnel_id(array $data, int $before): int
    {
        $funnel = $data['funnel'] ?? null;
        if (is_object($funnel) && method_exists($funnel, 'get_id')) {
            $id = (int) $funnel->get_id();
        } else {
            $id = is_array($funnel) ? (int) ($funnel['id'] ?? 0) : 0;
        }
        if ($id < 1) {
            $id = self::max_funnel_id();
        }
        return $id > $before ? $id : 0;
    }

    private static function max_funnel_id(): int
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- FunnelKit's own table; reading the newest id around a create.
        return (int) $wpdb->get_var($wpdb->prepare('SELECT MAX(id) FROM %i', FunnelKit_Funnel_Snapshot::funnels_table()));
    }

    /**
     * A funnel's steps as stored, in order.
     *
     * @return array<int,array{type:string,id:int}>
     */
    private static function steps(int $funnel_id): array
    {
        $row     = FunnelKit_Funnel_Snapshot::row($funnel_id);
        $decoded = null === $row ? null : json_decode((string) $row['steps'], true);
        $out     = [];
        foreach (is_array($decoded) ? $decoded : [] as $step) {
            if (is_array($step) && is_string($step['type'] ?? null) && (int) ($step['id'] ?? 0) > 0) {
                $out[] = [ 'type' => sanitize_key($step['type']), 'id' => (int) $step['id'] ];
            }
        }
        return $out;
    }

    private static function step_type(int $funnel_id, int $step_id): ?string
    {
        foreach (self::steps($funnel_id) as $step) {
            if ($step['id'] === $step_id) {
                return $step['type'];
            }
        }
        return null;
    }

    /** The funnel a checkout step belongs to (its _bwf_in_funnel), or 0. */
    private static function checkout_funnel(int $step_id): int
    {
        $funnel_id = (int) get_post_meta($step_id, '_bwf_in_funnel', true);
        return $funnel_id > 0 && null !== self::step_type($funnel_id, $step_id) ? $funnel_id : 0;
    }

    private static function status(int $post_id): string
    {
        $status = get_post_status($post_id);
        if (false === $status) {
            return 'missing';
        }
        return 'publish' === $status ? 'active' : 'inactive';
    }

    /** @return array<string,mixed> */
    private static function funnel_view(int $funnel_id): array
    {
        $row = (array) FunnelKit_Funnel_Snapshot::row($funnel_id);
        return [
            'id'          => $funnel_id,
            'title'       => sanitize_text_field((string) ($row['title'] ?? '')),
            'description' => sanitize_textarea_field((string) ($row['desc'] ?? '')),
            'steps'       => array_map(static fn (array $step): array => $step + [
                'title'  => sanitize_text_field((string) get_the_title($step['id'])),
                'status' => self::status($step['id']),
            ], self::steps($funnel_id)),
        ];
    }
}
