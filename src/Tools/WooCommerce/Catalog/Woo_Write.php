<?php

namespace WPMCP\Tools\WooCommerce\Catalog;

use WPMCP\Safety\Safe_Mutation;

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
 *   op is a write or destructive row -> WooCommerce available ->
 *   op-level governance -> per-op capability -> opt-in (destructive ops are
 *   off until the wpmcp_woo_op_enabled filter enables them) -> confirm:true
 *   for destructive ops -> forbidden params -> path params -> snapshot
 *   target resolves -> Safe_Mutation (snapshot first) -> dispatch.
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
            $session_id = 'woo-batch-' . wp_generate_uuid4();
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
            return Op_Guard::error(
                'confirmation_required',
                "Op \"{$op}\" is destructive and requires confirm:true."
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

        $target = null;
        if (null !== $def['snapshot']) {
            $target = $this->snapshot_target($def['snapshot'], $params);
            if (isset($target['error'])) {
                return $target;
            }
        }

        return [
            'op'     => $op,
            'def'    => $def,
            'route'  => $route,
            'body'   => $body,
            'target' => $target,
        ];
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function execute(array $plan, string $session_id): array
    {
        $def = $plan['def'];

        if (null === $plan['target']) {
            $out = $this->dispatch->send($def['method'], $plan['route'], $plan['body']);
            return $this->result($plan, $out) + [
                'recoverable' => false,
                'undo_op'     => $def['undo_op'],
            ];
        }

        $mutation = Safe_Mutation::run(
            [
                'object_type' => $plan['target']['object_type'],
                'object_id'   => $plan['target']['object_id'],
                'session_id'  => $session_id,
                'tool_name'   => 'woo-write',
                'args'        => [ 'op' => $plan['op'], 'route' => $plan['route'], 'params' => $plan['body'] ],
            ],
            fn () => $this->dispatch->send($def['method'], $plan['route'], $plan['body'])
        );

        return $this->result($plan, $mutation['result']) + [
            'operation_id' => $mutation['operation_id'],
            'recoverable'  => $def['recoverable'],
        ];
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
     * Resolve a row's snapshot strategy into a Safe_Mutation target, or a
     * structured error when it cannot be resolved (nothing is written then).
     *
     * @param array{type: string, param?: string} $strategy
     * @param array<string, mixed>                $params
     * @return array<string, mixed>
     */
    private function snapshot_target(array $strategy, array $params): array
    {
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
     * option_key like "woocommerce_foo_settings[enabled]" is one key inside
     * an array option, so the whole array option is what gets snapshotted.
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
            $key = (string) ($setting['option_key'] ?? $id);
            $pos = strpos($key, '[');
            $key = false === $pos ? $key : substr($key, 0, $pos);
            return '' === $key ? null : $key;
        }

        return null;
    }
}
