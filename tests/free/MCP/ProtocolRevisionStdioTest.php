<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\Governance\Governance;
use WPMCP\Identity\Identity_Context;
use WPMCP\Identity\Identity_Store;
use WPMCP\MCP\Handshake_Instructions;
use WPMCP\MCP\Protocol_Revision;
use WPMCP\MCP\Stdio_Transport;
use WPMCP\MCP\Tool_Exposure;
use WPMCP\RateLimit\Rate_Limiter;

/**
 * MCP 2026-07-28 over stdio (issue #386), next to the 2025-11-25
 * handshake StdioTransportTest already pins.
 *
 * 2026-07-28 has no initialize handshake: a client calls the sessionless
 * server/discover, then tags every request with the revision and its
 * capabilities in params._meta. Stdio accepts either revision per line,
 * which is what the WordPress MCP adapter's own stdio bridge does.
 */
class ProtocolRevisionStdioTest extends \WP_UnitTestCase
{
    private Stdio_Transport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new Stdio_Transport();
        Governance::reset_for_tests();
        Identity_Context::set_current_for_tests(null);
        delete_option(Identity_Store::OPTION);
        delete_option(Tool_Exposure::OPTION);
        delete_option(Handshake_Instructions::OPTION);
        Rate_Limiter::set_clock_override(fn() => 1_790_000_386);
        add_filter('wpmcp_rate_limit', fn() => 100000);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
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
        parent::tearDown();
    }

    /** @return array<string,mixed> A 2026-07-28 request's _meta. */
    private static function modern_meta(string $version = '2026-07-28'): array
    {
        return [
            'io.modelcontextprotocol/protocolVersion'    => $version,
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
            'io.modelcontextprotocol/clientInfo'         => [ 'name' => 'conformance', 'version' => '0.0.0' ],
        ];
    }

    /** @param array<string,mixed> $params */
    private function modern(string $method, array $params = [], int $id = 1, string $version = '2026-07-28'): array
    {
        $params['_meta'] = self::modern_meta($version);

        $response = $this->transport->handle_request([
            'jsonrpc' => '2.0',
            'id'      => $id,
            'method'  => $method,
            'params'  => $params,
        ]);
        $this->assertIsArray($response, "No response for $method");

        // As the client reads it: stdClass leaves become arrays.
        return json_decode((string) wp_json_encode($response), true);
    }

    public function test_the_revisions_are_named_once(): void
    {
        $this->assertSame('2026-07-28', Protocol_Revision::MODERN);
        $this->assertSame('2025-11-25', Protocol_Revision::LEGACY);
        $this->assertSame(['2026-07-28', '2025-11-25'], Protocol_Revision::supported_versions());
    }

    public function test_server_discover_lists_both_revisions_and_the_capabilities(): void
    {
        update_option(Handshake_Instructions::OPTION, 'Save drafts only.');

        $result = $this->modern('server/discover')['result'];

        $this->assertSame(['2026-07-28', '2025-11-25'], $result['supportedVersions']);
        $this->assertArrayHasKey('tools', $result['capabilities']);
        $this->assertArrayHasKey('prompts', $result['capabilities']);
        $this->assertArrayHasKey('resources', $result['capabilities']);
        $this->assertSame('complete', $result['resultType']);
        $this->assertSame(0, $result['ttlMs']);
        $this->assertSame('private', $result['cacheScope']);
        $this->assertSame('wpmcp', $result['_meta']['io.modelcontextprotocol/serverInfo']['name']);
        // The same guidance the 2025 initialize handshake carries.
        $this->assertSame((new Handshake_Instructions())->build(), $result['instructions']);
        $this->assertStringContainsString('Save drafts only.', $result['instructions']);
    }

    /** A bare probe, before the client knows any revision, still gets an answer. */
    public function test_server_discover_without_request_metadata_is_answered(): void
    {
        $response = $this->transport->handle_request([
            'jsonrpc' => '2.0',
            'id'      => 'probe',
            'method'  => 'server/discover',
        ]);

        $this->assertSame('probe', $response['id']);
        $this->assertContains('2026-07-28', $response['result']['supportedVersions']);
    }

    public function test_modern_tools_list_carries_the_2026_result_fields(): void
    {
        $result = $this->modern('tools/list')['result'];

        $this->assertContains('wpmcp-get-page', array_column($result['tools'], 'name'));
        $this->assertSame('complete', $result['resultType']);
        $this->assertSame(0, $result['ttlMs']);
        $this->assertSame('private', $result['cacheScope']);
        $this->assertSame('wpmcp', $result['_meta']['io.modelcontextprotocol/serverInfo']['name']);
    }

    public function test_modern_tools_call_round_trip(): void
    {
        $page_id = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Revision Round Trip']);

        $result = $this->modern('tools/call', [ 'name' => 'wpmcp-get-page', 'arguments' => [ 'id' => $page_id ] ])['result'];

        $this->assertFalse($result['isError'], (string) ($result['content'][0]['text'] ?? ''));
        $this->assertSame('complete', $result['resultType']);
        $this->assertSame('Revision Round Trip', $result['structuredContent']['title'] ?? null);
        // Only list and read results are cacheable.
        $this->assertArrayNotHasKey('ttlMs', $result);
    }

    public function test_modern_prompts_and_resources_lists_answer(): void
    {
        $this->assertSame('complete', $this->modern('prompts/list')['result']['resultType']);
        $this->assertSame('complete', $this->modern('resources/list')['result']['resultType']);
        $this->assertSame([], $this->modern('resources/templates/list')['result']['resourceTemplates']);
    }

    /** 2026-07-28 reports a missing resource as invalid params, not -32002. */
    public function test_modern_unknown_resource_is_invalid_params(): void
    {
        $response = $this->modern('resources/read', [ 'uri' => 'wpmcp://no/such/resource' ]);

        $this->assertSame(-32602, $response['error']['code']);
    }

    /** The 2025 lifecycle methods do not exist under 2026-07-28. */
    public function test_modern_initialize_is_method_not_found(): void
    {
        $this->assertSame(-32601, $this->modern('initialize')['error']['code']);
        $this->assertSame(-32601, $this->modern('ping')['error']['code']);
    }

    public function test_an_unsupported_request_revision_lists_the_supported_ones(): void
    {
        $error = $this->modern('tools/list', [], 9, '2099-01-01')['error'];

        $this->assertSame(-32022, $error['code']);
        $this->assertSame('2099-01-01', $error['data']['requested']);
        $this->assertSame(['2026-07-28', '2025-11-25'], $error['data']['supported']);
    }

    public function test_a_modern_request_without_client_capabilities_is_invalid_params(): void
    {
        $response = $this->transport->handle_request([
            'jsonrpc' => '2.0',
            'id'      => 3,
            'method'  => 'tools/list',
            'params'  => [ '_meta' => [ 'io.modelcontextprotocol/protocolVersion' => '2026-07-28' ] ],
        ]);

        $this->assertSame(-32602, $response['error']['code']);
    }

    /**
     * Initialization is the 2025 flow: a client proposing 2026-07-28 there
     * gets 2025-11-25 as the counter-proposal, and the session is 2025.
     */
    public function test_initialize_counter_proposes_2025_11_25(): void
    {
        foreach (['2026-07-28', '2025-11-25'] as $proposed) {
            $response = $this->transport->handle_request([
                'jsonrpc' => '2.0',
                'id'      => 1,
                'method'  => 'initialize',
                'params'  => [ 'protocolVersion' => $proposed ],
            ]);

            $this->assertSame('2025-11-25', $response['result']['protocolVersion']);
            $this->assertArrayNotHasKey('resultType', $response['result']);
        }
    }

    /** Legacy results stay exactly as they were: no 2026 fields leak in. */
    public function test_legacy_tools_list_has_no_2026_fields(): void
    {
        $result = $this->transport->handle_request([
            'jsonrpc' => '2.0',
            'id'      => 4,
            'method'  => 'tools/list',
        ])['result'];

        $this->assertArrayNotHasKey('resultType', $result);
        $this->assertArrayNotHasKey('ttlMs', $result);
        $this->assertArrayNotHasKey('_meta', $result);
    }
}
