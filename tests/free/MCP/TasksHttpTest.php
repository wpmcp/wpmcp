<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\Governance\Governance;
use WPMCP\Identity\Identity_Context;
use WPMCP\Identity\Identity_Store;
use WPMCP\MCP\Tool_Exposure;
use WPMCP\RateLimit\Rate_Limiter;

/**
 * Backup Tasks (issue #387) on the stateless HTTP route: the task handle
 * from one request is polled and cancelled from later, unrelated requests,
 * which only works because the task lives in the job store.
 */
class TasksHttpTest extends \WP_UnitTestCase
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
        delete_option(\WPMCP\Tools\Backup\Backup_Job_Store::OPTION);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->mount();
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_rate_limit');
        wp_clear_scheduled_hook(\WPMCP\Tools\Backup\Run_Backup_Job::HOOK);
        delete_option(\WPMCP\Tools\Backup\Backup_Job_Store::OPTION);
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

    private function modern(string $method, array $params): array
    {
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion'    => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => [ 'extensions' => [ 'io.modelcontextprotocol/tasks' => new \stdClass() ] ],
        ];
        $headers = [ 'MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => $method ];
        if (isset($params['name'])) {
            $headers['Mcp-Name'] = (string) $params['name'];
        }

        [ $status, $body ] = $this->post([ 'jsonrpc' => '2.0', 'id' => 5, 'method' => $method, 'params' => $params ], $headers);
        $this->assertSame(200, $status, (string) wp_json_encode($body));

        return $body['result'];
    }

    public function test_a_backup_task_is_created_polled_and_cancelled_over_http(): void
    {
        $task = $this->modern('tools/call', [ 'name' => 'wpmcp-trigger-backup', 'arguments' => [] ]);
        $this->assertSame('task', $task['resultType'], (string) wp_json_encode($task));
        $this->assertSame('working', $task['status']);

        $polled = $this->modern('tasks/get', [ 'taskId' => $task['taskId'] ]);
        $this->assertSame('complete', $polled['resultType']);
        $this->assertSame('working', $polled['status']);

        $this->modern('tasks/cancel', [ 'taskId' => $task['taskId'] ]);
        $this->assertSame('cancelled', $this->modern('tasks/get', [ 'taskId' => $task['taskId'] ])['status']);
    }
}
