<?php

namespace WPMCP\Tools\WooCommerce\Catalog;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Discovery tool for the deep WooCommerce operations catalog (issue #68):
 * lists every op with its mode (read, write, destructive), method, wc/v3
 * route template, required path params, the capability the dispatcher
 * checks before dispatch, whether it needs confirm:true, whether it is
 * switched on for this site, which snapshot type backs it and whether a
 * rollback can fully undo it, plus a one-line summary, grouped by domain and
 * optionally filtered to one domain.
 * Read-only; touches nothing but the static catalog, so it answers even when
 * WooCommerce is inactive, reporting available:false the way
 * Integration_Dispatcher's reserved list-operations op does.
 *
 * Exists so tools/list stays one entry per dispatcher instead of one entry
 * per store endpoint: an agent lists the catalog once, then drives woo-read
 * and woo-write by op name.
 */
class Woo_Ops
{
    public function handle(array $args): array
    {
        $domain_filter = isset($args['domain']) ? sanitize_key((string) $args['domain']) : '';

        $domains = [];
        foreach (Op_Catalog::ops() as $name => $def) {
            if ('' !== $domain_filter && $def['domain'] !== $domain_filter) {
                continue;
            }
            $domains[$def['domain']][] = [
                'op'               => $name,
                'mode'             => $def['mode'],
                'method'           => $def['method'],
                'route'            => $def['route'],
                'path_params'      => $def['path_params'],
                'capability'       => $def['capability'],
                'requires_confirm' => 'destructive' === $def['mode'],
                'enabled'          => 'read' === $def['mode'] || Woo_Write::is_op_enabled($name, $def),
                'snapshot'         => $def['snapshot']['type'] ?? null,
                'recoverable'      => $def['recoverable'],
                'summary'          => $def['summary'],
            ];
        }

        return [
            'available' => Woo_Read::is_available(),
            'domains'   => $domains,
            'total'     => array_sum(array_map('count', $domains)),
        ];
    }
}
