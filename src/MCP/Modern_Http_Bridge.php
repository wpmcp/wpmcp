<?php

namespace WPMCP\MCP;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * MCP 2026-07-28 on the HTTP route, whichever adapter copy mounts it
 * (issue #386).
 *
 * The route belongs to the WordPress MCP adapter. Adapter 0.7.0 and later
 * implement 2026-07-28 themselves; the 0.6.x copy WP MCP bundles predates
 * it and would answer a sessionless 2026 request with a missing-session
 * error. So on this one route:
 *
 *  - server/discover is always answered here, on every adapter version, so
 *    it carries the same handshake instructions and revision list as the
 *    stdio transport (the adapter's own discovery result has no filter and
 *    would advertise the server description instead);
 *  - other 2026-07-28 requests are answered here only when the loaded
 *    adapter does not speak that revision, and left to the adapter when it
 *    does;
 *  - a request naming a revision nobody serves gets the -32022 error with
 *    the supported list, which is what 0.7.0 answers too;
 *  - every 2025-11-25 request passes through to the adapter untouched.
 *
 * It hooks rest_dispatch_request, which core applies after the route's
 * permission_callback has passed, so the adapter's transport permission
 * check (and everything WP MCP layers on it) still decides who gets in.
 * The answer itself comes from the stdio transport's dispatcher, the one
 * implementation of the tool, prompt and resource methods outside the
 * adapter, so the two transports cannot drift.
 */
final class Modern_Http_Bridge
{
    /** Methods whose target the Mcp-Name header must repeat, and the param holding it. */
    private const NAMED = [
        'tools/call'     => 'name',
        'prompts/get'    => 'name',
        'resources/read' => 'uri',
    ];

    public static function register(): void
    {
        add_filter('rest_dispatch_request', [self::class, 'dispatch'], 10, 4);
    }

    /**
     * @param mixed $result  Null unless an earlier callback already answered.
     * @param mixed $request The WP_REST_Request being dispatched.
     * @param mixed $route   The matched route.
     * @param mixed $handler The matched handler (unused).
     * @return mixed A WP_REST_Response when this bridge answers, else $result.
     */
    public static function dispatch($result, $request, $route = '', $handler = null)
    {
        if (
            null !== $result
            || ! $request instanceof \WP_REST_Request
            || '/' . Server::NAMESPACE . '/' . Server::SERVER_ID !== $route
            || 'POST' !== $request->get_method()
        ) {
            return $result;
        }

        $message = json_decode((string) $request->get_body(), true);
        if (
            ! is_array($message)
            || array_is_list($message)
            || ! isset($message['method'])
            || ! is_string($message['method'])
        ) {
            // Batches and malformed bodies are the adapter's to reject.
            return $result;
        }

        $method    = $message['method'];
        $params    = is_array($message['params'] ?? null) ? $message['params'] : [];
        $body      = Protocol_Revision::requested($params);
        $header    = $request->get_header('mcp_protocol_version');
        $header    = null === $header ? null : trim((string) $header);
        $is_modern = Protocol_Revision::MODERN === $body || Protocol_Revision::MODERN === $header;
        $named     = $body ?? $header;

        // tasks/* belong to the Tasks extension this plugin implements on
        // its own job stores (issue #387), so they are answered here even
        // when the adapter speaks 2026-07-28 itself.
        $ours = 'server/discover' === $method
            || ($is_modern && str_starts_with($method, 'tasks/'))
            || ($is_modern && ! Protocol_Revision::adapter_speaks_modern())
            || (! $is_modern && null !== $named && ! Protocol_Revision::is_servable($named) && ! Protocol_Revision::adapter_speaks_modern());
        if (! $ours) {
            return $result;
        }

        $id = $message['id'] ?? null;

        if (! $is_modern && null !== $named && ! Protocol_Revision::is_servable($named)) {
            return self::respond(Protocol_Revision::unsupported_version_error($id, $named), $is_modern);
        }

        if ($is_modern) {
            $mismatch = self::header_mismatch($request, $method, $params, $body, $header);
            if (null !== $mismatch) {
                return self::respond(self::error($id, Protocol_Revision::HEADER_MISMATCH, $mismatch), true);
            }
        }

        if (! array_key_exists('id', $message)) {
            // 2026-07-28 defines no client notifications this server acts on.
            return new \WP_REST_Response(null, 202);
        }

        $response = (new Stdio_Transport())->handle_request($message);
        if (null === $response) {
            return new \WP_REST_Response(null, 202);
        }

        return self::respond($response, $is_modern || 'server/discover' === $method);
    }

    /**
     * The HTTP rules 2026-07-28 adds: MCP-Protocol-Version must be present
     * and agree with the body, Mcp-Method must repeat the method, and
     * Mcp-Name the tool, prompt or resource a named method targets. Returns
     * the reason for the first mismatch, or null when the headers agree.
     *
     * @param array<string,mixed> $params Request params.
     */
    private static function header_mismatch(\WP_REST_Request $request, string $method, array $params, ?string $body, ?string $header): ?string
    {
        if (Protocol_Revision::MODERN !== $header) {
            return 'MCP-Protocol-Version is missing or does not match the request metadata';
        }
        if (null !== $body && $body !== $header) {
            return 'MCP-Protocol-Version does not match the request body';
        }
        if (trim((string) $request->get_header('mcp_method')) !== $method) {
            return 'Mcp-Method is missing or does not match the request body';
        }

        // Compared as sent. The revision lets clients base64-wrap a
        // non-ASCII name; every name this server publishes (tool names,
        // skill slugs, wpmcp:// URIs) is ASCII, so a wrapped value can only
        // be a name that does not exist here.
        if (isset(self::NAMED[ $method ])) {
            $named = trim((string) $request->get_header('mcp_name'));
            if ('' === $named || ($params[ self::NAMED[ $method ] ] ?? null) !== $named) {
                return 'Mcp-Name is missing or does not match the request body';
            }
        }

        return null;
    }

    /**
     * @param mixed $id Request id.
     * @return array<string,mixed>
     */
    private static function error($id, int $code, string $message): array
    {
        return [ 'jsonrpc' => '2.0', 'id' => $id, 'error' => [ 'code' => $code, 'message' => $message ] ];
    }

    /**
     * Wraps a JSON-RPC response with the HTTP status the adapter uses for
     * the same error, where 2026-07-28 also turns invalid params into 400.
     *
     * @param array<string,mixed> $response JSON-RPC response.
     */
    private static function respond(array $response, bool $modern): \WP_REST_Response
    {
        $code = isset($response['error']['code']) ? (int) $response['error']['code'] : 0;

        switch ($code) {
            case 0:
                $status = 200;
                break;
            case -32700:
            case Protocol_Revision::INVALID_REQUEST:
            case Protocol_Revision::HEADER_MISMATCH:
            case -32021:
            case Protocol_Revision::UNSUPPORTED_VERSION:
                $status = 400;
                break;
            case -32010:
                $status = 401;
                break;
            case -32008:
                $status = 403;
                break;
            case Protocol_Revision::METHOD_NOT_FOUND:
            case Protocol_Revision::RESOURCE_NOT_FOUND:
                $status = 404;
                break;
            case -32603:
            case -32000:
                $status = 500;
                break;
            case Protocol_Revision::INVALID_PARAMS:
                $status = $modern ? 400 : 200;
                break;
            default:
                $status = 200;
        }

        return new \WP_REST_Response($response, $status);
    }
}
