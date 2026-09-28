<?php

namespace WPMCP\Tools\WooCommerce\Catalog;

use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Write dispatcher for the deep WooCommerce operations catalog (issue #68).
 * Resolves a named write or destructive op through Op_Catalog and dispatches
 * it as an INTERNAL REST request to the store's own wc/v3 route
 * (rest_do_request(), no HTTP loopback) as the current user, so the
 * endpoint's own permission_callback is the final gate.
 *
 * Every call runs this guard chain before anything is written; each step
 * short-circuits into a structured error and a refused call has no side
 * effects and writes no snapshot:
 *
 *   op is a write or destructive row -> WooCommerce (and the row's
 *   taxonomy) available -> op-level governance -> per-op capability ->
 *   opt-in (destructive ops are off until the wpmcp_woo_op_enabled filter
 *   enables them) -> confirm:true for destructive ops -> forbidden params
 *   and meta keys -> path params -> handler checks (Order_Ops,
 *   Shipping_Ops, Webhook_Ops, Review_Ops, Gateway_Ops, Status_Ops for
 *   order_*, shipping_*, webhook_*, review_*, gateway_* and status_*
 *   handlers) -> brand checks (Brand_Ops, rows naming a taxonomy) ->
 *   snapshot target resolves -> Safe_Mutation (snapshot first)
 *   -> dispatch, or the row's in-process handler.
 *
 * Ops that change or remove existing state always run inside
 * Safe_Mutation::run(), so the response carries an operation_id that
 * rollback-operation restores. Ops that only create a new object have no
 * prior state to capture (the exemption create-post and create-product
 * carry); they dispatch directly and say so with recoverable:false plus the
 * op that removes what they made. A row whose snapshot cannot undo its whole
 * effect (refunds.create: the status comes back, the money does not) is
 * still snapshotted but reports recoverable:false.
 *
 * Batches: pass batch (a list of {op, params}) instead of op. Every item is
 * checked against the full guard chain FIRST, and the whole batch is refused
 * if any item fails, so a gate refusal never leaves a half-applied batch.
 * Items then run one at a time, each with its own snapshot and
 * operation_id, all under one session_id (generated when the caller gives
 * none), so rollback-session undoes the whole batch in one call. A runtime
 * failure of one item (the endpoint answers 4xx, say) is reported on that
 * item and the rest still run. confirm:true at the top level covers every
 * destructive item in the batch.
 */
class Woo_Write
{
    /** Upper bound on items per batch, matching wc/v3's own batch limit order of magnitude. */
    public const MAX_BATCH = 25;

    /** Filter a site uses to switch an op on (destructive ops are off by default) or off. */
    public const ENABLE_FILTER = 'wpmcp_woo_op_enabled';

    /** @var Wc_Rest_Write_Dispatch */
    private $dispatch;

    public function __construct(?Wc_Rest_Write_Dispatch $dispatch = null)
    {
        $this->dispatch = $dispatch ?: new Wc_Rest_Write_Dispatch();
    }

    public function handle(array $args): array
    {
        $confirm = true === ($args['confirm'] ?? null);

        if (array_key_exists('batch', $args)) {
            if (isset($args['op'])) {
                return Op_Guard::error('invalid_request', 'Pass either op or batch, not both.');
            }
            return $this->handle_batch($args['batch'], $confirm, (string) ($args['session_id'] ?? ''));
        }

        $op = (string) ($args['op'] ?? '');
        if ('' === $op) {
            throw new \InvalidArgumentException('An op (or a batch) is required. Call woo-ops for the catalog.');
        }

        $params = isset($args['params']) && is_array($args['params']) ? $args['params'] : [];
        $plan   = $this->prepare($op, $params, $confirm);
        if (isset($plan['error'])) {
            return $plan;
        }

        $session_id = (string) ($args['session_id'] ?? '');
        return $this->execute($plan, '' === $session_id ? 'default' : $session_id);
    }

    /** Whether an op is switched on: destructive ops default off, writes on. */
    public static function is_op_enabled(string $op, array $def): bool
    {
        $default = 'destructive' !== ($def['mode'] ?? '');
        return (bool) apply_filters('wpmcp_woo_op_enabled', $default, $op);
    }

    /**
     * @param mixed $items
     */
    private function handle_batch($items, bool $confirm, string $session_id): array
    {
        if (! is_array($items) || [] === $items || ! array_is_list($items)) {
            return Op_Guard::error('invalid_request', 'batch must be a non-empty list of {op, params} items.');
        }
        if (count($items) > self::MAX_BATCH) {
            return Op_Guard::error(
                'batch_too_large',
                'A batch holds at most ' . self::MAX_BATCH . ' items.',
                [ 'max' => self::MAX_BATCH ]
            );
        }

        // Pass 1: every item through every gate, nothing written yet.
        $plans = [];
        foreach ($items as $index => $item) {
            $op     = is_array($item) ? (string) ($item['op'] ?? '') : '';
            $params = is_array($item) && isset($item['params']) && is_array($item['params']) ? $item['params'] : [];

            if ('' === $op) {
                $plan = Op_Guard::error('invalid_request', 'Every batch item needs an op.');
            } else {
                try {
                    $plan = $this->prepare($op, $params, $confirm);
                } catch (\InvalidArgumentException $e) {
                    $plan = Op_Guard::error('unknown_op', $e->getMessage());
                }
            }

            if (isset($plan['error'])) {
                return Op_Guard::error(
                    'batch_rejected',
                    "Batch item {$index} was refused, so no item was applied.",
                    [ 'index' => $index, 'error' => $plan['error'] ]
                );
            }
            $plans[] = $plan;
        }

        // Pass 2: apply, one snapshot per item, one session for the batch.
        if ('' === $session_id) {
            // A bare UUID: the snapshot table's session_id column is CHAR(36).
            $session_id = wp_generate_uuid4();
        }

        $results = [];
        $applied = 0;
        foreach ($plans as $index => $plan) {
            try {
                $result = $this->execute($plan, $session_id);
            } catch (\Throwable $e) {
                $result = [
                    'op'      => $plan['op'],
                    'applied' => false,
                    'error'   => [ 'code' => 'item_failed', 'message' => $e->getMessage() ],
                ];
            }
            if (! empty($result['applied'])) {
                $applied++;
            }
            $results[] = [ 'index' => $index ] + $result;
        }

        return [
            'batch'      => true,
            'session_id' => $session_id,
            'applied'    => $applied,
            'failed'     => count($results) - $applied,
            'results'    => $results,
        ];
    }

    /**
     * Run the guard chain for one op and resolve everything execute() needs.
     * Throws InvalidArgumentException for an unknown op (as woo-read does);
     * every other refusal is a structured error.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function prepare(string $op, array $params, bool $confirm): array
    {
        $def = Op_Catalog::get($op);

        if (! in_array($def['mode'], ['write', 'destructive'], true) || 'GET' === $def['method']) {
            return Op_Guard::error(
                'not_a_write_op',
                "Op \"{$op}\" is a read op. Dispatch it with woo-read."
            );
        }

        $denied = Op_Guard::check($op, $def);
        if (null !== $denied) {
            return $denied;
        }

        if (! self::is_op_enabled($op, $def)) {
            return Op_Guard::error(
                'operation_disabled',
                "Op \"{$op}\" is disabled. Destructive ops are off by default; a site enables one with the "
                . self::ENABLE_FILTER . ' filter.',
                [ 'filter' => self::ENABLE_FILTER ]
            );
        }

        if ('destructive' === $def['mode'] && ! $confirm) {
            $context = null !== $def['taxonomy'] ? Brand_Ops::confirm_context($params) : [];
            $usage   = isset($context['products_using'])
                ? " It is used by {$context['products_using']} products, which would lose it."
                : '';
            return Op_Guard::error(
                'confirmation_required',
                "Op \"{$op}\" is destructive and requires confirm:true.{$usage}",
                $context
            );
        }

        foreach ($def['forbidden_params'] as $forbidden) {
            if (array_key_exists($forbidden, $params)) {
                return Op_Guard::error(
                    'forbidden_param',
                    "Op \"{$op}\" does not accept \"{$forbidden}\": that change could not be rolled back.",
                    [ 'param' => $forbidden ]
                );
            }
        }

        if (null !== $def['forbidden_meta'] && array_key_exists('meta_data', $params)) {
            $entries = is_array($params['meta_data']) ? $params['meta_data'] : [];
            foreach ($entries as $entry) {
                $key = is_array($entry) ? ($entry['key'] ?? '') : '';
                if (! is_string($key) || preg_match($def['forbidden_meta'], trim($key))) {
                    return Op_Guard::error(
                        'forbidden_param',
                        "Op \"{$op}\" does not accept that meta_data key: it would change roles, capabilities or sessions.",
                        [ 'param' => 'meta_data' ]
                    );
                }
            }
        }

        try {
            [ $route, $body ] = Op_Catalog::resolve_route($op, $params);
        } catch (\InvalidArgumentException $e) {
            return Op_Guard::error('invalid_params', $e->getMessage());
        }

        foreach ($def['defaults'] as $key => $value) {
            if (! array_key_exists($key, $body)) {
                $body[ $key ] = $value;
            }
        }

        $report  = [];
        $handler = self::handler_class($def);
        if (null !== $handler) {
            // A handler may need confirm (a status tool that deletes rows)
            // and may add to the response (why a tool run cannot be undone).
            $checked = $handler::prepare((string) $def['handler'], $params, $confirm);
            if (isset($checked['error'])) {
                return $checked;
            }
            $body   = $checked['body'];
            $report = $checked['report'] ?? [];
        }

        if (null !== $def['taxonomy']) {
            $brand = Brand_Ops::prepare($op, $params, $body);
            if (isset($brand['error'])) {
                return $brand;
            }
            $body   = $brand['body'];
            $report = $brand['report'];
        }

        $target = null;
        if (null !== $def['snapshot']) {
            $target = $this->snapshot_target($def['snapshot'], $params, $body, $def['taxonomy']);
            if (isset($target['error'])) {
                return $target;
            }
        }

        if ($def['guard_variations'] && null !== $target && $this->variations_at_risk($def, $params, (int) $target['object_id'])) {
            return Op_Guard::error(
                'has_variations',
                "Op \"{$op}\" is refused: this product has variations, which WooCommerce would remove along with "
                . 'it and which its snapshot does not cover. Delete or change the variations first with the '
                . 'variations.* ops, each of which is snapshotted.'
            );
        }

        return [
            'op'     => $op,
            'def'    => $def,
            'route'  => $route,
            'body'   => $body,
            'target' => $target,
            'report' => $report,
        ];
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function execute(array $plan, string $session_id): array
    {
        $def = $plan['def'];

        // A brand image given as a URL is fetched now, through the remote
        // media guard, and recorded as its own undoable import.
        $media_op = null;
        if (null !== $def['taxonomy']) {
            [ $plan['body'], $media_op ] = Brand_Ops::materialize_image($plan['body'], $session_id);
        }
        $extra = $plan['report'] + (null !== $media_op ? [ 'media_operation_id' => $media_op ] : []);

        // An order, zone, webhook or review reply create has no prior state;
        // its handler records the creation row itself once the object exists
        // (issue #292).
        if (null !== $plan['target'] && ! empty($plan['target']['creation'])) {
            $made = self::handler_class($def)::create($plan['body'], $session_id, $plan['op']);
            if (isset($made['error'])) {
                return [ 'op' => $plan['op'], 'applied' => false ] + $made;
            }
            return $this->result($plan, $made) + [
                'operation_id' => $made['operation_id'],
                'recoverable'  => $def['recoverable'],
            ];
        }

        if (null === $plan['target']) {
            $out = $this->dispatch->send($def['method'], $plan['route'], $plan['body']);
            return $this->result($plan, $out) + [
                'recoverable' => false,
                'undo_op'     => $def['undo_op'],
            ] + $extra;
        }

        $mutation = Safe_Mutation::run(
            [
                'object_type' => $plan['target']['object_type'],
                'object_id'   => $plan['target']['object_id'],
                'session_id'  => $session_id,
                'tool_name'   => 'woo-write',
                'args'        => [ 'op' => $plan['op'], 'route' => $plan['route'], 'params' => $plan['body'] ],
            ] + (isset($plan['target']['restore_capability']) ? [ 'extra_snapshot_data' => [ 'restore_capability' => $plan['target']['restore_capability'] ] ] : []),
            fn () => $this->run($plan)
        );

        return $this->result($plan, $mutation['result']) + [
            'operation_id' => $mutation['operation_id'],
            'recoverable'  => $def['recoverable'],
        ] + $extra;
    }

    /**
     * The mutation one snapshotted op performs: its in-process handler, or
     * the dispatch of its route.
     *
     * @param array<string, mixed> $plan
     * @return array{status: int, body: mixed}
     */
    private function run(array $plan): array
    {
        $def     = $plan['def'];
        $handler = self::handler_class($def);
        if (Order_Ops::class === $handler) {
            return Order_Ops::update((int) $plan['target']['object_id'], $plan['body']);
        }
        if (null !== $handler) {
            return $handler::apply((string) $def['handler'], (int) $plan['target']['object_id'], $plan['body']);
        }
        if (null !== $def['handler']) {
            return Brand_Ops::apply_assignment($def['handler'], (int) $plan['target']['object_id'], $plan['body']['brands']);
        }
        return $this->dispatch->send($def['method'], $plan['route'], $plan['body']);
    }

    /**
     * The class a row's in-process handler runs through (issue #292), by the
     * handler's prefix, or null for dispatched rows and brand handlers.
     *
     * @return class-string<Order_Ops>|class-string<Shipping_Ops>|class-string<Webhook_Ops>|class-string<Review_Ops>|class-string<Gateway_Ops>|class-string<Status_Ops>|null
     */
    private static function handler_class(array $def): ?string
    {
        $handler = $def['handler'] ?? null;
        if (! is_string($handler)) {
            return null;
        }
        foreach ([ 'order_' => Order_Ops::class, 'shipping_' => Shipping_Ops::class, 'webhook_' => Webhook_Ops::class, 'review_' => Review_Ops::class, 'gateway_' => Gateway_Ops::class, 'status_' => Status_Ops::class ] as $prefix => $class) {
            if (str_starts_with($handler, $prefix)) {
                return $class;
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed>      $plan
     * @param array{status: int, body: mixed} $out
     */
    private function result(array $plan, array $out): array
    {
        return [
            'op'      => $plan['op'],
            'route'   => $plan['route'],
            'status'  => $out['status'],
            'applied' => $out['status'] >= 200 && $out['status'] < 300,
            'body'    => $out['body'],
        ];
    }

    /**
     * Whether a guarded product op would take variations with it: every
     * delete (trash included, since WooCommerce trashes the variations too),
     * and an update that changes the type away from variable.
     *
     * @param array{mode: string}  $def
     * @param array<string, mixed> $params
     */
    private function variations_at_risk(array $def, array $params, int $product_id): bool
    {
        if ('destructive' !== $def['mode']) {
            if (! array_key_exists('type', $params) || 'variable' === $params['type']) {
                return false;
            }
        }

        $children = get_posts([
            'post_type'      => 'product_variation',
            'post_parent'    => $product_id,
            'post_status'    => 'any',
            'fields'         => 'ids',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
        ]);

        return [] !== $children;
    }

    /**
     * Resolve a row's snapshot strategy into a Safe_Mutation target, or a
     * structured error when it cannot be resolved (nothing is written then).
     *
     * @param array{type: string, param?: string, create?: bool} $strategy
     * @param array<string, mixed>                               $params
     * @param array<string, mixed>                               $body     the body to dispatch (a create's derived slug)
     * @return array<string, mixed>
     */
    private function snapshot_target(array $strategy, array $params, array $body, ?string $taxonomy): array
    {
        if ('term' === $strategy['type']) {
            // Keyed by (taxonomy, slug), as create-term and delete-term are:
            // a create's slug was derived before the write (Brand_Ops), an
            // existing term's is read from the term itself.
            if (! empty($strategy['create'])) {
                $slug = (string) ($body['slug'] ?? '');
            } else {
                $term = get_term((int) ($params[ (string) ($strategy['param'] ?? '') ] ?? 0), (string) $taxonomy);
                $slug = $term instanceof \WP_Term ? (string) $term->slug : '';
            }
            if ('' === $slug || null === $taxonomy) {
                return Op_Guard::error('invalid_params', 'The term this op writes could not be resolved.');
            }
            return [ 'object_type' => 'term', 'object_id' => Snapshot::term_key($taxonomy, $slug) ];
        }

        if (in_array($strategy['type'], [ 'wc_order_create', 'wc_shipping_zone_create', 'wc_webhook_create', 'comment_create' ], true)) {
            // Nothing exists to capture yet; execute() records the creation.
            return [ 'object_type' => $strategy['type'], 'object_id' => 0, 'creation' => true ];
        }

        if ('wc_shipping_zone' === $strategy['type']) {
            // Shipping_Ops already resolved the zone, which may be zone 0
            // ("locations not covered"): it has methods but no zone row.
            return [ 'object_type' => 'wc_shipping_zone', 'object_id' => (int) ($body['zone_id'] ?? 0) ];
        }

        if ('wc_gateway' === $strategy['type']) {
            // Gateway_Ops already resolved the gateway and what the write
            // changes: its settings option, or the gateway order option.
            return Gateway_Ops::snapshot_target($body);
        }

        if ('wc_setting' === $strategy['type']) {
            $option = $this->setting_option((string) ($params['group_id'] ?? ''), (string) ($params['id'] ?? ''));
            if (null === $option) {
                return Op_Guard::error(
                    'unknown_setting',
                    'No such WooCommerce setting in that group. List the group with settings.options first.'
                );
            }
            return [ 'object_type' => 'option', 'object_id' => $option ];
        }

        $param = (string) ($strategy['param'] ?? '');
        $value = $params[ $param ] ?? null;
        $id    = is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : 0;
        if ($id <= 0) {
            return Op_Guard::error('invalid_params', "Path param \"{$param}\" must be a positive integer id.");
        }

        return [ 'object_type' => $strategy['type'], 'object_id' => $id ];
    }

    /**
     * The wp_options row backing one WooCommerce setting, read from the same
     * registry the wc/v3 settings controller writes through
     * (woocommerce_settings-{group} entries and their option_key). An
     * option_key like "woocommerce_foo_settings[enabled]", or an
     * [option, key] pair as email settings use, is one key inside an array
     * option, so the whole array option is what gets snapshotted.
     */
    private function setting_option(string $group, string $id): ?string
    {
        if ('' === $group || '' === $id) {
            return null;
        }

        // The settings registry is populated on rest_api_init.
        rest_get_server();

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- reads WooCommerce's own settings registry, the one the wc/v3 settings controller writes through; wpmcp adds no callback to it.
        $settings = apply_filters('woocommerce_settings-' . $group, []);
        if (! is_array($settings)) {
            return null;
        }

        foreach ($settings as $setting) {
            if (! is_array($setting) || ($setting['id'] ?? null) !== $id) {
                continue;
            }
            // option_key is either a string (possibly "option[key]") or,
            // for email settings, an [option, key] pair; either way the
            // array option as a whole is what the controller rewrites.
            $key = $setting['option_key'] ?? $id;
            if (is_array($key)) {
                $key = $key[0] ?? '';
            }
            if (! is_string($key)) {
                return null;
            }
            $pos = strpos($key, '[');
            $key = false === $pos ? $key : substr($key, 0, $pos);
            return '' === $key ? null : $key;
        }

        return null;
    }
}
