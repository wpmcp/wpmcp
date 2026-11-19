<?php

namespace WPMCP\Integrations;

use WPMCP\MCP\Registrar;
use WPMCP\Safety\Post_Creation_Snapshot;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Tools\Content\Readable_Posts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * CartFlows flows, steps and checkout products (issue #662): paid-tier ops
 * on the theme dispatcher pair, beside FunnelKit, so they add no top-level
 * tools.
 *
 * Storage, as CartFlows 3.3.1 keeps it (admin-core/ajax/importer.php and
 * flows.php, admin-core/inc/meta-ops.php):
 *  - a flow is a cartflows_flow post; its wcf-steps meta is the ordered step
 *    list, entries of [id, title, type] (plus whatever else CartFlows Pro
 *    keeps on an entry, which a reorder carries along untouched);
 *  - a step is a cartflows_step post with wcf-flow-id and wcf-step-type
 *    meta, filed under the cartflows_step_type term of its type and the
 *    cartflows_step_flow term flow-<flow id>; wcf-disable-step 'yes'
 *    switches it off;
 *  - a checkout step's products are its wcf-checkout-products meta, rows of
 *    strings: product, quantity, discount_type (discount_percent,
 *    discount_price or empty), discount_value, unique_id and add_to_cart;
 *    an empty list deletes the meta, as CartFlows does.
 *
 * Reads: list-cartflows-flows, get-cartflows-flow and list-cartflows-steps.
 * They hold no customer data (no orders, no analytics), so nothing needs
 * masking.
 *
 * Writes, all off until the site opts in (wpmcp_enable_cartflows_write):
 *  - create-cartflows-flow: a flow (draft by default) with its steps, each
 *    step taking the flow's status, recorded for rollback
 *    (Post_Creation_Snapshot: the undo trashes them);
 *  - add-cartflows-step: a step created (creation row) and placed in the
 *    flow's list (post snapshot of the flow), one session for both;
 *  - reorder-cartflows-steps, set-cartflows-step-status and
 *    set-cartflows-checkout-products: one post snapshot (meta included) of
 *    the flow or step they change.
 * Only the step types the free plugin builds are accepted (landing,
 * checkout, thankyou, optin); upsell and downsell offers, order bumps and
 * A/B tests are CartFlows Pro's and not part of this slice.
 *
 * Access: the theme pair's edit_theme_options, plus CartFlows' own
 * cartflows_manage_flows_steps (which CartFlows grants administrators; an
 * administrator holding manage_options is let through before CartFlows has
 * granted it on its first admin screen load), plus the per-post capability
 * of every flow and step named.
 *
 * Presence is filterable (wpmcp_cartflows_active, default: CARTFLOWS_VER is
 * defined).
 */
final class CartFlows_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    public const FLOW = 'cartflows_flow';

    public const STEP = 'cartflows_step';

    public const TAX_TYPE = 'cartflows_step_type';

    public const TAX_FLOW = 'cartflows_step_flow';

    public const CAPABILITY = 'cartflows_manage_flows_steps';

    public const ENABLE_FILTER = 'wpmcp_enable_cartflows_write';

    /** The step types the free plugin creates. */
    public const STEP_TYPES = [ 'landing', 'checkout', 'thankyou', 'optin' ];

    public const DISCOUNT_TYPES = [ '', 'discount_percent', 'discount_price' ];

    public const MAX_PER_PAGE = 50;

    public const MAX_STEPS = 20;

    public const MAX_PRODUCTS = 20;

    private const STATUSES = [ 'publish', 'draft', 'pending', 'private' ];

    public static function active(): bool
    {
        return Registrar::tier_permitted('pro') && self::loaded();
    }

    private static function loaded(): bool
    {
        return (bool) apply_filters('wpmcp_cartflows_active', defined('CARTFLOWS_VER'));
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $id      = [ 'type' => 'integer', 'minimum' => 1 ];
        $title   = [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ];
        $type    = [ 'type' => 'string', 'enum' => self::STEP_TYPES ];
        $enabled = (bool) apply_filters('wpmcp_enable_cartflows_write', false);
        $own     = static fn (string $access): array => [ 'type' => 'post', 'own_type' => true, 'access' => $access ];
        $base    = [
            'tier'     => 'pro',
            'requires' => static fn () => Ops_Status_Packs::presence(self::loaded(), 'cartflows_inactive', 'CartFlows'),
        ];
        $write   = $base + [
            'mode'               => 'write',
            'enabled_by_default' => $enabled,
            'opt_in_filter'      => self::ENABLE_FILTER,
        ];

        return [
            'list-cartflows-flows'            => $base + [
                'mode'         => 'read',
                'description'  => 'List CartFlows flows newest first: id, title, status, dates, step count and step types, and whether it is the store checkout. Filter by status or search the title',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'status'   => [ 'type' => 'string', 'enum' => self::STATUSES ],
                        'search'   => [ 'type' => 'string', 'maxLength' => 200 ],
                        'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
                        'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE ],
                    ],
                ],
                'validate'     => static fn (): ?array => self::access_refusal(),
                'handler'      => static fn (array $args): array => self::list_flows($args),
            ],
            'get-cartflows-flow'              => $base + [
                'mode'         => 'read',
                'objects'      => [ 'id' => $own('read') ],
                'description'  => 'One CartFlows flow with its steps in order: id, title, type, status, enabled, position, link and checkout product count',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => $id ], 'required' => [ 'id' ] ],
                'validate'     => static fn (array $args): ?array => self::access_refusal() ?? self::post_refusal((int) $args['id'], self::FLOW),
                'handler'      => static fn (array $args): array => [ 'flow' => self::flow(get_post((int) $args['id']), true) ],
            ],
            'list-cartflows-steps'            => $base + [
                'mode'         => 'read',
                'objects'      => [ 'flow_id' => $own('read') ],
                'description'  => 'A CartFlows flow\'s steps in order with their checkout products (product id and name, quantity, discount, added to cart or not). type keeps one step type',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'flow_id' => $id, 'type' => $type ], 'required' => [ 'flow_id' ] ],
                'validate'     => static fn (array $args): ?array => self::access_refusal() ?? self::post_refusal((int) $args['flow_id'], self::FLOW),
                'handler'      => static fn (array $args): array => [ 'steps' => self::steps((int) $args['flow_id'], true, (string) ($args['type'] ?? '')) ],
            ],
            'create-cartflows-flow'           => $write + [
                'self_snapshotting' => true,
                'description'       => 'Create a CartFlows flow (status defaults to draft) with optional steps in order, each [type, title]; the steps take the flow\'s status. Undo: rollback-session trashes them. Off until wpmcp_enable_cartflows_write',
                'input_schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'title'  => $title,
                        'status' => [ 'type' => 'string', 'enum' => [ 'draft', 'publish' ] ],
                        'steps'  => [
                            'type'     => 'array',
                            'maxItems' => self::MAX_STEPS,
                            'items'    => [
                                'type'       => 'object',
                                'properties' => [ 'type' => $type, 'title' => $title ],
                                'required'   => [ 'type', 'title' ],
                            ],
                        ],
                    ],
                    'required'   => [ 'title' ],
                ],
                'validate'          => static fn (array $args): ?array => self::access_refusal() ?? self::create_refusal((string) ($args['status'] ?? 'draft')),
                'handler'           => static fn (array $args, array $context): array => self::create_flow($args, $context),
            ],
            'add-cartflows-step'              => $write + [
                'self_snapshotting' => true,
                'objects'           => [ 'flow_id' => $own('write') ],
                'description'       => 'Add a step to a CartFlows flow: type, title, and position (1 is first; default last, before the store checkout\'s thank you step). The step takes the flow\'s status. Undo: rollback-session. Off until wpmcp_enable_cartflows_write',
                'input_schema'      => [
                    'type'       => 'object',
                    'properties' => [ 'flow_id' => $id, 'type' => $type, 'title' => $title, 'position' => [ 'type' => 'integer', 'minimum' => 1 ] ],
                    'required'   => [ 'flow_id', 'type', 'title' ],
                ],
                'validate'          => static fn (array $args): ?array => self::access_refusal() ?? self::post_refusal((int) $args['flow_id'], self::FLOW, true) ?? self::create_refusal((string) get_post_status((int) $args['flow_id'])),
                'handler'           => static fn (array $args, array $context): array => self::add_step($args, $context),
            ],
            'reorder-cartflows-steps'         => $write + [
                'objects'      => [ 'flow_id' => $own('write'), 'step_ids' => $own('read') ],
                'description'  => 'Put a CartFlows flow\'s steps in a new order: step_ids lists every step of the flow once. Undo: rollback-operation. Off until wpmcp_enable_cartflows_write',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'flow_id' => $id, 'step_ids' => [ 'type' => 'array', 'items' => $id, 'minItems' => 1, 'maxItems' => 100 ] ],
                    'required'   => [ 'flow_id', 'step_ids' ],
                ],
                'validate'     => static fn (array $args): ?array => self::access_refusal() ?? self::post_refusal((int) $args['flow_id'], self::FLOW, true) ?? self::order_refusal((int) $args['flow_id'], (array) $args['step_ids']),
                'snapshot'     => static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => (int) $args['flow_id'] ],
                'handler'      => static fn (array $args): array => self::reorder((int) $args['flow_id'], array_map('intval', (array) $args['step_ids'])),
            ],
            'set-cartflows-step-status'       => $write + [
                'objects'      => [ 'step_id' => $own('write') ],
                'description'  => 'Switch a CartFlows step on or off (enabled true or false, CartFlows\' own step switch). Undo: rollback-operation. Off until wpmcp_enable_cartflows_write',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'step_id' => $id, 'enabled' => [ 'type' => 'boolean' ] ],
                    'required'   => [ 'step_id', 'enabled' ],
                ],
                'validate'     => static fn (array $args): ?array => self::access_refusal() ?? self::post_refusal((int) $args['step_id'], self::STEP, true) ?? self::status_refusal((int) $args['step_id'], (bool) $args['enabled']),
                'snapshot'     => static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => (int) $args['step_id'] ],
                'handler'      => static fn (array $args): array => self::set_status((int) $args['step_id'], (bool) $args['enabled']),
            ],
            'set-cartflows-checkout-products' => $write + [
                'objects'      => [ 'step_id' => $own('write'), 'products.*.product_id' => $own('read') ],
                'description'  => 'Replace a CartFlows checkout step\'s products: each product_id with quantity (default 1), discount_type (discount_percent, discount_price or none), discount_value and add_to_cart (default true). An empty list removes them all. Undo: rollback-operation. Off until wpmcp_enable_cartflows_write',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'step_id'  => $id,
                        'products' => [
                            'type'     => 'array',
                            'maxItems' => self::MAX_PRODUCTS,
                            'items'    => [
                                'type'       => 'object',
                                'properties' => [
                                    'product_id'     => $id,
                                    'quantity'       => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 1000 ],
                                    'discount_type'  => [ 'type' => 'string', 'enum' => self::DISCOUNT_TYPES ],
                                    'discount_value' => [ 'type' => 'number', 'minimum' => 0 ],
                                    'add_to_cart'    => [ 'type' => 'boolean' ],
                                ],
                                'required'   => [ 'product_id' ],
                            ],
                        ],
                    ],
                    'required'   => [ 'step_id', 'products' ],
                ],
                'validate'     => static fn (array $args): ?array => self::access_refusal() ?? self::post_refusal((int) $args['step_id'], self::STEP, true) ?? self::products_refusal((int) $args['step_id'], (array) $args['products']),
                'snapshot'     => static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => (int) $args['step_id'] ],
                'handler'      => static fn (array $args): array => self::set_products((int) $args['step_id'], (array) $args['products']),
            ],
        ];
    }

    // ------------------------------------------------------------ reads

    /** @return array<int, array<string, mixed>> the flow's wcf-steps entries */
    private static function entries(int $flow): array
    {
        $list = get_post_meta($flow, 'wcf-steps', true);
        return is_array($list) ? array_values(array_filter($list, static fn ($e): bool => is_array($e) && isset($e['id']))) : [];
    }

    private static function flow(\WP_Post $post, bool $full): array
    {
        $entries = self::entries((int) $post->ID);
        $flow    = [
            'id'             => (int) $post->ID,
            'title'          => (string) $post->post_title,
            'status'         => (string) $post->post_status,
            'date'           => (string) $post->post_date,
            'modified'       => (string) $post->post_modified,
            'step_count'     => count($entries),
            'step_types'     => array_map(static fn (array $e): string => (string) ($e['type'] ?? get_post_meta((int) $e['id'], 'wcf-step-type', true)), $entries),
            'store_checkout' => (int) get_option('_cartflows_store_checkout', 0) === (int) $post->ID,
        ];
        if ($full) {
            $flow['steps'] = self::steps((int) $post->ID, false, '');
        }
        return $flow;
    }

    /** @return array<int, array<string, mixed>> */
    private static function steps(int $flow, bool $products, string $type): array
    {
        $out = [];
        foreach (self::entries($flow) as $i => $entry) {
            $post = get_post((int) $entry['id']);
            if (! $post instanceof \WP_Post || self::STEP !== $post->post_type) {
                $out[] = [ 'id' => (int) $entry['id'], 'position' => $i + 1, 'missing' => true ];
                continue;
            }
            $step = self::step($post, $products);
            if ('' !== $type && $type !== $step['type']) {
                continue;
            }
            $out[] = [ 'position' => $i + 1 ] + $step;
        }
        return $out;
    }

    private static function step(\WP_Post $post, bool $products): array
    {
        $type = (string) get_post_meta($post->ID, 'wcf-step-type', true);
        $rows = self::product_rows((int) $post->ID);
        $step = [
            'id'                     => (int) $post->ID,
            'title'                  => (string) $post->post_title,
            'type'                   => $type,
            'status'                 => (string) $post->post_status,
            'enabled'                => 'yes' !== get_post_meta($post->ID, 'wcf-disable-step', true),
            'link'                   => (string) get_permalink($post),
            'checkout_product_count' => count($rows),
        ];
        if ($products && 'checkout' === $type) {
            $step['checkout_products'] = array_map(static function (array $row): array {
                $id      = (int) $row['product'];
                $product = function_exists('wc_get_product') ? wc_get_product($id) : null;
                return [
                    'product_id'     => $id,
                    'name'           => $product ? (string) $product->get_name() : null,
                    'quantity'       => max(1, (int) ($row['quantity'] ?? 1)),
                    'discount_type'  => (string) ($row['discount_type'] ?? ''),
                    'discount_value' => '' === (string) ($row['discount_value'] ?? '') ? null : (float) $row['discount_value'],
                    'add_to_cart'    => 'no' !== (string) ($row['add_to_cart'] ?? 'yes') && '' !== (string) ($row['add_to_cart'] ?? 'yes'),
                ];
            }, $rows);
        }
        return $step;
    }

    /** @return array<int, array<string, mixed>> */
    private static function product_rows(int $step): array
    {
        $rows = get_post_meta($step, 'wcf-checkout-products', true);
        return is_array($rows) ? array_values(array_filter($rows, static fn ($r): bool => is_array($r) && isset($r['product']))) : [];
    }

    private static function list_flows(array $args): array
    {
        $query = [
            'post_type'      => self::FLOW,
            'post_status'    => isset($args['status']) ? [ (string) $args['status'] ] : self::STATUSES,
            'posts_per_page' => (int) ($args['per_page'] ?? 20),
            'paged'          => (int) ($args['page'] ?? 1),
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];
        if (isset($args['search']) && '' !== trim((string) $args['search'])) {
            $query['s'] = (string) $args['search'];
        }
        $q = Readable_Posts::query($query);
        return [ 'flows' => array_map(static fn (\WP_Post $p): array => self::flow($p, false), $q->posts), 'total' => (int) $q->found_posts ];
    }

    // ------------------------------------------------------------ refusals

    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    private static function access_refusal(): ?array
    {
        if (current_user_can(self::CAPABILITY) || current_user_can('manage_options')) {
            return null;
        }
        return self::refusal('operation_denied', 'CartFlows lets only users with its cartflows_manage_flows_steps capability work with flows and steps.', [ 'reason' => 'capability', 'capability' => self::CAPABILITY ]);
    }

    private static function post_refusal(int $id, string $type, bool $write = false): ?array
    {
        $post  = get_post($id);
        $label = self::FLOW === $type ? 'flow' : 'step';
        if (! $post instanceof \WP_Post || $type !== $post->post_type) {
            return self::refusal($label . '_not_found', sprintf('%d is not a CartFlows %s.', $id, $label), [ 'id' => $id ]);
        }
        if (! current_user_can($write ? 'edit_post' : 'read_post', $id)) {
            return self::refusal('operation_denied', sprintf('You are not allowed to %s %s %d.', $write ? 'edit' : 'read', $label, $id), [ 'reason' => 'capability', 'id' => $id ]);
        }
        if ('trash' === $post->post_status) {
            return self::refusal($label . '_trashed', sprintf('CartFlows %s %d is in the trash. Restore it in wp-admin first.', $label, $id), [ 'id' => $id ]);
        }
        return null;
    }

    private static function create_refusal(string $status): ?array
    {
        foreach ([ self::FLOW, self::STEP ] as $type) {
            $object = get_post_type_object($type);
            if (! $object instanceof \WP_Post_Type) {
                return self::refusal('post_type_missing', 'CartFlows has not registered its flow and step post types.');
            }
            $caps = 'publish' === $status ? [ $object->cap->create_posts, $object->cap->publish_posts ] : [ $object->cap->create_posts ];
            foreach ($caps as $cap) {
                if (! current_user_can($cap)) {
                    return self::refusal('operation_denied', sprintf('This needs the %s capability.', $cap), [ 'reason' => 'capability' ]);
                }
            }
        }
        return null;
    }

    private static function order_refusal(int $flow, array $ids): ?array
    {
        $current = array_map(static fn (array $e): int => (int) $e['id'], self::entries($flow));
        $ids     = array_map('intval', $ids);
        $sorted  = $ids;
        $have    = $current;
        sort($sorted);
        sort($have);
        if ($sorted !== $have) {
            return self::refusal('invalid_step_order', 'step_ids must list every step of the flow exactly once.', [ 'flow_id' => $flow, 'step_ids' => $current ]);
        }
        if ($ids === $current) {
            return self::refusal('no_change', 'The steps are already in that order. Nothing was changed.', [ 'step_ids' => $current ]);
        }
        return null;
    }

    private static function status_refusal(int $step, bool $enabled): ?array
    {
        $now = 'yes' !== get_post_meta($step, 'wcf-disable-step', true);
        return $now === $enabled
            ? self::refusal('no_change', sprintf('Step %d is already %s. Nothing was changed.', $step, $enabled ? 'enabled' : 'disabled'), [ 'enabled' => $now ])
            : null;
    }

    private static function products_refusal(int $step, array $products): ?array
    {
        if ('checkout' !== get_post_meta($step, 'wcf-step-type', true)) {
            return self::refusal('not_a_checkout_step', sprintf('Step %d is not a CartFlows checkout step.', $step), [ 'id' => $step ]);
        }
        if ([] === $products) {
            return null;
        }
        if (! function_exists('wc_get_product')) {
            return Plugin_Refusal::make('woocommerce_inactive', 'WooCommerce');
        }
        $bad  = [];
        $seen = [];
        foreach ($products as $row) {
            $id      = (int) ($row['product_id'] ?? 0);
            $product = wc_get_product($id);
            if (! $product || in_array($product->get_type(), [ 'grouped', 'external' ], true) || isset($seen[ $id ])) {
                $bad[] = $id;
            }
            $seen[ $id ] = true;
        }
        if ([] !== $bad) {
            return self::refusal('product_not_found', sprintf('Not a product CartFlows can put on a checkout, or listed twice: %s. Grouped and external products are not added to the cart.', implode(', ', $bad)), [ 'product_ids' => $bad ]);
        }
        return null;
    }

    // ------------------------------------------------------------ writes

    /** Insert a new flow or step post; every field is new input. */
    private static function insert(string $type, string $title, string $status): int
    {
        $id = wp_insert_post([
            'post_type'    => $type,
            'post_title'   => wp_slash($title),
            'post_content' => '',
            'post_status'  => $status,
            'post_author'  => get_current_user_id(),
        ], true);
        if (is_wp_error($id) || 0 === (int) $id) {
            throw new Operation_Refused('insert_failed', is_wp_error($id) ? $id->get_error_message() : 'CartFlows could not create the post.');
        }
        return (int) $id;
    }

    /** Give a new step its flow, type and terms, as CartFlows' create_step() does. */
    private static function link_step(int $step, int $flow, string $type): void
    {
        update_post_meta($step, 'wcf-flow-id', $flow);
        update_post_meta($step, 'wcf-step-type', $type);
        update_post_meta($step, '_wp_page_template', 'cartflows-default');
        if (taxonomy_exists(self::TAX_TYPE)) {
            wp_set_object_terms($step, $type, self::TAX_TYPE);
        }
        if (taxonomy_exists(self::TAX_FLOW)) {
            wp_set_object_terms($step, 'flow-' . $flow, self::TAX_FLOW);
        }
    }

    private static function create_flow(array $args, array $context): array
    {
        $status = (string) ($args['status'] ?? 'draft');
        $flow   = self::insert(self::FLOW, (string) $args['title'], $status);
        $ids    = [ $flow ];
        $list   = [];
        try {
            foreach ((array) ($args['steps'] ?? []) as $spec) {
                $step   = self::insert(self::STEP, (string) $spec['title'], $status);
                $ids[]  = $step;
                $list[] = [ 'id' => $step, 'title' => (string) $spec['title'], 'type' => (string) $spec['type'] ];
            }
        } catch (\Throwable $e) {
            foreach (array_reverse($ids) as $id) {
                wp_delete_post($id, true);
            }
            throw $e;
        }
        $op = Post_Creation_Snapshot::record((string) $context['tool_name'], $ids, [ 'operation' => $context['operation'], 'args' => $args ], (string) $context['session_id']);
        update_post_meta($flow, 'wcf-flow-creation-method', 'scratch');
        foreach ($list as $entry) {
            self::link_step($entry['id'], $flow, $entry['type']);
        }
        update_post_meta($flow, 'wcf-steps', $list);
        clean_post_cache($flow);
        return [ 'result' => [ 'flow' => self::flow(get_post($flow), true) ], 'operation_ids' => [ $op ] ];
    }

    private static function add_step(array $args, array $context): array
    {
        $flow    = (int) $args['flow_id'];
        $type    = (string) $args['type'];
        $title   = (string) $args['title'];
        $status  = 'publish' === get_post_status($flow) ? 'publish' : 'draft';
        $step    = self::insert(self::STEP, $title, $status);
        $created = Post_Creation_Snapshot::record((string) $context['tool_name'], [ $step ], [ 'operation' => $context['operation'], 'args' => $args ], (string) $context['session_id']);
        self::link_step($step, $flow, $type);

        $list  = self::entries($flow);
        $entry = [ 'id' => $step, 'title' => $title, 'type' => $type ];
        if (isset($args['position'])) {
            array_splice($list, max(0, min(count($list), (int) $args['position'] - 1)), 0, [ $entry ]);
        } else {
            $last = end($list);
            // The store checkout keeps its thank you step last (CartFlows'
            // maybe_update_flow_steps()).
            $keep = (int) get_option('_cartflows_store_checkout', 0) === $flow && is_array($last) && 'thankyou' === ($last['type'] ?? '');
            $keep ? array_splice($list, count($list) - 1, 0, [ $entry ]) : $list[] = $entry;
        }
        $placed = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $flow,
                'session_id'  => (string) $context['session_id'],
                'tool_name'   => (string) $context['tool_name'],
                'args'        => [ 'operation' => (string) $context['operation'], 'args' => $args ],
            ],
            static function () use ($flow, $list): bool {
                update_post_meta($flow, 'wcf-steps', $list);
                return true;
            }
        );
        clean_post_cache($step);
        return [ 'result' => [ 'step' => self::step(get_post($step), true), 'step_ids' => array_column($list, 'id') ], 'operation_ids' => [ $created, (string) $placed['operation_id'] ] ];
    }

    private static function reorder(int $flow, array $ids): array
    {
        $map = [];
        foreach (self::entries($flow) as $entry) {
            $map[ (int) $entry['id'] ] = $entry;
        }
        $list = [];
        foreach ($ids as $id) {
            $entry          = $map[ $id ] ?? [];
            $entry['id']    = $id;
            $entry['title'] = get_the_title($id);
            $entry['type']  = (string) get_post_meta($id, 'wcf-step-type', true);
            $list[]         = $entry;
        }
        update_post_meta($flow, 'wcf-steps', $list);
        return [ 'flow' => self::flow(get_post($flow), true) ];
    }

    private static function set_status(int $step, bool $enabled): array
    {
        update_post_meta($step, 'wcf-disable-step', $enabled ? 'no' : 'yes');
        return [ 'step' => self::step(get_post($step), false) ];
    }

    private static function set_products(int $step, array $products): array
    {
        if ([] === $products) {
            delete_post_meta($step, 'wcf-checkout-products');
            return [ 'step' => self::step(get_post($step), true) ];
        }
        $kept = [];
        foreach (self::product_rows($step) as $row) {
            if (isset($row['unique_id']) && '' !== (string) $row['unique_id']) {
                $kept[ (int) $row['product'] ] = (string) $row['unique_id'];
            }
        }
        $rows = [];
        foreach ($products as $p) {
            $id     = (int) $p['product_id'];
            $type   = (string) ($p['discount_type'] ?? '');
            $rows[] = [
                'product'        => (string) $id,
                'quantity'       => (string) (int) ($p['quantity'] ?? 1),
                'discount_type'  => $type,
                'discount_value' => '' === $type || ! isset($p['discount_value']) ? '' : (string) (0 + $p['discount_value']),
                'unique_id'      => $kept[ $id ] ?? strtolower(wp_generate_password(8, false, false)),
                'add_to_cart'    => false === ($p['add_to_cart'] ?? true) ? 'no' : 'yes',
            ];
        }
        update_post_meta($step, 'wcf-checkout-products', $rows);
        return [ 'step' => self::step(get_post($step), true) ];
    }
}
