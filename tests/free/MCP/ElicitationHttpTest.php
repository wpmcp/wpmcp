<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\Governance\Governance;
use WPMCP\Identity\Identity_Context;
use WPMCP\Identity\Identity_Store;
use WPMCP\MCP\Tool_Exposure;
use WPMCP\RateLimit\Rate_Limiter;

/**
 * Confirm gates as elicitation (issue #387) on the HTTP route.
 *
 * 2026-07-28 elicitation is a multi round-trip result, so it needs no
 * server-to-client channel and works over the stateless route exactly as
 * over stdio. The 2025-11-25 session on this route belongs to the bundled
 * adapter, whose HTTP transport answers with JSON only and has no way to
 * send elicitation/create mid-call, so a 2025 client keeps the confirm
 * argument there even when it advertises elicitation.
 */
class ElicitationHttpTest extends \WP_UnitTestCase
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
        Rate_Limiter::set_clock_override(fn() => 1_790_000_387);
        add_filter('wpmcp_rate_limit', fn() => 100000);
        add_filter('wpmcp_enable_delete_comment', '__return_true');
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->mount();
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_rate_limit');
        remove_all_filters('wpmcp_enable_delete_comment');
        Rate_Limiter::set_clock_override(null);
        Identity_Context::set_current_for_tests(null);
        Governance::reset_for_tests();
        delete_option(Identity_Store::OPTION);
        delete_option(Tool_Exposure::OPTION);
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tearDown();
    }

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

    /** @return array{0:int,1:array<string,mixed>,2:array<string,string>} */
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

        return [ $response->get_status(), json_decode((string) wp_json_encode($response->get_data()), true), $response->get_headers() ];
    }

    private function modern_call(array $params): array
    {
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion'    => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => [ 'elicitation' => new \stdClass() ],
        ];

        [ $status, $body ] = $this->post(
            [ 'jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => $params ],
            [ 'MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call', 'Mcp-Name' => (string) $params['name'] ]
        );
        $this->assertSame(200, $status, (string) wp_json_encode($body));

        return $body['result'];
    }

    public function test_the_multi_round_trip_elicitation_works_over_http(): void
    {
        $post = self::factory()->post->create();
        $id   = (int) self::factory()->comment->create(['comment_post_ID' => $post]);
        $call = [ 'name' => 'wpmcp-delete-comment', 'arguments' => [ 'id' => $id ] ];

        $first = $this->modern_call($call);
        $this->assertSame('input_required', $first['resultType'], (string) wp_json_encode($first));
        $key = (string) array_key_first($first['inputRequests']);
        $this->assertSame('elicitation/create', $first['inputRequests'][ $key ]['method']);
        $this->assertInstanceOf(\WP_Comment::class, get_comment($id));

        $result = $this->modern_call($call + [
            'inputResponses' => [ $key => [ 'action' => 'accept', 'content' => [ 'confirm' => true ] ] ],
            'requestState'   => $first['requestState'],
        ]);

        $this->assertSame('complete', $result['resultType']);
        $this->assertFalse($result['isError'], (string) ($result['content'][0]['text'] ?? ''));
        $this->assertNull(get_comment($id));
    }

    public function test_a_2025_http_session_keeps_the_argument_fallback(): void
    {
        $post = self::factory()->post->create();
        $id   = (int) self::factory()->comment->create(['comment_post_ID' => $post]);

        [ , $body, $headers ] = $this->post([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'initialize',
            'params'  => [
                'protocolVersion' => '2025-11-25',
                'capabilities'    => [ 'elicitation' => new \stdClass() ],
                'clientInfo'      => [ 'name' => 'conformance', 'version' => '0.0.0' ],
            ],
        ]);
        $session = [ 'Mcp-Session-Id' => (string) $headers['Mcp-Session-Id'], 'MCP-Protocol-Version' => '2025-11-25' ];

        [ , $body ] = $this->post([
            'jsonrpc' => '2.0',
            'id'      => 2,
            'method'  => 'tools/call',
            'params'  => [ 'name' => 'wpmcp-delete-comment', 'arguments' => [ 'id' => $id ] ],
        ], $session);

        $this->assertTrue($body['result']['isError'] ?? false, (string) wp_json_encode($body));
        $this->assertArrayNotHasKey('inputRequests', $body['result']);
        $this->assertInstanceOf(\WP_Comment::class, get_comment($id));

        [ , $body ] = $this->post([
            'jsonrpc' => '2.0',
            'id'      => 3,
            'method'  => 'tools/call',
            'params'  => [ 'name' => 'wpmcp-delete-comment', 'arguments' => [ 'id' => $id, 'confirm' => true ] ],
        ], $session);

        $this->assertFalse($body['result']['isError'] ?? true, (string) wp_json_encode($body));
        $this->assertNull(get_comment($id));
    }
}
