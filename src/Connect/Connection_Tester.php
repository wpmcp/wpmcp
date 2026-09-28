<?php

namespace WPMCP\Connect;

use WPMCP\MCP\Transport_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Server-side connection self-test (issue #76): POST an MCP initialize
 * request to this site's own MCP endpoint and classify the outcome. Runs
 * without credentials on purpose - the question it answers is "is the
 * endpoint mounted and answering?", so 401/403 count as reachable (bring
 * credentials), 404 means the adapter route is missing, and a transport
 * error means the site cannot loop back to itself (common on hosts that
 * block loopback HTTP; the endpoint may still work from outside).
 *
 * Extended in issue #133 to inspect the transport, not just the status
 * line. The three failure modes Transport_Guard exists to prevent are all
 * invisible in a status code and all present to the user as the same
 * "connector stopped responding": a caching layer that strips or ignores
 * our no-store headers, a proxy that buffers the response, and stray PHP
 * output that corrupts the JSON body. Each is now an explicit named check
 * with its own pass/fail and remediation text, so an admin can see which
 * one their host is doing before an agent ever connects. A 421 is
 * classified as the site-URL mismatch it is.
 *
 * Every check is advisory except reachability: `ok` still tracks "is the
 * endpoint answering", because a site behind an aggressive proxy is
 * misconfigured, not broken, and we should not tell the owner their
 * install has failed when it has not.
 */
class Connection_Tester
{
    /** @return array{ok: bool, status: int|null, message: string, checks: array<int, array{id: string, label: string, ok: bool, detail: string}>} */
    public function test(): array
    {
        $response = wp_remote_post(Client_Config_Generator::endpoint(), [
            'timeout' => 5,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json, text/event-stream',
            ],
            'body'    => (string) wp_json_encode([
                'jsonrpc' => '2.0',
                'id'      => 1,
                'method'  => 'initialize',
                'params'  => [
                    'protocolVersion' => '2025-06-18',
                    'capabilities'    => new \stdClass(),
                    'clientInfo'      => [
                        'name'    => 'wpmcp-self-test',
                        'version' => defined('WPMCP_VERSION') ? WPMCP_VERSION : '0.0.0',
                    ],
                ],
            ]),
        ]);

        if (is_wp_error($response)) {
            return [
                'ok'      => false,
                'status'  => null,
                'checks'  => [],
                'message' => sprintf(
                    /* translators: %s: transport error message. */
                    __('The MCP endpoint could not be reached from this server: %s. Loopback requests may be blocked on this host; the endpoint may still be reachable from your machine.', 'wpmcp'),
                    $response->get_error_message()
                ),
            ];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $checks = $this->transport_checks($response);

        if (404 === $status) {
            return [
                'ok'      => false,
                'status'  => 404,
                'checks'  => $checks,
                'message' => __('The MCP endpoint answered 404, the MCP adapter route is not mounted. Check that the Abilities API / MCP adapter is active on this WordPress version.', 'wpmcp'),
            ];
        }

        if (421 === $status) {
            return [
                'ok'      => false,
                'status'  => 421,
                'checks'  => $checks,
                'message' => __('The MCP endpoint answered 421, the site-URL mismatch guard rejected the request: the host this server used to reach itself is not the host in Settings > General. Fix the site address, or disable the guard with the wpmcp_host_guard_enabled filter if a reverse proxy rewrites Host on purpose.', 'wpmcp'),
            ];
        }

        if (in_array($status, [401, 403], true)) {
            return [
                'ok'      => true,
                'status'  => $status,
                'checks'  => $checks,
                'message' => sprintf(
                    /* translators: %d: HTTP status code. */
                    __('The MCP endpoint is up (HTTP %d without credentials). Connect with an Application Password and this becomes a session.', 'wpmcp'),
                    $status
                ),
            ];
        }

        return [
            'ok'      => true,
            'status'  => $status,
            'checks'  => $checks,
            'message' => sprintf(
                /* translators: %d: HTTP status code. */
                __('The MCP endpoint answered (HTTP %d).', 'wpmcp'),
                $status
            ),
        ];
    }

    /**
     * The transport-level checks run against a real HTTP answer.
     *
     * @param array|\WP_Error $response A wp_remote_post() response.
     * @return array<int, array{id: string, label: string, ok: bool, detail: string}>
     */
    private function transport_checks($response): array
    {
        $cache_control = strtolower((string) wp_remote_retrieve_header($response, 'cache-control'));
        $buffering     = strtolower((string) wp_remote_retrieve_header($response, 'x-accel-buffering'));
        $body          = (string) wp_remote_retrieve_body($response);

        $checks = [];

        $checks[] = [
            'id'     => 'no_store',
            'label'  => __('Responses are marked no-store', 'wpmcp'),
            'ok'     => str_contains($cache_control, 'no-store'),
            'detail' => str_contains($cache_control, 'no-store')
                ? __('Cache-Control: no-store is present, so proxies and page caches will not replay an old MCP response.', 'wpmcp')
                : __('Cache-Control: no-store did not survive to the client. A caching layer in front of WordPress is rewriting or dropping it; exclude the MCP endpoint from that cache.', 'wpmcp'),
        ];

        $checks[] = [
            'id'     => 'buffering',
            'label'  => __('Response buffering is disabled', 'wpmcp'),
            'ok'     => 'no' === $buffering,
            'detail' => 'no' === $buffering
                ? __('X-Accel-Buffering: no is present, so a streaming MCP response is not held back by the proxy.', 'wpmcp')
                : __('X-Accel-Buffering: no did not survive to the client. Streaming responses may be buffered by nginx or a similar proxy, which looks to a client like a hung connector.', 'wpmcp'),
        ];

        $checks[] = $this->json_framing_check($body);

        return $checks;
    }

    /**
     * Whether the body is clean JSON. A body that does not parse, or that
     * has content before the opening brace, is the signature of stray PHP
     * output (a notice, a warning, a _doing_it_wrong() block) landing in
     * the response - the exact corruption Transport_Guard suppresses, and
     * the one a client reports as a dropped connection rather than a parse
     * error. An empty body is not evidence of anything either way.
     *
     * @return array{id: string, label: string, ok: bool, detail: string}
     */
    private function json_framing_check(string $body): array
    {
        $trimmed = trim($body);

        if ('' === $trimmed) {
            return [
                'id'     => 'json_framing',
                'label'  => __('Response body is clean JSON', 'wpmcp'),
                'ok'     => true,
                'detail' => __('The endpoint returned no body to inspect (expected without credentials).', 'wpmcp'),
            ];
        }

        $clean = null !== json_decode($trimmed, true) && JSON_ERROR_NONE === json_last_error();

        return [
            'id'     => 'json_framing',
            'label'  => __('Response body is clean JSON', 'wpmcp'),
            'ok'     => $clean,
            'detail' => $clean
                ? __('The response body parsed as JSON with nothing prepended to it.', 'wpmcp')
                : __('The response body is not valid JSON. Something on this site is printing to the response (a PHP notice, a warning, or plugin output), which corrupts JSON-RPC framing and makes clients drop the connection. Check the PHP error log.', 'wpmcp'),
        ];
    }

    /**
     * The header set the guard is expected to emit, exposed so the admin
     * screen and tests read the same source of truth as the guard itself.
     *
     * @return array<string, string>
     */
    public static function expected_headers(): array
    {
        return Transport_Guard::no_store_headers();
    }
}
