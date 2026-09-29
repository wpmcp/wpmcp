<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\Governance\Governance;
use WPMCP\Identity\Identity_Context;
use WPMCP\Identity\Identity_Store;
use WPMCP\MCP\Stdio_Transport;
use WPMCP\MCP\Structured_Result;
use WPMCP\MCP\Tool_Exposure;
use WPMCP\RateLimit\Rate_Limiter;
use WPMCP\Tools\Backup\Backup_Job_Store;
use WPMCP\Tools\Backup\Run_Backup_Job;

/**
 * Tool output schemas (issue #387).
 *
 * Every wpmcp tool answers with structuredContent, so every wpmcp tool
 * declares an outputSchema, on both transports and both revisions. The
 * schema is a promise the MCP spec makes binding ("servers MUST provide
 * structured results that conform"), so this suite checks the promise
 * against real results rather than only checking that the field exists:
 *
 *  - the tools with a detailed contract (the backup and CLI job tools) are
 *    called and their structuredContent validated against the schema;
 *  - every other tool declares the object contract that
 *    Structured_Result::normalize() guarantees at the wire boundary, and
 *    that guarantee is validated for every shape a tool can return.
 *
 * outputSchema is for the client to validate results with, not text the
 * model reads, so it is kept out of the tools/list token budget
 * (ToolsListBudgetTest) and capped separately here instead.
 */
class OutputSchemaConformanceTest extends \WP_UnitTestCase
{
    /**
     * Max JSON bytes of all wpmcp outputSchema values together. About 32
     * bytes per tool for the object contract, plus the detailed job
     * schemas; a tool that grows a detailed schema spends from here.
     */
    private const OUTPUT_SCHEMA_BYTE_BUDGET = 16000;

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
        delete_option(Backup_Job_Store::OPTION);
        Rate_Limiter::set_clock_override(fn() => 1_790_000_387);
        add_filter('wpmcp_rate_limit', fn() => 100000);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_rate_limit');
        Rate_Limiter::set_clock_override(null);
        wp_clear_scheduled_hook(Run_Backup_Job::HOOK);
        delete_option(Backup_Job_Store::OPTION);
        Identity_Context::set_current_for_tests(null);
        Governance::reset_for_tests();
        delete_option(Identity_Store::OPTION);
        delete_option(Tool_Exposure::OPTION);
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tearDown();
    }

    /** @return array<string, array<string,mixed>> tool name => tools/list entry, over stdio. */
    private function stdio_tools(bool $modern): array
    {
        $params = [];
        if ($modern) {
            $params['_meta'] = [
                'io.modelcontextprotocol/protocolVersion'    => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
            ];
        }
        $response = (new Stdio_Transport())->handle_request([ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => $params ]);
        $tools    = json_decode((string) wp_json_encode($response), true)['result']['tools'];

        return array_column($tools, null, 'name');
    }

    /** @return array<string, array<string,mixed>> tool name => tools/list entry, over the 2025 HTTP session. */
    private function http_tools(): array
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

        $post = static function (array $message, array $headers = []) use (&$wp_rest_server): array {
            $request = new \WP_REST_Request('POST', self::ROUTE);
            $request->set_header('Content-Type', 'application/json');
            $request->set_header('Accept', 'application/json, text/event-stream');
            foreach ($headers as $name => $value) {
                $request->set_header($name, $value);
            }
            $request->set_body((string) wp_json_encode($message));
            $response = apply_filters('rest_post_dispatch', rest_ensure_response(rest_do_request($request)), $wp_rest_server, $request);

            return [ json_decode((string) wp_json_encode($response->get_data()), true), $response->get_headers() ];
        };

        [ , $headers ] = $post([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'initialize',
            'params'  => [ 'protocolVersion' => '2025-11-25', 'capabilities' => new \stdClass(), 'clientInfo' => [ 'name' => 'conformance', 'version' => '0.0.0' ] ],
        ]);
        [ $body ] = $post(
            [ 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list' ],
            [ 'Mcp-Session-Id' => (string) $headers['Mcp-Session-Id'], 'MCP-Protocol-Version' => '2025-11-25' ]
        );

        return array_column($body['result']['tools'], null, 'name');
    }

    /** @param array<string, array<string,mixed>> $tools */
    private function assert_every_wpmcp_tool_declares_an_object_output_schema(array $tools, string $where): void
    {
        $wpmcp = array_filter($tools, static fn(array $tool): bool => str_starts_with((string) $tool['name'], 'wpmcp-'));
        $this->assertGreaterThan(100, count($wpmcp), $where);

        foreach ($wpmcp as $name => $tool) {
            $this->assertIsArray($tool['outputSchema'] ?? null, "$where: $name has no outputSchema");
            $this->assertSame('object', $tool['outputSchema']['type'] ?? null, "$where: $name outputSchema must have an object root");
        }
    }

    public function test_every_tool_declares_an_output_schema_on_every_listing(): void
    {
        $this->assert_every_wpmcp_tool_declares_an_object_output_schema($this->stdio_tools(false), 'stdio 2025-11-25');
        $this->assert_every_wpmcp_tool_declares_an_object_output_schema($this->stdio_tools(true), 'stdio 2026-07-28');
        $this->assert_every_wpmcp_tool_declares_an_object_output_schema($this->http_tools(), 'HTTP 2025-11-25');
    }

    public function test_the_http_and_stdio_listings_declare_the_same_schemas(): void
    {
        $http  = $this->http_tools();
        $stdio = $this->stdio_tools(false);

        foreach ($stdio as $name => $tool) {
            if (! str_starts_with($name, 'wpmcp-') || ! isset($http[ $name ])) {
                continue;
            }
            // The adapter's DTO always serializes an empty properties map;
            // compare what the schemas constrain, not that serialization detail.
            $normalize = static function (array $schema): array {
                if ([] === ($schema['properties'] ?? null)) {
                    unset($schema['properties']);
                }
                return $schema;
            };
            $this->assertSame($normalize($tool['outputSchema']), $normalize($http[ $name ]['outputSchema']), $name);
        }
    }

    /**
     * The object contract holds for every result shape: Structured_Result
     * wraps lists and scalars, so a tool on the generic schema can never
     * answer with something that breaks it.
     */
    public function test_the_object_contract_holds_for_every_result_shape(): void
    {
        $generic = array_filter(
            $this->stdio_tools(false),
            static fn(array $tool): bool => str_starts_with((string) $tool['name'], 'wpmcp-') && [ 'type' => 'object' ] === $tool['outputSchema']
        );
        $this->assertNotEmpty($generic);

        $shapes = [ [ 'a' => 1 ], [ 1, 2 ], [], 'text', 3, null, true, [ 'nested' => [ 'x' => [ 1 ] ] ] ];
        foreach ($generic as $name => $tool) {
            foreach ($shapes as $shape) {
                $wire = json_decode((string) wp_json_encode(Structured_Result::normalize($shape)), true);
                $this->assertSame([], Json_Schema_Subset::validate($tool['outputSchema'], $wire), $name . ' ' . wp_json_encode($shape));
            }
        }
    }

    /** @return array<string,mixed> structuredContent of a stdio tools/call. */
    private function call(string $tool, array $arguments = []): array
    {
        $response = (new Stdio_Transport())->handle_request([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'tools/call',
            'params'  => [ 'name' => $tool, 'arguments' => $arguments ],
        ]);
        $result = json_decode((string) wp_json_encode($response), true)['result'];
        $this->assertFalse($result['isError'], $tool . ': ' . ($result['content'][0]['text'] ?? ''));

        return $result['structuredContent'];
    }

    /** Detailed schemas are checked against real results, not just declared. */
    public function test_the_job_tools_answer_what_their_schemas_declare(): void
    {
        $tools = $this->stdio_tools(false);

        $triggered = $this->call('wpmcp-trigger-backup', [ 'type' => 'database' ]);
        $job_id    = $triggered['job_id'];
        $second    = $this->call('wpmcp-trigger-backup')['job_id'];

        $results = [
            'wpmcp-trigger-backup'    => $triggered,
            'wpmcp-get-backup-status' => $this->call('wpmcp-get-backup-status', [ 'job_id' => $job_id ]),
            'wpmcp-list-backup-jobs'  => $this->call('wpmcp-list-backup-jobs'),
            'wpmcp-cancel-backup-job' => $this->call('wpmcp-cancel-backup-job', [ 'job_id' => $second ]),
        ];

        Backup_Job_Store::update($job_id, [ 'status' => 'completed', 'result' => [ 'archive' => 'a.zip' ] ]);
        $results['wpmcp-get-backup-status (completed)'] = $this->call('wpmcp-get-backup-status', [ 'job_id' => $job_id ]);

        foreach ($results as $label => $structured) {
            $name   = explode(' ', $label)[0];
            $schema = $tools[ $name ]['outputSchema'];
            $this->assertNotSame([ 'type' => 'object' ], $schema, "$name should declare its detailed contract");
            $this->assertSame([], Json_Schema_Subset::validate($schema, $structured), $label . ' ' . wp_json_encode($structured));
        }
    }

    /**
     * A task's completed result is what the original tools/call would have
     * returned, so it conforms to the original tool's outputSchema too.
     */
    public function test_a_completed_backup_task_result_conforms_to_trigger_backups_schema(): void
    {
        $meta = [
            'io.modelcontextprotocol/protocolVersion'    => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => [ 'extensions' => [ 'io.modelcontextprotocol/tasks' => new \stdClass() ] ],
        ];
        $transport = new Stdio_Transport();
        $task      = $transport->handle_request([ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => 'wpmcp-trigger-backup', 'arguments' => [], '_meta' => $meta ] ])['result'];
        Backup_Job_Store::update(Backup_Job_Store::list()[0]['id'], [ 'status' => 'completed', 'result' => [ 'archive' => 'a.zip' ] ]);
        $done = $transport->handle_request([ 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tasks/get', 'params' => [ 'taskId' => $task['taskId'], '_meta' => $meta ] ]);
        $done = json_decode((string) wp_json_encode($done), true)['result'];

        $schema = $this->stdio_tools(false)['wpmcp-trigger-backup']['outputSchema'];
        $this->assertSame([], Json_Schema_Subset::validate($schema, $done['result']['structuredContent']));
    }

    public function test_output_schemas_stay_within_their_byte_budget(): void
    {
        $schemas = [];
        foreach ($this->stdio_tools(false) as $name => $tool) {
            if (str_starts_with($name, 'wpmcp-')) {
                $schemas[] = $tool['outputSchema'];
            }
        }
        $bytes = strlen((string) wp_json_encode($schemas));

        $this->assertLessThanOrEqual(
            self::OUTPUT_SCHEMA_BYTE_BUDGET,
            $bytes,
            sprintf('outputSchema values are %d bytes over %d tools, over the %d-byte budget.', $bytes, count($schemas), self::OUTPUT_SCHEMA_BYTE_BUDGET)
        );
    }
}
