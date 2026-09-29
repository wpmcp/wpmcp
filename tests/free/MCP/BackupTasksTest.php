<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\Governance\Governance;
use WPMCP\Identity\Identity_Context;
use WPMCP\Identity\Identity_Store;
use WPMCP\MCP\Stdio_Transport;
use WPMCP\MCP\Tool_Exposure;
use WPMCP\RateLimit\Rate_Limiter;
use WPMCP\Tools\Backup\Backup_Job_Store;
use WPMCP\Tools\Backup\Run_Backup_Job;

/**
 * Backups as MCP Tasks (issue #387), through the Tasks extension
 * (io.modelcontextprotocol/tasks) of MCP 2026-07-28.
 *
 * trigger-backup already queues a WP-Cron job and returns its id at once.
 * A client that declares the Tasks extension gets that job as a task
 * handle instead (resultType "task"), then polls tasks/get, which maps the
 * job record onto the task lifecycle. Tasks live in the job store, not in
 * a session, so they survive the stateless HTTP route and a reconnect.
 */
class BackupTasksTest extends \WP_UnitTestCase
{
    private const EXTENSION = 'io.modelcontextprotocol/tasks';

    private Stdio_Transport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new Stdio_Transport();
        Governance::reset_for_tests();
        Identity_Context::set_current_for_tests(null);
        delete_option(Identity_Store::OPTION);
        delete_option(Tool_Exposure::OPTION);
        delete_option(Backup_Job_Store::OPTION);
        Backup_Job_Store::set_clock_for_tests(1_790_000_387);
        Rate_Limiter::set_clock_override(fn() => 1_790_000_387);
        add_filter('wpmcp_rate_limit', fn() => 100000);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_rate_limit');
        Rate_Limiter::set_clock_override(null);
        Backup_Job_Store::set_clock_for_tests(null);
        wp_clear_scheduled_hook(Run_Backup_Job::HOOK);
        delete_option(Backup_Job_Store::OPTION);
        Identity_Context::set_current_for_tests(null);
        Governance::reset_for_tests();
        delete_option(Identity_Store::OPTION);
        delete_option(Tool_Exposure::OPTION);
        parent::tearDown();
    }

    /** @return array<string,mixed> The decoded response. */
    private function modern(string $method, array $params = [], bool $tasks = true): array
    {
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion'    => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => $tasks
                ? [ 'extensions' => [ self::EXTENSION => new \stdClass() ] ]
                : new \stdClass(),
        ];

        $response = $this->transport->handle_request([ 'jsonrpc' => '2.0', 'id' => 3, 'method' => $method, 'params' => $params ]);
        $this->assertIsArray($response);

        return json_decode((string) wp_json_encode($response), true);
    }

    private function start_task(): array
    {
        $result = $this->modern('tools/call', [ 'name' => 'wpmcp-trigger-backup', 'arguments' => [ 'type' => 'database' ] ])['result'];
        $this->assertSame('task', $result['resultType'] ?? null, (string) wp_json_encode($result));

        return $result;
    }

    public function test_server_discover_advertises_the_tasks_extension(): void
    {
        $capabilities = $this->modern('server/discover')['result']['capabilities'];

        $this->assertArrayHasKey(self::EXTENSION, $capabilities['extensions'] ?? []);
    }

    public function test_trigger_backup_returns_a_task_handle_to_a_tasks_client(): void
    {
        $task = $this->start_task();

        $this->assertIsString($task['taskId']);
        $this->assertNotSame('', $task['taskId']);
        $this->assertSame('working', $task['status']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/', $task['createdAt']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $task['lastUpdatedAt']);
        $this->assertArrayHasKey('ttlMs', $task);
        $this->assertIsInt($task['pollIntervalMs']);
        $this->assertGreaterThan(0, $task['pollIntervalMs']);
        $this->assertArrayNotHasKey('content', $task, 'A task handle is not a tool result.');

        // The job was queued exactly as the synchronous call queues it.
        $jobs = Backup_Job_Store::list();
        $this->assertCount(1, $jobs);
        $this->assertSame('queued', $jobs[0]['status']);
        $this->assertNotFalse(wp_next_scheduled(Run_Backup_Job::HOOK, [ $jobs[0]['id'] ]));
    }

    public function test_a_client_without_the_extension_gets_the_synchronous_result(): void
    {
        $result = $this->modern('tools/call', [ 'name' => 'wpmcp-trigger-backup', 'arguments' => [] ], false)['result'];

        $this->assertSame('complete', $result['resultType']);
        $this->assertFalse($result['isError']);
        $this->assertSame('queued', $result['structuredContent']['status']);
        $this->assertIsInt($result['structuredContent']['job_id']);
    }

    public function test_tasks_get_follows_the_job_through_to_completion(): void
    {
        $task   = $this->start_task();
        $job_id = Backup_Job_Store::list()[0]['id'];

        $polled = $this->modern('tasks/get', [ 'taskId' => $task['taskId'] ])['result'];
        $this->assertSame('complete', $polled['resultType']);
        $this->assertSame($task['taskId'], $polled['taskId']);
        $this->assertSame('working', $polled['status']);
        $this->assertArrayNotHasKey('result', $polled);

        Backup_Job_Store::update($job_id, [ 'status' => 'running' ]);
        $this->assertSame('working', $this->modern('tasks/get', [ 'taskId' => $task['taskId'] ])['result']['status']);

        Backup_Job_Store::update($job_id, [ 'status' => 'completed', 'result' => [ 'archive' => 'backup-1.zip' ] ]);
        $done = $this->modern('tasks/get', [ 'taskId' => $task['taskId'] ])['result'];

        $this->assertSame('completed', $done['status']);
        $this->assertFalse($done['result']['isError']);
        $this->assertSame($job_id, $done['result']['structuredContent']['job_id']);
        $this->assertSame('completed', $done['result']['structuredContent']['status']);
        $this->assertSame([ 'archive' => 'backup-1.zip' ], $done['result']['structuredContent']['result']);
        $this->assertSame('text', $done['result']['content'][0]['type']);
    }

    public function test_a_failed_job_is_a_failed_task_with_the_error(): void
    {
        $task   = $this->start_task();
        $job_id = Backup_Job_Store::list()[0]['id'];
        Backup_Job_Store::update($job_id, [ 'status' => 'failed', 'error' => 'Disk full.' ]);

        $failed = $this->modern('tasks/get', [ 'taskId' => $task['taskId'] ])['result'];

        $this->assertSame('failed', $failed['status']);
        $this->assertIsInt($failed['error']['code']);
        $this->assertStringContainsString('Disk full.', $failed['error']['message']);
    }

    public function test_tasks_cancel_withdraws_a_queued_job(): void
    {
        $task   = $this->start_task();
        $job_id = Backup_Job_Store::list()[0]['id'];

        $ack = $this->modern('tasks/cancel', [ 'taskId' => $task['taskId'] ])['result'];
        $this->assertSame('complete', $ack['resultType']);

        $this->assertSame('canceled', Backup_Job_Store::get($job_id)['status']);
        $this->assertFalse(wp_next_scheduled(Run_Backup_Job::HOOK, [ $job_id ]));
        $this->assertSame('cancelled', $this->modern('tasks/get', [ 'taskId' => $task['taskId'] ])['result']['status']);
    }

    /** Cancellation is cooperative: a job already running is acknowledged, not interrupted. */
    public function test_tasks_cancel_on_a_running_job_is_acknowledged(): void
    {
        $task   = $this->start_task();
        $job_id = Backup_Job_Store::list()[0]['id'];
        Backup_Job_Store::update($job_id, [ 'status' => 'running' ]);

        $response = $this->modern('tasks/cancel', [ 'taskId' => $task['taskId'] ]);

        $this->assertArrayHasKey('result', $response);
        $this->assertSame('running', Backup_Job_Store::get($job_id)['status']);
    }

    public function test_tasks_update_is_acknowledged(): void
    {
        $task = $this->start_task();

        $response = $this->modern('tasks/update', [ 'taskId' => $task['taskId'], 'inputResponses' => new \stdClass() ]);

        $this->assertSame('complete', $response['result']['resultType']);
    }

    public function test_an_unknown_task_is_invalid_params(): void
    {
        foreach ([ 'tasks/get', 'tasks/cancel', 'tasks/update' ] as $method) {
            foreach ([ 'wpmcp-backup-999', 'nonsense', '' ] as $task_id) {
                $response = $this->modern($method, [ 'taskId' => $task_id ]);
                $this->assertSame(-32602, $response['error']['code'] ?? null, $method . ' ' . $task_id);
            }
        }
    }

    /**
     * A task is only as visible as the job behind it: a caller who could not
     * read the backup status directly cannot poll or cancel it as a task,
     * and cannot tell it exists.
     */
    public function test_a_caller_who_cannot_read_the_job_cannot_reach_the_task(): void
    {
        $task   = $this->start_task();
        $job_id = Backup_Job_Store::list()[0]['id'];

        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        foreach ([ 'tasks/get', 'tasks/cancel' ] as $method) {
            $response = $this->modern($method, [ 'taskId' => $task['taskId'] ]);
            $this->assertSame(-32602, $response['error']['code'] ?? null, $method);
        }
        $this->assertSame('queued', Backup_Job_Store::get($job_id)['status']);
    }

    /** Tasks are a 2026-07-28 extension; the 2025 session has no tasks/* methods. */
    public function test_the_2025_session_does_not_serve_tasks_methods(): void
    {
        $response = $this->transport->handle_request([ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tasks/get', 'params' => [ 'taskId' => 'wpmcp-backup-1' ] ]);

        $this->assertSame(-32601, $response['error']['code']);
    }
}
