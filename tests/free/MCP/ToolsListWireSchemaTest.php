<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\Governance\Governance;
use WPMCP\Identity\Identity_Context;
use WPMCP\Identity\Identity_Store;
use WPMCP\MCP\Stdio_Transport;
use WPMCP\MCP\Tool_Exposure;
use WPMCP\RateLimit\Rate_Limiter;

/**
 * Every schema in tools/list reaches the wire as JSON Schema, on every
 * transport and every protocol revision (issues #586, #591).
 *
 * PHP has one array type, so an empty `'properties' => []` or a
 * type-less `'value' => []` serializes as a JSON array. Clients that
 * validate the schema (Claude Code) then drop the tool. The assertions
 * here read the raw JSON text and decode it with objects kept as objects,
 * because a json_decode(..., true) round trip turns `{}` and `[]` into the
 * same PHP value and cannot tell a correct response from a broken one.
 * The sweep walks every registered tool's input and output schema to any
 * depth, so a new definition with an empty schema fails here (#591).
 */
class ToolsListWireSchemaTest extends \WP_UnitTestCase
{
    private const ROUTE = '/mcp/wpmcp-server';

    /** Keywords whose value is an object mapping names to schemas. */
    private const SCHEMA_MAPS = [ 'properties', 'patternProperties', 'definitions', '$defs', 'dependentSchemas' ];

    /** Keywords whose value is one schema. */
    private const SCHEMA_SINGLES = [
        'items', 'additionalProperties', 'additionalItems', 'not', 'if', 'then', 'else',
        'contains', 'propertyNames', 'unevaluatedProperties', 'unevaluatedItems', 'contentSchema',
    ];

    /** Keywords whose value is a list of schemas. */
    private const SCHEMA_LISTS = [ 'allOf', 'anyOf', 'oneOf', 'prefixItems' ];

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
        update_option(Tool_Exposure::OPTION, Tool_Exposure::MODE_FULL);
        Rate_Limiter::set_clock_override(fn() => 1_790_000_586);
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
     * POSTs one JSON-RPC message and returns the raw JSON text of the body,
     * encoded the way the REST server sends it.
     *
     * @param array<string,mixed>  $message JSON-RPC message.
     * @param array<string,string> $headers Extra request headers.
     * @return array{0:int,1:string,2:array<string,string>} Status, raw body, headers.
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

        return [ $response->get_status(), (string) wp_json_encode($response->get_data()), $response->get_headers() ];
    }

    private function modern_meta(): array
    {
        return [
            'io.modelcontextprotocol/protocolVersion'    => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
            'io.modelcontextprotocol/clientInfo'         => [ 'name' => 'wire-schema', 'version' => '0.0.0' ],
        ];
    }

    private function http_modern_tools_list(): string
    {
        [ $status, $raw ] = $this->post(
            [ 'jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/list', 'params' => [ '_meta' => $this->modern_meta() ] ],
            [ 'MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/list' ]
        );
        $this->assertSame(200, $status, $raw);

        return $raw;
    }

    private function http_session_tools_list(): string
    {
        [ $status, $raw, $headers ] = $this->post([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'initialize',
            'params'  => [
                'protocolVersion' => '2025-11-25',
                'capabilities'    => new \stdClass(),
                'clientInfo'      => [ 'name' => 'wire-schema', 'version' => '0.0.0' ],
            ],
        ]);
        $this->assertSame(200, $status, $raw);
        $this->assertArrayHasKey('Mcp-Session-Id', $headers);

        [ $status, $raw ] = $this->post(
            [ 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list' ],
            [ 'Mcp-Session-Id' => (string) $headers['Mcp-Session-Id'], 'MCP-Protocol-Version' => '2025-11-25' ]
        );
        $this->assertSame(200, $status, $raw);

        return $raw;
    }

    /** The stdio transport writes wp_json_encode() of the response, one line per message. */
    private function stdio_tools_list(bool $modern): string
    {
        $transport = new Stdio_Transport();
        if ($modern) {
            $response = $transport->handle_request([ 'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list', 'params' => [ '_meta' => $this->modern_meta() ] ]);
        } else {
            $transport->handle_request([
                'jsonrpc' => '2.0',
                'id'      => 1,
                'method'  => 'initialize',
                'params'  => [ 'protocolVersion' => '2025-11-25', 'capabilities' => new \stdClass() ],
            ]);
            $response = $transport->handle_request([ 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list' ]);
        }
        $this->assertIsArray($response);

        return (string) wp_json_encode($response);
    }

    /**
     * Decodes a tools/list body with JSON objects kept as objects.
     *
     * @return array<string,object> Tool name to tool object.
     */
    private function tools(string $raw): array
    {
        $body = json_decode($raw, false);
        $this->assertIsObject($body, $raw);
        $this->assertTrue(isset($body->result->tools), $raw);

        $tools = [];
        foreach ($body->result->tools as $tool) {
            $tools[ $tool->name ] = $tool;
        }
        $this->assertGreaterThan(100, count($tools), 'Sanity: the sweep must cover the full surface.');

        return $tools;
    }

    /**
     * Collects every place a schema is not a JSON object or boolean, or a
     * schema-map keyword is not a JSON object, at any depth.
     *
     * @param mixed    $node   A decoded schema.
     * @param string[] $errors Collected paths.
     */
    private function sweep($node, string $path, array &$errors): void
    {
        if (is_bool($node)) {
            return;
        }
        if (! is_object($node)) {
            $errors[] = $path . ' is ' . wp_json_encode($node) . ', not a schema object';
            return;
        }

        foreach (self::SCHEMA_MAPS as $keyword) {
            if (! property_exists($node, $keyword)) {
                continue;
            }
            if (! is_object($node->$keyword)) {
                $errors[] = $path . '.' . $keyword . ' is ' . wp_json_encode($node->$keyword) . ', not an object';
                continue;
            }
            foreach (get_object_vars($node->$keyword) as $name => $child) {
                $this->sweep($child, $path . '.' . $keyword . '.' . $name, $errors);
            }
        }
        foreach (self::SCHEMA_SINGLES as $keyword) {
            if (! property_exists($node, $keyword)) {
                continue;
            }
            if ('items' === $keyword && is_array($node->items) && [] !== $node->items) {
                // Draft-04 tuple form: a non-empty list of schemas.
                foreach ($node->items as $i => $child) {
                    $this->sweep($child, $path . '.items[' . $i . ']', $errors);
                }
                continue;
            }
            $this->sweep($node->$keyword, $path . '.' . $keyword, $errors);
        }
        foreach (self::SCHEMA_LISTS as $keyword) {
            if (! property_exists($node, $keyword)) {
                continue;
            }
            if (! is_array($node->$keyword)) {
                $errors[] = $path . '.' . $keyword . ' is not a list';
                continue;
            }
            foreach ($node->$keyword as $i => $child) {
                $this->sweep($child, $path . '.' . $keyword . '[' . $i . ']', $errors);
            }
        }
    }

    private function assert_wire_schemas(string $raw, string $transport): void
    {
        $this->assertStringNotContainsString('"properties":[]', $raw, $transport . ': raw tools/list has an empty properties array.');

        $errors = [];
        foreach ($this->tools($raw) as $name => $tool) {
            $this->assertIsObject($tool->inputSchema ?? null, $transport . ': ' . $name . ' inputSchema is not an object');
            $this->assertSame('object', $tool->inputSchema->type ?? null, $transport . ': ' . $name . ' inputSchema type');
            $this->sweep($tool->inputSchema, $name . '.inputSchema', $errors);
            if (property_exists($tool, 'outputSchema')) {
                $this->sweep($tool->outputSchema, $name . '.outputSchema', $errors);
            }
        }

        $this->assertSame([], $errors, $transport . ": schemas that are not JSON objects:\n" . implode("\n", $errors));
    }

    /** The two tools the issues name, checked on the exact bytes. */
    private function assert_named_tools(string $raw, string $transport): void
    {
        $tools = $this->tools($raw);

        $this->assertArrayHasKey('wpmcp-get-site-context', $tools);
        $this->assertEquals(new \stdClass(), $tools['wpmcp-get-site-context']->inputSchema->properties ?? new \stdClass(), $transport);
        $this->assertStringNotContainsString('"properties":[]', (string) wp_json_encode($tools['wpmcp-get-site-context']), $transport . ' (#586)');

        $this->assertArrayHasKey('wpmcp-set-term-meta', $tools);
        $this->assertIsObject($tools['wpmcp-set-term-meta']->inputSchema->properties->value, $transport . ' (#591)');
        $this->assertStringContainsString('"value":{}', (string) wp_json_encode($tools['wpmcp-set-term-meta']), $transport . ' (#591)');
    }

    public function test_http_2026_07_28_tools_list_sends_every_schema_as_an_object(): void
    {
        $raw = $this->http_modern_tools_list();

        $this->assert_wire_schemas($raw, 'HTTP 2026-07-28');
        $this->assert_named_tools($raw, 'HTTP 2026-07-28');
    }

    public function test_http_2025_11_25_tools_list_sends_every_schema_as_an_object(): void
    {
        $raw = $this->http_session_tools_list();

        $this->assert_wire_schemas($raw, 'HTTP 2025-11-25');
        $this->assert_named_tools($raw, 'HTTP 2025-11-25');
    }

    public function test_stdio_2026_07_28_tools_list_sends_every_schema_as_an_object(): void
    {
        $raw = $this->stdio_tools_list(true);

        $this->assert_wire_schemas($raw, 'stdio 2026-07-28');
        $this->assert_named_tools($raw, 'stdio 2026-07-28');
    }

    public function test_stdio_2025_11_25_tools_list_sends_every_schema_as_an_object(): void
    {
        $raw = $this->stdio_tools_list(false);

        $this->assert_wire_schemas($raw, 'stdio 2025-11-25');
        $this->assert_named_tools($raw, 'stdio 2025-11-25');
    }
}
