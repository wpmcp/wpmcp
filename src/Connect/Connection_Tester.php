<?php

namespace WPMCP\Connect;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Server-side connection self-test (issue #76): POST an MCP initialize
 * request to this site's own MCP endpoint and classify the outcome. Runs
 * without credentials on purpose — the question it answers is "is the
 * endpoint mounted and answering?", so 401/403 count as reachable (bring
 * credentials), 404 means the adapter route is missing, and a transport
 * error means the site cannot loop back to itself (common on hosts that
 * block loopback HTTP; the endpoint may still work from outside).
 */
class Connection_Tester
{
    /** @return array{ok: bool, status: int|null, message: string} */
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
                'message' => sprintf(
                    /* translators: %s: transport error message. */
                    __('The MCP endpoint could not be reached from this server: %s. Loopback requests may be blocked on this host; the endpoint may still be reachable from your machine.', 'wpmcp'),
                    $response->get_error_message()
                ),
            ];
        }

        $status = (int) wp_remote_retrieve_response_code($response);

        if (404 === $status) {
            return [
                'ok'      => false,
                'status'  => 404,
                'message' => __('The MCP endpoint answered 404 — the MCP adapter route is not mounted. Check that the Abilities API / MCP adapter is active on this WordPress version.', 'wpmcp'),
            ];
        }

        if (in_array($status, [401, 403], true)) {
            return [
                'ok'      => true,
                'status'  => $status,
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
            'message' => sprintf(
                /* translators: %d: HTTP status code. */
                __('The MCP endpoint answered (HTTP %d).', 'wpmcp'),
                $status
            ),
        ];
    }
}
