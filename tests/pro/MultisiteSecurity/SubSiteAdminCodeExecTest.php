<?php

namespace WPMCP\Tests\Pro\MultisiteSecurity;

use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Cli\Cli_Job_Store;
use WPMCP\Tools\Cli\Dispatch_Cli_Job;
use WPMCP\Tools\Cli\Run_Cli_Job;
use WPMCP\Tools\Cli\Run_Wp_Cli;
use WPMCP\Tools\Cli\Wp_Cli_Guard;
use WPMCP\Tools\Code\Activate_Php_Snippet;
use WPMCP\Tools\Code\Create_Php_Snippet;
use WPMCP\Tools\Code\Php_Snippet_Guard;
use WPMCP\Tools\Code\Php_Snippet_Store;
use WPMCP\Tools\Code\Run_Php_Snippet;

/**
 * Issue #562: run-php-snippet, activate-php-snippet and run-wp-cli (and its
 * async twin dispatch-cli-job, which reaches the same executor) are gated by
 * manage_options plus ONE network-wide opt-in. On multisite, once the network
 * operator opens that opt-in, every sub-site administrator holds
 * manage_options on its own blog and so gets code execution on the whole
 * network: network takeover. And run-wp-cli refuses a caller's --url, so the
 * command ran against the main site, not the caller's.
 *
 * These are pro abilities, which the free harness does not register, so this
 * drives the same handler objects Plugin::register_cli_abilities() and
 * register_php_exec_abilities() bind, with the evaluator and executor
 * injected so nothing is evaluated or spawned.
 *
 * Runs under WP_MULTISITE=1 and skips on single site, where nothing changes
 * (the single-site behavior stays covered by tests/free/Cli and
 * tests/free/Code).
 */
class SubSiteAdminCodeExecTest extends \WP_UnitTestCase
{
    private int $blog;
    private int $sub_admin;
    private int $super_admin;

    protected function setUp(): void
    {
        parent::setUp();
        if (! is_multisite()) {
            $this->markTestSkipped('Multisite only: run with WP_MULTISITE=1.');
        }
        $this->setExpectedDeprecated('wpmu_new_blog');

        $this->blog        = (int) self::factory()->blog->create();
        $this->super_admin = (int) self::factory()->user->create(['role' => 'administrator']);
        grant_super_admin($this->super_admin);
        $this->sub_admin = (int) self::factory()->user->create([
            'role'       => 'subscriber',
            'user_login' => 'subadmin' . wp_generate_password(6, false),
        ]);
        add_user_to_blog($this->blog, $this->sub_admin, 'administrator');

        switch_to_blog($this->blog);
        // Snapshot rows are per blog: the snippet tools snapshot first.
        Snapshot_Store::install();
        delete_option(Php_Snippet_Store::OPTION_NAME);
        delete_option(Cli_Job_Store::OPTION);
        wp_set_current_user($this->sub_admin);
        $this->assertFalse(is_super_admin($this->sub_admin));
        $this->assertTrue(current_user_can('manage_options'), 'Fixture: the sub-site admin holds manage_options on its blog');

        // Both opt-ins open and a permitted environment: the only thing left
        // between a sub-site admin and code execution is this issue's gate.
        add_filter('wpmcp_allow_php_exec', '__return_true');
        add_filter('wpmcp_allow_wp_cli', '__return_true');
        Php_Snippet_Guard::set_environment_override('development');
        Wp_Cli_Guard::set_environment_override('local');
        $bin = $this->fake_binary();
        add_filter('wpmcp_wp_cli_binary', static fn() => $bin);
    }

    protected function tearDown(): void
    {
        if (is_multisite()) {
            delete_option(Php_Snippet_Store::OPTION_NAME);
            delete_option(Cli_Job_Store::OPTION);
            wp_clear_scheduled_hook(Run_Cli_Job::HOOK, [1]);
            restore_current_blog();
            revoke_super_admin($this->super_admin);
        }
        remove_all_filters('wpmcp_allow_php_exec');
        remove_all_filters('wpmcp_allow_wp_cli');
        remove_all_filters('wpmcp_wp_cli_binary');
        remove_all_filters('wpmcp_allow_subsite_code_exec');
        Php_Snippet_Guard::set_environment_override(null);
        Wp_Cli_Guard::set_environment_override(null);
        parent::tearDown();
    }

    private function fake_binary(): string
    {
        $bin = sys_get_temp_dir() . '/wpmcp-fake-wp-' . getmypid();
        if (! file_exists($bin)) {
            file_put_contents($bin, "#!/bin/sh\necho ok\n");
            chmod($bin, 0755);
        }
        return $bin;
    }

    private function recording_evaluator(array &$calls): callable
    {
        return static function (string $code, int $timeout) use (&$calls): array {
            $calls[] = $code;
            return ['return_value' => 1, 'output' => '', 'error' => null];
        };
    }

    private function recording_executor(array &$calls): callable
    {
        return static function (array $argv, int $timeout) use (&$calls): array {
            $calls[] = $argv;
            return ['stdout' => 'ok', 'stderr' => '', 'exit_code' => 0, 'timed_out' => false];
        };
    }

    private function stored_snippet(): string
    {
        return (new Create_Php_Snippet())->handle(['name' => 'candidate', 'code' => '<?php return 1;'])['snippet']['id'];
    }

    /** The --url WP-CLI needs to address this blog: its domain plus path. */
    private function blog_url_flag(): string
    {
        $site = get_site($this->blog);
        return '--url=' . $site->domain . $site->path;
    }

    public function test_a_sub_site_admin_cannot_run_a_php_snippet(): void
    {
        $calls = [];
        try {
            (new Run_Php_Snippet($this->recording_evaluator($calls)))->handle(['code' => 'return 1;']);
            $this->fail('run-php-snippet must refuse a sub-site admin on multisite');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('network administrator', $e->getMessage());
        }
        $this->assertSame([], $calls, 'The evaluator must never run for a sub-site admin');
    }

    public function test_a_sub_site_admin_cannot_activate_a_stored_snippet(): void
    {
        $id = $this->stored_snippet();

        try {
            (new Activate_Php_Snippet())->handle(['id' => $id]);
            $this->fail('activate-php-snippet must refuse a sub-site admin on multisite');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('network administrator', $e->getMessage());
        }
        $this->assertSame(Php_Snippet_Store::STATUS_INACTIVE, Php_Snippet_Store::get($id)['status']);
    }

    public function test_a_sub_site_admin_cannot_run_wp_cli(): void
    {
        $calls = [];
        try {
            (new Run_Wp_Cli($this->recording_executor($calls)))->handle(['command' => 'core version']);
            $this->fail('run-wp-cli must refuse a sub-site admin on multisite');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('network administrator', $e->getMessage());
        }
        $this->assertSame([], $calls, 'The executor must never run for a sub-site admin');
    }

    public function test_a_sub_site_admin_cannot_dispatch_a_cli_job(): void
    {
        try {
            (new Dispatch_Cli_Job())->handle(['command' => 'core version']);
            $this->fail('dispatch-cli-job must refuse a sub-site admin on multisite');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('network administrator', $e->getMessage());
        }
        $this->assertSame([], Cli_Job_Store::list(), 'Nothing may be queued for a sub-site admin');
    }

    public function test_a_super_admin_can_use_every_exec_tool(): void
    {
        wp_set_current_user($this->super_admin);

        $evaluated = [];
        (new Run_Php_Snippet($this->recording_evaluator($evaluated)))->handle(['code' => 'return 1;']);
        $this->assertCount(1, $evaluated);

        $id  = $this->stored_snippet();
        $out = (new Activate_Php_Snippet())->handle(['id' => $id]);
        $this->assertSame(Php_Snippet_Store::STATUS_ACTIVE, $out['snippet']['status']);

        $executed = [];
        (new Run_Wp_Cli($this->recording_executor($executed)))->handle(['command' => 'core version']);
        $this->assertCount(1, $executed);

        $job = (new Dispatch_Cli_Job())->handle(['command' => 'core version']);
        $this->assertSame('queued', $job['status']);
    }

    public function test_run_wp_cli_targets_the_callers_own_site(): void
    {
        wp_set_current_user($this->super_admin);

        $calls = [];
        (new Run_Wp_Cli($this->recording_executor($calls)))->handle(['command' => 'core version']);

        $this->assertSame([$this->fake_binary(), 'core', 'version', $this->blog_url_flag()], $calls[0]);
    }

    public function test_a_queued_cli_job_runs_against_the_site_it_was_queued_on(): void
    {
        wp_set_current_user($this->super_admin);
        $job = (new Dispatch_Cli_Job())->handle(['command' => 'core version']);

        // WP-Cron runs with no signed-in user.
        wp_set_current_user(0);
        $calls = [];
        (new Run_Cli_Job($this->recording_executor($calls)))->handle((int) $job['job_id']);

        $this->assertSame('completed', Cli_Job_Store::get((int) $job['job_id'])['status']);
        $this->assertSame([$this->fake_binary(), 'core', 'version', $this->blog_url_flag()], $calls[0]);
    }

    public function test_a_queued_cli_job_fails_once_its_dispatcher_is_no_longer_a_network_admin(): void
    {
        wp_set_current_user($this->super_admin);
        $job = (new Dispatch_Cli_Job())->handle(['command' => 'core version']);

        revoke_super_admin($this->super_admin);
        wp_set_current_user(0);
        $calls = [];
        (new Run_Cli_Job($this->recording_executor($calls)))->handle((int) $job['job_id']);
        grant_super_admin($this->super_admin);

        $this->assertSame([], $calls, 'Revoking network admin must stop already-queued work');
        $stored = Cli_Job_Store::get((int) $job['job_id']);
        $this->assertSame('failed', $stored['status']);
        $this->assertStringContainsString('network administrator', (string) $stored['error']);
    }

    public function test_a_queued_cli_job_with_no_recorded_dispatcher_fails_closed(): void
    {
        // A record with no owner (written before owners were stored, or put
        // there by anything other than dispatch-cli-job) cannot prove a
        // network admin queued it.
        $job   = Cli_Job_Store::create(['core', 'version'], 60);
        $calls = [];
        wp_set_current_user(0);
        (new Run_Cli_Job($this->recording_executor($calls)))->handle((int) $job['id']);

        $this->assertSame([], $calls);
        $this->assertSame('failed', Cli_Job_Store::get((int) $job['id'])['status']);
    }

    public function test_a_network_can_grant_sub_site_admins_access_with_the_filter(): void
    {
        $seen = [];
        add_filter(
            'wpmcp_allow_subsite_code_exec',
            static function ($allowed, $surface, $user_id, $blog_id) use (&$seen) {
                $seen[] = [$surface, $user_id, $blog_id];
                return true;
            },
            10,
            4
        );

        $evaluated = [];
        (new Run_Php_Snippet($this->recording_evaluator($evaluated)))->handle(['code' => 'return 1;']);
        $executed = [];
        (new Run_Wp_Cli($this->recording_executor($executed)))->handle(['command' => 'core version']);

        $this->assertCount(1, $evaluated);
        $this->assertSame([$this->fake_binary(), 'core', 'version', $this->blog_url_flag()], $executed[0]);
        $this->assertSame(['php', $this->sub_admin, $this->blog], $seen[0]);
        $this->assertSame(['wp-cli', $this->sub_admin, $this->blog], $seen[1]);
    }

    public function test_the_php_opt_in_alone_does_not_satisfy_the_network_gate(): void
    {
        // The issue's "whatever the opt-in": the production override is a
        // different, environment-level switch and must not open it either.
        add_filter('wpmcp_allow_php_exec_on_production', '__return_true');
        Php_Snippet_Guard::set_environment_override('production');

        $calls = [];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('network administrator');
        (new Run_Php_Snippet($this->recording_evaluator($calls)))->handle(['code' => 'return 1;']);
    }
}
