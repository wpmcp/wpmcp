<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\Governance\Governance;
use WPMCP\Identity\Identity_Context;
use WPMCP\Identity\Identity_Store;
use WPMCP\MCP\Context_Primitives;
use WPMCP\MCP\Stdio_Transport;
use WPMCP\Skills\Skill_Library;

/**
 * MCP prompts and resources over the stdio transport (issue #301), driven
 * through Stdio_Transport::handle_request() exactly like the existing
 * handshake and tools/call suite.
 *
 * The skills library is served as prompts and the read-only site context
 * as resources. Each primitive is backed by the live wpmcp ability that
 * already serves the same data as a tool (get-skill, get-site-context,
 * list-skills), so the permission chain, audit trail and rate limit are the
 * tool's own, not a parallel implementation.
 */
class PromptsResourcesStdioTest extends \WP_UnitTestCase
{
    use PrimitivesFixtures;

    private Stdio_Transport $transport;

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
        $this->transport = new Stdio_Transport();
    }

    protected function tearDown(): void
    {
        $this->tear_down_primitives();
        parent::tearDown();
    }

    /** @param array<string, mixed> $params */
    private function rpc(string $method, array $params = [], int $id = 7): array
    {
        $request = [ 'jsonrpc' => '2.0', 'id' => $id, 'method' => $method ];
        if ([] !== $params) {
            $request['params'] = $params;
        }
        $response = $this->transport->handle_request($request);
        $this->assertIsArray($response);
        $this->assertSame($id, $response['id']);
        return $response;
    }

    public function test_initialize_advertises_prompts_and_resources(): void
    {
        $caps = $this->rpc('initialize', [ 'protocolVersion' => '2025-11-25' ])['result']['capabilities'];

        $this->assertSame([ 'listChanged' => false ], $caps['prompts']);
        $this->assertSame([ 'subscribe' => false, 'listChanged' => false ], $caps['resources']);
        $this->assertSame([ 'listChanged' => false ], $caps['tools']);
    }

    public function test_prompts_list_serves_available_free_skills_only(): void
    {
        $this->as_role('editor');

        $prompts = $this->rpc('prompts/list')['result']['prompts'];
        $by_name = array_column($prompts, null, 'name');

        $this->assertArrayHasKey('test-prompt-skill', $by_name);
        $this->assertSame('Test prompt skill', $by_name['test-prompt-skill']['title']);
        $this->assertSame('A fixture playbook served as an MCP prompt.', $by_name['test-prompt-skill']['description']);
        $this->assertArrayNotHasKey('test-locked-skill', $by_name, 'A pro skill whose body is withheld must not be offered as a prompt.');
        $this->assertArrayNotHasKey('test-missing-skill', $by_name, 'A skill whose tools are missing must not be offered.');
    }

    public function test_prompts_get_returns_the_skill_body_verbatim_as_a_user_message(): void
    {
        $this->as_role('editor');

        $result = $this->rpc('prompts/get', [ 'name' => 'test-prompt-skill' ])['result'];

        $this->assertSame('A fixture playbook served as an MCP prompt.', $result['description']);
        $this->assertCount(1, $result['messages']);
        $this->assertSame('user', $result['messages'][0]['role']);
        $this->assertSame('text', $result['messages'][0]['content']['type']);
        $this->assertSame(Skill_Library::get('test-prompt-skill')['body'], $result['messages'][0]['content']['text']);
    }

    public function test_prompts_get_rejects_unknown_and_unlisted_prompts(): void
    {
        $this->as_role('editor');

        $this->assertSame(-32602, $this->rpc('prompts/get', [ 'name' => 'no-such-skill' ])['error']['code']);
        $this->assertSame(-32602, $this->rpc('prompts/get', [ 'name' => 'test-locked-skill' ])['error']['code']);
        $this->assertSame(-32602, $this->rpc('prompts/get', [])['error']['code']);
    }

    public function test_resources_list_advertises_site_context_and_skill_catalog(): void
    {
        $this->as_role('editor');

        $resources = array_column($this->rpc('resources/list')['result']['resources'], null, 'uri');

        $this->assertArrayHasKey(Context_Primitives::SITE_CONTEXT_URI, $resources);
        $this->assertSame('application/json', $resources[ Context_Primitives::SITE_CONTEXT_URI ]['mimeType']);
        $this->assertArrayHasKey(Context_Primitives::SKILLS_URI, $resources);
        foreach ($resources as $uri => $resource) {
            $this->assertStringStartsWith('wpmcp://', $uri);
            $this->assertNotSame('', $resource['name']);
        }
    }

    public function test_resources_read_returns_the_same_payload_as_the_site_context_tool(): void
    {
        $this->as_role('editor');

        $contents = $this->rpc('resources/read', [ 'uri' => Context_Primitives::SITE_CONTEXT_URI ])['result']['contents'];

        $this->assertCount(1, $contents);
        $this->assertSame(Context_Primitives::SITE_CONTEXT_URI, $contents[0]['uri']);
        $this->assertSame('application/json', $contents[0]['mimeType']);

        $decoded = json_decode($contents[0]['text'], true);
        $this->assertSame(home_url(), $decoded['site']['url']);
        $this->assertSame(wp_get_ability('wpmcp/get-site-context')->execute([]), $decoded);
    }

    public function test_resources_read_skill_catalog_lists_the_fixture(): void
    {
        $this->as_role('editor');

        $contents = $this->rpc('resources/read', [ 'uri' => Context_Primitives::SKILLS_URI ])['result']['contents'];
        $slugs    = array_column(json_decode($contents[0]['text'], true)['skills'], 'slug');

        $this->assertContains('test-prompt-skill', $slugs);
    }

    public function test_resources_read_unknown_uri_is_resource_not_found(): void
    {
        $this->as_role('editor');

        $this->assertSame(-32002, $this->rpc('resources/read', [ 'uri' => 'wpmcp://nope' ])['error']['code']);
        $this->assertSame(-32602, $this->rpc('resources/read', [])['error']['code']);
    }

    public function test_resource_templates_list_is_an_empty_list(): void
    {
        $this->assertSame([], $this->rpc('resources/templates/list')['result']['resourceTemplates']);
    }

    public function test_capability_is_enforced_like_the_backing_tool(): void
    {
        $this->as_role('subscriber');

        $this->assertSame(-32008, $this->rpc('prompts/get', [ 'name' => 'test-prompt-skill' ])['error']['code']);
        $this->assertSame(-32008, $this->rpc('resources/read', [ 'uri' => Context_Primitives::SITE_CONTEXT_URI ])['error']['code']);
    }

    public function test_governance_disable_denies_and_is_audited_under_the_backing_ability(): void
    {
        $this->as_role('administrator');

        Governance::set_ability_toggle('wpmcp/get-skill', false);
        Governance::set_ability_toggle('wpmcp/get-site-context', false);

        $this->assertSame(-32008, $this->rpc('prompts/get', [ 'name' => 'test-prompt-skill' ])['error']['code']);
        $this->assertSame(-32008, $this->rpc('resources/read', [ 'uri' => Context_Primitives::SITE_CONTEXT_URI ])['error']['code']);

        $this->assertNotEmpty($this->audit_rows('wpmcp/get-skill', false));
        $this->assertNotEmpty($this->audit_rows('wpmcp/get-site-context', false));
    }

    public function test_identity_scope_narrowing_applies(): void
    {
        $this->as_role('administrator');

        Identity_Store::create('core-only-bot', [ 'domains' => [ 'core' ] ]);
        Identity_Context::set_current_for_tests('core-only-bot');

        $this->assertSame(-32008, $this->rpc('prompts/get', [ 'name' => 'test-prompt-skill' ])['error']['code']);
        $this->assertSame(-32008, $this->rpc('resources/read', [ 'uri' => Context_Primitives::SITE_CONTEXT_URI ])['error']['code']);

        Identity_Store::create('context-bot', [ 'domains' => [ 'skills', 'context' ] ]);
        Identity_Context::set_current_for_tests('context-bot');

        $this->assertArrayHasKey('result', $this->rpc('prompts/get', [ 'name' => 'test-prompt-skill' ]));
        $this->assertArrayHasKey('result', $this->rpc('resources/read', [ 'uri' => Context_Primitives::SITE_CONTEXT_URI ]));
    }

    public function test_reads_spend_the_same_rate_limit_budget_as_tools(): void
    {
        $this->as_role('administrator');

        remove_all_filters('wpmcp_rate_limit');
        add_filter('wpmcp_rate_limit', fn() => 1);
        add_filter('wpmcp_rate_limit_window', fn() => 60);

        $this->assertArrayHasKey('result', $this->rpc('resources/read', [ 'uri' => Context_Primitives::SITE_CONTEXT_URI ]));

        $throttled = $this->rpc('prompts/get', [ 'name' => 'test-prompt-skill' ]);
        $this->assertSame(-32603, $throttled['error']['code']);
        $this->assertStringContainsString('Rate limit', $throttled['error']['message']);

        remove_all_filters('wpmcp_rate_limit_window');
    }

    public function test_primitives_never_write_site_content(): void
    {
        $this->as_role('administrator');

        $before = wp_count_posts('post');
        $this->rpc('prompts/get', [ 'name' => 'test-prompt-skill' ]);
        $this->rpc('resources/read', [ 'uri' => Context_Primitives::SITE_CONTEXT_URI ]);
        $this->rpc('resources/read', [ 'uri' => Context_Primitives::SKILLS_URI ]);

        $this->assertEquals($before, wp_count_posts('post'));

        // Structural guarantee: every backing ability is a registered
        // read-only operation, so no primitive can ever reach a write path.
        foreach (Context_Primitives::backing_abilities() as $name) {
            $ability = \WPMCP\Plugin::instance()->registrar()->get($name);
            $this->assertNotNull($ability, $name);
            $this->assertSame('read', $ability->operation, $name);
            $this->assertTrue($ability->read_only_hint, $name);
        }
    }
}
