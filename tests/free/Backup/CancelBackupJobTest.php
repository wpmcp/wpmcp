<?php

namespace WPMCP\Tests\Free\Backup;

use WPMCP\Tools\Backup\Backup_Job_Store;
use WPMCP\Tools\Backup\Cancel_Backup_Job;
use WPMCP\Tools\Backup\Restore_Site_Backup;
use WPMCP\Tools\Backup\Run_Backup_Job;
use WPMCP\Tools\Backup\Site_Backup_Dir;
use WPMCP\Tools\Backup\Trigger_Backup;

/**
 * cancel-backup-job marks a queued job canceled and unschedules its cron
 * event, so a mistakenly triggered backup never actually runs. A running
 * backup is canceled too (issue #771): its run stops at the next heartbeat,
 * or, when the run died, its partial archive is removed here. A job in a
 * terminal status (completed, failed, canceled) and a running restore are
 * refused with a clear WP_Error rather than silently no-op'd.
 */
class CancelBackupJobTest extends \WP_UnitTestCase
{
    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Backup_Job_Store::OPTION);
        Backup_Job_Store::set_clock_for_tests(null);
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->cleanup = [];
        Backup_Job_Store::set_clock_for_tests(null);
        delete_option(Backup_Job_Store::OPTION);
        wp_clear_scheduled_hook(Run_Backup_Job::HOOK);
        parent::tearDown();
    }

    public function test_cancels_a_queued_job_and_unschedules_its_cron_event(): void
    {
        $triggered = (new Trigger_Backup())->handle(['type' => 'full', 'scope' => 'all']);
        $job_id    = $triggered['job_id'];

        $this->assertNotFalse(wp_next_scheduled(Run_Backup_Job::HOOK, [$job_id]));

        $out = (new Cancel_Backup_Job())->handle(['job_id' => $job_id]);

        $this->assertSame('canceled', $out['status']);
        $this->assertSame('canceled', Backup_Job_Store::get($job_id)['status']);
        $this->assertFalse(wp_next_scheduled(Run_Backup_Job::HOOK, [$job_id]));
    }

    public function test_refuses_to_cancel_a_completed_job(): void
    {
        $job = Backup_Job_Store::create('full', 'all');
        Backup_Job_Store::update($job['id'], ['status' => 'completed']);

        $out = (new Cancel_Backup_Job())->handle(['job_id' => $job['id']]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('wpmcp_backup_job_not_cancelable', $out->get_error_code());
        $this->assertSame('completed', Backup_Job_Store::get($job['id'])['status']);
    }

    /**
     * Issue #771: a full backup whose cron request was ended by the PHP time
     * limit stays "running" with nothing left to finish it. Cancel must be
     * able to clear it, and the partial archive its last heartbeat named
     * (plus libzip's temporary output beside it) must go with it.
     */
    public function test_cancels_a_running_job_and_removes_its_partial_archive(): void
    {
        $dir = Site_Backup_Dir::path();
        Site_Backup_Dir::protect($dir);
        $partial = $dir . '/wpmcp-all-cancel-fixture.zip.part';
        $temp    = $partial . '.AbC123';
        $scratch = $dir . '/db-cancelfixture.sql';
        file_put_contents($partial, 'half an archive');
        file_put_contents($temp, 'libzip output');
        file_put_contents($scratch, '-- half a dump');
        $this->cleanup = [$partial, $temp, $scratch];

        $job = Backup_Job_Store::create('full', 'all');
        Backup_Job_Store::transition($job['id'], 'queued', ['status' => 'running']);
        Backup_Job_Store::heartbeat($job['id'], [
            'phase'        => 'files',
            'partial_file' => $partial,
            'scratch_file' => $scratch,
            'files_added'  => 1200,
        ]);

        $out = (new Cancel_Backup_Job())->handle(['job_id' => $job['id']]);

        $this->assertSame('canceled', $out['status']);
        $stored = Backup_Job_Store::get($job['id']);
        $this->assertSame('canceled', $stored['status']);
        $this->assertNull($stored['result']);
        $this->assertStringContainsString('while running', (string) $stored['error']);
        $this->assertFileDoesNotExist($partial);
        $this->assertFileDoesNotExist($temp);
        $this->assertFileDoesNotExist($scratch);
    }

    public function test_a_partial_path_outside_the_backup_directory_is_left_alone(): void
    {
        $outside = tempnam(sys_get_temp_dir(), 'wpmcp-outside-');
        file_put_contents($outside, 'not ours to delete');
        $this->cleanup = [$outside];

        $job = Backup_Job_Store::create('full', 'all');
        Backup_Job_Store::transition($job['id'], 'queued', ['status' => 'running']);
        Backup_Job_Store::heartbeat($job['id'], ['phase' => 'files', 'partial_file' => $outside]);

        $out = (new Cancel_Backup_Job())->handle(['job_id' => $job['id']]);

        $this->assertSame('canceled', $out['status']);
        $this->assertFileExists($outside, 'A tampered progress record must not delete files outside the backup directory.');
    }

    public function test_refuses_to_cancel_a_running_restore(): void
    {
        $job = Backup_Job_Store::create(Restore_Site_Backup::JOB_TYPE, 'database');
        Backup_Job_Store::transition($job['id'], 'queued', ['status' => 'running']);

        $out = (new Cancel_Backup_Job())->handle(['job_id' => $job['id']]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('wpmcp_backup_job_not_cancelable', $out->get_error_code());
        $this->assertSame('running', Backup_Job_Store::get($job['id'])['status']);
    }

    public function test_returns_wp_error_for_an_unknown_job_id(): void
    {
        $out = (new Cancel_Backup_Job())->handle(['job_id' => 999999]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('wpmcp_backup_job_not_found', $out->get_error_code());
    }
}
