<?php

namespace WPMCP\Tests\Free\Cli;

use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Tools\Cli\Cli_Job_Store;
use WPMCP\Tools\Cli\Run_Cli_Job;
use WPMCP\Tools\Cli\Wp_Cli_Executor;
use WPMCP\Tools\Cli\Wp_Cli_Guard;

/**
 * Run_Cli_Job is the WP-Cron executor behind dispatch-cli-job. Like
 * Run_Wp_Cli, it takes the subprocess runner as an injected callable, so
 * every test here asserts the argv that WOULD run, the status transitions,
 * and the output handling without ever spawning a process.
 *
 * The security-relevant behavior under test is the SECOND guard pass: the
 * stored argv is re-guarded immediately before execution, so a job queued
 * while the opt-in gate was open still fails closed once that gate is shut,
 * the environment flips to production, or the allowlist is narrowed.
 */
class RunCliJobTest extends \WP_UnitTestCase
{
    // Issue #562: on multisite these tools need a network administrator.
    use \WPMCP\Tests\Free\MultisiteSecurity\Network_Exec_Caller;

    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Cli_Job_Store::OPTION);
        delete_option(Governance_Audit_Log::OPTION);
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
        remove_all_filters('wpmcp_wp_cli_binary');
        remove_all_filters('wpmcp_cli_job_output_limit');
        Wp_Cli_Guard::set_environment_override(null);
        Cli_Job_Store::set_clock_for_tests(null);
        delete_option(Cli_Job_Store::OPTION);
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
        add_filter('wpmcp_wp_cli_binary', fn() => $bin);
        return $bin;
    }

    private function enable(): string
    {
        add_filter('wpmcp_allow_wp_cli', '__return_true');
        return $this->fake_binary();
    }

    private function recording_executor(array &$calls, ?array $result = null): callable
    {
        return function (array $argv, int $timeout) use (&$calls, $result): array {
            $calls[] = ['argv' => $argv, 'timeout' => $timeout];
            return $result ?? [
                'stdout'    => 'ok',
                'stderr'    => '',
                'exit_code' => 0,
                'timed_out' => false,
            ];
        };
    }

    public function test_a_queued_job_runs_and_completes_with_its_captured_output(): void
    {
        $bin = $this->enable();
        $job = Cli_Job_Store::create(['plugin', 'list'], 120);

        $calls = [];
        (new Run_Cli_Job($this->recording_executor($calls)))->handle($job['id']);

        $this->assertSame(array_merge([$bin, 'plugin', 'list'], $this->expected_site_args()), $calls[0]['argv']);
        $this->assertSame(120, $calls[0]['timeout']);

        $done = Cli_Job_Store::get($job['id']);
        $this->assertSame('completed', $done['status']);
        $this->assertSame('ok', $done['result']['stdout']);
        $this->assertSame(0, $done['result']['exit_code']);
        $this->assertFalse($done['result']['timed_out']);
        $this->assertFalse($done['result']['truncated']);
        $this->assertNull($done['error']);
    }

    public function test_a_nonzero_exit_code_still_completes_the_job_and_is_reported(): void
    {
        $this->enable();
        $job = Cli_Job_Store::create(['plugin', 'list'], 120);

        $calls = [];
        (new Run_Cli_Job($this->recording_executor($calls, [
            'stdout'    => '',
            'stderr'    => 'Error: something went wrong',
            'exit_code' => 1,
            'timed_out' => false,
        ])))->handle($job['id']);

        // The command ran to completion, so the JOB completed; the command's
        // own failure is reported through exit_code/stderr rather than by
        // conflating "the job could not run" with "the command said no".
        $done = Cli_Job_Store::get($job['id']);
        $this->assertSame('completed', $done['status']);
        $this->assertSame(1, $done['result']['exit_code']);
        $this->assertSame('Error: something went wrong', $done['result']['stderr']);
    }

    public function test_a_timed_out_run_is_surfaced_in_the_job_result(): void
    {
        $this->enable();
        $job = Cli_Job_Store::create(['plugin', 'list'], 5);

        $calls = [];
        (new Run_Cli_Job($this->recording_executor($calls, [
            'stdout'    => '',
            'stderr'    => 'timed out',
            'exit_code' => -1,
            'timed_out' => true,
        ])))->handle($job['id']);

        $done = Cli_Job_Store::get($job['id']);
        $this->assertTrue($done['result']['timed_out']);
        $this->assertSame(-1, $done['result']['exit_code']);
    }

    public function test_status_transitions_from_queued_through_running_to_completed(): void
    {
        $this->enable();
        $job = Cli_Job_Store::create(['plugin', 'list'], 120);
        $this->assertSame('queued', Cli_Job_Store::get($job['id'])['status']);

        $observed = null;
        $executor = function (array $argv, int $timeout) use ($job, &$observed): array {
            // Observed from inside the run: the record a poller would see
            // while the command is in flight.
            $observed = Cli_Job_Store::get($job['id'])['status'];
            return ['stdout' => '', 'stderr' => '', 'exit_code' => 0, 'timed_out' => false];
        };

        (new Run_Cli_Job($executor))->handle($job['id']);

        $this->assertSame('running', $observed);
        $this->assertSame('completed', Cli_Job_Store::get($job['id'])['status']);
    }

    // ---------------------------------------------------------------
    // The pre-execution guard re-check
    // ---------------------------------------------------------------

    public function test_a_job_queued_before_the_gate_closed_does_not_run(): void
    {
        $this->fake_binary();
        // No wpmcp_allow_wp_cli filter: the gate that was open at dispatch
        // time is shut by the time cron fires.
        $job = Cli_Job_Store::create(['plugin', 'list'], 120);

        $calls = [];
        (new Run_Cli_Job($this->recording_executor($calls)))->handle($job['id']);

        $this->assertCount(0, $calls, 'A revoked gate must stop already-queued work.');
        $failed = Cli_Job_Store::get($job['id']);
        $this->assertSame('failed', $failed['status']);
        $this->assertStringContainsString('WP-CLI execution is disabled', $failed['error']);
    }

    public function test_a_job_does_not_run_after_the_environment_flips_to_production(): void
    {
        $this->enable();
        $job = Cli_Job_Store::create(['plugin', 'list'], 120);

        Wp_Cli_Guard::set_environment_override('production');

        $calls = [];
        (new Run_Cli_Job($this->recording_executor($calls)))->handle($job['id']);

        $this->assertCount(0, $calls);
        $this->assertSame('failed', Cli_Job_Store::get($job['id'])['status']);
    }

    public function test_a_job_does_not_run_after_the_allowlist_is_narrowed(): void
    {
        $this->enable();
        $job = Cli_Job_Store::create(['plugin', 'list'], 120);

        add_filter('wpmcp_wp_cli_allowlist', fn() => ['core version']);

        $calls = [];
        (new Run_Cli_Job($this->recording_executor($calls)))->handle($job['id']);

        $this->assertCount(0, $calls);
        $this->assertStringContainsString('not on the allowlist', Cli_Job_Store::get($job['id'])['error']);
    }

    public function test_a_tampered_argv_in_the_option_is_re_guarded_and_refused(): void
    {
        $this->enable();
        $job = Cli_Job_Store::create(['plugin', 'list'], 120);

        // Anyone who can write the option (a second vulnerability) must not
        // thereby get command execution: the stored argv is re-validated,
        // never trusted because it once passed.
        Cli_Job_Store::update($job['id'], ['argv' => ['plugin', 'list', '--exec=include("/tmp/x")']]);

        $calls = [];
        (new Run_Cli_Job($this->recording_executor($calls)))->handle($job['id']);

        $this->assertCount(0, $calls);
        $this->assertSame('failed', Cli_Job_Store::get($job['id'])['status']);
    }

    public function test_a_refused_run_records_a_denied_audit_entry(): void
    {
        $this->fake_binary();
        $job = Cli_Job_Store::create(['plugin', 'list'], 120);

        $calls = [];
        (new Run_Cli_Job($this->recording_executor($calls)))->handle($job['id']);

        $entries = Governance_Audit_Log::list();
        $this->assertCount(1, $entries);
        $this->assertSame('wpmcp/dispatch-cli-job', $entries[0]['ability']);
        $this->assertFalse($entries[0]['allowed']);
    }

    public function test_a_permitted_run_records_an_allowed_audit_entry(): void
    {
        $this->enable();
        $job = Cli_Job_Store::create(['plugin', 'list'], 120);

        $calls = [];
        (new Run_Cli_Job($this->recording_executor($calls)))->handle($job['id']);

        $entries = Governance_Audit_Log::list();
        $this->assertCount(1, $entries);
        $this->assertTrue($entries[0]['allowed']);
    }

    // ---------------------------------------------------------------
    // Non-queued and unknown jobs
    // ---------------------------------------------------------------

    public function test_a_canceled_job_is_skipped_even_if_its_cron_event_still_fires(): void
    {
        $this->enable();
        $job = Cli_Job_Store::create(['plugin', 'list'], 120);
        Cli_Job_Store::update($job['id'], ['status' => 'canceled']);

        $calls = [];
        (new Run_Cli_Job($this->recording_executor($calls)))->handle($job['id']);

        $this->assertCount(0, $calls);
        $this->assertSame('canceled', Cli_Job_Store::get($job['id'])['status']);
    }

    public function test_a_duplicate_cron_delivery_does_not_run_the_command_twice(): void
    {
        $this->enable();
        $job = Cli_Job_Store::create(['plugin', 'list'], 120);

        $calls  = [];
        $runner = new Run_Cli_Job($this->recording_executor($calls));
        $runner->handle($job['id']);
        $runner->handle($job['id']);

        $this->assertCount(1, $calls);
    }

    public function test_an_unknown_job_id_is_a_silent_no_op(): void
    {
        $this->enable();

        $calls = [];
        (new Run_Cli_Job($this->recording_executor($calls)))->handle(999999);

        $this->assertCount(0, $calls);
        $this->assertSame([], Cli_Job_Store::list());
    }

    public function test_an_executor_that_throws_fails_the_job_instead_of_leaving_it_running(): void
    {
        $this->enable();
        $job = Cli_Job_Store::create(['plugin', 'list'], 120);

        (new Run_Cli_Job(function (array $argv, int $timeout): array {
            throw new \RuntimeException('the runner exploded');
        }))->handle($job['id']);

        $failed = Cli_Job_Store::get($job['id']);
        $this->assertSame('failed', $failed['status']);
        $this->assertSame('the runner exploded', $failed['error']);
        $this->assertNull($failed['result']);
    }

    // ---------------------------------------------------------------
    // Output capping
    // ---------------------------------------------------------------

    public function test_oversized_output_is_capped_and_flagged_truncated(): void
    {
        $this->enable();
        add_filter('wpmcp_cli_job_output_limit', fn() => 16);
        $job = Cli_Job_Store::create(['plugin', 'list'], 120);

        $calls = [];
        (new Run_Cli_Job($this->recording_executor($calls, [
            'stdout'    => str_repeat('a', 100),
            'stderr'    => '',
            'exit_code' => 0,
            'timed_out' => false,
        ])))->handle($job['id']);

        $result = Cli_Job_Store::get($job['id'])['result'];
        $this->assertTrue($result['truncated']);
        $this->assertSame(100, $result['output_bytes']);
        $this->assertStringStartsWith(str_repeat('a', 16), $result['stdout']);
        $this->assertStringContainsString('84 further byte(s) dropped', $result['stdout']);
    }

    public function test_each_stream_is_capped_independently(): void
    {
        $this->enable();
        add_filter('wpmcp_cli_job_output_limit', fn() => 10);
        $job = Cli_Job_Store::create(['plugin', 'list'], 120);

        $calls = [];
        (new Run_Cli_Job($this->recording_executor($calls, [
            'stdout'    => 'short',
            'stderr'    => str_repeat('e', 50),
            'exit_code' => 0,
            'timed_out' => false,
        ])))->handle($job['id']);

        $result = Cli_Job_Store::get($job['id'])['result'];
        $this->assertSame('short', $result['stdout'], 'An under-cap stream must be stored verbatim.');
        $this->assertTrue($result['truncated']);
        $this->assertStringContainsString('40 further byte(s) dropped', $result['stderr']);
    }

    public function test_output_within_the_cap_is_stored_verbatim(): void
    {
        $this->enable();
        $job = Cli_Job_Store::create(['plugin', 'list'], 120);

        $calls = [];
        (new Run_Cli_Job($this->recording_executor($calls, [
            'stdout'    => "line one\nline two\n",
            'stderr'    => 'a warning',
            'exit_code' => 0,
            'timed_out' => false,
        ])))->handle($job['id']);

        $result = Cli_Job_Store::get($job['id'])['result'];
        $this->assertSame("line one\nline two\n", $result['stdout']);
        $this->assertSame('a warning', $result['stderr']);
        $this->assertFalse($result['truncated']);
    }

    public function test_the_stored_timeout_is_clamped_before_it_reaches_the_executor(): void
    {
        $this->enable();
        // A record whose timeout was written out of range (a hand-edited or
        // migrated option) must not hand an unbounded budget to the runner.
        $job = Cli_Job_Store::create(['plugin', 'list'], 120);
        Cli_Job_Store::update($job['id'], ['timeout' => 999999]);

        $calls = [];
        (new Run_Cli_Job($this->recording_executor($calls)))->handle($job['id']);

        $this->assertSame(\WPMCP\Tools\Cli\Dispatch_Cli_Job::MAX_TIMEOUT_SECONDS, $calls[0]['timeout']);
    }

    public function test_default_executor_is_the_real_wp_cli_executor_class(): void
    {
        $runner     = new Run_Cli_Job();
        $reflection = new \ReflectionProperty(Run_Cli_Job::class, 'executor');
        $executor   = $reflection->getValue($runner);

        $this->assertSame([Wp_Cli_Executor::class, 'run'], $executor);
    }
}
