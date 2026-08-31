<?php

namespace WPMCP\MCP;

use WPMCP\Connect\Client_Config_Generator;
use WP\MCP\Core\McpAdapter;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;
use WP\MCP\Transport\HttpTransport;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Mounts the MCP JSON-RPC transport this plugin has always advertised.
 *
 * wp_register_ability() makes a tool callable in-process and, with
 * show_in_rest, visible over WordPress's own abilities REST routes. Neither
 * of those is MCP. The protocol transport — initialize, tools/list,
 * tools/call, session handling — belongs to the WordPress MCP Adapter, and
 * the adapter only serves servers that are explicitly registered with it
 * during mcp_adapter_init. Nothing did that, so /wp-json/mcp/wpmcp-server
 * 404'd while README, the Connection screen and get-connection-info all
 * handed clients that exact URL.
 *
 * A custom server (rather than the adapter's default one) is what this
 * plugin's design already assumes everywhere else: the default server
 * exposes generic discover/execute meta-tools, whereas a custom server
 * publishes each ability as its own named tool, which is what
 * list-tool-catalog, the compact-mode tools/list filter and the handshake
 * instructions are all written against.
 */
class Server
{
    /** Must match Client_Config_Generator::ROUTE, asserted by the test suite. */
    public const SERVER_ID = 'wpmcp-server';
    public const NAMESPACE = 'mcp';

    /**
     * The server_name the adapter reports as serverInfo.name on the
     * handshake. Distinct from SERVER_ID (which is the registry key and the
     * route), and shared with Stdio_Transport so the two transports do not
     * identify themselves differently to the same client.
     */
    public const SERVER_NAME = 'wpmcp';

    public static function register(): void
    {
        // Suppress the adapter's own default server. It publishes generic
        // discover-abilities / get-ability-info / execute-ability meta-tools
        // on a second endpoint, which would be a way to reach abilities
        // without passing this plugin's compact-mode tools/list filter or
        // its handshake instructions — an exposure surface we do not own and
        // did not ask for. Every wpmcp tool is published by our own server
        // below, gated exactly as the rest of the plugin expects.
        add_filter('mcp_adapter_create_default_server', '__return_false');

        // The adapter ships as a library, not a plugin, so nothing calls its
        // bootstrap for us. Instantiating the adapter is what schedules its
        // own init (and therefore mcp_adapter_init) on rest_api_init. Boot
        // immediately when plugins_loaded has already fired, otherwise the
        // hook would never run and the endpoint would silently not exist.
        if (did_action('plugins_loaded')) {
            self::boot();
        } else {
            add_action('plugins_loaded', [self::class, 'boot'], 20);
        }

        add_action('mcp_adapter_init', [self::class, 'create_server']);
    }

    public static function boot(): void
    {
        if (class_exists(McpAdapter::class)) {
            McpAdapter::instance();
        }
    }

    /**
     * @param McpAdapter $adapter Passed by the adapter's own action.
     */
    public static function create_server($adapter): void
    {
        if (! is_object($adapter) || ! method_exists($adapter, 'create_server')) {
            return;
        }

        $tools = self::tool_names();
        if ([] === $tools) {
            // No abilities registered (every one governance-disabled, or the
            // master exposure switch is off). Mounting an empty server would
            // advertise a working endpoint with nothing behind it.
            return;
        }

        $result = $adapter->create_server(
            self::SERVER_ID,
            self::NAMESPACE,
            self::SERVER_ID,
            self::SERVER_NAME,
            __('AI builds and edits your WordPress site, and physically cannot wreck it.', 'wpmcp'),
            defined('WPMCP_VERSION') ? WPMCP_VERSION : '0.0.0',
            [ HttpTransport::class ],
            ErrorLogMcpErrorHandler::class,
            NullMcpObservabilityHandler::class,
            $tools
        );

        if (is_wp_error($result)) {
            // Never fatal: a site whose MCP server failed to mount must still
            // serve its admin screens and its REST abilities routes.
            error_log('[wpmcp] MCP server registration failed: ' . $result->get_error_message()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
    }

    /**
     * The abilities to publish as MCP tools.
     *
     * Read from the live Abilities registry rather than from our own
     * Registrar, so an ability that was declared but gated away (pro tier
     * without a licence, governance-disabled, memory-blocked) is absent from
     * the MCP surface for the same reason it is absent everywhere else,
     * without this class re-implementing any of those decisions.
     *
     * @return string[]
     */
    private static function tool_names(): array
    {
        if (! function_exists('wp_get_abilities')) {
            return [];
        }

        $names = [];
        foreach (wp_get_abilities() as $key => $ability) {
            $name = is_object($ability) && method_exists($ability, 'get_name')
                ? $ability->get_name()
                : (string) $key;

            if (str_starts_with($name, 'wpmcp/')) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /** The absolute endpoint, for anything that needs to show it to a user. */
    public static function endpoint(): string
    {
        return home_url(Client_Config_Generator::ROUTE);
    }
}
