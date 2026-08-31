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
        // The name the adapter reports on the HTTP handshake, not the
        // server id: a client keying config off serverInfo.name must see
        // the same string on both transports.
        $this->assertSame(\WPMCP\MCP\Server::SERVER_NAME, $response['result']['serverInfo']['name']);
        $this->assertSame('wpmcp', $response['result']['serverInfo']['name']);
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

    /**
     * A request naming a notifications/ method but carrying an id is still a
     * request. Answering nothing leaves a synchronous client blocked on a
     * response that is never written, so the id, not the method name, is the
     * whole rule.
     */
    public function test_an_id_bearing_notifications_method_still_gets_a_response(): void
    {
        $response = $this->transport->handle_request([
            'jsonrpc' => '2.0',
            'id'      => 7,
            'method'  => 'notifications/initialized',
        ]);

        $this->assertNotNull($response, 'A message with an id must always be answered.');
        $this->assertSame(7, $response['id']);
        $this->assertSame(-32601, $response['error']['code']);
    }

    /**
     * ping's result must be a JSON object. An empty PHP array encodes as
     * [], which is exactly the invalid shape Structured_Result exists to
     * prevent elsewhere in this class.
     */
    public function test_ping_result_serializes_as_a_json_object(): void
    {
        $response = $this->transport->handle_request([
            'jsonrpc' => '2.0',
            'id'      => 3,
            'method'  => 'ping',
        ]);

        $this->assertStringContainsString('"result":{}', (string) wp_json_encode($response));
    }

    /**
     * A tool that throws must cost one tool call, not the session. The HTTP
     * route gets this from the adapter's ToolsHandler, which catches
     * Throwable and returns an isError CallToolResult; WP_Ability::execute()
     * does not, so the stdio route has to do it itself.
     */
    public function test_a_throwing_tool_becomes_a_tool_error_not_a_dead_process(): void
    {
        $this->as_administrator();

        // wp_register_ability() only accepts registrations inside the
        // registry's own init window, so open one. Clearing the hook first
        // is what AbilityRegistrySmokeTest does and is not optional: firing
        // it with the plugin's own Registrar still attached re-registers
        // every shipped ability and trips the duplicate-registration
        // notice. WP_UnitTestCase restores the original hooks in tearDown.
        remove_all_actions('wp_abilities_api_init');
        add_action('wp_abilities_api_init', static function (): void {
            wp_register_ability('wpmcp/stdio-thrower', [
                'label'               => 'Stdio thrower',
                'description'         => 'Throws, for the transport test.',
                'category'            => 'wpmcp',
                'input_schema'        => [ 'type' => 'object' ],
                'meta'                => [ 'show_in_rest' => true ],
                'execute_callback'    => static function () {
                    throw new \RuntimeException('tool exploded');
                },
                'permission_callback' => '__return_true',
            ]);
        });
        do_action('wp_abilities_api_init');

        if (! wp_has_ability('wpmcp/stdio-thrower')) {
            $this->markTestSkipped('The Abilities API would not accept a test ability here.');
        }

        try {
            $response = $this->transport->handle_request([
                'jsonrpc' => '2.0',
                'id'      => 11,
                'method'  => 'tools/call',
                'params'  => [ 'name' => 'wpmcp-stdio-thrower', 'arguments' => [] ],
            ]);
        } finally {
            wp_unregister_ability('wpmcp/stdio-thrower');
        }

        $this->assertArrayHasKey('result', $response, 'A throwing tool must not become a JSON-RPC fault.');
        $this->assertTrue($response['result']['isError']);
        $this->assertStringContainsString('tool exploded', $response['result']['content'][0]['text']);
    }

    /**
     * The per-message reset must not wipe a persistent object cache. It used
     * to call wp_cache_flush() unconditionally, which on a site running a
     * drop-in deletes the whole shared cache once per JSON-RPC message,
     * including the transient the rate limiter counts in (transients live in
     * the object cache when one is present), so per-client rate limiting was
     * disabled on this transport entirely.
     */
    public function test_the_per_message_reset_does_not_flush_a_persistent_object_cache(): void
    {
        $was = wp_using_ext_object_cache();
        wp_using_ext_object_cache(true);

        wp_cache_set('stdio-reset-canary', 'kept', 'wpmcp-test');

        $reset = new \ReflectionMethod(Stdio_Transport::class, 'reset_per_message_state');
        $reset->setAccessible(true);

        try {
            $reset->invoke(null);
            $this->assertSame(
                'kept',
                wp_cache_get('stdio-reset-canary', 'wpmcp-test'),
                'A persistent cache must survive a message boundary.'
            );
        } finally {
            wp_using_ext_object_cache($was);
            wp_cache_delete('stdio-reset-canary', 'wpmcp-test');
        }
    }

    /**
     * The rate-limit budget has to keep counting across message boundaries,
     * which is the behavior the flush above was destroying.
     */
    public function test_the_rate_limit_counter_survives_the_per_message_reset(): void
    {
        $reset = new \ReflectionMethod(Stdio_Transport::class, 'reset_per_message_state');
        $reset->setAccessible(true);

        $key   = 'stdio-reset-' . wp_generate_uuid4();
        $first = Rate_Limiter::check($key);

        $reset->invoke(null);

        $second = Rate_Limiter::check($key);

        $this->assertSame(
            $first['remaining'] - 1,
            $second['remaining'],
            'The counter must keep counting across the per-message reset.'
        );
    }

    /**
     * The reset must drop Memory_Store's plain static memo too. No object
     * cache reaches a static property, so a guardrail published mid-session
     * would otherwise stay invisible for the life of the process.
     */
    public function test_the_per_message_reset_drops_the_memoized_memory_rules(): void
    {
        $memo = new \ReflectionProperty(\WPMCP\Memory\Memory_Store::class, 'rules_cache');
        $memo->setAccessible(true);
        $memo->setValue(null, [ [ 'entry' => 'stale' ] ]);

        $reset = new \ReflectionMethod(Stdio_Transport::class, 'reset_per_message_state');
        $reset->setAccessible(true);
        $reset->invoke(null);

        $this->assertNull($memo->getValue(), 'A memoized block rule must not survive a message boundary.');
    }
}
