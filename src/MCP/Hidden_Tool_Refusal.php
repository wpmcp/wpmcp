<?php

namespace WPMCP\MCP;

use WPMCP\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * A direct tools/call for a tool that is not registered because no plugin
 * it covers is active (issue #876): the plugin-data pair registers only
 * while a covered plugin is loaded, so the adapter answers "Tool not found"
 * and stdio "Unknown tool". Both are replaced with the refusal that names
 * the plugin to activate (Registrar::plugin_inactive_for_tool), as a tool
 * error carrying the code and data. A declared Pro tool on an unlicensed
 * install is answered the same way with Registrar::pro_required, which
 * carries the upgrade link. It runs after the request was
 * authenticated and dispatched, only rewrites that one not-found answer,
 * and grants nothing: the tool stays unregistered.
 */
class Hidden_Tool_Refusal
{
    public function register(): void
    {
        add_filter('rest_post_dispatch', [ $this, 'filter_response' ], 20, 3);
    }

    /**
     * A tool error result for a refusal: isError, the message as text, and
     * the code, message and data as structuredContent.
     *
     * @return array<string, mixed>
     */
    public static function result(\WP_Error $error): array
    {
        $data = $error->get_error_data();
        unset($data['status']);

        return [
            'isError'           => true,
            'content'           => [ [ 'type' => 'text', 'text' => $error->get_error_message() ] ],
            'structuredContent' => [
                'error' => [
                    'code'    => $error->get_error_code(),
                    'message' => $error->get_error_message(),
                    'data'    => is_array($data) ? $data : [],
                ],
            ],
        ];
    }

    /**
     * @param mixed $response
     * @param mixed $server
     * @param mixed $request
     * @return mixed
     */
    public function filter_response($response, $server, $request)
    {
        if (! $response instanceof \WP_REST_Response || ! $request instanceof \WP_REST_Request) {
            return $response;
        }
        if (! Transport_Guard::is_mcp_route((string) $request->get_route()) || 'POST' !== $request->get_method()) {
            return $response;
        }
        $body = $request->get_json_params();
        $data = $response->get_data();
        if (! is_array($body) || ! is_array($data)) {
            return $response;
        }
        $original = $data;

        if (array_is_list($body) && array_is_list($data)) {
            // The adapter drops notification answers, so match by JSON-RPC id, not position.
            $by_id = [];
            foreach ($body as $message) {
                if (is_array($message) && isset($message['id']) && (is_int($message['id']) || is_string($message['id']))) {
                    $by_id[ $message['id'] ] = $message;
                }
            }
            foreach ($data as $i => $item) {
                $id = is_array($item) ? ($item['id'] ?? null) : null;
                if ((is_int($id) || is_string($id)) && isset($by_id[ $id ])) {
                    $data[ $i ] = self::rewrite($by_id[ $id ], $item);
                }
            }
        } elseif (! array_is_list($body) && ! array_is_list($data)) {
            $data = self::rewrite($body, $data);
        }
        if ($data === $original) {
            return $response;
        }
        $response->set_data($data);
        // The adapter answers an unknown tool with HTTP 404, and a
        // Streamable HTTP client reads a 404 on POST as an expired session
        // and starts over instead of showing the refusal. A rewritten answer
        // is a normal tool result, so it goes out as 200.
        $response->set_status(200);

        return $response;
    }

    /**
     * @param array<string, mixed> $message  One JSON-RPC request.
     * @param array<string, mixed> $answer   Its JSON-RPC answer.
     * @return array<string, mixed>
     */
    private static function rewrite(array $message, array $answer): array
    {
        if ('tools/call' !== ($message['method'] ?? null) || -32003 !== ($answer['error']['code'] ?? null)) {
            return $answer;
        }
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];
        $name   = is_string($params['name'] ?? null) ? trim($params['name']) : '';
        if ('' === $name) {
            return $answer;
        }
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $error     = Plugin::instance()->registrar()->plugin_inactive_for_tool($name, $arguments);
        // A declared Pro tool on an unlicensed install (issue #631) gets the
        // refusal call-tool and stdio give, upgrade link included, rather
        // than the adapter's "Tool not found".
        $error ??= self::pro_refusal($name);
        if (null === $error) {
            return $answer;
        }

        return [ 'jsonrpc' => '2.0', 'id' => $answer['id'] ?? null, 'result' => self::result($error) ];
    }

    /**
     * The pro_required refusal for an advertised tool name that maps to a
     * declared Pro ability this install did not register, or null.
     */
    private static function pro_refusal(string $tool_name): ?\WP_Error
    {
        $plugin = Plugin::instance();
        foreach ($plugin->declared_abilities() as $ability) {
            if (Stdio_Transport::tool_name($ability->name) === $tool_name) {
                return $plugin->registrar()->pro_required($ability->name);
            }
        }
        return null;
    }
}
