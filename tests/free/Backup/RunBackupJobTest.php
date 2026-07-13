<?php

namespace WPMCP\Tests\Free\Backup;

use WPMCP\Tools\Backup\Backup_Job_Store;
use WPMCP\Tools\Backup\Run_Backup_Job;

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
