<?php

namespace WPMCP\Tests\Free\Backup;

use WPMCP\Tools\Backup\Backup_Job_Store;
use WPMCP\Tools\Backup\Get_Backup_Status;

/**
 * get-backup-status is a thin read-only wrapper around Backup_Job_Store::get():
 * given a job id, return its current record, or a WP_Error for an unknown id.
 */
class GetBackupStatusTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Backup_Job_Store::OPTION);
        Backup_Job_Store::set_clock_for_tests(null);
    }

    protected function tearDown(): void
    {
        Backup_Job_Store::set_clock_for_tests(null);
        delete_option(Backup_Job_Store::OPTION);
        parent::tearDown();
    }

    public function test_returns_the_job_record_by_id(): void
    {
        Backup_Job_Store::set_clock_for_tests(1700000000);
        $job = Backup_Job_Store::create('full', 'all');

        $out = (new Get_Backup_Status())->handle(['job_id' => $job['id']]);

        $this->assertArrayHasKey('storage', $out);
        unset($out['storage']);
        $this->assertSame($job, $out);
    }

    public function test_returns_wp_error_for_an_unknown_job_id(): void
    {
        $out = (new Get_Backup_Status())->handle(['job_id' => 999999]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('wpmcp_backup_job_not_found', $out->get_error_code());
    }
}
