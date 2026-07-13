<?php

namespace WPMCP\Tests\Free\Backup;

use WPMCP\Tools\Backup\Backup_Job_Store;

/**
 * Backup_Job_Store is a CRUD layer over a single wpmcp_backup_jobs option
 * (an array of job records keyed by job id, plus a 'next_id' sequence used
 * to mint new ids). Job ids are a deterministic incrementing integer
 * sequence, never wp_generate_uuid4()/random, so tests can assert on exact
 * ids. Timestamps come from an injectable clock (set_clock_for_tests), not
 * time(), for the same determinism reason.
 */
class BackupJobStoreTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option('wpmcp_backup_jobs');
        Backup_Job_Store::set_clock_for_tests(null);
    }

    protected function tearDown(): void
    {
        Backup_Job_Store::set_clock_for_tests(null);
        delete_option('wpmcp_backup_jobs');
        parent::tearDown();
    }

    public function test_create_returns_a_queued_job_with_a_deterministic_id(): void
    {
        Backup_Job_Store::set_clock_for_tests(1700000000);

        $job = Backup_Job_Store::create('full', 'all');

        $this->assertSame(1, $job['id']);
        $this->assertSame('full', $job['type']);
        $this->assertSame('all', $job['scope']);
        $this->assertSame('queued', $job['status']);
        $this->assertSame(1700000000, $job['created_at']);
        $this->assertSame(1700000000, $job['updated_at']);
        $this->assertNull($job['result']);
        $this->assertNull($job['error']);
    }

    public function test_ids_increment_deterministically_across_creates(): void
    {
        Backup_Job_Store::set_clock_for_tests(1700000000);

        $first  = Backup_Job_Store::create('full', 'all');
        $second = Backup_Job_Store::create('full', 'all');

        $this->assertSame(1, $first['id']);
        $this->assertSame(2, $second['id']);
    }

    public function test_get_returns_the_stored_job(): void
    {
        Backup_Job_Store::set_clock_for_tests(1700000000);
        $created = Backup_Job_Store::create('full', 'all');

        $fetched = Backup_Job_Store::get($created['id']);

        $this->assertSame($created, $fetched);
    }

    public function test_get_returns_null_for_an_unknown_job_id(): void
    {
        $this->assertNull(Backup_Job_Store::get(999));
    }
}
