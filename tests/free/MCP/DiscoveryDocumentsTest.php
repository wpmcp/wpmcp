<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\Auth\Mcp_Resource;
use WPMCP\Governance\Governance;
use WPMCP\MCP\Discovery_Documents;
use WPMCP\MCP\Discovery_Endpoints;
use WPMCP\Skills\Skill_Library;

/**
 * Agent discovery documents (issue #302).
 *
 *  - The MCP Server Card (SEP-2127, extension io.modelcontextprotocol/server-card),
 *    served at <streamable-http-url>/server-card and validated against the
 *    extension's published schema.json, vendored from
 *    modelcontextprotocol/experimental-ext-server-card at 526201bb as
 *    tests/support/schemas/mcp-server-card.v1.schema.json (dashes in its
 *    description strings normalized, see the README next to it).
 *  - The AI Catalog at /.well-known/ai-catalog.json that points at the card.
 *  - The Agent Skills discovery index at /.well-known/agent-skills/index.json
 *    (schema 0.2.0) with one skill-md artifact per published skill.
 *
 * Everything here is public, unauthenticated metadata, so the assertions
 * that matter most are the negative ones: nothing governance-disabled,
 * unavailable, paid or site-custom is advertised.
 */
class DiscoveryDocumentsTest extends \WP_UnitTestCase
{
    use PrimitivesFixtures;

    private const CARD_SCHEMA   = __DIR__ . '/../../support/schemas/mcp-server-card.v1.schema.json';
    private const SKILLS_SCHEMA = __DIR__ . '/../../support/schemas/agent-skills-discovery-0.2.0.schema.json';

    /** @var mixed */
    private $original_request_uri;

    /** @var mixed */
    private $original_method;

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
        wp_set_current_user(0);
        Discovery_Endpoints::set_test_mode(true);
        $this->original_request_uri = $_SERVER['REQUEST_URI'] ?? null;
        $this->original_method      = $_SERVER['REQUEST_METHOD'] ?? null;
    }

    protected function tearDown(): void
    {
        Discovery_Endpoints::set_test_mode(false);
        remove_all_filters('wpmcp_discovery_documents_enabled');
        remove_all_filters('wpmcp_discovery_publish_skill');
        remove_all_filters('wpmcp_oauth_enabled');
        unset($_SERVER['HTTP_IF_NONE_MATCH']);
        foreach (['REQUEST_URI' => $this->original_request_uri, 'REQUEST_METHOD' => $this->original_method] as $key => $value) {
            if (null === $value) {
                unset($_SERVER[ $key ]);
            } else {
                $_SERVER[ $key ] = $value;
            }
        }
        $this->tear_down_primitives();
        parent::tearDown();
    }

    /** @return array{status: int, headers: array<string, string>, body: string}|null */
    private function request(string $url_or_path, string $method = 'GET'): ?array
    {
        $path = (string) wp_parse_url($url_or_path, PHP_URL_PATH);
        $_SERVER['REQUEST_URI']    = $path;
        $_SERVER['REQUEST_METHOD'] = $method;

        return Discovery_Endpoints::maybe_serve();
    }

    /** @return array<string, mixed> */
    private function json_ok(string $url_or_path): array
    {
        $response = $this->request($url_or_path);
        $this->assertNotNull($response, "Nothing served at $url_or_path");
        $this->assertSame(200, $response['status']);
        $data = json_decode($response['body'], true);
        $this->assertIsArray($data);
        return $data;
    }

    private function include_fixture_skills(): void
    {
        add_filter('wpmcp_discovery_publish_skill', static fn (bool $publish, array $record) => $publish || 'fixture' === $record['source'], 10, 2);
    }

    public function test_server_card_is_served_under_the_mcp_endpoint(): void
    {
        $this->assertSame(Mcp_Resource::canonical() . '/server-card', Discovery_Documents::server_card_url());

        $response = $this->request(Discovery_Documents::server_card_url());

        $this->assertNotNull($response);
        $this->assertSame(200, $response['status']);
        $this->assertSame('application/mcp-server-card+json', $response['headers']['Content-Type']);
    }

    public function test_server_card_validates_against_the_published_schema(): void
    {
        $card = $this->json_ok(Discovery_Documents::server_card_url());

        $this->assertSame([], Json_Schema_Subset::validate_file(self::CARD_SCHEMA, $card, '#/$defs/ServerCard'));
    }

    public function test_server_card_describes_the_mcp_endpoint(): void
    {
        $card = $this->json_ok(Discovery_Documents::server_card_url());

        $this->assertSame('https://static.modelcontextprotocol.io/schemas/v1/server-card.schema.json', $card['$schema']);
        $this->assertSame(WPMCP_VERSION, $card['version']);
        $this->assertMatchesRegularExpression('#^[a-zA-Z0-9.-]+/[a-zA-Z0-9._-]+$#', $card['name']);
        $this->assertCount(1, $card['remotes']);
        $this->assertSame('streamable-http', $card['remotes'][0]['type']);
        $this->assertSame(Mcp_Resource::canonical(), $card['remotes'][0]['url']);
        $this->assertContains('2025-06-18', $card['remotes'][0]['supportedProtocolVersions']);
        // Both schema-backed revisions, whichever adapter copy mounts the
        // endpoint (issue #386).
        $this->assertContains('2026-07-28', $card['remotes'][0]['supportedProtocolVersions']);
        $this->assertContains('2025-11-25', $card['remotes'][0]['supportedProtocolVersions']);
    }

    public function test_server_card_meta_reports_primitives_and_the_skills_index(): void
    {
        $meta = $this->json_ok(Discovery_Documents::server_card_url())['_meta'][ Discovery_Documents::META_KEY ];

        $this->assertSame(['tools' => true, 'prompts' => true, 'resources' => true], $meta['primitives']);
        $this->assertSame(home_url('/.well-known/agent-skills/index.json'), $meta['skillsIndex']);
    }

    public function test_server_card_points_at_protected_resource_metadata_when_oauth_is_on(): void
    {
        add_filter('wpmcp_oauth_enabled', '__return_true');

        $card = $this->json_ok(Discovery_Documents::server_card_url());
        $auth = $card['_meta'][ Discovery_Documents::META_KEY ]['authorization'];

        $this->assertSame('oauth2', $auth['type']);
        $this->assertSame(Mcp_Resource::metadata_url(), $auth['protectedResourceMetadata']);
        $this->assertArrayNotHasKey('headers', $card['remotes'][0]);
    }

    public function test_server_card_asks_for_an_authorization_header_when_oauth_is_off(): void
    {
        add_filter('wpmcp_oauth_enabled', '__return_false');

        $card = $this->json_ok(Discovery_Documents::server_card_url());

        $this->assertSame('http-basic', $card['_meta'][ Discovery_Documents::META_KEY ]['authorization']['type']);
        $this->assertArrayNotHasKey('protectedResourceMetadata', $card['_meta'][ Discovery_Documents::META_KEY ]['authorization']);
        $header = $card['remotes'][0]['headers'][0];
        $this->assertSame('Authorization', $header['name']);
        $this->assertTrue($header['isRequired']);
        $this->assertTrue($header['isSecret']);
        $this->assertArrayNotHasKey('value', $header);
    }

    public function test_ai_catalog_links_the_server_card(): void
    {
        $response = $this->request('/.well-known/ai-catalog.json');
        $this->assertSame('application/ai-catalog+json', $response['headers']['Content-Type']);

        $catalog = json_decode($response['body'], true);
        $this->assertSame('1.0', $catalog['specVersion']);
        $this->assertIsString($catalog['host']['displayName']);
        $cards = array_values(array_filter($catalog['entries'], static fn ($e) => 'application/mcp-server-card+json' === $e['type']));
        $this->assertCount(1, $cards);
        $this->assertSame(Discovery_Documents::server_card_url(), $cards[0]['url']);
        $this->assertStringStartsWith('urn:air:', $cards[0]['identifier']);
        foreach ($catalog['entries'] as $entry) {
            $this->assertTrue(isset($entry['url']) xor isset($entry['data']), 'Each entry carries exactly one of url or data');
        }
    }

    public function test_skills_index_validates_against_the_discovery_schema(): void
    {
        $this->include_fixture_skills();
        $index = $this->json_ok('/.well-known/agent-skills/index.json');

        $this->assertSame([], Json_Schema_Subset::validate_file(self::SKILLS_SCHEMA, $index));
        $this->assertNotEmpty($index['skills']);
    }

    public function test_skills_index_lists_bundled_skills_with_required_tools(): void
    {
        $index   = $this->json_ok('/.well-known/agent-skills/index.json');
        $by_name = array_column($index['skills'], null, 'name');

        $this->assertArrayHasKey('wpmcp-governance', $by_name);
        $entry = $by_name['wpmcp-governance'];
        $this->assertSame(Skill_Library::get('wpmcp-governance')['description'], $entry['description']);
        $this->assertSame('skill-md', $entry['type']);
        $this->assertSame(Skill_Library::index()['wpmcp-governance']['requires'], $entry[ Discovery_Documents::META_KEY ]['requiredTools']);
        $this->assertSame('wpmcp-governance', $entry[ Discovery_Documents::META_KEY ]['prompt']);
    }

    public function test_site_custom_skills_are_not_published_by_default(): void
    {
        $names = array_column($this->json_ok('/.well-known/agent-skills/index.json')['skills'], 'name');

        $this->assertNotContains('test-prompt-skill', $names);
        $this->assertSame(404, $this->request('/.well-known/agent-skills/test-prompt-skill/SKILL.md')['status']);
    }

    public function test_locked_and_unavailable_skills_are_never_published(): void
    {
        $this->include_fixture_skills();
        $response = $this->request('/.well-known/agent-skills/index.json');
        $names    = array_column(json_decode($response['body'], true)['skills'], 'name');

        $this->assertContains('test-prompt-skill', $names);
        $this->assertNotContains('test-locked-skill', $names);
        $this->assertNotContains('test-missing-skill', $names);
        $this->assertStringNotContainsString('wpmcp/no-such-ability', $response['body']);
        $this->assertSame(404, $this->request('/.well-known/agent-skills/test-locked-skill/SKILL.md')['status']);
    }

    public function test_skill_artifact_matches_its_digest_and_the_agent_skills_format(): void
    {
        $this->include_fixture_skills();
        $by_name = array_column($this->json_ok('/.well-known/agent-skills/index.json')['skills'], null, 'name');
        $entry   = $by_name['test-prompt-skill'];

        $artifact = $this->request($entry['url']);

        $this->assertSame(200, $artifact['status']);
        $this->assertStringStartsWith('text/markdown', $artifact['headers']['Content-Type']);
        $this->assertSame('sha256:' . hash('sha256', $artifact['body']), $entry['digest']);
        $this->assertMatchesRegularExpression('/\A---\nname: test-prompt-skill\ndescription: /', $artifact['body']);
        $this->assertStringContainsString(Skill_Library::get('test-prompt-skill')['body'], $artifact['body']);
    }

    public function test_governance_disabled_required_tool_hides_the_skill(): void
    {
        $this->include_fixture_skills();
        $required = Skill_Library::index()['wpmcp-governance']['requires'][0];
        Governance::set_ability_toggle($required, false);

        $response = $this->request('/.well-known/agent-skills/index.json');

        $this->assertNotContains('wpmcp-governance', array_column(json_decode($response['body'], true)['skills'], 'name'));
        $this->assertStringNotContainsString($required, $response['body']);
        $this->assertSame(404, $this->request('/.well-known/agent-skills/wpmcp-governance/SKILL.md')['status']);
    }

    public function test_governance_disabled_skill_tools_remove_the_skills_surface(): void
    {
        Governance::set_ability_toggle('wpmcp/get-skill', false);
        Governance::set_ability_toggle('wpmcp/get-site-context', false);
        Governance::set_ability_toggle('wpmcp/list-skills', false);

        $this->assertSame(404, $this->request('/.well-known/agent-skills/index.json')['status']);
        $meta = $this->json_ok(Discovery_Documents::server_card_url())['_meta'][ Discovery_Documents::META_KEY ];
        $this->assertFalse($meta['primitives']['prompts']);
        $this->assertFalse($meta['primitives']['resources']);
        $this->assertArrayNotHasKey('skillsIndex', $meta);
    }

    public function test_documents_carry_no_private_data(): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator', 'user_login' => 'discovery-admin', 'user_email' => 'discovery-admin@example.org']);
        wp_set_current_user($admin);

        $bodies = implode("\n", [
            $this->request(Discovery_Documents::server_card_url())['body'],
            $this->request('/.well-known/ai-catalog.json')['body'],
            $this->request('/.well-known/agent-skills/index.json')['body'],
        ]);

        foreach (['discovery-admin', get_option('admin_email'), 'wp-content', ABSPATH, wp_create_nonce('wp_rest')] as $needle) {
            $this->assertStringNotContainsString((string) $needle, $bodies);
        }
    }

    public function test_responses_are_public_cacheable_and_cors_open(): void
    {
        foreach ([Discovery_Documents::server_card_url(), '/.well-known/ai-catalog.json', '/.well-known/agent-skills/index.json'] as $url) {
            $headers = $this->request($url)['headers'];

            $this->assertSame('public, max-age=' . Discovery_Endpoints::MAX_AGE, $headers['Cache-Control'], $url);
            $this->assertSame('*', $headers['Access-Control-Allow-Origin'], $url);
            $this->assertSame('ETag', $headers['Access-Control-Expose-Headers'], $url);
            $this->assertMatchesRegularExpression('/^"[0-9a-f]{32}"$/', $headers['ETag'], $url);
        }
    }

    public function test_matching_if_none_match_returns_304_without_a_body(): void
    {
        $etag = $this->request(Discovery_Documents::server_card_url())['headers']['ETag'];

        $_SERVER['HTTP_IF_NONE_MATCH'] = $etag;
        $response = $this->request(Discovery_Documents::server_card_url());

        $this->assertSame(304, $response['status']);
        $this->assertSame('', $response['body']);
    }

    public function test_head_and_options_are_answered_without_a_body(): void
    {
        $head = $this->request('/.well-known/ai-catalog.json', 'HEAD');
        $this->assertSame(200, $head['status']);
        $this->assertSame('', $head['body']);

        $options = $this->request('/.well-known/ai-catalog.json', 'OPTIONS');
        $this->assertSame(204, $options['status']);
        $this->assertSame('*', $options['headers']['Access-Control-Allow-Origin']);
    }

    public function test_other_methods_and_paths_fall_through(): void
    {
        $this->assertNull($this->request('/.well-known/ai-catalog.json', 'POST'));
        $this->assertNull($this->request('/some/other/path'));
        $this->assertNull($this->request('/.well-known/oauth-protected-resource'));
        $this->assertNull($this->request(Mcp_Resource::canonical()));
    }

    public function test_unknown_or_traversing_skill_paths_are_404(): void
    {
        foreach (['/.well-known/agent-skills/nope/SKILL.md', '/.well-known/agent-skills/../../wp-config.php/SKILL.md', '/.well-known/agent-skills/wpmcp-governance/../SKILL.md'] as $path) {
            $this->assertSame(404, $this->request($path)['status'], $path);
        }
    }

    public function test_filter_switches_every_document_off(): void
    {
        add_filter('wpmcp_discovery_documents_enabled', '__return_false');

        foreach ([Discovery_Documents::server_card_url(), '/.well-known/ai-catalog.json', '/.well-known/agent-skills/index.json'] as $url) {
            $this->assertNull($this->request($url), $url);
        }
    }

    public function test_the_hook_is_registered_before_the_rest_api_parses_the_request(): void
    {
        $this->assertSame(1, has_action('parse_request', [Discovery_Endpoints::class, 'maybe_serve']));
    }
}
