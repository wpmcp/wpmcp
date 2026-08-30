<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\Governance\Governance;
use WPMCP\Identity\Identity_Context;
use WPMCP\Identity\Identity_Store;
use WPMCP\MCP\Stdio_Transport;
use WPMCP\MCP\Tool_Exposure;
use WPMCP\RateLimit\Rate_Limiter;

/**
 * The stdio transport (issue #77) must complete an MCP handshake and a
 * tools/list + tools/call round-trip through its pure dispatch method,
 * without any process or HTTP involvement.
 *
 * Parity with the HTTP transport is the point of most of these tests: the
 * adapter's pipeline (version negotiation, compact-mode exposure, the
 * structuredContent wire shape) does not run on this route, so each of
 * those has to be re-established here and pinned.
 */
class StdioTransportTest extends \WP_UnitTestCase
{
    private Stdio_Transport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new Stdio_Transport();

        // These tests execute real abilities, so they need the same
        // fixture hygiene CallToolConformanceTest uses: no leftover
        // governance toggle, identity scope or rate-limit budget from a
        // neighbouring test class deciding the outcome here.
        Governance::reset_for_tests();
        Identity_Context::set_current_for_tests(null);
        delete_option(Identity_Store::OPTION);
        delete_option(Tool_Exposure::OPTION);
        Rate_Limiter::set_clock_override(fn() => 1_790_000_000);
        add_filter('wpmcp_rate_limit', fn() => 100000);
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_rate_limit');
        Rate_Limiter::set_clock_override(null);
        Identity_Context::set_current_for_tests(null);
        Governance::reset_for_tests();
        delete_option(Identity_Store::OPTION);
        delete_option(Tool_Exposure::OPTION);
        parent::tearDown();
    }

    private function as_administrator(): int
    {
        $id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($id);
        return $id;
    }

    public function test_initialize_handshake(): void
    {
        $response = $this->transport->handle_request([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'initialize',
            'params'  => [
                'protocolVersion' => '2025-06-18',
                'capabilities'    => [],
                'clientInfo'      => [ 'name' => 'test', 'version' => '0.0.0' ],
            ],
        ]);

        $this->assertSame('2.0', $response['jsonrpc']);
        $this->assertSame(1, $response['id']);
        $this->assertArrayHasKey('result', $response);
        $this->assertSame('wpmcp-server', $response['result']['serverInfo']['name']);
    }

    /**
     * A supported version is echoed back verbatim, per the MCP handshake
     * rules the adapter's negotiator implements.
     */
    public function test_initialize_echoes_a_supported_protocol_version(): void
    {
        $response = $this->transport->handle_request([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'initialize',
            'params'  => [ 'protocolVersion' => '2024-11-05' ],
        ]);

        $this->assertSame('2024-11-05', $response['result']['protocolVersion']);
    }

    /**
     * An unsupported request falls back to a version this stack actually
     * speaks. 2025-03-26 is NOT in the adapter's supported list, and the
     * HTTP route rejects it on the MCP-Protocol-Version header, so the two
     * transports must not answer it differently.
     */
    public function test_initialize_falls_back_for_an_unsupported_protocol_version(): void
    {
        $response = $this->transport->handle_request([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'initialize',
            'params'  => [ 'protocolVersion' => '2025-03-26' ],
        ]);

        $negotiated = $response['result']['protocolVersion'];
        $this->assertNotSame('2025-03-26', $negotiated);
        if (class_exists(\WP\MCP\Core\McpVersionNegotiator::class)) {
            $this->assertContains(
                $negotiated,
                \WP\MCP\Core\McpVersionNegotiator::SUPPORTED_PROTOCOL_VERSIONS
            );
        } else {
            $this->assertSame(Stdio_Transport::FALLBACK_PROTOCOL_VERSION, $negotiated);
        }
    }

    /** Both transports must hand clients the same guidance text. */
    public function test_initialize_carries_the_handshake_instructions(): void
    {
        $this->as_administrator();

        $response = $this->transport->handle_request([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'initialize',
            'params'  => [ 'protocolVersion' => '2025-11-25' ],
        ]);

        $this->assertArrayHasKey('instructions', $response['result']);
        $this->assertNotSame('', $response['result']['instructions']);
    }

    public function test_notification_produces_no_response(): void
    {
        $response = $this->transport->handle_request([
            'jsonrpc' => '2.0',
            'method'  => 'notifications/initialized',
        ]);

        $this->assertNull($response);
    }

    /**
     * JSON-RPC defines a notification by the ABSENCE of an id, not by the
     * method name, so an id-less call of any method gets no response.
     */
    public function test_any_id_less_request_is_treated_as_a_notification(): void
    {
        $this->assertNull($this->transport->handle_request([
            'jsonrpc' => '2.0',
            'method'  => 'ping',
        ]));
    }

    public function test_unknown_method_is_a_jsonrpc_error(): void
    {
        $response = $this->transport->handle_request([
            'jsonrpc' => '2.0',
            'id'      => 2,
            'method'  => 'no/such/method',
        ]);

        $this->assertSame(-32601, $response['error']['code']);
    }

    public function test_tools_list_exposes_only_wpmcp_tools(): void
    {
        $response = $this->transport->handle_request([
            'jsonrpc' => '2.0',
            'id'      => 3,
            'method'  => 'tools/list',
        ]);

        $tools = $response['result']['tools'];
        $this->assertNotEmpty($tools, 'The registry must actually advertise tools over stdio.');
        $this->assertContains('wpmcp-get-page', array_column($tools, 'name'));
        foreach ($tools as $tool) {
            $this->assertStringStartsWith('wpmcp-', $tool['name']);
            $this->assertArrayHasKey('inputSchema', $tool);
        }
    }

    /**
     * Compact mode (issue #79) is applied by Tool_Exposure on an adapter
     * filter the stdio route never fires, so without an explicit hand-off
     * a compact site would advertise its whole 160+ tool surface here.
     */
    public function test_tools_list_honors_compact_exposure_mode(): void
    {
        $full = $this->transport->handle_request([
            'jsonrpc' => '2.0',
            'id'      => 3,
            'method'  => 'tools/list',
        ])['result']['tools'];

        update_option(Tool_Exposure::OPTION, Tool_Exposure::MODE_COMPACT);

        $compact = $this->transport->handle_request([
            'jsonrpc' => '2.0',
            'id'      => 4,
            'method'  => 'tools/list',
        ])['result']['tools'];

        $this->assertLessThan(count($full), count($compact));
        $names = array_column($compact, 'name');
        $this->assertContains('wpmcp-call-tool', $names);
        $this->assertNotContains('wpmcp-get-page', $names);
    }

    public function test_call_of_unknown_tool_is_an_invalid_params_error(): void
    {
        $response = $this->transport->handle_request([
            'jsonrpc' => '2.0',
            'id'      => 4,
            'method'  => 'tools/call',
            'params'  => [ 'name' => 'wpmcp-definitely-not-registered', 'arguments' => [] ],
        ]);

        $this->assertSame(-32602, $response['error']['code']);
    }

    /**
     * The acceptance-criteria round trip: a real registered ability,
     * invoked by MCP tool name, returning its result as MCP content.
     */
    public function test_tools_call_round_trip_returns_the_ability_result(): void
    {
        $this->as_administrator();
        $page_id = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Stdio Round Trip']);

        $response = $this->transport->handle_request([
            'jsonrpc' => '2.0',
            'id'      => 5,
            'method'  => 'tools/call',
            'params'  => [ 'name' => 'wpmcp-get-page', 'arguments' => [ 'id' => $page_id ] ],
        ]);

        $this->assertArrayHasKey('result', $response, 'A successful call is a result, not a JSON-RPC error.');
        $this->assertFalse(
            $response['result']['isError'],
            'tools/call must succeed: ' . ($response['result']['content'][0]['text'] ?? '')
        );
        $this->assertStringContainsString('Stdio Round Trip', $response['result']['content'][0]['text']);

        $direct = wp_get_ability('wpmcp/get-page')->execute(['id' => $page_id]);
        $this->assertSame($direct['title'] ?? null, $response['result']['structuredContent']['title'] ?? null);
    }

    /**
     * structuredContent is typed as a JSON object by the MCP schema, so a
     * list-returning tool must be wrapped exactly as Structured_Result
     * wraps it on the HTTP route (issue #133).
     */
    public function test_tools_call_normalizes_structured_content_to_an_object(): void
    {
        $this->as_administrator();

        $response = $this->transport->handle_request([
            'jsonrpc' => '2.0',
            'id'      => 6,
            'method'  => 'tools/call',
            'params'  => [ 'name' => 'wpmcp-list-tools', 'arguments' => [] ],
        ]);

        $structured = $response['result']['structuredContent'];
        $this->assertIsArray($structured);
        $this->assertFalse(array_is_list($structured), 'structuredContent must serialize as a JSON object.');
    }

    /**
     * A denied ability is an MCP tool error, not a JSON-RPC fault: the
     * permission chain is the ability's, unchanged.
     */
    public function test_tools_call_reports_a_permission_denial_as_a_tool_error(): void
    {
        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);

        $response = $this->transport->handle_request([
            'jsonrpc' => '2.0',
            'id'      => 7,
            'method'  => 'tools/call',
            'params'  => [ 'name' => 'wpmcp-get-settings', 'arguments' => [] ],
        ]);

        $this->assertTrue($response['result']['isError']);
    }

    /**
     * WP-CLI runs as user 0 unless --user is given, and user 0 fails every
     * ability's capability check, so the command must refuse to start
     * rather than serve a surface where every call is denied.
     */
    public function test_serve_requires_a_user_context(): void
    {
        wp_set_current_user(0);
        $error = Stdio_Transport::user_context_error();

        $this->assertNotNull($error);
        $this->assertStringContainsString('--user', $error);

        $this->as_administrator();
        $this->assertNull(Stdio_Transport::user_context_error());
    }
}
