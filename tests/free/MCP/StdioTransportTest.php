<?php

namespace WPMCP\Tests\Free\Mcp;

use WPMCP\MCP\Stdio_Transport;

/**
 * The stdio transport (issue #77) must complete an MCP handshake and a
 * tools/list + tools/call round-trip through its pure dispatch method,
 * without any process or HTTP involvement. Tool visibility must match the
 * HTTP transport: only wpmcp/ abilities from the live registry.
 */
class StdioTransportTest extends \WP_UnitTestCase
{
    private Stdio_Transport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new Stdio_Transport();
    }

    public function test_initialize_handshake(): void
    {
        $response = $this->transport->handle_request([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'initialize',
            'params'  => [
                'protocolVersion' => '2025-03-26',
                'capabilities'    => [],
                'clientInfo'      => [ 'name' => 'test', 'version' => '0.0.0' ],
            ],
        ]);

        $this->assertSame('2.0', $response['jsonrpc']);
        $this->assertSame(1, $response['id']);
        $this->assertArrayHasKey('result', $response);
        $this->assertArrayHasKey('protocolVersion', $response['result']);
        $this->assertSame('wpmcp-server', $response['result']['serverInfo']['name']);
    }

    public function test_notification_produces_no_response(): void
    {
        $response = $this->transport->handle_request([
            'jsonrpc' => '2.0',
            'method'  => 'notifications/initialized',
        ]);

        $this->assertNull($response);
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

        $this->assertArrayHasKey('tools', $response['result']);
        foreach ($response['result']['tools'] as $tool) {
            $this->assertStringStartsWith('wpmcp-', $tool['name']);
            $this->assertArrayHasKey('inputSchema', $tool);
        }
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

    // TODO(#77): full round-trip test that registers a real wpmcp/ ability
    // and asserts tools/call returns its result as MCP content, mirroring
    // CallToolConformanceTest's fixtures.
}
