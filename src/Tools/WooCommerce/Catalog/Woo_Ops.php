<?php

namespace WPMCP\Tools\WooCommerce\Catalog;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Discovery tool for the deep WooCommerce operations catalog (issue #68):
 * lists every op with its method, wc/v3 route template, required path
 * params, and one-line summary, grouped by domain and optionally filtered
 * to one domain. Read-only; touches nothing but the static catalog, so it
 * is safe to call before WooCommerce checks even matter.
 *
 * Exists so tools/list stays one entry per dispatcher instead of one entry
 * per store endpoint: an agent lists the catalog once, then drives woo-read
 * by op name.
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
                'op'          => $name,
                'method'      => $def['method'],
                'route'       => $def['route'],
                'path_params' => $def['path_params'],
                'summary'     => $def['summary'],
            ];
        }

        return [
            'domains' => $domains,
            'total'   => array_sum(array_map('count', $domains)),
        ];
    }
}
