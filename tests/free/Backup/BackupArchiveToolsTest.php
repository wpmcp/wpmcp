<?php

namespace WPMCP\Tests\Free\Backup;

use WPMCP\Tools\Backup\Archive_Locator;
use WPMCP\Tools\Backup\Backup_Job_Store;
use WPMCP\Tools\Backup\Delete_Backup_Archive;
use WPMCP\Tools\Backup\Get_Backup_Manifest;
use WPMCP\Tools\Backup\Run_Backup_Job;
use WPMCP\Tools\Backup\Site_Archive_Builder;
use WPMCP\Tools\Backup\Site_Backup_Dir;

/**
 * The tools that read and prune archives, plus the job-type routing that
 * decides which artifact a queued backup produces.
 *
 * The containment tests are the important ones here. Both tools take a path
 * straight from an MCP client, which is a language model acting on text it
 * read somewhere on the site, so "delete the backup at ../../wp-config.php"
 * is a request that will eventually be made.
 */
class BackupArchiveToolsTest extends \WP_UnitTestCase
{
    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Backup_Job_Store::OPTION);
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            if (is_link($path) || is_file($path)) {
                unlink($path);
            }
        }
        $this->cleanup = [];
        delete_option(Backup_Job_Store::OPTION);
        parent::tearDown();
    }

    private function build_archive(): array
    {
        $result = (new Site_Archive_Builder())->build('database');
        $this->cleanup[] = $result['file'];
        return $result;
    }

    public function test_manifest_is_readable_by_path(): void
    {
        $archive = $this->build_archive();

        $out = (new Get_Backup_Manifest())->handle(['path' => $archive['file']]);

        // The locator canonicalises: containment is enforced on the realpath,
        // so the resolved path may differ textually from the one passed in
        // (on macOS /var is a symlink to /private/var) while pointing at the
        // same file. Comparing realpaths is the assertion that holds on every
        // platform this runs on.
        $this->assertSame(realpath($archive['file']), $out['file']);
        $this->assertGreaterThan(0, $out['size']);
        $this->assertSame('wpmcp-site-backup', $out['manifest']['format']);
        $this->assertSame(get_site_url(), $out['manifest']['site']['site_url']);
    }

    public function test_manifest_is_readable_by_job_id(): void
    {
        $archive = $this->build_archive();
        $job     = Backup_Job_Store::create('database', 'all');
        Backup_Job_Store::update($job['id'], ['status' => 'completed', 'result' => $archive]);

        $out = (new Get_Backup_Manifest())->handle(['job_id' => $job['id']]);

        $this->assertSame(realpath($archive['file']), $out['file']);
    }

    public function test_a_job_that_has_not_finished_reports_why_rather_than_a_missing_file(): void
    {
        $job = Backup_Job_Store::create('full', 'all');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is queued, so it has no archive yet');

        (new Get_Backup_Manifest())->handle(['job_id' => $job['id']]);
    }

    public function test_unknown_job_id_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown backup job.');

        (new Get_Backup_Manifest())->handle(['job_id' => 999999]);
    }

    /** A real file that exists outside the backup directory. */
    private function sentinel_outside(): string
    {
        $path = WP_CONTENT_DIR . '/wpmcp-outside-fixture.txt';
        file_put_contents($path, 'outside');
        $this->cleanup[] = $path;
        return $path;
    }

    public function test_a_path_outside_the_backup_directory_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not inside the site-backup directory');

        Archive_Locator::resolve(['path' => $this->sentinel_outside()]);
    }

    public function test_a_traversal_path_is_refused(): void
    {
        $this->sentinel_outside();
        // Resolves up out of uploads/wpmcp-site-backups and back into
        // wp-content, so the string starts with the backup directory but the
        // file does not live there.
        $traversal = Site_Backup_Dir::path() . '/../../wpmcp-outside-fixture.txt';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not inside the site-backup directory');

        Archive_Locator::resolve(['path' => $traversal]);
    }

    public function test_a_nonexistent_path_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No such backup archive.');

        Archive_Locator::resolve(['path' => Site_Backup_Dir::path() . '/never-existed.zip']);
    }

    public function test_a_symlink_pointing_out_of_the_backup_directory_is_refused(): void
    {
        // A string-prefix check would accept this: the path starts with the
        // backup directory, but the bytes it resolves to do not live there.
        $link = Site_Backup_Dir::path() . '/escape-fixture.zip';
        Site_Backup_Dir::protect(Site_Backup_Dir::path());
        $target = $this->sentinel_outside();

        // is_file() follows symlinks, so a dangling link left by an
        // interrupted earlier run is invisible to the cleanup in tearDown;
        // clear it explicitly or symlink() below fails and the test silently
        // skips itself forever.
        if (is_link($link)) {
            unlink($link);
        }

        if (! @symlink($target, $link)) {
            $this->markTestSkipped('The filesystem does not permit symlink creation.');
        }
        $this->cleanup[] = $link;

        try {
            Archive_Locator::resolve(['path' => $link]);
            $this->fail('A symlink out of the backup directory must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not inside the site-backup directory', $e->getMessage());
        }
    }

    public function test_missing_reference_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Provide either job_id or path.');

        Archive_Locator::resolve([]);
    }

    public function test_delete_removes_the_archive_and_reports_the_bytes_freed(): void
    {
        $archive = $this->build_archive();
        $size    = filesize($archive['file']);

        $out = (new Delete_Backup_Archive())->handle(['path' => $archive['file']]);

        $this->assertTrue($out['deleted']);
        $this->assertSame((int) $size, $out['bytes_freed']);
        $this->assertFileDoesNotExist($archive['file']);
    }

    public function test_delete_by_job_id_flags_the_job_result_rather_than_dropping_the_record(): void
    {
        // Backup history that silently points at a pruned artifact is how
        // someone discovers mid-incident that the backup is gone.
        $archive = $this->build_archive();
        $job     = Backup_Job_Store::create('database', 'all');
        Backup_Job_Store::update($job['id'], ['status' => 'completed', 'result' => $archive]);

        (new Delete_Backup_Archive())->handle(['job_id' => $job['id']]);

        $updated = Backup_Job_Store::get($job['id']);

        $this->assertNotNull($updated, 'The job record must survive the prune.');
        $this->assertSame('completed', $updated['status']);
        $this->assertTrue($updated['result']['deleted']);
    }

    public function test_deleting_a_path_outside_the_backup_directory_is_refused(): void
    {
        $sentinel = WP_CONTENT_DIR . '/wpmcp-must-not-be-deleted.txt';
        file_put_contents($sentinel, 'important');
        $this->cleanup[] = $sentinel;

        try {
            (new Delete_Backup_Archive())->handle(['path' => $sentinel]);
            $this->fail('Deleting outside the backup directory must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not inside the site-backup directory', $e->getMessage());
        }

        $this->assertFileExists($sentinel, 'The refused delete must not have touched the file.');
    }

    public function test_reading_a_non_archive_file_reports_a_missing_manifest(): void
    {
        $junk = Site_Backup_Dir::path() . '/not-an-archive.zip';
        Site_Backup_Dir::protect(Site_Backup_Dir::path());
        file_put_contents($junk, 'not a zip at all');
        $this->cleanup[] = $junk;

        $this->expectException(\RuntimeException::class);

        (new Get_Backup_Manifest())->handle(['path' => $junk]);
    }

    /**
     * @dataProvider archive_scope_provider
     */
    public function test_job_type_maps_to_an_archive_scope(string $type, string $scope, ?string $expected): void
    {
        $this->assertSame($expected, Run_Backup_Job::archive_scope(['type' => $type, 'scope' => $scope]));
    }

    public function archive_scope_provider(): array
    {
        return [
            'full defaults to everything'         => ['full', 'all', 'all'],
            'full honours a narrowed scope'       => ['full', 'database', 'database'],
            'full ignores a nonsense scope'       => ['full', 'nonsense', 'all'],
            'database carries its own scope'      => ['database', 'all', 'database'],
            'files carries its own scope'         => ['files', 'all', 'files'],
            'uploads carries its own scope'       => ['uploads', 'all', 'uploads'],
            // 'content' predates archive support and must keep routing to
            // the WXR exporter, not the new builder.
            'content routes to the WXR export'    => ['content', 'all', null],
            'an unknown type routes to WXR'       => ['something-new', 'all', null],
        ];
    }

    public function test_the_default_producer_builds_a_real_archive_for_an_archive_job(): void
    {
        $result = (Run_Backup_Job::default_producer())(['type' => 'database', 'scope' => 'all']);
        $this->cleanup[] = $result['file'];

        $this->assertSame('database', $result['scope']);
        $this->assertFileExists($result['file']);
        $this->assertSame('wpmcp-site-backup', $result['manifest']['format']);
    }

    public function test_the_runner_passes_the_job_record_to_the_producer(): void
    {
        $job = Backup_Job_Store::create('uploads', 'all');

        $seen   = null;
        $runner = new Run_Backup_Job(static function (array $received) use (&$seen): array {
            $seen = $received;
            return ['file' => 'stub', 'size' => 0];
        });
        $runner->handle($job['id']);

        $this->assertSame('uploads', $seen['type'], 'The producer must see the job type in order to route on it.');
        $this->assertSame('running', $seen['status'], 'The job is flipped to running before the artifact is produced.');
    }
}
