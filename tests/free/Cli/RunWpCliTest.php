<?php

namespace WPMCP\Tests\Free\Cli;

use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Tools\Cli\Run_Wp_Cli;
use WPMCP\Tools\Cli\Wp_Cli_Guard;

/**
 * Run_Wp_Cli composes every Wp_Cli_Guard check plus an injectable executor
 * callable, so these tests assert the ARGV that would be run and every guard
 * outcome WITHOUT ever spawning a real process: the executor passed in here
 * is a plain closure that records what it was called with and returns a
 * canned result.
 */
class RunWpCliTest extends \WP_UnitTestCase
{
    // Issue #562: on multisite these tools need a network administrator.
    use \WPMCP\Tests\Free\MultisiteSecurity\Network_Exec_Caller;

    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Governance_Audit_Log::OPTION);
        Wp_Cli_Guard::set_environment_override('local');
        $this->act_as_network_exec_caller();
    }

    protected function tearDown(): void
    {
        $this->release_network_exec_caller();
        remove_all_filters('wpmcp_allow_wp_cli');
        remove_all_filters('wpmcp_allow_wp_cli_on_production');
        remove_all_filters('wpmcp_wp_cli_allowlist');
        remove_all_filters('wpmcp_wp_cli_binary');
        Wp_Cli_Guard::set_environment_override(null);
        delete_option(Governance_Audit_Log::OPTION);
        parent::tearDown();
    }

    private function fake_binary(): string
    {
        $bin = sys_get_temp_dir() . '/wpmcp-fake-wp-' . getmypid();
        if (! file_exists($bin)) {
            file_put_contents($bin, "#!/bin/sh\necho ok\n");
            chmod($bin, 0755);
        }
        add_filter('wpmcp_wp_cli_binary', function () use ($bin) {
            return $bin;
        });
        return $bin;
    }

    private function recording_executor(array &$calls): callable
    {
        return function (array $argv, int $timeout) use (&$calls): array {
            $calls[] = ['argv' => $argv, 'timeout' => $timeout];
            return [
                'stdout'    => 'ok',
                'stderr'    => '',
                'exit_code' => 0,
                'timed_out' => false,
            ];
        };
    }

    public function test_default_off_blocks_execution_without_calling_executor(): void
    {
        $calls   = [];
        $tool    = new Run_Wp_Cli($this->recording_executor($calls));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('WP-CLI execution is disabled');

        try {
            $tool->handle(['command' => 'core version']);
        } finally {
            $this->assertCount(0, $calls, 'The executor must never be invoked when disabled.');
        }
    }

    public function test_default_off_records_a_denied_audit_entry(): void
    {
        $calls = [];
        $tool  = new Run_Wp_Cli($this->recording_executor($calls));

        try {
            $tool->handle(['command' => 'core version']);
        } catch (\RuntimeException $e) {
            // expected
        }

        $entries = Governance_Audit_Log::list();
        $this->assertCount(1, $entries);
        $this->assertSame('wpmcp/run-wp-cli', $entries[0]['ability']);
        $this->assertFalse($entries[0]['allowed']);
    }

    public function test_refuses_on_production_even_when_enabled(): void
    {
        add_filter('wpmcp_allow_wp_cli', '__return_true');
        Wp_Cli_Guard::set_environment_override('production');
        $this->fake_binary();

        $calls = [];
        $tool  = new Run_Wp_Cli($this->recording_executor($calls));

        $this->expectException(\RuntimeException::class);
        try {
            $tool->handle(['command' => 'core version']);
        } finally {
            $this->assertCount(0, $calls);
        }
    }

    public function test_allowed_subcommand_runs_with_correct_argv(): void
    {
        add_filter('wpmcp_allow_wp_cli', '__return_true');
        $bin = $this->fake_binary();

        $calls = [];
        $tool  = new Run_Wp_Cli($this->recording_executor($calls));

        $result = $tool->handle(['command' => 'plugin list --format=json']);

        $this->assertCount(1, $calls);
        $this->assertSame(array_merge([$bin, 'plugin', 'list', '--format=json'], $this->expected_site_args()), $calls[0]['argv']);
        $this->assertSame('ok', $result['stdout']);
        $this->assertSame(0, $result['exit_code']);
    }

    public function test_non_allowlisted_subcommand_is_rejected_without_calling_executor(): void
    {
        add_filter('wpmcp_allow_wp_cli', '__return_true');
        $this->fake_binary();

        $calls = [];
        $tool  = new Run_Wp_Cli($this->recording_executor($calls));

        $this->expectException(\RuntimeException::class);
        try {
            $tool->handle(['command' => 'plugin delete akismet']);
        } finally {
            $this->assertCount(0, $calls);
        }
    }

    public function test_metacharacter_argument_is_rejected_without_calling_executor(): void
    {
        add_filter('wpmcp_allow_wp_cli', '__return_true');
        $this->fake_binary();

        $calls = [];
        $tool  = new Run_Wp_Cli($this->recording_executor($calls));

        // The subcommand words themselves ("plugin", "list") exactly match
        // the default allowlist entry, so this exercises the metacharacter
        // check specifically, not the allowlist check: only the trailing
        // argument carries the '$(...)' metacharacter. Asserting the
        // "disallowed character" message (not just "not on the allowlist")
        // confirms which guard actually fired.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('disallowed character');
        try {
            $tool->handle(['command' => 'plugin list $(whoami)']);
        } finally {
            $this->assertCount(0, $calls);
        }
    }

    /**
     * @dataProvider global_flag_rce_payloads
     *
     * Issue #44 security review, C1: end-to-end confirmation (through the
     * full Run_Wp_Cli::handle() guard chain, not just Wp_Cli_Guard in
     * isolation) that an allowlisted subcommand prefix plus a dangerous
     * wp-cli GLOBAL flag never reaches the executor. Which specific guard
     * fires first (the metacharacter check or the safe-flag allowlist) can
     * differ per payload since both run in sequence; either is an
     * acceptable rejection, what matters is that the executor is never
     * called.
     */
    public function test_global_flag_rce_payload_is_rejected_without_calling_executor(string $command): void
    {
        add_filter('wpmcp_allow_wp_cli', '__return_true');
        $this->fake_binary();

        $calls = [];
        $tool  = new Run_Wp_Cli($this->recording_executor($calls));

        $this->expectException(\RuntimeException::class);
        try {
            $tool->handle(['command' => $command]);
        } finally {
            $this->assertCount(0, $calls, 'The executor must never be invoked for a disallowed-flag payload.');
        }
    }

    public function global_flag_rce_payloads(): array
    {
        return [
            'require (file inclusion)' => ['core version --require=/tmp/evil.php'],
            'exec (inline PHP)'        => ['core version --exec=include("/tmp/x")'],
            'ssh (remote host pivot)'  => ['plugin list --ssh=attacker@evil.tld'],
        ];
    }

    public function test_missing_binary_returns_error_without_calling_executor(): void
    {
        add_filter('wpmcp_allow_wp_cli', '__return_true');
        add_filter('wpmcp_wp_cli_binary', function () {
            return '/no/such/wp-binary-anywhere';
        });

        $calls = [];
        $tool  = new Run_Wp_Cli($this->recording_executor($calls));

        $this->expectException(\RuntimeException::class);
        try {
            $tool->handle(['command' => 'core version']);
        } finally {
            $this->assertCount(0, $calls);
        }
    }

    public function test_timeout_is_passed_through_to_the_executor(): void
    {
        add_filter('wpmcp_allow_wp_cli', '__return_true');
        $this->fake_binary();

        $calls = [];
        $tool  = new Run_Wp_Cli($this->recording_executor($calls));
        $tool->handle(['command' => 'core version']);

        $this->assertSame(30, $calls[0]['timeout']);
    }

    public function test_executor_returning_timed_out_surfaces_it_in_the_result(): void
    {
        add_filter('wpmcp_allow_wp_cli', '__return_true');
        $this->fake_binary();

        $tool = new Run_Wp_Cli(function (array $argv, int $timeout): array {
            return ['stdout' => '', 'stderr' => 'timed out', 'exit_code' => -1, 'timed_out' => true];
        });

        $result = $tool->handle(['command' => 'core version']);
        $this->assertTrue($result['timed_out']);
        $this->assertSame(-1, $result['exit_code']);
    }

    public function test_allowed_execution_records_an_allowed_audit_entry(): void
    {
        add_filter('wpmcp_allow_wp_cli', '__return_true');
        $this->fake_binary();

        $calls = [];
        $tool  = new Run_Wp_Cli($this->recording_executor($calls));
        $tool->handle(['command' => 'core version']);

        $entries = Governance_Audit_Log::list();
        $this->assertCount(1, $entries);
        $this->assertSame('wpmcp/run-wp-cli', $entries[0]['ability']);
        $this->assertTrue($entries[0]['allowed']);
    }

    public function test_denied_allowlist_attempt_records_a_denied_audit_entry(): void
    {
        add_filter('wpmcp_allow_wp_cli', '__return_true');
        $this->fake_binary();

        $calls = [];
        $tool  = new Run_Wp_Cli($this->recording_executor($calls));
        try {
            $tool->handle(['command' => 'plugin delete akismet']);
        } catch (\RuntimeException $e) {
            // expected
        }

        $entries = Governance_Audit_Log::list();
        $this->assertCount(1, $entries);
        $this->assertFalse($entries[0]['allowed']);
    }

    public function test_audit_entries_never_contain_the_raw_command_string(): void
    {
        // The audit log must never contain secrets; a wp-cli command could
        // include e.g. `option get some_api_key` or similar sensitive
        // arguments, so only the ability name/identity/outcome are logged,
        // never the actual command/argv. `option get` is no longer on the
        // default allowlist (issue #44 security review, M1), so it is
        // filtered in here specifically to keep exercising the scenario the
        // audit log must protect against even when an operator opts into it.
        add_filter('wpmcp_allow_wp_cli', '__return_true');
        add_filter('wpmcp_wp_cli_allowlist', function (array $allowlist): array {
            $allowlist[] = 'option get';
            return $allowlist;
        });
        $this->fake_binary();

        $calls = [];
        $tool  = new Run_Wp_Cli($this->recording_executor($calls));
        $tool->handle(['command' => 'option get some_secret_option_name']);

        $entries = Governance_Audit_Log::list();
        $serialized = wp_json_encode($entries);
        $this->assertStringNotContainsString('some_secret_option_name', $serialized);
    }

    public function test_requires_a_command(): void
    {
        $calls = [];
        $tool  = new Run_Wp_Cli($this->recording_executor($calls));
        $this->expectException(\InvalidArgumentException::class);
        $tool->handle([]);
    }

    public function test_default_executor_is_the_real_wp_cli_executor_class(): void
    {
        // Verifies the injection seam's default without invoking it: the
        // no-arg constructor must wire up Wp_Cli_Executor::run as the
        // default callable, per the "default = the real proc_open runner"
        // requirement, while every behavioral test above supplies a fake.
        $tool       = new Run_Wp_Cli();
        $reflection = new \ReflectionProperty(Run_Wp_Cli::class, 'executor');
        $executor   = $reflection->getValue($tool);

        $this->assertSame([\WPMCP\Tools\Cli\Wp_Cli_Executor::class, 'run'], $executor);
    }
}
