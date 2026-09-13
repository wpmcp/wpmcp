<?php

namespace WPMCP\Tests\Free\Backup;

use WPMCP\Tools\Backup\Backup_Job_Store;
use WPMCP\Tools\Backup\Restore_Site_Backup;
use WPMCP\Tools\Backup\Site_Archive_Builder;
use WPMCP\Tools\Backup\Site_Backup_Dir;

/**
 * The compatibility gate in front of a site restore (issue #190, phase 1).
 *
 * Every test here runs against the dry-run path or the pre-execution
 * refusal, because that is all this build implements. The invariant under
 * test is that nothing is written before the gate has spoken: the
 * maintenance option stays untouched and the archive stays on disk in
 * every case, including the one where a compatible archive is asked to
 * restore for real and is refused as not implemented.
 */
class RestoreSiteBackupTest extends \WP_UnitTestCase
{
    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Backup_Job_Store::OPTION);
        delete_option('wpmcp_maintenance');
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->cleanup = [];
        delete_option(Backup_Job_Store::OPTION);
        delete_option('wpmcp_maintenance');
        parent::tearDown();
    }

    /** A real archive of this site, built by the same code a backup job runs. */
    private function build_archive(string $scope = 'database'): array
    {
        $result = (new Site_Archive_Builder())->build($scope);
        $this->cleanup[] = $result['file'];
        return $result;
    }

    /**
     * A hand-written archive: a real archive's manifest with overrides
     * merged in, plus an optional db.sql entry. Overrides use dotted keys
     * ('site.table_prefix'); a null value removes the key entirely so the
     * "manifest is missing X" refusals can be exercised.
     */
    private function write_archive(array $overrides = [], bool $with_db_sql = true): string
    {
        $manifest = $this->build_archive()['manifest'];

        foreach ($overrides as $dotted => $value) {
            $keys = explode('.', $dotted);
            $ref  = &$manifest;
            while (count($keys) > 1) {
                $key = array_shift($keys);
                if (! isset($ref[ $key ]) || ! is_array($ref[ $key ])) {
                    $ref[ $key ] = [];
                }
                $ref = &$ref[ $key ];
            }
            if (null === $value) {
                unset($ref[ $keys[0] ]);
            } else {
                $ref[ $keys[0] ] = $value;
            }
            unset($ref);
        }

        Site_Backup_Dir::protect(Site_Backup_Dir::path());
        $path = Site_Backup_Dir::path() . '/restore-fixture-' . wp_generate_password(8, false) . '.zip';

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString('manifest.json', wp_json_encode($manifest));
        if ($with_db_sql) {
            $zip->addFromString('db.sql', "-- fixture\n");
        }
        $zip->close();
        $this->cleanup[] = $path;

        return $path;
    }

    /** The warnings whose text mentions $needle. */
    private function warnings_about(array $out, string $needle): array
    {
        return array_values(array_filter(
            $out['warnings'],
            static fn (string $w): bool => str_contains($w, $needle)
        ));
    }

    private function refusals_of(string $path, array $extra = []): array
    {
        $out = (new Restore_Site_Backup())->handle(array_merge(['path' => $path], $extra));
        $this->assertTrue($out['dry_run']);
        return $out['refusals'];
    }

    public function test_dry_run_is_the_default_and_a_fresh_same_site_archive_is_compatible(): void
    {
        $archive = $this->build_archive();

        $out = (new Restore_Site_Backup())->handle(['path' => $archive['file']]);

        $this->assertTrue($out['dry_run'], 'Omitting dry_run must produce a report, not a restore.');
        $this->assertTrue($out['compatible']);
        $this->assertSame([], $out['refusals']);
        // A same-version archive can never be a downgrade. BLOB warnings are
        // not pinned: whether the test database carries a binary column is
        // a property of whichever third-party plugins CI installed.
        $this->assertSame([], $this->warnings_about($out, 'downgrade'));
        $this->assertSame('database', $out['scope']);
        $this->assertFalse($out['include_files']);
        $this->assertSame(realpath($archive['file']), $out['file']);
        $this->assertSame('wpmcp-site-backup', $out['manifest']['format']);
    }

    public function test_dry_run_touches_nothing(): void
    {
        $archive = $this->build_archive();
        $post    = self::factory()->post->create(['post_title' => 'Survives the dry run']);

        (new Restore_Site_Backup())->handle(['path' => $archive['file'], 'dry_run' => true]);

        $this->assertFalse(get_option('wpmcp_maintenance'), 'A dry run must not enter maintenance mode.');
        $this->assertSame('Survives the dry run', get_post($post)->post_title);
        $this->assertFileExists($archive['file']);
    }

    public function test_dry_run_works_by_job_id(): void
    {
        $archive = $this->build_archive();
        $job     = Backup_Job_Store::create('database', 'all');
        Backup_Job_Store::update($job['id'], ['status' => 'completed', 'result' => $archive]);

        $out = (new Restore_Site_Backup())->handle(['job_id' => $job['id']]);

        $this->assertTrue($out['compatible']);
        $this->assertSame(realpath($archive['file']), $out['file']);
    }

    public function test_a_table_prefix_mismatch_is_refused_and_names_both_prefixes(): void
    {
        global $wpdb;
        $path = $this->write_archive(['site.table_prefix' => 'other_']);

        $refusals = $this->refusals_of($path);

        $this->assertCount(1, $refusals);
        $this->assertStringContainsString('Table prefix mismatch', $refusals[0]);
        $this->assertStringContainsString('"other_"', $refusals[0]);
        $this->assertStringContainsString('"' . $wpdb->prefix . '"', $refusals[0]);
    }

    public function test_a_manifest_without_a_table_prefix_is_refused_rather_than_assumed_to_match(): void
    {
        $refusals = $this->refusals_of($this->write_archive(['site.table_prefix' => null]));

        $this->assertCount(1, $refusals);
        $this->assertStringContainsString('does not record a table_prefix', $refusals[0]);
    }

    public function test_a_newer_format_version_is_refused(): void
    {
        $refusals = $this->refusals_of($this->write_archive(['format_version' => 2]));

        $this->assertCount(1, $refusals);
        $this->assertStringContainsString('format_version 2 is newer', $refusals[0]);
    }

    public function test_a_missing_format_version_is_refused_rather_than_treated_as_zero(): void
    {
        $refusals = $this->refusals_of($this->write_archive(['format_version' => null]));

        $this->assertCount(1, $refusals);
        $this->assertStringContainsString('no usable format_version', $refusals[0]);
    }

    public function test_an_unknown_manifest_format_is_refused(): void
    {
        $refusals = $this->refusals_of($this->write_archive(['format' => 'someone-elses-backup']));

        $this->assertCount(1, $refusals);
        $this->assertStringContainsString('unknown manifest format', $refusals[0]);
    }

    public function test_a_multisite_mismatch_is_refused(): void
    {
        $refusals = $this->refusals_of($this->write_archive(['site.multisite' => ! is_multisite()]));

        $this->assertCount(1, $refusals);
        $this->assertStringContainsString('Multisite mismatch', $refusals[0]);
    }

    public function test_a_manifest_without_a_multisite_flag_is_refused(): void
    {
        $refusals = $this->refusals_of($this->write_archive(['site.multisite' => null]));

        $this->assertCount(1, $refusals);
        $this->assertStringContainsString('whether the source was multisite', $refusals[0]);
    }

    public function test_a_files_only_archive_is_refused_because_it_has_no_database(): void
    {
        // A hand-written files-scope archive (no db.sql) rather than a real
        // one: building a real files archive zips the whole test wp-content.
        $path = $this->write_archive(['scope' => 'files'], false);

        $out = (new Restore_Site_Backup())->handle(['path' => $path]);

        $this->assertFalse($out['compatible']);
        $this->assertSame('files', $out['scope']);
        $this->assertCount(1, $out['refusals']);
        $this->assertStringContainsString('scope is "files", which carries no database dump', $out['refusals'][0]);
    }

    public function test_include_files_against_a_database_only_archive_is_refused(): void
    {
        $archive = $this->build_archive('database');

        $out = (new Restore_Site_Backup())->handle(['path' => $archive['file'], 'include_files' => true]);

        $this->assertFalse($out['compatible']);
        $this->assertTrue($out['include_files']);
        $this->assertCount(1, $out['refusals']);
        $this->assertStringContainsString('include_files was requested', $out['refusals'][0]);
    }

    public function test_a_database_scope_archive_without_a_dump_entry_is_refused(): void
    {
        $refusals = $this->refusals_of($this->write_archive([], false));

        $this->assertCount(1, $refusals);
        $this->assertStringContainsString('no db.sql entry', $refusals[0]);
    }

    public function test_a_wordpress_downgrade_is_a_warning_not_a_refusal(): void
    {
        $out = (new Restore_Site_Backup())->handle([
            'path' => $this->write_archive(['versions.wordpress' => '99.0']),
        ]);

        $this->assertTrue($out['compatible']);
        $warnings = $this->warnings_about($out, 'database downgrade');
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('WordPress 99.0', $warnings[0]);
    }

    public function test_blob_tables_are_a_warning_not_a_refusal(): void
    {
        $out = (new Restore_Site_Backup())->handle([
            'path' => $this->write_archive(['database.blob_tables' => ['wp_options', 'wp_postmeta']]),
        ]);

        $this->assertTrue($out['compatible']);
        $warnings = $this->warnings_about($out, 'BLOB columns');
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('wp_options, wp_postmeta', $warnings[0]);
    }

    public function test_a_real_restore_of_an_incompatible_archive_is_refused_with_the_reason(): void
    {
        $path = $this->write_archive(['site.table_prefix' => 'other_']);

        try {
            (new Restore_Site_Backup())->handle(['path' => $path, 'dry_run' => false]);
            $this->fail('An incompatible archive must never reach the restore step.');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('Restore refused: ', $e->getMessage());
            $this->assertStringContainsString('Table prefix mismatch', $e->getMessage());
        }

        $this->assertFalse(get_option('wpmcp_maintenance'));
        $this->assertFileExists($path);
    }

    public function test_a_real_restore_of_a_compatible_archive_is_refused_as_not_implemented_without_side_effects(): void
    {
        $archive = $this->build_archive();

        try {
            (new Restore_Site_Backup())->handle(['path' => $archive['file'], 'dry_run' => false]);
            $this->fail('This build must not execute a restore.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not implemented in this build', $e->getMessage());
            $this->assertStringNotContainsString('Restore refused', $e->getMessage());
        }

        $this->assertFalse(get_option('wpmcp_maintenance'), 'The not-implemented refusal must leave maintenance mode alone.');
        $this->assertFileExists($archive['file']);
    }

    public function test_a_non_archive_file_is_refused_before_anything_else(): void
    {
        Site_Backup_Dir::protect(Site_Backup_Dir::path());
        $junk = Site_Backup_Dir::path() . '/restore-not-an-archive.zip';
        file_put_contents($junk, 'not a zip at all');
        $this->cleanup[] = $junk;

        // Only the class is pinned: which of the locator's messages fires
        // depends on how libzip reports a non-zip file.
        $this->expectException(\RuntimeException::class);

        (new Restore_Site_Backup())->handle(['path' => $junk, 'dry_run' => false]);
    }

    public function test_a_path_outside_the_backup_directory_is_refused(): void
    {
        $outside = WP_CONTENT_DIR . '/restore-outside-fixture.zip';
        file_put_contents($outside, 'outside');
        $this->cleanup[] = $outside;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not inside the site-backup directory');

        (new Restore_Site_Backup())->handle(['path' => $outside]);
    }
}
