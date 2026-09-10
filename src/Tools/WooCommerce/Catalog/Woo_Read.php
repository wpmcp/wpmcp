<?php

namespace WPMCP\Tools\WooCommerce\Catalog;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read dispatcher for the deep WooCommerce operations catalog (issue #68).
 * Resolves a named op through Op_Catalog and dispatches it as an INTERNAL
 * REST request to the store's own wc/v3 route via Wc_Rest_Dispatch:
 * rest_do_request() with a WP_REST_Request, no HTTP loopback, running as the
 * current authenticated user so the endpoint's own permission_callback (and
 * therefore WooCommerce's store permission model) is the final gate.
 *
 * On top of that inherited check this class applies three wpmcp-layer gates
 * BEFORE dispatch, mirroring Integration_Dispatcher's guard chain (which the
 * catalog cannot extend directly: the vertical wpmcp-for-woocommerce build
 * prunes src/Integrations while still registering the woocommerce group):
 *
 *  1. availability: WooCommerce absent returns a structured
 *     integration_unavailable error instead of a bare rest_no_route 404;
 *  2. per-op capability: order, note and refund ops require
 *     edit_shop_orders and customer ops require list_users, so this surface
 *     is never looser than the free tool covering the same data;
 *  3. read-only: the op must exist in the catalog AND its row's method must
 *     be GET. Wc_Rest_Dispatch is GET-only by construction as well, so the
 *     invariant survives a caller that forgets to check.
 *
 * Raw wc/v3 bodies are large: list ops get a conservative per_page default
 * and a hard cap, so a single call cannot flood a model context with a
 * hundred full order or customer records.
 *
 * TODO(#68): companion Woo_Write dispatcher with its OWN mutating dispatch
 *            carrying the confirm gate and the opt-in filter inside it, plus
 *            snapshots where a snapshot type exists and batch support.
 */
class Woo_Read
{
    /** Conservative page size for list ops; raw wc/v3 records are large. */
    public const DEFAULT_PER_PAGE = 20;

    /** Hard ceiling on per_page, below wc/v3's own maximum of 100. */
    public const MAX_PER_PAGE = 50;

    /** @var Wc_Rest_Dispatch */
    private $dispatch;

    public function __construct(?Wc_Rest_Dispatch $dispatch = null)
    {
        $this->dispatch = $dispatch ?: new Wc_Rest_Dispatch();
    }

    public function handle(array $args): array
    {
        $op = (string) ($args['op'] ?? '');
        if ('' === $op) {
            throw new \InvalidArgumentException('An op is required. Call woo-ops for the catalog.');
        }

        $def = Op_Catalog::get($op);
        $this->assert_read_op($op, $def);

        if (! self::is_available()) {
            return self::error(
                'integration_unavailable',
                'WooCommerce is not active on this site, so no store op can be dispatched.'
            );
        }

        if (! current_user_can($def['capability'])) {
            return self::error(
                'operation_denied',
                "Op \"{$op}\" requires the \"{$def['capability']}\" capability.",
                [ 'reason' => 'capability' ]
            );
        }

        $params = isset($args['params']) && is_array($args['params']) ? $args['params'] : [];
        [ $route, $query ] = Op_Catalog::resolve_route($op, $params);

        $out = $this->dispatch->get($route, $this->apply_query_defaults($def, $query));

        return [
            'op'     => $op,
            'route'  => $route,
            'status' => $out['status'],
            'body'   => $out['body'],
        ];
    }

    /** Whether the host plugin is loaded, mirroring Integration_Dispatcher. */
    public static function is_available(): bool
    {
        return class_exists('WooCommerce');
    }

    /**
     * Defense in depth for when write rows join the catalog: this dispatcher
     * refuses anything whose row is not a GET.
     *
     * @param array{method: string, path_params: string[]} $def
     */
    protected function assert_read_op(string $op, array $def): void
    {
        if ('GET' !== ($def['method'] ?? '')) {
            throw new \RuntimeException("Op \"{$op}\" is not a read op and cannot be dispatched by woo-read.");
        }
    }

    /**
     * Collection ops get a bounded per_page; single-resource ops (the ones
     * with an {id}-style path param) take no paging params at all, so none
     * are injected there.
     *
     * @param array{path_params: string[]} $def
     * @param array<string, mixed>         $query
     * @return array<string, mixed>
     */
    protected function apply_query_defaults(array $def, array $query): array
    {
        if ($this->is_single_resource($def)) {
            return $query;
        }

        if (! isset($query['per_page'])) {
            $query['per_page'] = self::DEFAULT_PER_PAGE;
            return $query;
        }

        $per_page = (int) $query['per_page'];
        if ($per_page < 1) {
            $per_page = self::DEFAULT_PER_PAGE;
        }
        $query['per_page'] = min($per_page, self::MAX_PER_PAGE);

        return $query;
    }

    /** @param array{path_params: string[]} $def */
    private function is_single_resource(array $def): bool
    {
        return in_array('id', $def['path_params'] ?? [], true);
    }

    /** @param array<string, mixed> $data */
    private static function error(string $code, string $message, array $data = []): array
    {
        return [
            'integration' => 'woocommerce',
            'error'       => [
                'code'    => $code,
                'message' => $message,
                'data'    => $data,
            ],
        ];
    }
}
