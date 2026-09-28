<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\Connect\Client_Config_Generator;

/**
 * The endpoint we tell people to connect to has to exist.
 *
 * README, the Connection screen and the get-connection-info tool have all
 * shipped the same URL - /wp-json/mcp/wpmcp-server - since before there was
 * any code capable of serving it. It was a guess at a route a future
 * WordPress might provide, never something this plugin mounted, so following
 * the documented setup produced a 404 and an MCP client that connects to
 * nothing. wp_register_ability() alone does not create a transport; the
 * WordPress MCP Adapter does, and only for servers explicitly registered
 * with it.
 *
 * The last assertion is the one that keeps this from regressing: it pins the
 * advertised route to the mounted route, so the two cannot drift apart again
 * without a test failing.
 */
class McpTransportMountedTest extends \WP_UnitTestCase
{
    private const ROUTE = '/mcp/wpmcp-server';
    private const NAMESPACE_SEGMENT = 'mcp';

    /**
     * Build the route table from a fresh WP_REST_Server, the way
     * Auth\EndpointsTest does. Reading the global server instead would make
     * this depend on whichever earlier test last replaced it: the adapter's
     * HttpTransport re-registers on every rest_api_init, so a server swapped
     * in without firing the action simply has no routes on it yet, which
     * says nothing about whether this plugin mounts its endpoint.
     */
    private static function fresh_rest_routes(): array
    {
        global $wp_rest_server;

        self::reset_adapter();

        $wp_rest_server = new \WP_REST_Server();
        do_action('rest_api_init', $wp_rest_server);

        return $wp_rest_server->get_routes();
    }

    /**
     * Clear the adapter singleton so this test gets a real initialisation.
     *
     * McpAdapter::init() is one-shot behind a private static $initialized,
     * while WP_UnitTestCase restores $wp_filter after every test - so the
     * transport's rest_api_init hook, added during whichever earlier test
     * first touched the REST API, is gone by the time this one runs and the
     * adapter refuses to register it again. That interaction is an artefact
     * of the test harness (one request only ever initialises once in
     * production), so resetting here tests the plugin rather than the
     * ordering of the suite.
     */
    private static function reset_adapter(): void
    {
        $adapter    = \WP\MCP\Core\McpAdapter::instance();
        $reflection = new \ReflectionClass($adapter);

        // Drop the already-registered server: create_server() rejects a
        // duplicate id with a WP_Error, so without this the re-initialisation
        // below would no-op and never build a new transport.
        if ($reflection->hasProperty('servers')) {
            $reflection->getProperty('servers')->setValue($adapter, []);
        }

        // $initialized is a plain static bool, unlike the typed $instance
        // singleton, so it can be flipped back without tripping PHP's type
        // checks on an uninitialised typed property.
        if ($reflection->hasProperty('initialized')) {
            $reflection->getProperty('initialized')->setValue(null, false);
        }

        // The adapter's own init hook was added the first time instance() ran
        // and may have been restored away since. Re-adding is idempotent:
        // WordPress stores callbacks by unique id.
        add_action('rest_api_init', [$adapter, 'init'], 15);
    }

    public function test_the_mcp_adapter_is_available(): void
    {
        $this->assertTrue(
            class_exists(\WP\MCP\Core\McpAdapter::class),
            'wordpress/mcp-adapter is not installed, so no MCP transport can exist.'
        );
    }

    /**
     * Route, server and advertised-path checks share one lifecycle on
     * purpose. WP_UnitTestCase restores $wp_filter after every test, which
     * removes the rest_api_init hook the adapter's HttpTransport registers
     * when it is constructed; the adapter only constructs it once, so a
     * second test method would find no route through no fault of the code.
     */
    public function test_the_mcp_endpoint_is_mounted_and_matches_what_we_advertise(): void
    {
        $routes = self::fresh_rest_routes();

        $this->assertArrayHasKey(
            self::ROUTE,
            $routes,
            'The MCP JSON-RPC endpoint is not mounted, so every documented client config points at a 404.'
        );

        $server = \WP\MCP\Core\McpAdapter::instance()->get_server('wpmcp-server');

        $this->assertNotNull($server, 'The wpmcp MCP server was never registered with the adapter.');
        $this->assertNotEmpty($server->get_tools(), 'The MCP server mounted with no tools on it.');
        $this->assertSame(self::NAMESPACE_SEGMENT, $server->get_server_route_namespace());

        // The assertion that stops the documented URL drifting from the
        // mounted one again, which is how this shipped broken for 46 releases.
        $this->assertSame(
            '/wp-json' . self::ROUTE,
            Client_Config_Generator::ROUTE,
            'get-connection-info advertises a different path than the one actually mounted.'
        );
        $this->assertArrayHasKey(
            str_replace('/wp-json', '', Client_Config_Generator::ROUTE),
            $routes,
            'The advertised client-config endpoint does not resolve to a real route.'
        );
    }
}
