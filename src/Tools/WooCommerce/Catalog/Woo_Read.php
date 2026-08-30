<?php

namespace WPMCP\Tools\WooCommerce\Catalog;

use WPMCP\Tools\Rest\Call_Rest;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read dispatcher for the deep WooCommerce operations catalog (issue #68).
 * Resolves a named op through Op_Catalog and dispatches it as an INTERNAL
 * REST request to the store's own wc/v3 route via the inherited Call_Rest
 * dispatch: rest_do_request() with a WP_REST_Request, no HTTP loopback,
 * running as the current authenticated user so the endpoint's own
 * permission_callback (and therefore WooCommerce's store permission model)
 * is the gate. This class adds no access of its own on top of that.
 *
 * Only GET ops are dispatchable here by construction: the op must exist in
 * the catalog, and this class refuses any catalog row whose method is not
 * GET as a defense-in-depth invariant for when write rows are added.
 *
 * TODO(#68): companion Woo_Write dispatcher: confirm gates on destructive
 *            ops, snapshots where a snapshot type exists, batch support.
 */
class Woo_Read extends Call_Rest
{
    public function handle(array $args): array
    {
        $op = (string) ($args['op'] ?? '');
        if ('' === $op) {
            throw new \InvalidArgumentException('An op is required. Call woo-ops for the catalog.');
        }

        $def = Op_Catalog::get($op);
        if ('GET' !== $def['method']) {
            throw new \RuntimeException("Op \"{$op}\" is not a read op and cannot be dispatched by woo-read.");
        }

        $params = isset($args['params']) && is_array($args['params']) ? $args['params'] : [];
        [ $route, $query ] = Op_Catalog::resolve_route($op, $params);

        $out = $this->dispatch('GET', $route, [ 'params' => $query ]);

        return [
            'op'     => $op,
            'route'  => $route,
            'status' => $out['status'],
            'body'   => $out['body'],
        ];
    }
}
