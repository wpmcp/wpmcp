<?php

namespace WPMCP\MCP;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * WP-CLI-invoked stdio MCP transport (issue #77).
 *
 * Several MCP clients only speak stdio, and local development wants a server
 * without any HTTP setup. This class serves the same tool surface as the
 * HTTP transport (WPMCP\MCP\Server) over newline-delimited JSON-RPC on
 * stdin/stdout, started with `wp mcp-stdio serve`.
 *
 * Design mirrors the rest of the plugin: the transport is a thin loop, and
 * every protocol decision lives in a pure, independently testable method
 * (handle_request), so tests exercise the handshake, tools/list and
 * tools/call round-trip without spawning a process. Tool dispatch goes
 * through the live Abilities registry exactly like Server::tool_names(), so
 * governance gating, tier gating and exposure decisions apply unchanged and
 * no new permission surface is introduced: WP-CLI already runs as the site
 * owner, matching the guarded CLI work from issue #44.
 */
class Stdio_Transport
{
    private const JSONRPC = '2.0';
    private const PROTOCOL_VERSION = '2025-03-26';

    /** Registers the WP-CLI command. No-op outside WP-CLI. */
    public static function register(): void
    {
        if (! defined('WP_CLI') || ! WP_CLI || ! class_exists('WP_CLI')) {
            return;
        }

        \WP_CLI::add_command('mcp-stdio serve', [self::class, 'serve']);
    }

    /**
     * Blocking read loop: one JSON-RPC message per line on stdin, one
     * response per line on stdout. Notifications produce no output.
     */
    public static function serve(): void
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $stdin = fopen('php://stdin', 'r');
        if (false === $stdin) {
            return;
        }

        while (false !== ($line = fgets($stdin))) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }

            $request = json_decode($line, true);
            if (! is_array($request)) {
                self::emit(self::error_response(null, -32700, 'Parse error'));
                continue;
            }

            $response = (new self())->handle_request($request);
            if (null !== $response) {
                self::emit($response);
            }
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($stdin);
    }

    /**
     * Dispatches one JSON-RPC request. Returns the response array, or null
     * for notifications (no id). Pure with respect to I/O: the serve() loop
     * owns stdin/stdout so tests can call this directly.
     *
     * @param array<string,mixed> $request Decoded JSON-RPC request.
     * @return array<string,mixed>|null
     */
    public function handle_request(array $request): ?array
    {
        $id     = $request['id'] ?? null;
        $method = isset($request['method']) ? (string) $request['method'] : '';

        if (str_starts_with($method, 'notifications/')) {
            return null;
        }

        switch ($method) {
            case 'initialize':
                return self::result_response($id, $this->initialize_result());
            case 'ping':
                return self::result_response($id, []);
            case 'tools/list':
                return self::result_response($id, [ 'tools' => $this->list_tools() ]);
            case 'tools/call':
                return $this->call_tool($id, is_array($request['params'] ?? null) ? $request['params'] : []);
            default:
                return self::error_response($id, -32601, sprintf('Method not found: %s', $method));
        }
    }

    /** @return array<string,mixed> */
    private function initialize_result(): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities'    => [ 'tools' => [ 'listChanged' => false ] ],
            'serverInfo'      => [
                'name'    => Server::SERVER_ID,
                'version' => defined('WPMCP_VERSION') ? WPMCP_VERSION : '0.0.0',
            ],
            // TODO(#77): reuse Handshake_Instructions so stdio clients get the
            // same instructions text the HTTP handshake serves.
        ];
    }

    /**
     * Tool descriptors for every wpmcp/ ability visible in the live
     * registry, same filtering rule as Server::tool_names().
     *
     * @return array<int,array<string,mixed>>
     */
    private function list_tools(): array
    {
        if (! function_exists('wp_get_abilities')) {
            return [];
        }

        $tools = [];
        foreach (wp_get_abilities() as $key => $ability) {
            $name = is_object($ability) && method_exists($ability, 'get_name')
                ? $ability->get_name()
                : (string) $key;

            if (! str_starts_with($name, 'wpmcp/')) {
                continue;
            }

            $tools[] = [
                // MCP tool names cannot contain '/', match the adapter's mapping.
                'name'        => str_replace('/', '-', $name),
                'description' => is_object($ability) && method_exists($ability, 'get_description')
                    ? (string) $ability->get_description()
                    : '',
                'inputSchema' => is_object($ability) && method_exists($ability, 'get_input_schema')
                    ? (array) $ability->get_input_schema()
                    : [ 'type' => 'object' ],
            ];
        }

        return $tools;
    }

    /**
     * Executes one tool via the Abilities API. Ability-level failures come
     * back as MCP tool errors (isError content), not JSON-RPC faults,
     * matching the HTTP transport's behavior.
     *
     * @param mixed               $id     Request id.
     * @param array<string,mixed> $params tools/call params.
     * @return array<string,mixed>
     */
    private function call_tool($id, array $params): array
    {
        $tool_name = isset($params['name']) ? (string) $params['name'] : '';
        $ability_name = str_replace('-', '/', $tool_name);
        // The first '-' is the namespace separator; the rest belong to the slug.
        if (str_contains($tool_name, '-')) {
            [ $ns, $slug ] = explode('-', $tool_name, 2);
            $ability_name  = $ns . '/' . $slug;
        }

        if (! function_exists('wp_get_ability') || ! str_starts_with($ability_name, 'wpmcp/')) {
            return self::error_response($id, -32602, sprintf('Unknown tool: %s', $tool_name));
        }

        $ability = wp_get_ability($ability_name);
        if (! is_object($ability) || ! method_exists($ability, 'execute')) {
            return self::error_response($id, -32602, sprintf('Unknown tool: %s', $tool_name));
        }

        $input  = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $result = $ability->execute($input);

        if (is_wp_error($result)) {
            return self::result_response($id, [
                'isError' => true,
                'content' => [ [ 'type' => 'text', 'text' => $result->get_error_message() ] ],
            ]);
        }

        return self::result_response($id, [
            'isError' => false,
            'content' => [ [ 'type' => 'text', 'text' => (string) wp_json_encode($result) ] ],
        ]);
    }

    /**
     * @param mixed               $id     Request id.
     * @param array<string,mixed> $result Result payload.
     * @return array<string,mixed>
     */
    private static function result_response($id, array $result): array
    {
        return [ 'jsonrpc' => self::JSONRPC, 'id' => $id, 'result' => $result ];
    }

    /**
     * @param mixed  $id      Request id.
     * @param int    $code    JSON-RPC error code.
     * @param string $message Error message.
     * @return array<string,mixed>
     */
    private static function error_response($id, int $code, string $message): array
    {
        return [
            'jsonrpc' => self::JSONRPC,
            'id'      => $id,
            'error'   => [ 'code' => $code, 'message' => $message ],
        ];
    }

    /** @param array<string,mixed> $response Response to write to stdout. */
    private static function emit(array $response): void
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- machine protocol stream, not HTML.
        echo wp_json_encode($response) . "\n";
    }
}
