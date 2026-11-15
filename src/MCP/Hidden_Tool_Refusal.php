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
 * error carrying the code and data. It runs after the request was
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

        if (array_is_list($body) && array_is_list($data)) {
            foreach ($data as $i => $item) {
                if (is_array($body[ $i ] ?? null) && is_array($item)) {
                    $data[ $i ] = self::rewrite($body[ $i ], $item);
                }
            }
        } elseif (! array_is_list($body) && ! array_is_list($data)) {
            $data = self::rewrite($body, $data);
        }
        $response->set_data($data);

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
        if (null === $error) {
            return $answer;
        }

        return [ 'jsonrpc' => '2.0', 'id' => $answer['id'] ?? null, 'result' => self::result($error) ];
    }
}
