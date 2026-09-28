<?php

namespace WPMCP\Tools\WooCommerce\Catalog;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * MUTATING in-process dispatch of a wc/v3 route, used only by Woo_Write.
 *
 * Kept apart from Wc_Rest_Dispatch on purpose: that class is GET-only by
 * construction so a reader can never reach a mutating route, and this one
 * refuses GET so the two cannot be confused. It carries no gates of its own
 * because Woo_Write is its single caller and runs every gate (governance,
 * capability, opt-in, confirm, snapshot) before calling it.
 *
 * Like the read dispatch, rest_do_request() runs the target endpoint's own
 * permission_callback against the CURRENT user exactly as a real HTTP
 * request would, in-process with no HTTP loopback. Nothing here grants,
 * bypasses or widens access.
 */
class Wc_Rest_Write_Dispatch
{
    private const METHODS = ['POST', 'PUT', 'DELETE'];

    /**
     * @param array<string, mixed> $params body params for POST/PUT, query
     *                                     params for DELETE (force, reassign)
     * @return array{status: int, body: mixed}
     */
    public function send(string $method, string $route, array $params): array
    {
        $method = strtoupper($method);
        if (! in_array($method, self::METHODS, true)) {
            throw new \InvalidArgumentException('The write dispatch only sends POST, PUT or DELETE.');
        }

        $request = new \WP_REST_Request($method, $route);
        if (! empty($params)) {
            if ('DELETE' === $method) {
                $request->set_query_params($params);
            } else {
                $request->set_body_params($params);
            }
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
