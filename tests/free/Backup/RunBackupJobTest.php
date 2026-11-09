<?php

namespace WPMCP\Tests\Free\Backup;

use WPMCP\Tools\Backup\Backup_Job_Canceled;
use WPMCP\Tools\Backup\Backup_Job_Store;
use WPMCP\Tools\Backup\Cancel_Backup_Job;
use WPMCP\Tools\Backup\Get_Backup_Status;
use WPMCP\Tools\Backup\Run_Backup_Job;
use WPMCP\Tools\Backup\Site_Backup_Dir;

/**
 * Run_Backup_Job is the WP-Cron executor scheduled by Trigger_Backup: given
 * a job id, it flips the job to running, produces a backup artifact via its
 * injected producer callable (defaulting to Export_Content), and flips the
 * job to completed (with a result referencing the artifact) or failed (with
 * the error message).
 *
 * The producer is injected here with a real (not mocked) callable that
 * either writes an actual file or throws, so the status-transition and
 * artifact-recording behavior this class owns is exercised deterministically
 * without depending on WordPress core's export_wp(), which can only be
 * safely called once per PHP process, ever. That single call budget is
 * already claimed by ExportContentTest elsewhere in this same suite, so an
 * end-to-end test against the real default producer is deliberately NOT
 * included here: it would either fatal (a second raw export_wp() call) or
 * non-deterministically steal the budget ExportContentTest depends on,
 * depending on file-system test order. This is a genuine harness limitation
 * (a real WordPress core constraint), not an omitted assertion; the default
 * callable itself is a one-line pass-through to Export_Content and carries
 * no logic of its own to verify beyond what ExportContentTest already covers.
 */
class RunBackupJobTest extends \WP_UnitTestCase
{
    private array $cleanup_files = [];

    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Backup_Job_Store::OPTION);
        Backup_Job_Store::set_clock_for_tests(null);
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup_files as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->cleanup_files = [];
        Backup_Job_Store::set_clock_for_tests(null);
        delete_option(Backup_Job_Store::OPTION);
        parent::tearDown();
    }

    public function test_flips_job_to_running_then_completed_with_the_artifact(): void
    {
        $job  = Backup_Job_Store::create('full', 'all');
        $file = tempnam(sys_get_temp_dir(), 'wpmcp-backup-test-');
        file_put_contents($file, 'fixture backup contents');
        $this->cleanup_files[] = $file;

        $runner = new Run_Backup_Job(static fn(): array => ['file' => $file, 'size' => 24]);
        $runner->handle($job['id']);

        $updated = Backup_Job_Store::get($job['id']);

        $this->assertSame('completed', $updated['status']);
        $this->assertSame(['file' => $file, 'size' => 24], $updated['result']);
        $this->assertNull($updated['error']);
        $this->assertGreaterThan($job['updated_at'] - 1, $updated['updated_at']);
    }

    public function test_flips_job_to_failed_with_the_error_message_when_the_producer_throws(): void
    {
        $job = Backup_Job_Store::create('full', 'all');

        $runner = new Run_Backup_Job(static function (): array {
            throw new \RuntimeException('disk full');
        });
        $runner->handle($job['id']);

        $updated = Backup_Job_Store::get($job['id']);

        $this->assertSame('failed', $updated['status']);
        $this->assertNull($updated['result']);
        $this->assertSame('disk full', $updated['error']);
    }

    /**
     * Issue #771: the producer's progress reports are written to the job as
     * heartbeats, so get-backup-status shows the build moving (and the stale
     * sweep can tell a live job from a dead one). The first report goes
     * through at once; later ones are throttled to HEARTBEAT_SECONDS unless
     * the phase changed.
     */
    public function test_progress_reports_are_written_to_the_job_as_heartbeats(): void
    {
        Backup_Job_Store::set_clock_for_tests(1700000000);
        $job  = Backup_Job_Store::create('full', 'all');
        $seen = [];

        $runner = new Run_Backup_Job(static function (array $job, callable $progress) use (&$seen): array {
            $progress(['phase' => 'files', 'files_added' => 1, 'partial_file' => '/x/a.zip.part']);
            $seen[] = Backup_Job_Store::get($job['id']);

            // Same phase, inside the interval: throttled, nothing written.
            Backup_Job_Store::set_clock_for_tests(1700000000 + Backup_Job_Store::HEARTBEAT_SECONDS - 1);
            $progress(['phase' => 'files', 'files_added' => 2, 'partial_file' => '/x/a.zip.part']);
            $seen[] = Backup_Job_Store::get($job['id']);

            // Interval elapsed: written.
            Backup_Job_Store::set_clock_for_tests(1700000000 + Backup_Job_Store::HEARTBEAT_SECONDS + 1);
            $progress(['phase' => 'files', 'files_added' => 3, 'partial_file' => '/x/a.zip.part']);
            $seen[] = Backup_Job_Store::get($job['id']);

            // A phase change goes through at once, even inside the interval.
            Backup_Job_Store::set_clock_for_tests(1700000000 + Backup_Job_Store::HEARTBEAT_SECONDS + 2);
            $progress(['phase' => 'finalise', 'files_added' => 3, 'percent' => 10, 'partial_file' => '/x/a.zip.part']);
            $seen[] = Backup_Job_Store::get($job['id']);

            return ['file' => '/x/a.zip', 'size' => 1];
        });
        $runner->handle($job['id']);

        $this->assertSame('running', $seen[0]['status']);
        $this->assertSame(1, $seen[0]['progress']['files_added']);
        $this->assertSame(1700000000, $seen[0]['updated_at']);

        $this->assertSame(1, $seen[1]['progress']['files_added'], 'A report inside the interval is not written');

        $this->assertSame(3, $seen[2]['progress']['files_added']);
        $this->assertSame(1700000000 + Backup_Job_Store::HEARTBEAT_SECONDS + 1, $seen[2]['updated_at']);

        $this->assertSame('finalise', $seen[3]['progress']['phase']);
        $this->assertSame(10, $seen[3]['progress']['percent']);

        $done = Backup_Job_Store::get($job['id']);
        $this->assertSame('completed', $done['status']);
        $this->assertNull($done['progress'], 'A finished job carries no progress');
    }

    /**
     * Issue #771, the reported case: a full backup of a 1.1 GB site whose
     * cron request PHP ended for max_execution_time. No catch block runs, so
     * the job's own code can never flip it; the heartbeat it last wrote is
     * all that is left. A job that reports progress is held to the heartbeat
     * limit (minutes, not the two hours a report-less job gets): past it,
     * get-backup-status reports the job failed with where it got to, and
     * the partial archive the heartbeat named is removed.
     */
    public function test_a_worker_that_dies_mid_build_is_found_failed_and_its_partial_archive_removed(): void
    {
        $dir = Site_Backup_Dir::path();
        Site_Backup_Dir::protect($dir);
        $partial = $dir . '/wpmcp-all-dead-fixture.zip.part';
        $temp    = $partial . '.XyZ789';
        $scratch = $dir . '/db-deadfixture.sql';
        file_put_contents($partial, str_repeat('x', 1024));
        file_put_contents($temp, 'libzip output');
        file_put_contents($scratch, '-- dump');
        $this->cleanup_files = [$partial, $temp, $scratch];

        Backup_Job_Store::set_clock_for_tests(1700000000);
        $job = Backup_Job_Store::create('full', 'all');

        // The worker's writes, exactly as Run_Backup_Job makes them: the
        // claim, then a heartbeat per phase. Then PHP ends the request: no
        // catch block runs, so nothing else is ever written. (A producer
        // that throws would not model this: handle() catches it and records
        // a failure, which is the path a killed request never reaches.)
        Backup_Job_Store::transition($job['id'], 'queued', ['status' => 'running']);
        Backup_Job_Store::heartbeat($job['id'], ['phase' => 'dump', 'partial_file' => $partial, 'scratch_file' => $scratch, 'dump_bytes' => 4096]);
        Backup_Job_Store::set_clock_for_tests(1700000060);
        Backup_Job_Store::heartbeat($job['id'], ['phase' => 'files', 'partial_file' => $partial, 'scratch_file' => $scratch, 'files_added' => 1200]);

        $stuck = Backup_Job_Store::get($job['id']);
        $this->assertSame('running', $stuck['status'], 'Nothing has flipped the job: the request died');
        $this->assertSame('files', $stuck['progress']['phase']);
        $this->assertSame(1700000060, $stuck['updated_at']);

        // Inside the heartbeat limit: still running (the long limit would
        // not apply to a job that reports progress).
        Backup_Job_Store::set_clock_for_tests(1700000060 + Backup_Job_Store::STALE_HEARTBEAT_AFTER - 1);
        $this->assertSame('running', (new Get_Backup_Status())->handle(['job_id' => $job['id']])['status']);
        $this->assertFileExists($partial);

        Backup_Job_Store::set_clock_for_tests(1700000060 + Backup_Job_Store::STALE_HEARTBEAT_AFTER + 1);
        $status = (new Get_Backup_Status())->handle(['job_id' => $job['id']]);

        $this->assertSame('failed', $status['status']);
        $this->assertStringContainsString('no progress for 10 minutes', (string) $status['error']);
        $this->assertStringContainsString('adding files, 1200 added', (string) $status['error']);
        $this->assertStringContainsString('partial archive was removed', (string) $status['error']);
        $this->assertFileDoesNotExist($partial);
        $this->assertFileDoesNotExist($temp);
        $this->assertFileDoesNotExist($scratch);
        $this->assertSame([], Backup_Job_Store::list('running'));
    }

    /**
     * Issue #771: cancel-backup-job on a running job. The run learns of it
     * at its next heartbeat, which throws Backup_Job_Canceled; the builder
     * cleans up on the way out and the record stays as cancel wrote it, not
     * overwritten with "failed".
     */
    public function test_a_cancel_from_another_request_stops_the_run_at_its_next_heartbeat(): void
    {
        Backup_Job_Store::set_clock_for_tests(1700000000);
        $job = Backup_Job_Store::create('full', 'all');

        $cleaned = false;
        $runner  = new Run_Backup_Job(static function (array $job, callable $progress) use (&$cleaned): array {
            $progress(['phase' => 'files', 'files_added' => 10]);

            // Another request cancels the running job.
            $out = (new Cancel_Backup_Job())->handle(['job_id' => $job['id']]);
            if ('canceled' !== ($out['status'] ?? null)) {
                throw new \LogicException('The cancel of a running job must succeed');
            }

            Backup_Job_Store::set_clock_for_tests(1700000000 + Backup_Job_Store::HEARTBEAT_SECONDS + 1);
            try {
                $progress(['phase' => 'files', 'files_added' => 20]);
            } catch (Backup_Job_Canceled $e) {
                // What Site_Archive_Builder's catch does: clean up, rethrow.
                $cleaned = true;
                throw $e;
            }

            throw new \LogicException('The heartbeat after a cancel must throw');
        });
        $runner->handle($job['id']);

        $this->assertTrue($cleaned, 'The run was stopped by its heartbeat');
        $stored = Backup_Job_Store::get($job['id']);
        $this->assertSame('canceled', $stored['status']);
        $this->assertNull($stored['result']);
        $this->assertStringContainsString('while running', (string) $stored['error']);
    }

    public function test_an_archive_finished_after_a_cancel_is_discarded_and_the_job_stays_canceled(): void
    {
        $job  = Backup_Job_Store::create('full', 'all');
        $file = tempnam(sys_get_temp_dir(), 'wpmcp-late-finish-');
        file_put_contents($file, 'a finished archive');
        $this->cleanup_files[] = $file;

        $runner = new Run_Backup_Job(static function (array $job, callable $progress) use ($file): array {
            $progress(['phase' => 'finalise', 'percent' => 99]);
            (new Cancel_Backup_Job())->handle(['job_id' => $job['id']]);
            // No further heartbeat: the archive completes before the next one.
            return ['file' => $file, 'size' => 18];
        });
        $runner->handle($job['id']);

        $stored = Backup_Job_Store::get($job['id']);
        $this->assertSame('canceled', $stored['status']);
        $this->assertNull($stored['result']);
        $this->assertFileDoesNotExist($file, 'The user asked for no archive');
    }

    public function test_a_job_without_progress_reports_keeps_the_long_stale_limit(): void
    {
        Backup_Job_Store::set_clock_for_tests(1700000000);
        $job = Backup_Job_Store::create('content', 'all');
        Backup_Job_Store::transition($job['id'], 'queued', ['status' => 'running']);

        Backup_Job_Store::set_clock_for_tests(1700000000 + Backup_Job_Store::STALE_HEARTBEAT_AFTER + 1);
        $this->assertSame('running', Backup_Job_Store::get($job['id'])['status'], 'A WXR export reports no progress and is not held to the heartbeat limit');

        Backup_Job_Store::set_clock_for_tests(1700000000 + Backup_Job_Store::STALE_RUNNING_AFTER + 1);
        $this->assertSame('failed', Backup_Job_Store::get($job['id'])['status']);
    }

    public function test_unknown_job_id_is_a_no_op(): void
    {
        // Must not throw or create a phantom job record; uses the injected
        // no-op-safe producer since handle() returns before ever calling it
        // for an unknown id, but a real default would be equally untouched.
        $runner = new Run_Backup_Job(static function (): array {
            throw new \RuntimeException('must not be called for an unknown job id');
        });
        $runner->handle(999999);

        $this->assertNull(Backup_Job_Store::get(999999));
    }
}
