<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\Governance\Governance;
use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Identity\Identity_Context;
use WPMCP\MCP\Handshake_Instructions;
use WPMCP\MCP\Handshake_Timing;
use WPMCP\RateLimit\Rate_Limiter;

/**
 * The MCP handshake stays cheap on the server (issue #733).
 *
 * Clients give the handshake about 5 seconds in all: Claude Desktop skips
 * its connector checks past that, and Claude Code's version negotiation
 * probe times out at 5000ms. Measured on a WordPress 7.1 site with 21
 * plugins active (bin/bench-handshake.php), each handshake request costs
 * about 300ms on the server, of which WP MCP is about 25ms; the rest is
 * WordPress and the other plugins booting. So these tests pin what is
 * ours: the handshake writes nothing to the database, each response says
 * how long the server took (Server-Timing, so a slow connection through a
 * tunnel can be split into server and network time), and a generous wall
 * clock budget catches a regression that makes a handshake method do real
 * work.
 */
class HandshakeLatencyTest extends \WP_UnitTestCase
{
    private const ROUTE = '/mcp/wpmcp-server';

    /** Per request, in the test harness. Ten times what the bench measures. */
    private const BUDGET_MS = 1000;

    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Governance::reset_for_tests();
        Identity_Context::set_current_for_tests(null);
        delete_option(Handshake_Instructions::OPTION);
        delete_option(Governance_Audit_Log::OPTION);
        Rate_Limiter::set_clock_override(fn() => 1_790_000_733);
        add_filter('wpmcp_rate_limit', fn() => 100000);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->mount();
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_rate_limit');
        Rate_Limiter::set_clock_override(null);
        Identity_Context::set_current_for_tests(null);
        Governance::reset_for_tests();
        delete_option(Governance_Audit_Log::OPTION);
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tearDown();
    }

    /** Same re-initialisation McpTransportMountedTest documents. */
    private function mount(): void
    {
        global $wp_rest_server;

        $adapter    = \WP\MCP\Core\McpAdapter::instance();
        $reflection = new \ReflectionClass($adapter);
        if ($reflection->hasProperty('servers')) {
            $reflection->getProperty('servers')->setValue($adapter, []);
        }
        if ($reflection->hasProperty('initialized')) {
            $reflection->getProperty('initialized')->setValue(null, false);
        }
        add_action('rest_api_init', [$adapter, 'init'], 15);

        $wp_rest_server = new \WP_REST_Server();
        do_action('rest_api_init', $wp_rest_server);
    }

    /**
     * @param array<string,mixed>  $message
     * @param array<string,string> $headers
     * @return array{0:int,1:array<string,mixed>,2:array<string,string>,3:float} Status, body, headers, milliseconds.
     */
    private function post(array $message, array $headers = []): array
    {
        global $wp_rest_server;

        $request = new \WP_REST_Request('POST', self::ROUTE);
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('Accept', 'application/json, text/event-stream');
        foreach ($headers as $name => $value) {
            $request->set_header($name, $value);
        }
        $request->set_body((string) wp_json_encode($message));

        $start    = microtime(true);
        $response = rest_do_request($request);
        $response = apply_filters('rest_post_dispatch', rest_ensure_response($response), $wp_rest_server, $request);
        $ms       = (microtime(true) - $start) * 1000;

        $data = json_decode((string) wp_json_encode($response->get_data()), true);
        $this->assertIsArray($data, 'No JSON-RPC body for ' . ($message['method'] ?? '?'));

        return [ $response->get_status(), $data, $response->get_headers(), $ms ];
    }

    /** @return array<string,mixed> */
    private static function modern_meta(): array
    {
        return [
            'io.modelcontextprotocol/protocolVersion'    => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
            'io.modelcontextprotocol/clientInfo'         => [ 'name' => 'latency', 'version' => '0.0.0' ],
        ];
    }

    /** @return array{0:int,1:array<string,mixed>,2:array<string,string>,3:float} */
    private function initialize(): array
    {
        return $this->post([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'initialize',
            'params'  => [
                'protocolVersion' => '2025-11-25',
                'capabilities'    => new \stdClass(),
                'clientInfo'      => [ 'name' => 'latency', 'version' => '0.0.0' ],
            ],
        ]);
    }

    /** @return array{0:int,1:array<string,mixed>,2:array<string,string>,3:float} */
    private function discover(): array
    {
        return $this->post(
            [ 'jsonrpc' => '2.0', 'id' => 3, 'method' => 'server/discover', 'params' => [ '_meta' => self::modern_meta() ] ],
            [ 'MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'server/discover' ]
        );
    }

    public function test_initialize_writes_no_audit_row(): void
    {
        [ $status, $body ] = $this->initialize();

        $this->assertSame(200, $status, (string) wp_json_encode($body));
        // The site summary was served, so the get-site-context gate ran...
        $this->assertStringContainsString('You are connected to the WordPress site', $body['result']['instructions']);
        // ...without recording a call nobody made, which rewrote the whole
        // 500-row audit option on every connect.
        $this->assertSame([], Governance_Audit_Log::list());
    }

    public function test_server_discover_writes_no_audit_row(): void
    {
        [ $status, $body ] = $this->discover();

        $this->assertSame(200, $status, (string) wp_json_encode($body));
        $this->assertStringContainsString('You are connected to the WordPress site', $body['result']['instructions']);
        $this->assertSame([], Governance_Audit_Log::list());
    }

    public function test_the_handshake_gate_still_narrows_without_auditing(): void
    {
        Governance::set_ability_toggle('wpmcp/get-site-context', false);

        $this->assertStringNotContainsString('You are connected to the WordPress site', (new Handshake_Instructions())->build());
        $this->assertSame([], Governance_Audit_Log::list());
    }

    public function test_a_real_permission_check_is_still_audited(): void
    {
        // Only the handshake is quiet: a check made for a call still leaves its row.
        $ability = wp_get_abilities()['wpmcp/get-site-context'];
        $this->assertTrue($ability->check_permissions());
        $this->assertCount(1, Governance_Audit_Log::list());
    }

    public function test_handshake_responses_say_how_long_the_server_took(): void
    {
        [ , , $headers ] = $this->initialize();

        $this->assertArrayHasKey('Server-Timing', $headers);
        $this->assertMatchesRegularExpression('/(^|, )app;dur=\d+(\.\d+)?/', $headers['Server-Timing']);

        [ , , $headers ] = $this->discover();
        $this->assertMatchesRegularExpression('/(^|, )app;dur=\d+(\.\d+)?/', $headers['Server-Timing']);
    }

    public function test_ability_registration_time_is_reported_when_this_request_ran_it(): void
    {
        Handshake_Timing::reset_for_tests();
        Handshake_Timing::abilities_start();
        Handshake_Timing::abilities_end();

        $this->assertMatchesRegularExpression('/^abilities;dur=\d+(\.\d+)?, app;dur=\d+(\.\d+)?$/', Handshake_Timing::header_value(microtime(true)));
    }

    public function test_other_routes_get_no_server_timing_header(): void
    {
        global $wp_rest_server;

        $request  = new \WP_REST_Request('GET', '/');
        $response = apply_filters('rest_post_dispatch', rest_ensure_response(rest_do_request($request)), $wp_rest_server, $request);

        $this->assertArrayNotHasKey('Server-Timing', $response->get_headers());
    }

    public function test_each_handshake_method_stays_within_budget(): void
    {
        // Warm up: the first dispatch loads classes the rest then share.
        $this->initialize();

        [ $status, $body, $headers, $initialize_ms ] = $this->initialize();
        $this->assertSame(200, $status);
        $session = (string) $headers['Mcp-Session-Id'];

        [ $status, $body, , $list_ms ] = $this->post(
            [ 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list' ],
            [ 'Mcp-Session-Id' => $session, 'MCP-Protocol-Version' => '2025-11-25' ]
        );
        $this->assertSame(200, $status);
        $this->assertNotEmpty($body['result']['tools']);

        [ $status, , , $discover_ms ] = $this->discover();
        $this->assertSame(200, $status);

        [ $status, $body, , $modern_list_ms ] = $this->post(
            [ 'jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/list', 'params' => [ '_meta' => self::modern_meta() ] ],
            [ 'MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/list' ]
        );
        $this->assertSame(200, $status, (string) wp_json_encode($body));
        $this->assertNotEmpty($body['result']['tools']);

        foreach ([
            '2025-11-25 initialize' => $initialize_ms,
            '2025-11-25 tools/list' => $list_ms,
            '2026-07-28 server/discover' => $discover_ms,
            '2026-07-28 tools/list' => $modern_list_ms,
        ] as $method => $ms) {
            $this->assertLessThan(self::BUDGET_MS, $ms, sprintf('%s took %.0fms on the server.', $method, $ms));
        }
    }
}
