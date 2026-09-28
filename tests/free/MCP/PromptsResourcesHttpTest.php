<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\Governance\Governance;
use WPMCP\MCP\Context_Primitives;
use WPMCP\Skills\Skill_Library;

/**
 * MCP prompts and resources over the HTTP transport (issue #301): real
 * JSON-RPC messages POSTed to the mounted /mcp/wpmcp-server route, through
 * the adapter's session handling and request router, the same path a
 * standard MCP client takes.
 */
class PromptsResourcesHttpTest extends \WP_UnitTestCase
{
    use PrimitivesFixtures;

    private const ROUTE = '/mcp/wpmcp-server';

    private ?string $session = null;

    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->set_up_primitives();
    }

    protected function tearDown(): void
    {
        $this->tear_down_primitives();
        $this->session = null;
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tearDown();
    }

    /**
     * Mount a fresh server with the adapter re-initialised, the same reset
     * McpTransportMountedTest documents (the adapter initialises once per
     * process, WP_UnitTestCase restores hooks per test).
     */
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

    /** @param array<string, mixed> $params */
    private function rpc(string $method, array $params = [], int $id = 11): array
    {
        global $wp_rest_server;

        $message = [ 'jsonrpc' => '2.0', 'id' => $id, 'method' => $method ];
        if ([] !== $params) {
            $message['params'] = $params;
        }

        $request = new \WP_REST_Request('POST', self::ROUTE);
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('Accept', 'application/json, text/event-stream');
        if (null !== $this->session) {
            $request->set_header('Mcp-Session-Id', $this->session);
        }
        $request->set_body((string) wp_json_encode($message));

        $response = rest_do_request($request);
        $response = apply_filters('rest_post_dispatch', rest_ensure_response($response), $wp_rest_server, $request);

        $headers = $response->get_headers();
        if (isset($headers['Mcp-Session-Id'])) {
            $this->session = (string) $headers['Mcp-Session-Id'];
        }

        // Round-trip through JSON, as a client sees it: the adapter hands
        // back DTO arrays that still carry stdClass leaves.
        $data = json_decode((string) wp_json_encode($response->get_data()), true);
        $this->assertIsArray($data, "No JSON-RPC body for $method");
        return $data;
    }

    private function connect(string $role): array
    {
        $this->as_role($role);
        $this->mount();
        $init = $this->rpc('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities'    => new \stdClass(),
            'clientInfo'      => [ 'name' => 'conformance', 'version' => '0.0.0' ],
        ], 1);
        $this->assertNotNull($this->session, 'initialize must issue an Mcp-Session-Id');
        return $init;
    }

    public function test_initialize_advertises_prompts_and_resources(): void
    {
        $caps = $this->connect('editor')['result']['capabilities'];

        $this->assertArrayHasKey('prompts', $caps);
        $this->assertArrayHasKey('resources', $caps);
        $this->assertArrayHasKey('tools', $caps);
    }

    public function test_prompts_list_and_get_round_trip(): void
    {
        $this->connect('editor');

        $by_name = array_column($this->rpc('prompts/list')['result']['prompts'], null, 'name');
        $this->assertArrayHasKey('test-prompt-skill', $by_name);
        $this->assertSame('Test prompt skill', $by_name['test-prompt-skill']['title']);
        $this->assertArrayNotHasKey('test-locked-skill', $by_name);
        $this->assertArrayNotHasKey('test-missing-skill', $by_name);

        $result = $this->rpc('prompts/get', [ 'name' => 'test-prompt-skill' ])['result'];
        $this->assertSame('user', $result['messages'][0]['role']);
        $this->assertSame(Skill_Library::get('test-prompt-skill')['body'], $result['messages'][0]['content']['text']);
    }

    public function test_prompts_get_unknown_is_an_error(): void
    {
        $this->connect('editor');

        $this->assertArrayHasKey('error', $this->rpc('prompts/get', [ 'name' => 'no-such-skill' ]));
    }

    public function test_resources_list_and_read_return_site_context(): void
    {
        $this->connect('editor');

        $uris = array_column($this->rpc('resources/list')['result']['resources'], 'uri');
        $this->assertContains(Context_Primitives::SITE_CONTEXT_URI, $uris);
        $this->assertContains(Context_Primitives::SKILLS_URI, $uris);

        $contents = $this->rpc('resources/read', [ 'uri' => Context_Primitives::SITE_CONTEXT_URI ])['result']['contents'];
        $this->assertSame(Context_Primitives::SITE_CONTEXT_URI, $contents[0]['uri']);
        $this->assertSame('application/json', $contents[0]['mimeType']);
        $this->assertSame(home_url(), json_decode($contents[0]['text'], true)['site']['url']);
    }

    public function test_capability_and_governance_apply_over_http(): void
    {
        $this->connect('subscriber');

        $this->assertSame(-32008, $this->rpc('prompts/get', [ 'name' => 'test-prompt-skill' ])['error']['code']);
        $this->assertSame(-32008, $this->rpc('resources/read', [ 'uri' => Context_Primitives::SITE_CONTEXT_URI ])['error']['code']);

        $this->session = null;
        $this->connect('administrator');
        Governance::set_ability_toggle('wpmcp/get-site-context', false);
        Governance::set_ability_toggle('wpmcp/get-skill', false);

        $this->assertSame(-32008, $this->rpc('resources/read', [ 'uri' => Context_Primitives::SITE_CONTEXT_URI ])['error']['code']);
        $this->assertSame(-32008, $this->rpc('prompts/get', [ 'name' => 'test-prompt-skill' ])['error']['code']);
        $this->assertNotEmpty($this->audit_rows('wpmcp/get-site-context', false));
        $this->assertNotEmpty($this->audit_rows('wpmcp/get-skill', false));
    }
}
