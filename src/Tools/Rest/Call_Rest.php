<?php

namespace WPMCP\Tools\Rest;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Perform an INTERNAL WP REST API request via rest_do_request(new
 * WP_REST_Request($method, $route)), and return its HTTP status and body.
 *
 * Authorization is inherited from the REST API itself: because this goes
 * through rest_do_request(), the target endpoint's own permission_callback
 * runs against the CURRENT user exactly as it would for a real HTTP request
 * to that route. This tool does not grant, bypass, or widen access; a caller
 * can reach only what the endpoint's own permission_callback already allows
 * the current user to reach. That inherited check is the safety property
 * this tool relies on, not anything enforced here.
 *
 * GET and HEAD requests (reads) are always allowed through to
 * rest_do_request(); the endpoint's permission_callback is the only gate.
 */
class Call_Rest
{
    private const READ_METHODS = ['GET', 'HEAD'];

    public function handle(array $args): array
    {
        $method = strtoupper((string) ($args['method'] ?? 'GET'));
        $route  = isset($args['route']) ? (string) $args['route'] : '';

        if ('' === $route) {
            throw new \InvalidArgumentException('A route is required.');
        }
        if ('/' !== substr($route, 0, 1)) {
            throw new \InvalidArgumentException('The route must start with a leading slash, e.g. /wp/v2/posts.');
        }

        if (! in_array($method, self::READ_METHODS, true)) {
            throw new \InvalidArgumentException("Unsupported method \"{$method}\".");
        }

        return $this->dispatch($method, $route, $args);
    }

    /**
     * Build and dispatch the WP_REST_Request and reduce the resulting
     * WP_REST_Response to a plain status + body array. rest_do_request()
     * always returns a WP_REST_Response (WP_Error results are converted to
     * one internally), so no is_wp_error() branch is needed here.
     */
    protected function dispatch(string $method, string $route, array $args): array
    {
        $request = new \WP_REST_Request($method, $route);

        $params = isset($args['params']) && is_array($args['params']) ? $args['params'] : [];
        if (! empty($params)) {
            if (in_array($method, self::READ_METHODS, true)) {
                $request->set_query_params($params);
            } else {
                $request->set_body_params($params);
            }
        }

        $response = rest_do_request($request);

        return [
            'status' => $response->get_status(),
            'body'   => $response->get_data(),
        ];
    }
}
