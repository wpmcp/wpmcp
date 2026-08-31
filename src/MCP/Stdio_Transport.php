<?php

namespace WPMCP\MCP;

use WPMCP\Memory\Memory_Store;
use WPMCP\Safety\Operation_Context;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * WP-CLI-invoked stdio MCP transport (issue #77).
 *
 * Several MCP clients only speak stdio, and local development wants a server
 * without any HTTP setup. This class serves the same tool surface as the
 * HTTP transport (WPMCP\MCP\Server) over newline-delimited JSON-RPC on
 * stdin/stdout, started with `wp mcp-stdio serve --user=<login|id>`.
 *
 * Design mirrors the rest of the plugin: the transport is a thin loop, and
 * every protocol decision lives in a pure, independently testable method
 * (handle_request), so tests exercise the handshake, tools/list and the
 * tools/call round-trip without spawning a process.
 *
 * Three things this transport must get right, because none of them are free:
 *
 *  1. USER CONTEXT. WP-CLI runs as user 0 unless the global --user flag is
 *     given, and every wpmcp ability's permission_callback
 *     (Registrar::is_permitted) starts with current_user_can(). Without a
 *     user, every tools/call would be denied, so serve() refuses to start
 *     rather than serving a surface where nothing works. (This is the
 *     opposite direction of travel from issue #44, which is MCP -> wp-cli
 *     and default-OFF; here wp-cli is the client, so the operator's own
 *     --user choice is the whole trust decision.)
 *  2. EXPOSURE PARITY. Compact mode (issue #79) is applied by
 *     Tool_Exposure on the adapter's mcp_adapter_tools_list filter, which
 *     the adapter's HTTP pipeline fires and this transport does not. The
 *     tool list is therefore run through Tool_Exposure directly, and the
 *     handshake reuses Handshake_Instructions, so both transports advertise
 *     the same surface and the same guidance. Tool results go through
 *     Structured_Result::normalize() for the same reason.
 *  3. FRAMING. Newline-delimited JSON-RPC is destroyed by a single PHP
 *     notice on stdout, which is exactly failure mode 2 in Transport_Guard
 *     (issue #133), so the display channel is suppressed before the first
 *     read and protocol output is written with fwrite() rather than echo.
 *
 * The loop is long-lived, which per-request state does not expect, so
 * Operation_Context and the memoized reads behind the permission chain are
 * reset between messages (see reset_per_message_state). What that does and
 * does not buy is worth stating precisely, because the obvious claim is
 * wrong: abilities are registered once, at process start, and
 * Registrar::register() skips governance-disabled ones at registration
 * time, so the ADVERTISED tool list is fixed for the life of the process
 * (which is why capabilities advertise listChanged:false). What is
 * re-evaluated per message is everything inside the call path: compact-mode
 * exposure, the Governance and project-memory gates, capability checks and
 * the rate-limit budget. A mid-process revocation therefore still denies at
 * execute, which is the half that matters for safety.
 */
class Stdio_Transport
{
    private const JSONRPC = '2.0';

    /**
     * Protocol version used when the MCP Adapter (which owns negotiation)
     * is not loaded. Kept equal to the newest version the adapter's
     * McpVersionNegotiator supports so both transports agree.
     */
    public const FALLBACK_PROTOCOL_VERSION = '2025-11-25';

    /** The adapter class that owns protocol-version negotiation. */
    private const NEGOTIATOR = '\\WP\\MCP\\Core\\McpVersionNegotiator';

    /** Registers the WP-CLI command. No-op outside WP-CLI. */
    public static function register(): void
    {
        if (! defined('WP_CLI') || ! WP_CLI || ! class_exists('WP_CLI')) {
            return;
        }

        \WP_CLI::add_command('mcp-stdio serve', [self::class, 'serve'], [
            'shortdesc' => 'Serves MCP over stdio. Requires the global --user flag, e.g. '
                . 'wp mcp-stdio serve --user=admin',
        ]);
    }

    /**
     * Blocking read loop: one JSON-RPC message per line on stdin, one
     * response per line on stdout. Notifications produce no output.
     */
    public static function serve(): void
    {
        Transport_Guard::suppress_error_display();

        $context_error = self::user_context_error();
        if (null !== $context_error) {
            // register() only ever wires this as a WP-CLI callback, and
            // WP_CLI::error() halts, so there is no second exit path to
            // write here.
            \WP_CLI::error($context_error);
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- stdio transport: the protocol stream IS php://stdin. WP_Filesystem has no equivalent and would not be a filesystem operation here.
        $stdin = fopen('php://stdin', 'r');
        if (false === $stdin) {
            return;
        }

        $transport = new self();

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

            // A throwing tool costs one HTTP request on the other
            // transport; here it would cost the whole session, and the
            // client would block forever on a request id that never gets
            // an answer. WP_Ability::execute() does not catch Throwable on
            // the WordPress versions this plugin targets, and
            // Registrar::throttled() re-throws deliberately, so the loop
            // owns the last line of defence.
            try {
                $response = $transport->handle_request($request);
            } catch (\Throwable $e) {
                $response = self::error_response(
                    $request['id'] ?? null,
                    -32603,
                    'Internal error: ' . $e->getMessage()
                );
            }

            if (null !== $response) {
                self::emit($response);
            }

            self::reset_per_message_state();
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pairs with the fopen above; closing php://stdin is not a filesystem operation.
        fclose($stdin);
    }

    /**
     * Why the process must not start, or null when the context is usable.
     *
     * Separated from serve() so the requirement is testable without a
     * process: WP-CLI is user 0 unless --user is given, and user 0 fails
     * every ability's capability check.
     */
    public static function user_context_error(): ?string
    {
        if (get_current_user_id() > 0) {
            return null;
        }

        return 'wp mcp-stdio serve needs a user context: WP-CLI runs as no user by default, so every '
            . 'tool call would be denied by its capability check. Re-run with the global --user flag, '
            . 'e.g. wp mcp-stdio serve --user=admin';
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
        $method = isset($request['method']) ? (string) $request['method'] : '';

        // JSON-RPC 2.0 defines a notification as a request with no id at
        // all, and that is the whole rule. Adding "or the method starts
        // with notifications/" would silently drop an id-bearing request of
        // such a method, leaving a synchronous client blocked forever on a
        // response that is never written.
        if (! array_key_exists('id', $request)) {
            return null;
        }

        $id     = $request['id'];
        $params = is_array($request['params'] ?? null) ? $request['params'] : [];

        switch ($method) {
            case 'initialize':
                return self::result_response($id, $this->initialize_result($params));
            case 'ping':
                // stdClass, not []: an empty PHP array serializes as a JSON
                // array and the MCP spec requires an object here. Same trap
                // Structured_Result exists to prevent three lines below.
                return self::result_response($id, new \stdClass());
            case 'tools/list':
                return self::result_response($id, [ 'tools' => $this->list_tools() ]);
            case 'tools/call':
                return $this->call_tool($id, $params);
            default:
                return self::error_response($id, -32601, sprintf('Method not found: %s', $method));
        }
    }

    /**
     * @param array<string,mixed> $params initialize params.
     * @return array<string,mixed>
     */
    private function initialize_result(array $params): array
    {
        $result = [
            'protocolVersion' => self::negotiate_protocol_version($params),
            'capabilities'    => [ 'tools' => [ 'listChanged' => false ] ],
            'serverInfo'      => [
                // The name the adapter is handed in Server::create_server(),
                // not the server id: clients key config and display off
                // serverInfo.name, so the two transports must not disagree.
                'name'    => Server::SERVER_NAME,
                'version' => defined('WPMCP_VERSION') ? WPMCP_VERSION : '0.0.0',
            ],
        ];

        // Same guidance the HTTP handshake serves, built directly rather
        // than through mcp_adapter_initialize_response: that filter passes
        // the adapter's InitializeResult DTO, and handing foreign callbacks
        // a plain array instead would be a contract break.
        $instructions = (new Handshake_Instructions())->build();
        if ('' !== $instructions) {
            $result['instructions'] = $instructions;
        }

        return $result;
    }

    /**
     * Echoes the client's protocol version when the adapter supports it,
     * otherwise the newest supported version. No hardcoded version: the
     * adapter validates the MCP-Protocol-Version header against this exact
     * list on the HTTP route, so the two transports must not drift.
     *
     * @param array<string,mixed> $params initialize params.
     */
    private static function negotiate_protocol_version(array $params): string
    {
        $requested = isset($params['protocolVersion']) ? (string) $params['protocolVersion'] : '';

        if (class_exists(self::NEGOTIATOR)) {
            $negotiator = self::NEGOTIATOR;
            return (string) $negotiator::negotiate($requested);
        }

        return self::FALLBACK_PROTOCOL_VERSION;
    }

    /**
     * Tool descriptors for every wpmcp/ ability visible in the live
     * registry, same filtering rule as Server::tool_names(), then the same
     * exposure trim compact mode applies on the HTTP route.
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
                'name'        => self::tool_name($name),
                'description' => is_object($ability) && method_exists($ability, 'get_description')
                    ? (string) $ability->get_description()
                    : '',
                'inputSchema' => is_object($ability) && method_exists($ability, 'get_input_schema')
                    ? (array) $ability->get_input_schema()
                    : [ 'type' => 'object' ],
            ];
        }

        $trimmed = (new Tool_Exposure())->filter_tools_list($tools);

        return is_array($trimmed) ? array_values($trimmed) : $tools;
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
        $tool_name    = isset($params['name']) ? (string) $params['name'] : '';
        $ability_name = self::ability_for_tool($tool_name);

        if (null === $ability_name) {
            return self::error_response($id, -32602, sprintf('Unknown tool: %s', $tool_name));
        }

        // wp_has_ability() first: wp_get_ability() on an unknown name raises
        // _doing_it_wrong(), which prints HTML onto the protocol stream.
        $ability = wp_has_ability($ability_name) ? wp_get_ability($ability_name) : null;
        if (! is_object($ability) || ! method_exists($ability, 'execute')) {
            return self::error_response($id, -32602, sprintf('Unknown tool: %s', $tool_name));
        }

        $input = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        // The adapter's ToolsHandler::call_tool() catches Throwable and
        // returns an isError CallToolResult, so the HTTP route survives a
        // throwing tool. WP_Ability::execute() does not catch Throwable on
        // the WordPress versions this plugin targets, and
        // Registrar::throttled() re-throws on purpose, so parity here has
        // to be written rather than assumed.
        try {
            $result = $ability->execute($input);
        } catch (\Throwable $e) {
            return self::result_response($id, [
                'isError' => true,
                'content' => [ [ 'type' => 'text', 'text' => $e->getMessage() ] ],
            ]);
        }

        if (is_wp_error($result)) {
            return self::result_response($id, [
                'isError' => true,
                'content' => [ [ 'type' => 'text', 'text' => $result->get_error_message() ] ],
            ]);
        }

        // Same wire normalization the HTTP route gets from Structured_Result
        // on mcp_adapter_tool_call_result: structuredContent must be a JSON
        // object, never a top-level list or scalar.
        $normalized = Structured_Result::normalize($result);

        // Unchecked, a false from wp_json_encode (invalid UTF-8 in a tool
        // result, depth overflow) casts to '' and the text block silently
        // becomes empty while structuredContent still claims a payload.
        $text = wp_json_encode($normalized);
        if (false === $text) {
            return self::result_response($id, [
                'isError' => true,
                'content' => [ [ 'type' => 'text', 'text' => 'Tool result could not be serialized as JSON.' ] ],
            ]);
        }

        return self::result_response($id, [
            'isError'           => false,
            'content'           => [ [ 'type' => 'text', 'text' => $text ] ],
            'structuredContent' => $normalized,
        ]);
    }

    /**
     * The ability name behind an advertised MCP tool name, or null when no
     * registered wpmcp ability maps to it.
     *
     * Resolved by inverting the registry through the same mapping used to
     * advertise the tool rather than by reversing the string: the adapter's
     * sanitizer is not a reversible transform (it truncates long names and
     * rewrites out-of-charset ones), so guessing where the '/' was is wrong
     * for exactly the names that are hardest to debug.
     */
    private static function ability_for_tool(string $tool_name): ?string
    {
        if (
            '' === $tool_name
            || ! function_exists('wp_get_abilities')
            || ! function_exists('wp_get_ability')
            || ! function_exists('wp_has_ability')
        ) {
            return null;
        }

        foreach (wp_get_abilities() as $key => $ability) {
            $name = is_object($ability) && method_exists($ability, 'get_name')
                ? $ability->get_name()
                : (string) $key;

            if (! str_starts_with($name, 'wpmcp/')) {
                continue;
            }

            if (self::tool_name($name) === $tool_name) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Ability name to MCP tool name, using the adapter's sanitizer when it
     * is loaded so stdio and HTTP advertise byte-identical names.
     */
    private static function tool_name(string $ability_name): string
    {
        $sanitizer = '\\WP\\MCP\\Domain\\Utils\\McpNameSanitizer';
        if (class_exists($sanitizer)) {
            $sanitized = $sanitizer::sanitize_name($ability_name);
            if (is_string($sanitized)) {
                return $sanitized;
            }
        }

        return Tool_Exposure::tool_name($ability_name);
    }

    /**
     * Drops the memoized reads a long-lived loop would otherwise freeze, so
     * an option written by another process (a revoked ability, a flipped
     * exposure mode, a newly published guardrail) is observed on the next
     * message rather than at the next restart.
     *
     * Deliberately NOT wp_cache_flush(). On a site running a persistent
     * object-cache drop-in that wipes the whole shared cache, once per
     * JSON-RPC message, for every other process on the site; it would also
     * delete the transient-backed rate-limit counter (Rate_Limiter::PREFIX
     * transients live in the object cache when one is present), which is
     * the one piece of per-client state the loop most needs to keep. The
     * plugin treats wp_cache_flush() as an explicit, operator-invoked
     * destructive action (Tools\Cache\Clear_Cache); doing it implicitly is
     * not this transport's call to make.
     *
     * So: flush the process-local cache only when it IS process-local, and
     * otherwise delete the narrow option keys the gates read through (the
     * same targeted pattern as Auth\Code_Store). Statics that no cache
     * layer can reach are reset by hand; Memory_Store::$rules_cache is one,
     * and it backs a severity=block guardrail.
     */
    private static function reset_per_message_state(): void
    {
        Operation_Context::reset();

        Memory_Store::flush_rules_cache();

        $persistent = function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();

        if (! $persistent) {
            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
            }
            return;
        }

        if (function_exists('wp_cache_delete')) {
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('notoptions', 'options');
        }
    }

    /**
     * @param mixed $id     Request id.
     * @param mixed $result Result payload (an array, or stdClass where the
     *                      spec requires an empty JSON object).
     * @return array<string,mixed>
     */
    private static function result_response($id, $result): array
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
        $json = wp_json_encode($response);

        // Unchecked, a false here writes a bare newline and the client
        // waits forever on a request id that will never be answered. Core
        // handles the analogous case on the HTTP route by turning the
        // encode failure into a 500 rest_encode_error; the stdio equivalent
        // is an internal-error envelope carrying the same id.
        if (false === $json) {
            $json = wp_json_encode(
                self::error_response($response['id'] ?? null, -32603, 'Response could not be serialized as JSON.')
            );
        }
        if (false === $json) {
            return;
        }

        // fwrite, not echo: WP-CLI's output layer can buffer and interleave,
        // and one interleaved byte desynchronizes the framing.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- stdio transport: STDOUT is the protocol stream, not a file. WP_Filesystem has no equivalent.
        fwrite(STDOUT, $json . "\n");
    }
}
