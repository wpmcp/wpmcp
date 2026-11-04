<?php

namespace WPMCP\Tests\Free\MultisiteSecurity;

use WPMCP\Cron_Registry;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Backup\Backup_Job_Store;
use WPMCP\Tools\Backup\Run_Backup_Job;
use WPMCP\Tools\Cron\Schedule_Event;

/**
 * Issue #563 on a network. Network_Scope lets a request with no signed-in
 * user through to the whole-database engine, on the grounds that a backup
 * job "could only have been queued by someone who passed the gate". A
 * sub-site admin holds manage_options, so schedule-event let it queue
 * wpmcp_run_backup_job for any job id in its blog's store, and the runner
 * re-ran it: a network-wide backup produced at a sub-site admin's request.
 *
 * Runs under WP_MULTISITE=1 and skips on single site (InternalHooksTest
 * covers the same rules there).
 */
class SubSiteAdminInternalJobsTest extends \WP_UnitTestCase
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
        Snapshot_Store::install();
        delete_option(Backup_Job_Store::OPTION);
        wp_set_current_user($this->sub_admin);
        $this->assertTrue(current_user_can('manage_options'), 'Fixture: the sub-site admin holds manage_options on its blog');
    }

    protected function tearDown(): void
    {
        if (is_multisite()) {
            foreach (Cron_Registry::hooks() as $hook) {
                wp_unschedule_hook($hook);
            }
            delete_option(Backup_Job_Store::OPTION);
            restore_current_blog();
            revoke_super_admin($this->super_admin);
        }
        parent::tearDown();
    }

    public function test_a_sub_site_admin_cannot_queue_a_backup_job_run(): void
    {
        $job = Backup_Job_Store::create('full', 'all', ['user_id' => $this->super_admin]);
        Backup_Job_Store::update($job['id'], ['status' => 'completed']);

        try {
            (new Schedule_Event())->handle(['hook' => Run_Backup_Job::HOOK, 'args' => [$job['id']], 'timestamp' => time()]);
            $this->fail('schedule-event must refuse wpmcp_run_backup_job');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString(Run_Backup_Job::HOOK, $e->getMessage());
        }

        $this->assertFalse(wp_next_scheduled(Run_Backup_Job::HOOK, [$job['id']]));
    }

    public function test_a_finished_network_backup_is_not_produced_again_by_cron(): void
    {
        $job = Backup_Job_Store::create('full', 'all', ['user_id' => $this->super_admin]);
        Backup_Job_Store::update($job['id'], ['status' => 'completed', 'result' => ['file' => '/tmp/done.zip']]);

        wp_set_current_user(0);
        $ran = false;
        (new Run_Backup_Job(static function () use (&$ran): array {
            $ran = true;
            return [];
        }))->handle($job['id']);

        $this->assertFalse($ran);
        $after = Backup_Job_Store::get($job['id']);
        $this->assertSame('completed', $after['status']);
        $this->assertSame(['file' => '/tmp/done.zip'], $after['result']);
        $this->assertNotEmpty($after['last_refusal'] ?? '');
    }

    public function test_a_queued_backup_whose_owner_is_no_longer_a_network_admin_is_canceled(): void
    {
        $job = Backup_Job_Store::create('full', 'all', ['user_id' => $this->super_admin]);
        revoke_super_admin($this->super_admin);

        wp_set_current_user(0);
        $ran = false;
        (new Run_Backup_Job(static function () use (&$ran): array {
            $ran = true;
            return [];
        }))->handle($job['id']);
        grant_super_admin($this->super_admin);

        $this->assertFalse($ran);
        $this->assertSame('canceled', Backup_Job_Store::get($job['id'])['status']);
    }

    public function test_a_queued_backup_from_a_network_admin_still_runs(): void
    {
        $job = Backup_Job_Store::create('full', 'all', ['user_id' => $this->super_admin]);

        wp_set_current_user(0);
        (new Run_Backup_Job(static fn(): array => ['file' => '/tmp/ok.zip']))->handle($job['id']);

        $this->assertSame('completed', Backup_Job_Store::get($job['id'])['status']);
    }
}
