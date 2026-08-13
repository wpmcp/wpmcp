<?php

namespace WPMCP\Tests\Free\Backup;

use WPMCP\Tools\Backup\Db_Dumper;
use WPMCP\Tools\Backup\Site_Archive_Builder;
use WPMCP\Tools\Backup\Site_Backup_Dir;

/**
 * Site_Archive_Builder assembles the portable archive: db.sql, manifest.json
 * and (for a full backup) wp-content.
 *
 * The database dumper is injected with a stub in most tests. The real dumper
 * is covered end-to-end by DbDumperTest against the live database; repeating
 * that here would make these tests slow and would test the wrong class. The
 * one thing that IS verified against the real dumper is that build() with no
 * injection produces an importable db.sql, since the wiring between the two
 * is this class's own responsibility.
 */
class SiteArchiveBuilderTest extends \WP_UnitTestCase
{
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->cleanup = [];
        parent::tearDown();
    }

    /** A dumper that writes a fixed, recognisable payload. */
    private function stub_dumper(): Db_Dumper
    {
        return new class extends Db_Dumper {
            public function dump(callable $write, ?array $tables = null): array
            {
                $write("-- stub dump\nSELECT 1;\n");
                return [
                    'tables'      => ['wp_posts' => 3, 'wp_options' => 7],
                    'blob_tables' => ['wp_options'],
                    'bytes'       => 26,
                ];
            }
        };
    }

    private function open(string $archive): \ZipArchive
    {
        $zip = new \ZipArchive();
        $this->assertTrue(true === $zip->open($archive), 'The archive must be a readable zip.');
        return $zip;
    }

    public function test_database_scope_archive_contains_the_dump_and_a_manifest(): void
    {
        $result = (new Site_Archive_Builder($this->stub_dumper()))->build('database');
        $this->cleanup[] = $result['file'];

        $this->assertFileExists($result['file']);
        $this->assertGreaterThan(0, $result['size']);
        $this->assertSame('database', $result['scope']);

        $zip = $this->open($result['file']);
        $this->assertNotFalse($zip->locateName('db.sql'));
        $this->assertNotFalse($zip->locateName('manifest.json'));
        $this->assertStringContainsString('-- stub dump', (string) $zip->getFromName('db.sql'));

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $zip->close();

        $this->assertSame('wpmcp-site-backup', $manifest['format']);
        $this->assertSame(1, $manifest['format_version']);
        $this->assertSame(get_site_url(), $manifest['site']['site_url']);
        $this->assertSame(get_home_url(), $manifest['site']['home_url']);
        $this->assertSame($GLOBALS['wpdb']->prefix, $manifest['site']['table_prefix']);
        $this->assertSame(10, $manifest['database']['row_count']);
        $this->assertSame(['wp_options'], $manifest['database']['blob_tables']);
        $this->assertSame(0, $manifest['files']['count'], 'A database-scope archive must contain no files.');
    }

    public function test_database_scope_archive_contains_no_site_files(): void
    {
        $result = (new Site_Archive_Builder($this->stub_dumper()))->build('database');
        $this->cleanup[] = $result['file'];

        $zip = $this->open($result['file']);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $this->assertContains($name, ['db.sql', 'manifest.json'], 'Unexpected entry in a database-only archive: ' . $name);
        }
        $zip->close();
    }

    public function test_files_scope_archives_wp_content_and_skips_the_backup_directory(): void
    {
        $content_dir = WP_CONTENT_DIR;
        $marker      = $content_dir . '/wpmcp-archive-fixture.txt';
        file_put_contents($marker, 'fixture');
        $this->cleanup[] = $marker;

        // A file inside the plugin's own backup directory must never be
        // archived: a backup that contains previous backups grows without
        // bound and, on the second run, reads the file it is writing.
        $backup_dir = Site_Backup_Dir::path();
        Site_Backup_Dir::protect($backup_dir);
        $stale = $backup_dir . '/previous-archive-fixture.txt';
        file_put_contents($stale, 'should not be archived');
        $this->cleanup[] = $stale;

        $result = (new Site_Archive_Builder($this->stub_dumper()))->build('files');
        $this->cleanup[] = $result['file'];

        $this->assertSame('files', $result['scope']);
        $this->assertGreaterThan(0, $result['file_count']);

        $zip   = $this->open($result['file']);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        $has_dump = false !== $zip->locateName('db.sql');
        $zip->close();

        $this->assertContains('wp-content/wpmcp-archive-fixture.txt', $names);
        $this->assertFalse($has_dump, 'A files-scope archive carries no database dump.');

        foreach ($names as $name) {
            $this->assertStringNotContainsString(
                Site_Backup_Dir::DIR_NAME,
                $name,
                'The site-backup directory must be excluded from archives.'
            );
        }
    }

    public function test_excluded_extensions_are_skipped(): void
    {
        $noisy = WP_CONTENT_DIR . '/wpmcp-archive-fixture.log';
        file_put_contents($noisy, 'debug output');
        $this->cleanup[] = $noisy;

        $result = (new Site_Archive_Builder($this->stub_dumper()))->build('files');
        $this->cleanup[] = $result['file'];

        $zip = $this->open($result['file']);
        $this->assertFalse($zip->locateName('wp-content/wpmcp-archive-fixture.log'));
        $zip->close();
    }

    public function test_full_scope_carries_both_the_dump_and_files(): void
    {
        $marker = WP_CONTENT_DIR . '/wpmcp-archive-fixture-full.txt';
        file_put_contents($marker, 'fixture');
        $this->cleanup[] = $marker;

        $result = (new Site_Archive_Builder($this->stub_dumper()))->build('all');
        $this->cleanup[] = $result['file'];

        $zip = $this->open($result['file']);
        $this->assertNotFalse($zip->locateName('db.sql'));
        $this->assertNotFalse($zip->locateName('wp-content/wpmcp-archive-fixture-full.txt'));
        $zip->close();

        $this->assertSame('all', $result['scope']);
    }

    public function test_an_unknown_scope_falls_back_to_a_full_archive(): void
    {
        $result = (new Site_Archive_Builder($this->stub_dumper()))->build('nonsense');
        $this->cleanup[] = $result['file'];

        $this->assertSame('all', $result['scope']);
    }

    public function test_the_archive_lands_in_the_protected_backup_directory(): void
    {
        $result = (new Site_Archive_Builder($this->stub_dumper()))->build('database');
        $this->cleanup[] = $result['file'];

        $dir = Site_Backup_Dir::path();

        $this->assertStringStartsWith($dir, $result['file']);
        $this->assertFileExists($dir . '/.htaccess');
        $this->assertFileExists($dir . '/index.php');
        $this->assertStringContainsString('Require all denied', (string) file_get_contents($dir . '/.htaccess'));
    }

    public function test_archive_names_are_unpredictable(): void
    {
        // The archive holds every password hash on the site. On a server that
        // ignores .htaccess, a guessable name is a download link.
        $one = (new Site_Archive_Builder($this->stub_dumper()))->build('database');
        $two = (new Site_Archive_Builder($this->stub_dumper()))->build('database');
        $this->cleanup[] = $one['file'];
        $this->cleanup[] = $two['file'];

        $this->assertNotSame(basename($one['file']), basename($two['file']));
    }

    public function test_a_failing_dump_leaves_no_partial_archive_behind(): void
    {
        $exploding = new class extends Db_Dumper {
            public function dump(callable $write, ?array $tables = null): array
            {
                $write("-- partial\n");
                throw new \RuntimeException('disk full');
            }
        };

        $target = Site_Backup_Dir::path() . '/wpmcp-failure-fixture.zip';

        try {
            (new Site_Archive_Builder($exploding))->build('database', $target);
            $this->fail('The builder must propagate the dump failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('disk full', $e->getMessage());
        }

        // A truncated zip that looks like a backup is worse than no backup:
        // it is the file someone reaches for during an incident.
        $this->assertFileDoesNotExist($target);
        $this->assertSame(
            [],
            glob(Site_Backup_Dir::path() . '/db-*.sql') ?: [],
            'The scratch dump file must be cleaned up after a failure.'
        );
    }

    public function test_the_real_dumper_produces_an_importable_db_sql(): void
    {
        // No stub: this is the wiring between the builder and the real
        // dumper, which is this class's own responsibility.
        $result = (new Site_Archive_Builder())->build('database');
        $this->cleanup[] = $result['file'];

        $zip = $this->open($result['file']);
        $sql = (string) $zip->getFromName('db.sql');
        $zip->close();

        global $wpdb;
        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString($wpdb->prefix . 'options', $sql);
        $this->assertArrayHasKey($wpdb->prefix . 'options', $result['tables']);
        $this->assertGreaterThan(0, $result['manifest']['database']['row_count']);
    }
}
