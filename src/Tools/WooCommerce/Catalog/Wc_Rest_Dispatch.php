<?php

namespace WPMCP\Tools\WooCommerce\Catalog;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * READ-ONLY in-process dispatch of a wc/v3 route.
 *
 * Deliberately self-contained rather than reusing Call_Rest: the vertical
 * wpmcp-for-woocommerce build prunes src/Tools/Rest from the zip while still
 * registering the woocommerce ability group, so anything the catalog reaches
 * for must live inside a tree that build keeps. It is also a collaborator
 * rather than a base class, matching how the rest of src/Tools composes
 * helpers (SEO_Adapter, Redirect_Store) instead of extending sibling tools.
 *
 * Authorization is inherited from the REST API itself: rest_do_request() runs
 * the target endpoint's own permission_callback against the CURRENT user
 * exactly as a real HTTP request would. Nothing here grants, bypasses or
 * widens access.
 *
 * The GET-only refusal is an invariant of THIS class, not of its callers, so
 * a future Woo_Write cannot reach a mutating wc/v3 route by composing it and
 * quietly skipping a caller-side gate. A write path needs its own dispatcher
 * carrying its own confirm and snapshot posture.
 */
final class Wc_Rest_Dispatch
{
    /**
     * @param array<string, mixed> $query
     * @return array{status: int, body: mixed}
     */
    public function get(string $route, array $query = []): array
    {
        $request = new \WP_REST_Request('GET', $route);
        if (! empty($query)) {
            $request->set_query_params($query);
        }

        // rest_do_request() always returns a WP_REST_Response (WP_Error
        // results are converted internally), so no is_wp_error() branch.
        $response = rest_do_request($request);

        return [
            'status' => (int) $response->get_status(),
            'body'   => $response->get_data(),
        ];
    }
}
