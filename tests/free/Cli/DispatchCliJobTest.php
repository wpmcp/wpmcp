<?php

namespace WPMCP\Tests\Free\Cli;

use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Tools\Cli\Cli_Job_Store;
use WPMCP\Tools\Cli\Dispatch_Cli_Job;
use WPMCP\Tools\Cli\Run_Cli_Job;
use WPMCP\Tools\Cli\Wp_Cli_Guard;

/**
 * dispatch-cli-job (issue #84) queues a guarded wp-cli command as a WP-Cron
 * background job and returns its id immediately.
 *
 * The guard-chain parity with the synchronous tool is proved in
 * WpCliGuardChainSharedTest, which drives the identical refusal matrix
 * through both entry points; these tests cover what is specific to
 * dispatching: nothing is queued when a guard refuses, the job record and
 * cron event that a permitted dispatch produces, timeout clamping, and the
 * audit convention.
 */
class DispatchCliJobTest extends \WP_UnitTestCase
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

    private function enable(): void
    {
        add_filter('wpmcp_allow_wp_cli', '__return_true');
        $this->fake_binary();
    }

    public function test_dispatch_returns_a_job_id_immediately_and_queues_a_cron_event(): void
    {
        $this->enable();

        $out = (new Dispatch_Cli_Job())->handle(['command' => 'plugin list --format=json']);

        $this->assertSame(1, $out['job_id']);
        $this->assertSame('queued', $out['status']);
        $this->assertSame('plugin list --format=json', $out['command']);
        $this->assertNotFalse(wp_next_scheduled(Run_Cli_Job::HOOK, [$out['job_id']]));
    }

    public function test_the_stored_job_holds_the_split_argv_not_the_raw_string(): void
    {
        $this->enable();

        $out = (new Dispatch_Cli_Job())->handle(['command' => "plugin   list\t--format=json"]);
        $job = Cli_Job_Store::get($out['job_id']);

        // The argv is what Run_Cli_Job re-guards and executes, so it must be
        // stored already split and normalized rather than re-parsed later
        // from whatever whitespace the caller happened to send.
        $this->assertSame(['plugin', 'list', '--format=json'], $job['argv']);
    }

    public function test_a_refused_command_queues_nothing_at_all(): void
    {
        $this->enable();

        try {
            (new Dispatch_Cli_Job())->handle(['command' => 'plugin delete akismet']);
            $this->fail('A non-allowlisted subcommand must be refused at dispatch.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not on the allowlist', $e->getMessage());
        }

        $this->assertSame([], Cli_Job_Store::list());
        $this->assertFalse(wp_next_scheduled(Run_Cli_Job::HOOK, [1]));
    }

    public function test_dispatch_while_the_gate_is_closed_queues_nothing(): void
    {
        $this->fake_binary();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('WP-CLI execution is disabled');
        try {
            (new Dispatch_Cli_Job())->handle(['command' => 'core version']);
        } finally {
            $this->assertSame([], Cli_Job_Store::list());
        }
    }

    public function test_requires_a_command(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Dispatch_Cli_Job())->handle([]);
    }

    public function test_default_timeout_is_used_when_none_is_given(): void
    {
        $this->enable();

        $out = (new Dispatch_Cli_Job())->handle(['command' => 'core version']);

        $this->assertSame(Dispatch_Cli_Job::DEFAULT_TIMEOUT_SECONDS, $out['timeout']);
    }

    /** @dataProvider timeout_clamping */
    public function test_requested_timeout_is_clamped_into_range($requested, int $expected): void
    {
        $this->enable();

        $out = (new Dispatch_Cli_Job())->handle(['command' => 'core version', 'timeout' => $requested]);

        $this->assertSame($expected, $out['timeout']);
    }

    public function timeout_clamping(): array
    {
        return [
            'in range'          => [120, 120],
            'above the ceiling' => [100000, Dispatch_Cli_Job::MAX_TIMEOUT_SECONDS],
            'zero'              => [0, 1],
            'negative'          => [-5, 1],
        ];
    }

    public function test_dispatch_records_an_allowed_audit_entry_without_the_command(): void
    {
        add_filter('wpmcp_allow_wp_cli', '__return_true');
        add_filter('wpmcp_wp_cli_allowlist', function (array $allowlist): array {
            $allowlist[] = 'option get';
            return $allowlist;
        });
        $this->fake_binary();

        (new Dispatch_Cli_Job())->handle(['command' => 'option get some_secret_option_name']);

        $entries = Governance_Audit_Log::list();
        $this->assertCount(1, $entries);
        $this->assertSame('wpmcp/dispatch-cli-job', $entries[0]['ability']);
        $this->assertTrue($entries[0]['allowed']);
        $this->assertStringNotContainsString('some_secret_option_name', wp_json_encode($entries));
    }

    public function test_a_refused_dispatch_records_a_denied_audit_entry(): void
    {
        $this->enable();

        try {
            (new Dispatch_Cli_Job())->handle(['command' => 'plugin delete akismet']);
        } catch (\RuntimeException $e) {
            // expected
        }

        $entries = Governance_Audit_Log::list();
        $this->assertCount(1, $entries);
        $this->assertSame('wpmcp/dispatch-cli-job', $entries[0]['ability']);
        $this->assertFalse($entries[0]['allowed']);
    }

    public function test_dispatch_is_refused_once_too_many_jobs_are_in_flight(): void
    {
        $this->enable();
        add_filter('wpmcp_cli_job_max_in_flight', fn() => 2);

        $tool = new Dispatch_Cli_Job();
        $tool->handle(['command' => 'core version']);
        $tool->handle(['command' => 'core version']);

        try {
            $tool->handle(['command' => 'core version']);
            $this->fail('A third dispatch must be refused at an in-flight cap of two.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Too many CLI jobs are already in flight', $e->getMessage());
        }

        $this->assertCount(2, Cli_Job_Store::list());
        remove_all_filters('wpmcp_cli_job_max_in_flight');
    }

    public function test_a_finished_job_frees_an_in_flight_slot(): void
    {
        $this->enable();
        add_filter('wpmcp_cli_job_max_in_flight', fn() => 1);

        $tool  = new Dispatch_Cli_Job();
        $first = $tool->handle(['command' => 'core version']);
        Cli_Job_Store::update($first['job_id'], ['status' => 'completed']);

        $second = $tool->handle(['command' => 'core version']);

        $this->assertSame('queued', $second['status']);
        remove_all_filters('wpmcp_cli_job_max_in_flight');
    }

    public function test_a_dead_worker_does_not_hold_an_in_flight_slot_forever(): void
    {
        $this->enable();
        add_filter('wpmcp_cli_job_max_in_flight', fn() => 1);

        Cli_Job_Store::set_clock_for_tests(1700000000);
        $stuck = Cli_Job_Store::create(['core', 'version'], 60);
        Cli_Job_Store::update($stuck['id'], ['status' => 'running']);

        // The purge that runs before the cap is counted reaps the abandoned
        // job, so a dead worker cannot wedge the dispatcher permanently.
        Cli_Job_Store::set_clock_for_tests(1700000000 + 60 + Cli_Job_Store::ABANDONED_GRACE_SECONDS + 1);

        $out = (new Dispatch_Cli_Job())->handle(['command' => 'core version']);

        $this->assertSame('queued', $out['status']);
        $this->assertSame('failed', Cli_Job_Store::get($stuck['id'])['status']);
        remove_all_filters('wpmcp_cli_job_max_in_flight');
    }

    public function test_an_in_flight_refusal_records_a_denied_audit_entry(): void
    {
        $this->enable();
        add_filter('wpmcp_cli_job_max_in_flight', fn() => 1);

        $tool = new Dispatch_Cli_Job();
        $tool->handle(['command' => 'core version']);
        try {
            $tool->handle(['command' => 'core version']);
        } catch (\RuntimeException $e) {
            // expected
        }

        $entries = Governance_Audit_Log::list();
        $this->assertCount(2, $entries);
        $denied = array_values(array_filter($entries, fn(array $e): bool => false === $e['allowed']));
        $this->assertCount(1, $denied);
        remove_all_filters('wpmcp_cli_job_max_in_flight');
    }

    public function test_dispatch_reaps_stale_records_so_the_store_stays_bounded(): void
    {
        $this->enable();
        add_filter('wpmcp_cli_job_max_records', fn() => 2);
        add_filter('wpmcp_cli_job_retention_seconds', fn() => 1000000);

        Cli_Job_Store::set_clock_for_tests(1700000000);
        foreach ([1, 2, 3] as $ignored) {
            $job = Cli_Job_Store::create(['core', 'version'], 300);
            Cli_Job_Store::update($job['id'], ['status' => 'completed']);
        }

        $out = (new Dispatch_Cli_Job())->handle(['command' => 'core version']);

        // Three stale records trimmed to the cap of 2 before the new job was
        // added, so the store holds the cap plus the job just dispatched.
        $ids = array_column(Cli_Job_Store::list(), 'id');
        $this->assertCount(3, $ids);
        $this->assertContains($out['job_id'], $ids);

        remove_all_filters('wpmcp_cli_job_max_records');
        remove_all_filters('wpmcp_cli_job_retention_seconds');
    }
}
