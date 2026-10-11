<?php

namespace WPMCP\Tests\Free\MultisiteSecurity;

use WPMCP\Tools\Backup\Backup_Job_Store;
use WPMCP\Tools\Backup\Backup_Schedule;
use WPMCP\Tools\Backup\List_Backup_Jobs;
use WPMCP\Tools\Backup\Run_Backup_Job;
use WPMCP\Tools\Backup\Trigger_Backup;

/**
 * Issue #541: a recurring backup (issue #455) that a sub-site administrator
 * scheduled before whole-database backups became network-admin only (issue
 * #525) must stop producing network-wide archives.
 *
 * A schedule records its creator (set_by). On multisite each run checks
 * that the creator still holds manage_network_options; when they do not,
 * or no creator is recorded (fail closed), the run queues nothing, the
 * schedule is disabled with a recorded reason, and a network admin sees
 * that reason in list-backup-jobs on any site of the network. A backup job
 * such a schedule queued before the upgrade is canceled rather than run,
 * and the archives it already produced are flagged for review.
 *
 * The pre-upgrade state is written straight into the option and WP-Cron,
 * since set() itself now refuses a sub-site admin. Runs under
 * WP_MULTISITE=1 and skips on single site.
 */
class SubSiteAdminScheduledBackupTest extends \WP_UnitTestCase
{
    /** Tue 14 Nov 2023 22:13:20 UTC. */
    private const NOW = 1700000000;

    private int $blog;
    private int $sub_admin;
    private int $super_admin;
    private int $produced = 0;

    protected function setUp(): void
    {
        parent::setUp();
        if (! is_multisite()) {
            $this->markTestSkipped('Multisite only: run with WP_MULTISITE=1.');
        }
        $this->setExpectedDeprecated('wpmu_new_blog');

        Backup_Job_Store::set_clock_for_tests(self::NOW);
        delete_site_option(Backup_Schedule::NETWORK_DISABLED_OPTION);

        $this->blog        = (int) self::factory()->blog->create();
        $this->super_admin = (int) self::factory()->user->create(['role' => 'administrator']);
        grant_super_admin($this->super_admin);
        $this->sub_admin = (int) self::factory()->user->create([
            'role'       => 'subscriber',
            'user_login' => 'subadmin' . wp_generate_password(6, false),
        ]);
        add_user_to_blog($this->blog, $this->sub_admin, 'administrator');

        switch_to_blog($this->blog);
        delete_option(Backup_Job_Store::OPTION);
        delete_option(Backup_Schedule::OPTION);
        // A cron run has no signed-in user.
        wp_set_current_user(0);
    }

    protected function tearDown(): void
    {
        if (is_multisite()) {
            foreach (['full', 'database', 'files', 'uploads'] as $type) {
                wp_clear_scheduled_hook(Backup_Schedule::HOOK, [$type]);
            }
            wp_clear_scheduled_hook(Run_Backup_Job::HOOK);
            delete_option(Backup_Job_Store::OPTION);
            delete_option(Backup_Schedule::OPTION);
            restore_current_blog();
            delete_site_option(Backup_Schedule::NETWORK_DISABLED_OPTION);
            revoke_super_admin($this->super_admin);
        }
        Backup_Job_Store::set_clock_for_tests(null);
        parent::tearDown();
    }

    /**
     * A schedule record as #455's set() stored it, plus its WP-Cron event.
     * $set_by null leaves the creator out entirely.
     */
    private function legacy_schedule(string $type, ?int $set_by): void
    {
        $record = [
            'type'        => $type,
            'scope'       => 'all',
            'every'       => 'daily 03:00',
            'recurrence'  => 'daily',
            'day'         => null,
            'time'        => '03:00',
            'keep'        => 7,
            'set_at'      => self::NOW - 10 * DAY_IN_SECONDS,
            'last_job_id' => null,
            'last_run_at' => null,
            'pruned'      => [],
        ];
        if (null !== $set_by) {
            $record['set_by'] = $set_by;
        }
        $schedules          = (array) get_option(Backup_Schedule::OPTION, []);
        $schedules[ $type ] = $record;
        update_option(Backup_Schedule::OPTION, $schedules, false);
        wp_schedule_event(self::NOW + HOUR_IN_SECONDS, 'daily', Backup_Schedule::HOOK, [$type]);
    }

    /** Fire the type's recurring event the way WP-Cron would. */
    private function fire(string $type): void
    {
        do_action(Backup_Schedule::HOOK, $type);
    }

    private function producer(): callable
    {
        return function (array $job): array {
            ++$this->produced;
            return ['file' => '/nonexistent/schedule-' . (int) $job['id'] . '.zip', 'size' => 1, 'scope' => 'all'];
        };
    }

    private function report_for(string $type): ?array
    {
        $previous = get_current_user_id();
        wp_set_current_user($this->super_admin);
        $out = (new List_Backup_Jobs())->handle([]);
        wp_set_current_user($previous);
        foreach ($out['schedules'] as $schedule) {
            if ($type === $schedule['type']) {
                return $schedule;
            }
        }
        return null;
    }

    public function test_a_sub_site_admin_schedule_does_not_run_and_is_disabled_with_a_reason(): void
    {
        $this->legacy_schedule('full', $this->sub_admin);

        $this->fire('full');

        $this->assertSame([], Backup_Job_Store::list(), 'no network-wide backup job is queued');
        $this->assertFalse(wp_next_scheduled(Run_Backup_Job::HOOK), 'nothing is queued on WP-Cron');
        $this->assertFalse(wp_next_scheduled(Backup_Schedule::HOOK, ['full']), 'the recurring event is removed');

        $schedule = $this->report_for('full');
        $this->assertNotNull($schedule, 'the disabled schedule stays visible on its site');
        $this->assertIsArray($schedule['disabled']);
        $this->assertStringContainsString('manage_network_options', $schedule['disabled']['reason']);
        $this->assertStringContainsString('#' . $this->sub_admin, $schedule['disabled']['reason']);
        $this->assertSame(self::NOW, $schedule['disabled']['at']);
        $this->assertNull($schedule['next_run']);
        $this->assertFalse($schedule['overdue']);

        // A second firing (a stale event elsewhere) still runs nothing.
        $this->fire('full');
        $this->assertSame([], Backup_Job_Store::list());
    }

    public function test_the_disable_reason_is_visible_to_a_network_admin_from_the_main_site(): void
    {
        $this->legacy_schedule('database', $this->sub_admin);
        $this->fire('database');

        restore_current_blog();
        wp_set_current_user($this->super_admin);
        $out = (new List_Backup_Jobs())->handle([]);
        switch_to_blog($this->blog);

        $this->assertArrayHasKey('disabled_schedules', $out);
        $this->assertCount(1, $out['disabled_schedules']);
        $entry = $out['disabled_schedules'][0];
        $this->assertSame($this->blog, $entry['blog_id']);
        $this->assertSame('database', $entry['type']);
        $this->assertSame($this->sub_admin, $entry['set_by']);
        $this->assertStringContainsString('manage_network_options', $entry['reason']);
    }

    public function test_a_schedule_with_no_recorded_creator_fails_closed(): void
    {
        $this->legacy_schedule('full', null);

        $this->fire('full');

        $this->assertSame([], Backup_Job_Store::list());
        $this->assertFalse(wp_next_scheduled(Backup_Schedule::HOOK, ['full']));
        $schedule = $this->report_for('full');
        $this->assertStringContainsString('no recorded creator', $schedule['disabled']['reason']);
    }

    public function test_a_schedule_whose_creator_was_deleted_fails_closed(): void
    {
        $this->legacy_schedule('full', 999999);

        $this->fire('full');

        $this->assertSame([], Backup_Job_Store::list());
        $this->assertIsArray($this->report_for('full')['disabled']);
    }

    public function test_a_super_admin_schedule_keeps_running(): void
    {
        wp_set_current_user($this->super_admin);
        (new Trigger_Backup())->handle(['type' => 'full', 'every' => 'daily 03:00']);
        wp_set_current_user(0);

        $this->fire('full');

        $jobs = Backup_Job_Store::list();
        $this->assertCount(1, $jobs, 'the run queues its backup');
        $this->assertSame($this->super_admin, $jobs[0]['set_by'], 'the job records who set its schedule');
        (new Run_Backup_Job($this->producer()))->handle((int) $jobs[0]['id']);
        $this->assertSame(1, $this->produced);
        $this->assertSame('completed', Backup_Job_Store::get((int) $jobs[0]['id'])['status']);

        $this->assertNotFalse(wp_next_scheduled(Backup_Schedule::HOOK, ['full']));
        $this->assertNull($this->report_for('full')['disabled']);
        $this->assertSame([], (array) get_site_option(Backup_Schedule::NETWORK_DISABLED_OPTION, []));
    }

    public function test_a_legacy_super_admin_schedule_with_a_recorded_creator_keeps_running(): void
    {
        $this->legacy_schedule('database', $this->super_admin);

        $this->fire('database');

        $this->assertCount(1, Backup_Job_Store::list());
        $this->assertNotFalse(wp_next_scheduled(Backup_Schedule::HOOK, ['database']));
    }

    public function test_a_job_queued_by_the_schedule_before_the_upgrade_is_canceled_not_run(): void
    {
        $this->legacy_schedule('full', $this->sub_admin);
        // What #455's run() queued before the upgrade: no set_by on the job.
        $job = Backup_Job_Store::create('full', 'all', ['schedule' => 'full']);

        (new Run_Backup_Job($this->producer()))->handle((int) $job['id']);

        $this->assertSame(0, $this->produced, 'no network-wide archive is produced');
        $after = Backup_Job_Store::get((int) $job['id']);
        $this->assertSame('canceled', $after['status']);
        $this->assertStringContainsString('manage_network_options', (string) $after['error']);
        $this->assertIsArray($this->report_for('full')['disabled'], 'the schedule is disabled too');
    }

    public function test_archives_the_disabled_schedule_already_produced_are_flagged_for_review(): void
    {
        $this->legacy_schedule('full', $this->sub_admin);
        $old = Backup_Job_Store::create('full', 'all', ['schedule' => 'full']);
        Backup_Job_Store::update((int) $old['id'], ['status' => 'completed', 'result' => ['file' => '/nonexistent/a.zip']]);
        $manual = Backup_Job_Store::create('full', 'all');
        Backup_Job_Store::update((int) $manual['id'], ['status' => 'completed', 'result' => ['file' => '/nonexistent/b.zip']]);

        $this->fire('full');

        $flag = Backup_Job_Store::get((int) $old['id'])['review'] ?? null;
        $this->assertIsString($flag);
        $this->assertStringContainsString('manage_network_options', $flag);
        $this->assertArrayNotHasKey('review', Backup_Job_Store::get((int) $manual['id']), 'a manual archive is not flagged');
        $this->assertFileDoesNotExist('/nonexistent/a.zip');
        $this->assertNotNull(Backup_Job_Store::get((int) $old['id']), 'the archive record is left for a network admin to review');
    }

    public function test_reactivation_does_not_bring_a_disabled_or_sub_site_admin_schedule_back(): void
    {
        $this->legacy_schedule('full', $this->sub_admin);
        wp_clear_scheduled_hook(Backup_Schedule::HOOK, ['full']);

        $this->assertSame([], Backup_Schedule::restore());
        $this->assertFalse(wp_next_scheduled(Backup_Schedule::HOOK, ['full']));
        $this->assertIsArray($this->report_for('full')['disabled']);

        $this->assertSame([], Backup_Schedule::restore(), 'a disabled schedule stays off');
    }

    public function test_a_network_admin_setting_the_schedule_again_clears_the_disabled_state(): void
    {
        $this->legacy_schedule('full', $this->sub_admin);
        $this->fire('full');
        $this->assertCount(1, (array) get_site_option(Backup_Schedule::NETWORK_DISABLED_OPTION, []));

        wp_set_current_user($this->super_admin);
        (new Trigger_Backup())->handle(['type' => 'full', 'every' => 'weekly']);
        wp_set_current_user(0);

        $this->assertNull($this->report_for('full')['disabled']);
        $this->assertSame([], (array) get_site_option(Backup_Schedule::NETWORK_DISABLED_OPTION, []));

        $this->fire('full');
        $this->assertCount(1, Backup_Job_Store::list());
    }
}
