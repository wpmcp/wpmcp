<?php

namespace WPMCP\Tests\Pro\MCP;

use WPMCP\Governance\Governance;
use WPMCP\MCP\Ability;
use WPMCP\MCP\Registrar;
use WPMCP\MCP\Stdio_Transport;
use WPMCP\Plugin;
use WPMCP\Pro\Gate;
use WPMCP\RateLimit\Rate_Limiter;
use WPMCP\Tools\Cli\Cancel_Cli_Job;
use WPMCP\Tools\Cli\Cli_Job_Store;
use WPMCP\Tools\Cli\Dispatch_Cli_Job;
use WPMCP\Tools\Cli\Get_Cli_Job;
use WPMCP\Tools\Cli\Run_Cli_Job;
use WPMCP\Tools\Cli\Wp_Cli_Guard;

/**
 * Background WP-CLI jobs as MCP Tasks (issue #387). dispatch-cli-job is the
 * pro twin of trigger-backup: it queues a WP-Cron job and returns its id, so
 * a client declaring io.modelcontextprotocol/tasks gets a task handle and
 * polls the job through tasks/get.
 *
 * The CLI job abilities are pro tier and are not registered on an
 * unlicensed test install, so they are registered here through a private
 * wp_abilities_api_init window against a swapped Registrar (the pattern
 * CallToolConformanceTest uses) and removed again afterwards.
 */
class CliJobTasksTest extends \WP_UnitTestCase
{
    private const NAMES = [ 'wpmcp/dispatch-cli-job', 'wpmcp/get-cli-job', 'wpmcp/cancel-cli-job' ];

    private ?Registrar $original = null;

    protected function setUp(): void
    {
        parent::setUp();
        Governance::reset_for_tests();
        delete_option(Cli_Job_Store::OPTION);
        Cli_Job_Store::set_clock_for_tests(1_790_000_387);
        Wp_Cli_Guard::set_environment_override('local');
        Rate_Limiter::set_clock_override(fn() => 1_790_000_387);
        add_filter('wpmcp_rate_limit', fn() => 100000);
        add_filter('wpmcp_allow_wp_cli', '__return_true');
        $bin = sys_get_temp_dir() . '/wpmcp-fake-wp-tasks-' . getmypid();
        if (! file_exists($bin)) {
            file_put_contents($bin, "#!/bin/sh\necho ok\n");
            chmod($bin, 0755);
        }
        add_filter('wpmcp_wp_cli_binary', fn() => $bin);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->register_cli_abilities();
    }

    protected function tearDown(): void
    {
        foreach (self::NAMES as $name) {
            if (wp_has_ability($name)) {
                wp_unregister_ability($name);
            }
        }
        if (null !== $this->original) {
            (new \ReflectionProperty(Plugin::class, 'registrar'))->setValue(Plugin::instance(), $this->original);
        }
        Gate::set_pro_for_tests(null);
        remove_all_filters('wpmcp_rate_limit');
        remove_all_filters('wpmcp_allow_wp_cli');
        remove_all_filters('wpmcp_wp_cli_binary');
        Rate_Limiter::set_clock_override(null);
        Wp_Cli_Guard::set_environment_override(null);
        Cli_Job_Store::set_clock_for_tests(null);
        wp_clear_scheduled_hook(Run_Cli_Job::HOOK);
        delete_option(Cli_Job_Store::OPTION);
        Governance::reset_for_tests();
        parent::tearDown();
    }

    private function register_cli_abilities(): void
    {
        $plugin         = Plugin::instance();
        $prop           = new \ReflectionProperty(Plugin::class, 'registrar');
        $this->original = $prop->getValue($plugin);
        $fresh          = new Registrar();
        $prop->setValue($plugin, $fresh);

        Gate::set_pro_for_tests(true);
        remove_all_actions('wp_abilities_api_init');
        add_action('wp_abilities_api_init', static function () use ($fresh): void {
            $job = [ 'type' => 'object', 'properties' => [ 'job_id' => [ 'type' => 'integer' ] ], 'required' => [ 'job_id' ] ];
            $fresh->register(new Ability('wpmcp/dispatch-cli-job', 'pro', 'Queue a wp-cli job.', [
                'type'       => 'object',
                'properties' => [ 'command' => [ 'type' => 'string' ], 'timeout' => [ 'type' => 'integer' ] ],
                'required'   => [ 'command' ],
            ], [ new Dispatch_Cli_Job(), 'handle' ], 'manage_options', 'cli', 'create'));
            $fresh->register(new Ability('wpmcp/get-cli-job', 'pro', 'Get a wp-cli job.', $job, [ new Get_Cli_Job(), 'handle' ], 'manage_options', 'cli', 'read'));
            $fresh->register(new Ability('wpmcp/cancel-cli-job', 'pro', 'Cancel a wp-cli job.', $job, [ new Cancel_Cli_Job(), 'handle' ], 'manage_options', 'cli', 'update'));
        });
        do_action('wp_abilities_api_init');
    }

    private function modern(string $method, array $params, bool $tasks = true): array
    {
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion'    => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => $tasks
                ? [ 'extensions' => [ 'io.modelcontextprotocol/tasks' => new \stdClass() ] ]
                : new \stdClass(),
        ];

        $response = (new Stdio_Transport())->handle_request([ 'jsonrpc' => '2.0', 'id' => 4, 'method' => $method, 'params' => $params ]);

        return json_decode((string) wp_json_encode($response), true);
    }

    public function test_a_dispatched_cli_job_is_a_task_polled_to_completion(): void
    {
        $task = $this->modern('tools/call', [ 'name' => 'wpmcp-dispatch-cli-job', 'arguments' => [ 'command' => 'plugin list --format=json' ] ])['result'];

        $this->assertSame('task', $task['resultType'] ?? null, (string) wp_json_encode($task));
        $this->assertSame('working', $task['status']);
        $this->assertIsInt($task['ttlMs'], 'CLI job records are purged on a retention TTL, which the task carries.');

        $job_id = Cli_Job_Store::list()[0]['id'];
        $this->assertSame('working', $this->modern('tasks/get', [ 'taskId' => $task['taskId'] ])['result']['status']);

        Cli_Job_Store::update($job_id, [
            'status' => 'completed',
            'result' => [ 'stdout' => 'ok', 'stderr' => '', 'exit_code' => 0, 'timed_out' => false, 'truncated' => false, 'output_bytes' => 2 ],
        ]);
        $done = $this->modern('tasks/get', [ 'taskId' => $task['taskId'] ])['result'];

        $this->assertSame('completed', $done['status']);
        $this->assertSame($job_id, $done['result']['structuredContent']['job_id']);
        $this->assertSame('ok', $done['result']['structuredContent']['result']['stdout']);
    }

    public function test_a_queued_cli_task_can_be_cancelled(): void
    {
        $task   = $this->modern('tools/call', [ 'name' => 'wpmcp-dispatch-cli-job', 'arguments' => [ 'command' => 'plugin list' ] ])['result'];
        $job_id = Cli_Job_Store::list()[0]['id'];

        $this->modern('tasks/cancel', [ 'taskId' => $task['taskId'] ]);

        $this->assertSame('canceled', Cli_Job_Store::get($job_id)['status']);
        $this->assertSame('cancelled', $this->modern('tasks/get', [ 'taskId' => $task['taskId'] ])['result']['status']);
    }

    public function test_without_the_extension_dispatch_answers_synchronously(): void
    {
        $result = $this->modern('tools/call', [ 'name' => 'wpmcp-dispatch-cli-job', 'arguments' => [ 'command' => 'plugin list' ] ], false)['result'];

        $this->assertSame('complete', $result['resultType']);
        $this->assertSame('queued', $result['structuredContent']['status']);
    }
}
