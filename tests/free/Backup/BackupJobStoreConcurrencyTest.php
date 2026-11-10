<?php

namespace WPMCP\Tests\Free\Backup;

use WPMCP\Tools\Backup\Backup_Job_Store;
use WPMCP\Tools\Backup\Get_Backup_Status;
use WPMCP\Tools\Backup\Run_Backup_Job;

/**
 * Issue #607: three backups triggered in one batch ran in parallel cron
 * requests, and one of them stayed "running" forever although it had
 * written its export. Backup_Job_Store keeps every job in one option and
 * did read, change, write with no lock, from the request's own (stale)
 * option cache, so the later save of one job's record put back another
 * job's earlier status.
 *
 * Every write now runs under a MySQL named lock and starts from the value
 * in the database, not the request's cache; a job left "running" past a
 * limit (its run died) is marked failed when it is next read.
 */
class BackupJobStoreConcurrencyTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Backup_Job_Store::OPTION);
        Backup_Job_Store::set_clock_for_tests(null);
    }

    protected function tearDown(): void
    {
        remove_all_filters('pre_update_option_' . Backup_Job_Store::OPTION);
        remove_all_filters('wpmcp_backup_job_stale_after');
        Backup_Job_Store::set_clock_for_tests(null);
        delete_option(Backup_Job_Store::OPTION);
        parent::tearDown();
    }

    /**
     * What another PHP request does: it writes the option row in the
     * database, and this request's option cache never hears of it.
     */
    private function write_from_another_request(array $stored): void
    {
        global $wpdb;

        $wpdb->update($wpdb->options, ['option_value' => maybe_serialize($stored)], ['option_name' => Backup_Job_Store::OPTION]);
    }

    private function stored_in_database(): array
    {
        global $wpdb;

        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Backup_Job_Store::OPTION));
        return (array) maybe_unserialize($raw);
    }

    public function test_two_jobs_finishing_at_the_same_time_both_keep_their_outcome(): void
    {
        $first  = Backup_Job_Store::create('content', 'all');
        $second = Backup_Job_Store::create('uploads', 'uploads');
        Backup_Job_Store::update($first['id'], ['status' => 'running']);
        Backup_Job_Store::update($second['id'], ['status' => 'running']);

        // This request has the list cached. Meanwhile the second job's own
        // cron request completes it.
        $this->assertSame('running', Backup_Job_Store::get($second['id'])['status']);
        $stored = $this->stored_in_database();
        $stored['jobs'][ $second['id'] ]['status'] = 'completed';
        $stored['jobs'][ $second['id'] ]['result'] = ['file' => 'uploads.zip'];
        $this->write_from_another_request($stored);

        Backup_Job_Store::update($first['id'], ['status' => 'completed', 'result' => ['file' => 'export.xml']]);

        $jobs = $this->stored_in_database()['jobs'];
        $this->assertSame('completed', $jobs[ $first['id'] ]['status']);
        $this->assertSame('completed', $jobs[ $second['id'] ]['status'], 'The other job\'s completion must not be overwritten by a stale copy of the list');
        $this->assertSame(['file' => 'uploads.zip'], $jobs[ $second['id'] ]['result']);
    }

    public function test_a_job_created_by_another_request_is_not_lost_by_a_create_here(): void
    {
        $first  = Backup_Job_Store::create('content', 'all');
        $stored = $this->stored_in_database();
        $stored['jobs'][2] = ['id' => 2, 'type' => 'files', 'scope' => 'files', 'status' => 'queued', 'created_at' => 1, 'updated_at' => 1, 'result' => null, 'error' => null];
        $stored['next_id'] = 3;
        $this->write_from_another_request($stored);

        $third = Backup_Job_Store::create('uploads', 'uploads');

        $this->assertSame(3, $third['id'], 'A new job must not reuse the id another request just took');
        $this->assertSame([1, 2, 3], array_keys($this->stored_in_database()['jobs']));
        $this->assertSame(1, $first['id']);
    }

    public function test_the_write_holds_a_lock_another_connection_can_see(): void
    {
        $job = Backup_Job_Store::create('content', 'all');

        $other = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $other->suppress_errors(true);
        $seen = [];
        add_filter(
            'pre_update_option_' . Backup_Job_Store::OPTION,
            static function ($value) use ($other, &$seen) {
                $seen[] = (string) $other->get_var($other->prepare('SELECT IS_FREE_LOCK(%s)', Backup_Job_Store::lock_name()));
                return $value;
            }
        );

        Backup_Job_Store::update($job['id'], ['status' => 'running']);

        $this->assertSame(['0'], $seen, 'The read, change and write must happen under the store lock');
        $this->assertSame('1', (string) $other->get_var($other->prepare('SELECT IS_FREE_LOCK(%s)', Backup_Job_Store::lock_name())), 'The lock is released after the write');
        $other->close();
    }

    public function test_the_runner_claims_a_queued_job_only_once(): void
    {
        $job  = Backup_Job_Store::create('content', 'all');
        $runs = 0;

        // A second delivery of the same event arrives while the first is
        // producing: the job is no longer queued, so it does not run again.
        $runner = new Run_Backup_Job(static function (array $job) use (&$runs, &$runner): array {
            $runs++;
            if (1 === $runs) {
                $runner->handle((int) $job['id']);
            }
            return ['file' => 'export.xml'];
        });
        $runner->handle($job['id']);

        $this->assertSame(1, $runs);
        $this->assertSame('completed', Backup_Job_Store::get($job['id'])['status']);
    }

    public function test_a_job_left_running_past_the_limit_is_marked_failed(): void
    {
        Backup_Job_Store::set_clock_for_tests(1700000000);
        $job = Backup_Job_Store::create('content', 'all');
        Backup_Job_Store::update($job['id'], ['status' => 'running']);

        Backup_Job_Store::set_clock_for_tests(1700000000 + Backup_Job_Store::STALE_RUNNING_AFTER - 1);
        $this->assertSame('running', Backup_Job_Store::get($job['id'])['status'], 'A job inside the limit is still running');

        Backup_Job_Store::set_clock_for_tests(1700000000 + Backup_Job_Store::STALE_RUNNING_AFTER + 1);
        $status = (new Get_Backup_Status())->handle(['job_id' => $job['id']]);

        $this->assertSame('failed', $status['status']);
        $this->assertStringContainsString('still running', (string) $status['error']);
        $this->assertSame('failed', Backup_Job_Store::list('failed')[0]['status'] ?? null);
        $this->assertSame([], Backup_Job_Store::list('running'));
    }

    public function test_the_stale_limit_is_filterable_and_never_below_a_minute(): void
    {
        Backup_Job_Store::set_clock_for_tests(1700000000);
        $job = Backup_Job_Store::create('content', 'all');
        Backup_Job_Store::update($job['id'], ['status' => 'running']);

        add_filter('wpmcp_backup_job_stale_after', static fn(): int => 1);
        Backup_Job_Store::set_clock_for_tests(1700000030);
        $this->assertSame('running', Backup_Job_Store::get($job['id'])['status']);

        Backup_Job_Store::set_clock_for_tests(1700000061);
        $this->assertSame('failed', Backup_Job_Store::get($job['id'])['status']);
    }

    public function test_a_run_that_finishes_after_being_marked_failed_still_records_its_archive(): void
    {
        Backup_Job_Store::set_clock_for_tests(1700000000);
        $job = Backup_Job_Store::create('content', 'all');

        $runner = new Run_Backup_Job(static function (array $job): array {
            Backup_Job_Store::set_clock_for_tests(1700000000 + Backup_Job_Store::STALE_RUNNING_AFTER + 1);
            Backup_Job_Store::get((int) $job['id']);
            return ['file' => 'export.xml'];
        });
        $runner->handle($job['id']);

        $stored = Backup_Job_Store::get($job['id']);
        $this->assertSame('completed', $stored['status']);
        $this->assertSame(['file' => 'export.xml'], $stored['result']);
        $this->assertNull($stored['error']);
    }
}
