<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\Governance\Governance;
use WPMCP\Identity\Identity_Context;
use WPMCP\Identity\Identity_Store;
use WPMCP\MCP\Handshake_Instructions;
use WPMCP\MCP\Tool_Exposure;
use WPMCP\RateLimit\Rate_Limiter;

/**
 * MCP 2026-07-28 and 2025-11-25 side by side on the HTTP route (issue #386),
 * as real JSON-RPC POSTs to the mounted /mcp/wpmcp-server endpoint.
 *
 * These pass whichever adapter copy serves the route: the bundled 0.6.x
 * (which predates 2026-07-28, so WP MCP answers those requests itself) or a
 * 0.7.x canonical plugin (which answers them natively). Run them against a
 * canonical build with WPMCP_TEST_ADAPTER_PLUGIN, see tests/bootstrap.php.
 */
class ProtocolRevisionHttpTest extends \WP_UnitTestCase
{
    private const ROUTE = '/mcp/wpmcp-server';

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
        delete_option(Identity_Store::OPTION);
        delete_option(Tool_Exposure::OPTION);
        delete_option(Handshake_Instructions::OPTION);
        Rate_Limiter::set_clock_override(fn() => 1_790_000_386);
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
        delete_option(Identity_Store::OPTION);
        delete_option(Tool_Exposure::OPTION);
        delete_option(Handshake_Instructions::OPTION);
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
     * @param array<string,mixed>  $message JSON-RPC message.
     * @param array<string,string> $headers Extra request headers.
     * @return array{0:int,1:array<string,mixed>,2:array<string,string>} Status, decoded body, headers.
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

        $response = rest_do_request($request);
        $response = apply_filters('rest_post_dispatch', rest_ensure_response($response), $wp_rest_server, $request);

        $data = json_decode((string) wp_json_encode($response->get_data()), true);
        $this->assertIsArray($data, 'No JSON-RPC body for ' . ($message['method'] ?? '?'));

        return [ $response->get_status(), $data, $response->get_headers() ];
    }

    /**
     * A well-formed 2026-07-28 request: revision and capabilities in the
     * body, mirrored in the MCP-Protocol-Version / Mcp-Method / Mcp-Name
     * headers the revision requires on HTTP.
     *
     * @param array<string,mixed>  $params
     * @param array<string,string> $headers Overrides for the derived headers.
     * @return array{0:int,1:array<string,mixed>,2:array<string,string>}
     */
    private function modern(string $method, array $params = [], array $headers = [], string $version = '2026-07-28'): array
    {
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion'    => $version,
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
            'io.modelcontextprotocol/clientInfo'         => [ 'name' => 'conformance', 'version' => '0.0.0' ],
        ];

        $derived = [ 'MCP-Protocol-Version' => $version, 'Mcp-Method' => $method ];
        if (isset($params['name'])) {
            $derived['Mcp-Name'] = (string) $params['name'];
        } elseif (isset($params['uri'])) {
            $derived['Mcp-Name'] = (string) $params['uri'];
        }

        return $this->post(
            [ 'jsonrpc' => '2.0', 'id' => 7, 'method' => $method, 'params' => $params ],
            array_filter(array_merge($derived, $headers), static fn($v) => null !== $v)
        );
    }

    public function test_server_discover_is_answered_without_a_session(): void
    {
        update_option(Handshake_Instructions::OPTION, 'Save drafts only.');

        [ $status, $body, $headers ] = $this->modern('server/discover');

        $this->assertSame(200, $status, (string) wp_json_encode($body));
        $this->assertArrayNotHasKey('Mcp-Session-Id', $headers, 'server/discover is sessionless.');
        $result = $body['result'];
        $this->assertSame(['2026-07-28', '2025-11-25'], $result['supportedVersions']);
        $this->assertArrayHasKey('tools', $result['capabilities']);
        $this->assertSame('complete', $result['resultType']);
        $this->assertSame((new Handshake_Instructions())->build(), $result['instructions']);
        $this->assertStringContainsString('Save drafts only.', $result['instructions']);
    }

    public function test_modern_tools_list_without_a_session(): void
    {
        [ $status, $body ] = $this->modern('tools/list');

        $this->assertSame(200, $status, (string) wp_json_encode($body));
        $this->assertContains('wpmcp-get-page', array_column($body['result']['tools'], 'name'));
        $this->assertSame('complete', $body['result']['resultType']);
    }

    public function test_modern_tools_call_round_trip(): void
    {
        $page_id = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'HTTP Revision Trip']);

        [ $status, $body ] = $this->modern('tools/call', [ 'name' => 'wpmcp-get-page', 'arguments' => [ 'id' => $page_id ] ]);

        $this->assertSame(200, $status, (string) wp_json_encode($body));
        $this->assertFalse($body['result']['isError'], (string) ($body['result']['content'][0]['text'] ?? ''));
        $this->assertSame('complete', $body['result']['resultType']);
        $this->assertSame('HTTP Revision Trip', $body['result']['structuredContent']['title'] ?? null);
    }

    public function test_a_method_header_that_disagrees_with_the_body_is_rejected(): void
    {
        [ $status, $body ] = $this->modern('tools/list', [], [ 'Mcp-Method' => 'prompts/list' ]);

        $this->assertSame(400, $status);
        $this->assertSame(-32020, $body['error']['code']);
    }

    public function test_a_modern_body_without_the_version_header_is_rejected(): void
    {
        [ $status, $body ] = $this->modern('tools/list', [], [ 'MCP-Protocol-Version' => null ]);

        $this->assertSame(400, $status);
        $this->assertSame(-32020, $body['error']['code']);
    }

    public function test_a_tool_name_header_that_disagrees_with_the_body_is_rejected(): void
    {
        [ $status, $body ] = $this->modern('tools/call', [ 'name' => 'wpmcp-get-page', 'arguments' => [] ], [ 'Mcp-Name' => 'wpmcp-get-post' ]);

        $this->assertSame(400, $status);
        $this->assertSame(-32020, $body['error']['code']);
    }

    public function test_an_unsupported_revision_is_rejected_with_the_supported_list(): void
    {
        [ $status, $body ] = $this->modern('tools/list', [], [], '2099-01-01');

        $this->assertSame(400, $status);
        $this->assertSame(-32022, $body['error']['code']);
        $this->assertSame(['2026-07-28', '2025-11-25'], $body['error']['data']['supported']);
    }

    /**
     * 2025-11-25 keeps working next to 2026-07-28: the session handshake,
     * the handshake instructions, and a follow-up request on the session.
     */
    public function test_the_2025_11_25_session_still_works(): void
    {
        update_option(Handshake_Instructions::OPTION, 'Save drafts only.');

        [ $status, $body, $headers ] = $this->post([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'initialize',
            'params'  => [
                'protocolVersion' => '2025-11-25',
                'capabilities'    => new \stdClass(),
                'clientInfo'      => [ 'name' => 'conformance', 'version' => '0.0.0' ],
            ],
        ]);

        $this->assertSame(200, $status, (string) wp_json_encode($body));
        $this->assertSame('2025-11-25', $body['result']['protocolVersion']);
        $this->assertSame((new Handshake_Instructions())->build(), $body['result']['instructions']);
        $this->assertArrayHasKey('Mcp-Session-Id', $headers);

        [ $status, $body ] = $this->post(
            [ 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list' ],
            [ 'Mcp-Session-Id' => (string) $headers['Mcp-Session-Id'], 'MCP-Protocol-Version' => '2025-11-25' ]
        );

        $this->assertSame(200, $status, (string) wp_json_encode($body));
        $this->assertContains('wpmcp-get-page', array_column($body['result']['tools'], 'name'));
        $this->assertArrayNotHasKey('resultType', $body['result']);
    }

    /** A client proposing 2026-07-28 through initialize gets the 2025 counter-proposal. */
    public function test_initialize_counter_proposes_2025_11_25(): void
    {
        [ , $body ] = $this->post([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'initialize',
            'params'  => [
                'protocolVersion' => '2026-07-28',
                'capabilities'    => new \stdClass(),
                'clientInfo'      => [ 'name' => 'conformance', 'version' => '0.0.0' ],
            ],
        ]);

        $this->assertSame('2025-11-25', $body['result']['protocolVersion']);
    }
}
