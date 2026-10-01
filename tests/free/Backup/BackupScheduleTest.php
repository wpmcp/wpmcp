<?php

namespace WPMCP\Tests\Free\Backup;

use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Tools\Backup\Backup_Job_Store;
use WPMCP\Tools\Backup\Backup_Schedule;
use WPMCP\Tools\Backup\List_Backup_Jobs;
use WPMCP\Tools\Backup\Run_Backup_Job;
use WPMCP\Tools\Backup\Site_Backup_Dir;
use WPMCP\Tools\Backup\Trigger_Backup;

/**
 * Recurring backups with retention (issue #455): trigger-backup's every and
 * keep arguments, the WP-Cron schedule they create, retention after each
 * scheduled run, and the schedule report on list-backup-jobs.
 *
 * Nothing here depends on real time or the network. The clock is
 * Backup_Job_Store's injectable one, and cron is driven directly: a test
 * reads the event WP-Cron would fire out of the cron array and fires its
 * hook with its args, then runs the queued backup job through
 * Run_Backup_Job with an injected producer that writes a small real file in
 * the site-backup directory, so retention deletes real archives.
 */
class BackupScheduleTest extends \WP_UnitTestCase
{
    /** Tue 14 Nov 2023 22:13:20 UTC. */
    private const NOW = 1700000000;

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Backup_Job_Store::OPTION);
        delete_option(Backup_Schedule::OPTION);
        delete_option(Governance_Audit_Log::OPTION);
        update_option('timezone_string', 'UTC');
        update_option('gmt_offset', 0);
        Backup_Job_Store::set_clock_for_tests(self::NOW);
        $this->admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->admin);
    }

    protected function tearDown(): void
    {
        foreach (Backup_Job_Store::list() as $job) {
            $file = is_array($job['result'] ?? null) ? (string) ($job['result']['file'] ?? '') : '';
            if ('' !== $file && is_file($file)) {
                unlink($file);
            }
        }
        foreach (['full', 'database', 'files', 'uploads'] as $type) {
            wp_clear_scheduled_hook(Backup_Schedule::HOOK, [$type]);
        }
        wp_clear_scheduled_hook(Run_Backup_Job::HOOK);
        Backup_Job_Store::set_clock_for_tests(null);
        delete_option(Backup_Job_Store::OPTION);
        delete_option(Backup_Schedule::OPTION);
        delete_option(Governance_Audit_Log::OPTION);
        parent::tearDown();
    }

    /** The recurring event for a type, as WP-Cron stores it, or null. */
    private function event(string $type): ?object
    {
        $event = wp_get_scheduled_event(Backup_Schedule::HOOK, [$type]);
        return false === $event ? null : $event;
    }

    /** How many recurring backup-schedule events exist, across all types. */
    private function schedule_event_count(): int
    {
        $count = 0;
        foreach ((array) _get_cron_array() as $hooks) {
            $count += count($hooks[ Backup_Schedule::HOOK ] ?? []);
        }
        return $count;
    }

    /**
     * Fire the type's scheduled event the way WP-Cron would, then run the
     * backup job it queued with $producer. Returns the job id.
     */
    private function run_schedule(string $type, ?callable $producer = null): int
    {
        $event = $this->event($type);
        $this->assertNotNull($event, "no scheduled event for $type");

        $before = array_column(Backup_Job_Store::list(), 'id');
        do_action_ref_array($event->hook, $event->args);
        $new = array_values(array_diff(array_column(Backup_Job_Store::list(), 'id'), $before));
        $this->assertCount(1, $new, 'one scheduled run queues exactly one job');
        $job_id = (int) $new[0];

        $this->assertNotFalse(wp_next_scheduled(Run_Backup_Job::HOOK, [$job_id]), 'the run queues the same job event trigger-backup queues');
        wp_clear_scheduled_hook(Run_Backup_Job::HOOK, [$job_id]);

        (new Run_Backup_Job($producer ?? $this->producer()))->handle($job_id);

        return $job_id;
    }

    /** A producer that writes a real archive file in the site-backup directory. */
    private function producer(): callable
    {
        return static function (array $job): array {
            $dir = Site_Backup_Dir::path();
            Site_Backup_Dir::protect($dir);
            $file = trailingslashit($dir) . 'schedule-test-' . (int) $job['id'] . '.zip';
            file_put_contents($file, 'archive ' . (int) $job['id']);
            return ['file' => $file, 'size' => (int) filesize($file), 'scope' => Run_Backup_Job::archive_scope($job)];
        };
    }

    private function file_of(int $job_id): string
    {
        return (string) (Backup_Job_Store::get($job_id)['result']['file'] ?? '');
    }

    private function schedule_report(string $type): ?array
    {
        foreach ((new List_Backup_Jobs())->handle([])['schedules'] as $schedule) {
            if ($type === $schedule['type']) {
                return $schedule;
            }
        }
        return null;
    }

    public function test_every_daily_at_a_time_creates_one_recurring_event_for_the_type(): void
    {
        $out = (new Trigger_Backup())->handle(['type' => 'full', 'every' => 'daily 03:00', 'keep' => 5]);

        $this->assertIsArray($out);
        $this->assertArrayNotHasKey('job_id', $out, 'setting a schedule does not queue a backup');
        $this->assertSame('full', $out['schedule']['type']);
        $this->assertSame('daily 03:00', $out['schedule']['every']);
        $this->assertSame(5, $out['schedule']['keep']);

        $event = $this->event('full');
        $this->assertNotNull($event);
        $this->assertSame('daily', $event->schedule);
        // The next 03:00 after Tue 22:13 UTC is Wed 15 Nov 2023 03:00 UTC.
        $this->assertSame(1700017200, (int) $event->timestamp);
        $this->assertSame(1700017200, $out['schedule']['next_run']);

        // Setting it again replaces the event instead of adding a second one.
        (new Trigger_Backup())->handle(['type' => 'full', 'every' => 'daily 04:30']);
        $this->assertSame(1, $this->schedule_event_count());
        $this->assertSame(1700022600, (int) $this->event('full')->timestamp);
    }

    public function test_every_weekly_uses_the_day_and_time_in_the_site_timezone(): void
    {
        update_option('timezone_string', 'Asia/Karachi');

        $out = (new Trigger_Backup())->handle(['type' => 'database', 'every' => 'weekly sun 03:00']);

        $event = $this->event('database');
        $this->assertSame('weekly', $event->schedule);
        // Sun 19 Nov 2023 03:00 PKT is Sat 18 Nov 2023 22:00 UTC.
        $this->assertSame(1700344800, (int) $event->timestamp);
        $this->assertSame('weekly sun 03:00', $out['schedule']['every']);
        $this->assertSame(Backup_Schedule::DEFAULT_KEEP, $out['schedule']['keep']);
    }

    public function test_defaults_to_03_00_and_sunday_when_no_time_or_day_is_given(): void
    {
        $daily  = (new Trigger_Backup())->handle(['type' => 'full', 'every' => 'daily']);
        $weekly = (new Trigger_Backup())->handle(['type' => 'uploads', 'every' => 'weekly']);

        $this->assertSame('daily 03:00', $daily['schedule']['every']);
        $this->assertSame('weekly sun 03:00', $weekly['schedule']['every']);
        $this->assertSame(1700362800, (int) $this->event('uploads')->timestamp);
    }

    public function test_each_backup_type_has_its_own_schedule(): void
    {
        (new Trigger_Backup())->handle(['type' => 'full', 'every' => 'weekly']);
        (new Trigger_Backup())->handle(['type' => 'database', 'every' => 'daily 01:00']);

        $this->assertSame(2, $this->schedule_event_count());
        $this->assertSame('weekly', $this->event('full')->schedule);
        $this->assertSame('daily', $this->event('database')->schedule);
    }

    public function test_every_off_removes_the_schedule_and_its_cron_event(): void
    {
        (new Trigger_Backup())->handle(['type' => 'full', 'every' => 'daily']);
        (new Trigger_Backup())->handle(['type' => 'database', 'every' => 'daily']);

        $out = (new Trigger_Backup())->handle(['type' => 'full', 'every' => 'off']);

        $this->assertSame('off', $out['schedule']['every']);
        $this->assertNull($this->event('full'));
        $this->assertNotNull($this->event('database'), 'other types keep their schedule');
        $this->assertNull($this->schedule_report('full'));
        $this->assertNotNull($this->schedule_report('database'));
    }

    public function test_invalid_schedules_are_refused_without_scheduling_anything(): void
    {
        $cases = [
            ['type' => 'full', 'every' => 'hourly'],
            ['type' => 'full', 'every' => 'daily 25:00'],
            ['type' => 'full', 'every' => 'weekly funday'],
            ['type' => 'full', 'every' => 'daily', 'keep' => 0],
            ['type' => 'content', 'every' => 'daily'],
            ['type' => 'full', 'keep' => 3],
        ];

        foreach ($cases as $args) {
            $out = (new Trigger_Backup())->handle($args);
            $this->assertInstanceOf(\WP_Error::class, $out, wp_json_encode($args));
        }

        $this->assertSame(0, $this->schedule_event_count());
        $this->assertSame([], Backup_Job_Store::list(), 'a refused schedule queues no job either');
    }

    public function test_a_scheduled_run_queues_the_same_job_a_manual_trigger_queues(): void
    {
        $manual = (new Trigger_Backup())->handle(['type' => 'database']);
        wp_clear_scheduled_hook(Run_Backup_Job::HOOK, [$manual['job_id']]);
        (new Trigger_Backup())->handle(['type' => 'database', 'every' => 'daily']);

        $event = $this->event('database');
        do_action_ref_array($event->hook, $event->args);

        $jobs      = Backup_Job_Store::list();
        $scheduled = $jobs[0];
        $manual    = Backup_Job_Store::get($manual['job_id']);

        $this->assertSame('queued', $scheduled['status']);
        $this->assertSame($manual['type'], $scheduled['type']);
        $this->assertSame($manual['scope'], $scheduled['scope']);
        $this->assertSame(Run_Backup_Job::archive_scope($manual), Run_Backup_Job::archive_scope($scheduled));
        $this->assertSame('database', $scheduled['schedule'] ?? null, 'the job records which schedule made it');
        $this->assertArrayNotHasKey('schedule', $manual, 'a manual job is not marked as scheduled');
        $this->assertNotFalse(wp_next_scheduled(Run_Backup_Job::HOOK, [$scheduled['id']]));
    }

    public function test_the_schedule_runner_is_hooked_to_wp_cron(): void
    {
        $this->assertNotFalse(has_action(Backup_Schedule::HOOK));
    }

    public function test_retention_keeps_the_newest_scheduled_archives_and_never_manual_ones(): void
    {
        (new Trigger_Backup())->handle(['type' => 'full', 'every' => 'daily', 'keep' => 2]);

        $first  = $this->run_schedule('full');
        $second = $this->run_schedule('full');

        // A manual backup of the same type, made between scheduled runs.
        $manual = (new Trigger_Backup())->handle(['type' => 'full'])['job_id'];
        wp_clear_scheduled_hook(Run_Backup_Job::HOOK, [$manual]);
        (new Run_Backup_Job($this->producer()))->handle($manual);

        $third  = $this->run_schedule('full');
        $fourth = $this->run_schedule('full');

        $this->assertFileDoesNotExist($this->file_of($first));
        $this->assertFileDoesNotExist($this->file_of($second));
        $this->assertTrue(Backup_Job_Store::get($first)['result']['deleted'] ?? false, 'a pruned job is flagged deleted');
        $this->assertFileExists($this->file_of($third));
        $this->assertFileExists($this->file_of($fourth));
        $this->assertFileExists($this->file_of($manual), 'manual archives are never pruned');
        $this->assertArrayNotHasKey('deleted', Backup_Job_Store::get($manual)['result']);

        $report = $this->schedule_report('full');
        $this->assertSame($fourth, $report['last_run']['job_id']);
        $this->assertSame('completed', $report['last_run']['status']);
        $this->assertSame([$second], $report['last_run']['pruned'], 'each run prunes what falls beyond keep');
    }

    public function test_a_failed_run_is_recorded_and_deletes_nothing(): void
    {
        (new Trigger_Backup())->handle(['type' => 'full', 'every' => 'daily', 'keep' => 3]);
        $kept = [$this->run_schedule('full'), $this->run_schedule('full'), $this->run_schedule('full')];

        // Tighten retention, then have the next run fail.
        (new Trigger_Backup())->handle(['type' => 'full', 'every' => 'daily', 'keep' => 1]);
        $failed = $this->run_schedule('full', static function (): array {
            throw new \RuntimeException('disk full');
        });

        foreach ($kept as $job_id) {
            $this->assertFileExists($this->file_of($job_id), 'a failed run must not prune');
        }
        $report = $this->schedule_report('full');
        $this->assertSame($failed, $report['last_run']['job_id']);
        $this->assertSame('failed', $report['last_run']['status']);
        $this->assertSame('disk full', $report['last_run']['error']);
        $this->assertSame([], $report['last_run']['pruned']);

        // The next successful run applies the tighter retention.
        $newest = $this->run_schedule('full');
        foreach ($kept as $job_id) {
            $this->assertFileDoesNotExist($this->file_of($job_id));
        }
        $this->assertFileExists($this->file_of($newest), 'the newest complete archive is never pruned');
        $this->assertSame($kept, $this->schedule_report('full')['last_run']['pruned']);
    }

    public function test_list_backup_jobs_reports_the_schedule_next_run_and_overdue_flag(): void
    {
        $this->assertSame([], (new List_Backup_Jobs())->handle([])['schedules']);

        (new Trigger_Backup())->handle(['type' => 'full', 'every' => 'daily 03:00', 'keep' => 4]);

        $report = $this->schedule_report('full');
        $this->assertSame('daily 03:00', $report['every']);
        $this->assertSame(4, $report['keep']);
        $this->assertSame(1700017200, $report['next_run']);
        $this->assertFalse($report['overdue']);
        $this->assertNull($report['last_run']);

        // One interval late is not overdue yet; two intervals late is.
        Backup_Job_Store::set_clock_for_tests(self::NOW + DAY_IN_SECONDS + HOUR_IN_SECONDS);
        $this->assertFalse($this->schedule_report('full')['overdue']);
        Backup_Job_Store::set_clock_for_tests(self::NOW + 2 * DAY_IN_SECONDS + HOUR_IN_SECONDS);
        $this->assertTrue($this->schedule_report('full')['overdue']);

        // A run brings it back on time.
        $this->run_schedule('full');
        $this->assertFalse($this->schedule_report('full')['overdue']);
    }

    public function test_a_lost_cron_event_is_reported_as_overdue_once_late(): void
    {
        (new Trigger_Backup())->handle(['type' => 'full', 'every' => 'daily']);
        wp_clear_scheduled_hook(Backup_Schedule::HOOK, ['full']);

        Backup_Job_Store::set_clock_for_tests(self::NOW + 3 * DAY_IN_SECONDS);
        $report = $this->schedule_report('full');

        $this->assertNull($report['next_run']);
        $this->assertTrue($report['overdue']);
    }

    public function test_an_orphaned_event_runs_nothing_and_unschedules_itself(): void
    {
        wp_schedule_event(self::NOW, 'daily', Backup_Schedule::HOOK, ['full']);

        do_action(Backup_Schedule::HOOK, 'full');

        $this->assertSame([], Backup_Job_Store::list());
        $this->assertNull($this->event('full'));
    }

    public function test_schedule_changes_need_manage_options(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        $out = (new Trigger_Backup())->handle(['type' => 'full', 'every' => 'daily']);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertNull($this->event('full'));
        $entry = Governance_Audit_Log::list(1)[0];
        $this->assertSame('wpmcp/trigger-backup', $entry['ability']);
        $this->assertFalse($entry['allowed']);
        $this->assertStringContainsString('backup-schedule', $entry['reason']);
    }

    public function test_schedule_changes_are_audit_logged(): void
    {
        (new Trigger_Backup())->handle(['type' => 'full', 'every' => 'weekly mon 02:15', 'keep' => 3]);
        (new Trigger_Backup())->handle(['type' => 'full', 'every' => 'off']);

        $entries = Governance_Audit_Log::list(2);
        $this->assertSame('wpmcp/trigger-backup', $entries[1]['ability']);
        $this->assertTrue($entries[1]['allowed']);
        $this->assertSame('backup-schedule:full:weekly mon 02:15:keep=3', $entries[1]['reason']);
        $this->assertSame('backup-schedule:full:off', $entries[0]['reason']);
    }

    public function test_pruning_is_audit_logged(): void
    {
        (new Trigger_Backup())->handle(['type' => 'full', 'every' => 'daily', 'keep' => 1]);
        $first = $this->run_schedule('full');
        $this->run_schedule('full');

        $entry = Governance_Audit_Log::list(1)[0];
        $this->assertSame('backup-schedule:full:pruned=' . $first, $entry['reason']);
    }
}
