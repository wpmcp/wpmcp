<?php

namespace WPMCP\Tests\Free\Cli;

use WPMCP\Tools\Cli\Cli_Job_Store;
use WPMCP\Tools\Cli\Dispatch_Cli_Job;
use WPMCP\Tools\Cli\Run_Cli_Job;
use WPMCP\Tools\Cli\Run_Wp_Cli;
use WPMCP\Tools\Cli\Wp_Cli_Guard;

/**
 * Zero-divergence proof for issue #84's hard requirement: the background
 * dispatcher must add NO privilege surface over the synchronous tool.
 *
 * Every scenario here is driven through BOTH entry points, run-wp-cli
 * (synchronous) and dispatch-cli-job (async), from one data provider, and
 * asserted to reach the same verdict. That is why the guard chain was
 * extracted into Wp_Cli_Guard_Chain: two copies of an ordered guard sequence
 * would pass their own tests forever while drifting apart, and the drift
 * would be a privilege escalation (a command the sync tool refuses being
 * accepted by the async one). If either entry point stops calling the shared
 * chain, or calls it in a different order, this file fails.
 *
 * The dispatch side additionally asserts that a refusal leaves NO job record
 * and NO scheduled cron event behind: refusing to run but still queueing
 * would be a different, quieter kind of divergence.
 */
class WpCliGuardChainSharedTest extends \WP_UnitTestCase
{
    // Issue #562: on multisite these tools need a network administrator.
    use \WPMCP\Tests\Free\MultisiteSecurity\Network_Exec_Caller;

    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Cli_Job_Store::OPTION);
        Cli_Job_Store::set_clock_for_tests(null);
        Wp_Cli_Guard::set_environment_override('local');
        $this->act_as_network_exec_caller();
    }

    protected function tearDown(): void
    {
        $this->release_network_exec_caller();
        remove_all_filters('wpmcp_allow_wp_cli');
        remove_all_filters('wpmcp_allow_wp_cli_on_production');
        remove_all_filters('wpmcp_wp_cli_allowlist');
        remove_all_filters('wpmcp_wp_cli_flag_allowlist');
        remove_all_filters('wpmcp_wp_cli_binary');
        Wp_Cli_Guard::set_environment_override(null);
        Cli_Job_Store::set_clock_for_tests(null);
        delete_option(Cli_Job_Store::OPTION);
        wp_clear_scheduled_hook(Run_Cli_Job::HOOK);
        parent::tearDown();
    }

    private function fake_binary(): string
    {
        $bin = sys_get_temp_dir() . '/wpmcp-fake-wp-' . getmypid();
        if (! file_exists($bin)) {
            file_put_contents($bin, "#!/bin/sh\necho ok\n");
            chmod($bin, 0755);
        }
        add_filter('wpmcp_wp_cli_binary', fn() => $bin);
        return $bin;
    }

    /**
     * Every guard-refusal scenario, as [setup closure, command, expected
     * message fragment]. Each is asserted against both entry points below.
     */
    public function refused_commands(): array
    {
        return [
            'execution disabled by default' => [
                'binary_only',
                'core version',
                'WP-CLI execution is disabled',
            ],
            'refused on production' => [
                'enabled_on_production',
                'core version',
                'refused on a production environment',
            ],
            'subcommand not allowlisted' => [
                'enabled',
                'plugin delete akismet',
                'not on the allowlist',
            ],
            'shell metacharacter in an argument' => [
                'enabled',
                'plugin list $(whoami)',
                'disallowed character',
            ],
            'global --require flag' => [
                'enabled',
                'core version --require=/tmp/evil.php',
                '',
            ],
            'global --exec flag' => [
                'enabled',
                'core version --exec=include("/tmp/x")',
                '',
            ],
            'global --ssh flag' => [
                'enabled',
                'plugin list --ssh=attacker@evil.tld',
                '',
            ],
            'non-allowlisted trailing flag' => [
                'enabled',
                'plugin list --path=/var/www',
                'safe-flag allowlist',
            ],
            'partial allowlist prefix match' => [
                'enabled',
                'cron event',
                'not on the allowlist',
            ],
            'wp-cli binary cannot be resolved' => [
                'enabled_without_binary',
                'core version',
                'wp-cli binary',
            ],
        ];
    }

    private function apply_setup(string $setup): void
    {
        switch ($setup) {
            case 'binary_only':
                $this->fake_binary();
                break;
            case 'enabled_on_production':
                add_filter('wpmcp_allow_wp_cli', '__return_true');
                Wp_Cli_Guard::set_environment_override('production');
                $this->fake_binary();
                break;
            case 'enabled_without_binary':
                add_filter('wpmcp_allow_wp_cli', '__return_true');
                add_filter('wpmcp_wp_cli_binary', fn() => '/no/such/wp-binary-anywhere');
                break;
            case 'enabled':
            default:
                add_filter('wpmcp_allow_wp_cli', '__return_true');
                $this->fake_binary();
                break;
        }
    }

    /** @dataProvider refused_commands */
    public function test_the_synchronous_tool_refuses(string $setup, string $command, string $fragment): void
    {
        $this->apply_setup($setup);

        $calls = [];
        $tool  = new Run_Wp_Cli(function (array $argv, int $timeout) use (&$calls): array {
            $calls[] = $argv;
            return ['stdout' => '', 'stderr' => '', 'exit_code' => 0, 'timed_out' => false];
        });

        try {
            $tool->handle(['command' => $command]);
            $this->fail("run-wp-cli must refuse: {$command}");
        } catch (\RuntimeException $e) {
            if ('' !== $fragment) {
                $this->assertStringContainsString($fragment, $e->getMessage());
            }
        }

        $this->assertCount(0, $calls, 'The executor must never be reached for a refused command.');
    }

    /** @dataProvider refused_commands */
    public function test_the_dispatcher_refuses_identically_and_queues_nothing(string $setup, string $command, string $fragment): void
    {
        $this->apply_setup($setup);

        try {
            (new Dispatch_Cli_Job())->handle(['command' => $command]);
            $this->fail("dispatch-cli-job must refuse: {$command}");
        } catch (\RuntimeException $e) {
            if ('' !== $fragment) {
                $this->assertStringContainsString($fragment, $e->getMessage());
            }
        }

        $this->assertSame([], Cli_Job_Store::list(), 'A refused dispatch must not leave a job record.');
        $this->assertFalse(wp_next_scheduled(Run_Cli_Job::HOOK, [1]));
    }

    /** @dataProvider refused_commands */
    public function test_both_entry_points_produce_the_same_refusal_message(string $setup, string $command, string $fragment): void
    {
        $this->apply_setup($setup);

        $sync_message = null;
        try {
            (new Run_Wp_Cli(fn() => ['stdout' => '', 'stderr' => '', 'exit_code' => 0, 'timed_out' => false]))
                ->handle(['command' => $command]);
        } catch (\RuntimeException $e) {
            $sync_message = $e->getMessage();
        }

        $async_message = null;
        try {
            (new Dispatch_Cli_Job())->handle(['command' => $command]);
        } catch (\RuntimeException $e) {
            $async_message = $e->getMessage();
        }

        $this->assertNotNull($sync_message);
        // Identical text, not merely "both refused": the message names which
        // guard fired, so equal messages prove the same guard fired in the
        // same position of the same chain.
        $this->assertSame($sync_message, $async_message);
    }

    /**
     * @dataProvider permitted_commands
     *
     * The mirror image: a command the synchronous tool accepts must also be
     * accepted by the dispatcher, and must reach the executor as the exact
     * same argv. A dispatcher that was merely stricter would be safe but
     * useless, and would hide chain drift just as effectively.
     */
    public function test_a_permitted_command_reaches_the_executor_identically(string $command, array $expected_argv): void
    {
        $bin = $this->fake_binary();
        add_filter('wpmcp_allow_wp_cli', '__return_true');

        $sync_calls = [];
        (new Run_Wp_Cli(function (array $argv, int $timeout) use (&$sync_calls): array {
            $sync_calls[] = $argv;
            return ['stdout' => 'ok', 'stderr' => '', 'exit_code' => 0, 'timed_out' => false];
        }))->handle(['command' => $command]);

        $dispatched = (new Dispatch_Cli_Job())->handle(['command' => $command]);

        $async_calls = [];
        (new Run_Cli_Job(function (array $argv, int $timeout) use (&$async_calls): array {
            $async_calls[] = $argv;
            return ['stdout' => 'ok', 'stderr' => '', 'exit_code' => 0, 'timed_out' => false];
        }))->handle($dispatched['job_id']);

        $this->assertSame([array_merge([$bin], $expected_argv, $this->expected_site_args())], $sync_calls);
        $this->assertSame($sync_calls, $async_calls);
        $this->assertSame('completed', Cli_Job_Store::get($dispatched['job_id'])['status']);
    }

    public function permitted_commands(): array
    {
        return [
            'core version'            => ['core version', ['core', 'version']],
            'plugin list with format' => ['plugin list --format=json', ['plugin', 'list', '--format=json']],
            'nested cron subcommand'  => ['cron event list', ['cron', 'event', 'list']],
        ];
    }

    /**
     * The allowlist filter is a single shared seam, so widening it must
     * widen both tools at once. If the dispatcher ever grew its own
     * allowlist, this would catch it.
     */
    public function test_widening_the_allowlist_widens_both_entry_points(): void
    {
        $bin = $this->fake_binary();
        add_filter('wpmcp_allow_wp_cli', '__return_true');
        add_filter('wpmcp_wp_cli_allowlist', function (array $allowlist): array {
            $allowlist[] = 'user list';
            return $allowlist;
        });

        $sync_calls = [];
        (new Run_Wp_Cli(function (array $argv, int $timeout) use (&$sync_calls): array {
            $sync_calls[] = $argv;
            return ['stdout' => '', 'stderr' => '', 'exit_code' => 0, 'timed_out' => false];
        }))->handle(['command' => 'user list']);

        $dispatched = (new Dispatch_Cli_Job())->handle(['command' => 'user list']);

        $this->assertSame([array_merge([$bin, 'user', 'list'], $this->expected_site_args())], $sync_calls);
        $this->assertSame(['user', 'list'], Cli_Job_Store::get($dispatched['job_id'])['argv']);
    }
}
