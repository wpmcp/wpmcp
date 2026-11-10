<?php

namespace WPMCP\Tests\Free\Cron;

use WPMCP\Cron_Registry;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Backup\Backup_Job_Store;
use WPMCP\Tools\Backup\Run_Backup_Job;
use WPMCP\Tools\Cron\Run_Event;
use WPMCP\Tools\Cron\Schedule_Event;

/**
 * Issue #563: schedule-event (manage_options, on by default) could queue
 * wpmcp_run_backup_job, or any other WP MCP internal hook, with any
 * arguments, and Run_Backup_Job::handle() ran whatever job id it was given
 * whatever that job's status. So an admin could trigger internal jobs out of
 * band and re-run finished or canceled backups.
 *
 * Agents may not queue (or fire) WP MCP's own hooks, nor core hooks whose
 * schedule WordPress manages, and the backup runner refuses any job that is
 * not queued, recording why without touching the job's outcome.
 */
class InternalHooksTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        delete_option(Backup_Job_Store::OPTION);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        if (is_multisite()) {
            grant_super_admin(get_current_user_id());
        }
    }

    protected function tearDown(): void
    {
        if (is_multisite()) {
            revoke_super_admin(get_current_user_id());
        }
        foreach (Cron_Registry::hooks() as $hook) {
            wp_unschedule_hook($hook);
        }
        remove_all_filters('wpmcp_cron_protected_hooks');
        remove_all_filters('wpmcp_enable_run_cron_event');
        delete_option(Backup_Job_Store::OPTION);
        parent::tearDown();
    }

    public function test_schedule_event_refuses_every_wp_mcp_internal_hook(): void
    {
        $this->assertContains('wpmcp_run_backup_job', Cron_Registry::hooks());

        $accepted = [];
        foreach (Cron_Registry::hooks() as $hook) {
            foreach ([['args' => [1]], ['recurrence' => 'hourly']] as $extra) {
                try {
                    (new Schedule_Event())->handle(['hook' => $hook] + $extra);
                    $accepted[] = $hook;
                } catch (\RuntimeException $e) {
                    $this->assertStringContainsString($hook, $e->getMessage());
                }
            }
            $this->assertFalse(wp_next_scheduled($hook, [1]), "{$hook} must not be queued");
            $this->assertFalse(wp_next_scheduled($hook), "{$hook} must not be queued");
        }

        $this->assertSame([], $accepted, 'schedule-event must refuse WP MCP internal hooks');
    }

    public function test_the_protected_hooks_filter_cannot_reopen_an_internal_hook(): void
    {
        add_filter('wpmcp_cron_protected_hooks', '__return_empty_array');

        $this->expectException(\RuntimeException::class);
        (new Schedule_Event())->handle(['hook' => 'wpmcp_run_backup_job', 'args' => [1]]);
    }

    /** Core hooks WordPress schedules and runs itself, beyond the update checks already refused. */
    public function test_schedule_event_refuses_core_maintenance_hooks(): void
    {
        $accepted = [];
        foreach (
            [
                'recovery_mode_clean_expired_keys',
                'wp_site_health_scheduled_check',
                'wp_update_user_counts',
                'wp_scheduled_auto_draft_delete',
                'wp_delete_temp_updater_backups',
                'wp_https_detection',
            ] as $hook
        ) {
            try {
                (new Schedule_Event())->handle(['hook' => $hook, 'recurrence' => 'hourly']);
                $accepted[] = $hook;
                wp_clear_scheduled_hook($hook);
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($hook, $e->getMessage());
            }
        }

        $this->assertSame([], $accepted);
    }

    public function test_run_event_refuses_to_fire_an_internal_hook_out_of_band(): void
    {
        add_filter('wpmcp_enable_run_cron_event', '__return_true');
        $job = Backup_Job_Store::create('full', 'all');
        wp_schedule_single_event(time() + HOUR_IN_SECONDS, Run_Backup_Job::HOOK, [$job['id']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(Run_Backup_Job::HOOK);
        (new Run_Event())->handle(['hook' => Run_Backup_Job::HOOK]);
    }

    /** @return array<string, array{string}> */
    public function finished_statuses(): array
    {
        return [
            'completed'                    => ['completed'],
            'failed'                       => ['failed'],
            'canceled'                     => ['canceled'],
            'running (duplicate delivery)' => ['running'],
        ];
    }

    /** @dataProvider finished_statuses */
    public function test_a_job_that_is_not_queued_is_not_run_again(string $status): void
    {
        $job = Backup_Job_Store::create('full', 'all');
        Backup_Job_Store::update($job['id'], ['status' => $status, 'result' => ['file' => '/tmp/x.zip'], 'error' => null]);

        $ran = false;
        (new Run_Backup_Job(static function () use (&$ran): array {
            $ran = true;
            return ['file' => '/tmp/new.zip'];
        }))->handle($job['id']);

        $after = Backup_Job_Store::get($job['id']);
        $this->assertFalse($ran, "A {$status} job must not be produced again");
        $this->assertSame($status, $after['status'], 'The job keeps its outcome');
        $this->assertSame(['file' => '/tmp/x.zip'], $after['result']);
        $this->assertStringContainsString($status, (string) ($after['last_refusal'] ?? ''), 'The refused run is recorded with its reason');
    }

    public function test_a_one_off_backup_records_who_queued_it(): void
    {
        $out = (new \WPMCP\Tools\Backup\Trigger_Backup())->handle(['type' => 'database']);

        $this->assertSame(get_current_user_id(), Backup_Job_Store::get($out['job_id'])['user_id'] ?? null);
    }

    public function test_a_queued_backup_is_canceled_when_its_owner_lost_the_capability(): void
    {
        $owner = (int) self::factory()->user->create(['role' => 'editor']);
        $job   = Backup_Job_Store::create('full', 'all', ['user_id' => $owner]);

        $ran = false;
        wp_set_current_user(0);
        (new Run_Backup_Job(static function () use (&$ran): array {
            $ran = true;
            return [];
        }))->handle($job['id']);

        $this->assertFalse($ran);
        $this->assertSame('canceled', Backup_Job_Store::get($job['id'])['status']);
        $this->assertNotEmpty(Backup_Job_Store::get($job['id'])['error']);
    }
}
