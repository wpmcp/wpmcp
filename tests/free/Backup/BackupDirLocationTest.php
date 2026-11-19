<?php

namespace WPMCP\Tests\Free\Backup;

use WPMCP\Admin\Backup_Dir_Notice;
use WPMCP\Tools\Backup\Archive_Locator;
use WPMCP\Tools\Backup\Backup_Dir_Health;
use WPMCP\Tools\Backup\Backup_Job_Store;
use WPMCP\Tools\Backup\Get_Backup_Status;
use WPMCP\Tools\Backup\Site_Backup_Dir;

/**
 * Issue #846: backup archives can live outside the web root (WPMCP_BACKUP_DIR),
 * archives made before the move keep working and are moved in the
 * background, and a canary request tells the owner whether the folder is
 * downloadable.
 */
class BackupDirLocationTest extends \WP_UnitTestCase
{
    private string $base = '';
    private string $uploads = '';

    /** @var string[] */
    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Backup_Job_Store::OPTION);
        delete_option(Backup_Dir_Health::OPTION);
        $this->base    = rtrim(get_temp_dir(), '/') . '/wpmcp-846-' . wp_generate_password(8, false, false);
        $this->uploads = $this->base . '-uploads';
        // The install under test is shared with other checkouts running the
        // suite, and migration moves every archive in uploads: point
        // uploads at a private folder so theirs are never touched.
        wp_mkdir_p($this->uploads);
        add_filter('upload_dir', [ $this, 'redirect_uploads' ]);
    }

    public function redirect_uploads(array $dirs): array
    {
        $dirs['basedir'] = $this->uploads;
        $dirs['path']    = $this->uploads;
        return $dirs;
    }

    protected function tearDown(): void
    {
        Site_Backup_Dir::reset_for_tests();
        remove_filter('upload_dir', [ $this, 'redirect_uploads' ]);
        foreach ($this->cleanup as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->remove_tree($this->base);
        $this->remove_tree($this->uploads);
        delete_option(Backup_Job_Store::OPTION);
        delete_option(Backup_Dir_Health::OPTION);
        parent::tearDown();
    }

    public function test_default_folder_is_under_uploads(): void
    {
        $where = Site_Backup_Dir::resolve();

        $this->assertSame('uploads', $where['source']);
        $this->assertSame(trailingslashit(wp_upload_dir()['basedir']) . Site_Backup_Dir::DIR_NAME, $where['path']);
        $this->assertNull($where['fallback_reason']);
        $this->assertTrue(Site_Backup_Dir::inside_web_root($where['path']));
    }

    public function test_constant_moves_the_folder_outside_the_web_root(): void
    {
        Site_Backup_Dir::set_constant_for_tests(true, $this->base . '/');

        $where = Site_Backup_Dir::resolve();

        $this->assertSame('constant', $where['source']);
        $this->assertSame($this->base . '/' . Site_Backup_Dir::DIR_NAME, $where['path']);
        $this->assertSame($where['path'], Site_Backup_Dir::path());
        $this->assertFalse(Site_Backup_Dir::inside_web_root($where['path']));
        $this->assertNull(Site_Backup_Dir::public_url($where['path']));
    }

    public function test_unusable_constant_falls_back_to_uploads(): void
    {
        $file = $this->base . '-file';
        file_put_contents($file, 'x');
        $this->cleanup[] = $file;

        foreach ([ $file, 'relative/path', '', 42 ] as $value) {
            Site_Backup_Dir::set_constant_for_tests(true, $value);
            $where = Site_Backup_Dir::resolve();
            $this->assertSame('uploads', $where['source'], var_export($value, true));
            $this->assertSame(Site_Backup_Dir::uploads_path(), $where['path']);
            $this->assertIsString($where['fallback_reason']);
        }
    }

    public function test_locator_finds_legacy_archives_and_refuses_outside_paths(): void
    {
        $legacy = $this->legacy_archive();
        Site_Backup_Dir::set_constant_for_tests(true, $this->base);

        $this->assertSame(realpath($legacy), Archive_Locator::resolve(['path' => $legacy]));

        $outside = $this->base . '/stray.zip';
        wp_mkdir_p($this->base);
        file_put_contents($outside, 'zip');
        $this->expectException(\RuntimeException::class);
        Archive_Locator::resolve(['path' => $outside]);
    }

    public function test_migration_moves_archives_and_repoints_job_records(): void
    {
        $legacy = $this->legacy_archive();
        $job    = Backup_Job_Store::create('full', 'database');
        Backup_Job_Store::update($job['id'], [ 'status' => 'completed', 'result' => [ 'file' => $legacy, 'size' => 3 ] ]);

        Site_Backup_Dir::set_constant_for_tests(true, $this->base);
        $out = Backup_Dir_Health::migrate_legacy();

        $moved = realpath($this->base) . '/' . Site_Backup_Dir::DIR_NAME . '/' . basename($legacy);
        $this->assertNull($out['skipped']);
        $this->assertSame([ $moved ], $out['moved']);
        $this->assertFileDoesNotExist($legacy);
        $this->assertFileExists($moved);
        $this->assertFileExists(dirname($moved) . '/.htaccess');
        $this->assertSame($moved, Backup_Job_Store::get($job['id'])['result']['file']);
        $this->assertSame($moved, Archive_Locator::resolve(['job_id' => $job['id']]));
    }

    public function test_migration_waits_while_a_job_is_running(): void
    {
        $legacy = $this->legacy_archive();
        $job    = Backup_Job_Store::create('full', 'database');
        Backup_Job_Store::update($job['id'], [ 'status' => 'running' ]);

        Site_Backup_Dir::set_constant_for_tests(true, $this->base);

        $this->assertSame('job_in_flight', Backup_Dir_Health::migrate_legacy()['skipped']);
        $this->assertFileExists($legacy);
    }

    public function test_self_check_flags_a_served_folder(): void
    {
        $served = static function ($pre, $args, $url) {
            if (false !== strpos((string) $url, '/wpmcp-canary-')) {
                $file = Site_Backup_Dir::path() . '/' . basename((string) wp_parse_url((string) $url, PHP_URL_PATH));
                return [ 'headers' => [], 'body' => (string) @file_get_contents($file), 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ];
            }
            return $pre;
        };
        add_filter('pre_http_request', $served, 5, 3);

        try {
            $result = Backup_Dir_Health::self_check();
        } finally {
            remove_filter('pre_http_request', $served, 5);
        }

        $this->assertSame('exposed', $result['status']);
        $this->assertSame(200, $result['http_code']);
        $this->assertSame([], glob(Site_Backup_Dir::path() . '/wpmcp-canary-*'), 'The canary is removed.');
        $this->assertIsString(Backup_Dir_Health::warning());

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        set_current_screen('dashboard');
        ob_start();
        Backup_Dir_Notice::render();
        $html = (string) ob_get_clean();
        set_current_screen('front');
        $this->assertStringContainsString('WPMCP_BACKUP_DIR', $html);
    }

    public function test_self_check_reports_a_protected_folder(): void
    {
        $result = Backup_Dir_Health::self_check();

        $this->assertSame('protected', $result['status']);
        $this->assertSame(404, $result['http_code']);
        $this->assertNull(Backup_Dir_Health::warning());
        $this->assertFalse(Backup_Dir_Health::is_due());
    }

    public function test_self_check_skips_the_request_outside_the_web_root(): void
    {
        Site_Backup_Dir::set_constant_for_tests(true, $this->base);
        $requests = 0;
        $count    = static function ($pre) use (&$requests) {
            ++$requests;
            return $pre;
        };
        add_filter('pre_http_request', $count, 1);

        try {
            $result = Backup_Dir_Health::self_check();
        } finally {
            remove_filter('pre_http_request', $count, 1);
        }

        $this->assertSame('outside_web_root', $result['status']);
        $this->assertSame(0, $requests);
    }

    public function test_status_reports_the_folder_and_the_last_check(): void
    {
        Site_Backup_Dir::set_constant_for_tests(true, $this->base);
        $job = Backup_Job_Store::create('full', 'all');

        $out = (new Get_Backup_Status())->handle(['job_id' => $job['id']]);

        $this->assertSame($this->base . '/' . Site_Backup_Dir::DIR_NAME, $out['storage']['path']);
        $this->assertSame('constant', $out['storage']['source']);
        $this->assertTrue($out['storage']['outside_web_root']);
        $this->assertSame('outside_web_root', $out['storage']['last_check']['status']);
    }

    private function legacy_archive(): string
    {
        $dir = Site_Backup_Dir::uploads_path();
        Site_Backup_Dir::protect($dir);
        $file = $dir . '/wpmcp-backup-846-' . wp_generate_password(6, false, false) . '.zip';
        file_put_contents($file, 'zip');
        $this->cleanup[] = $file;

        return $file;
    }

    private function remove_tree(string $dir): void
    {
        if ('' === $dir || ! file_exists($dir)) {
            return;
        }
        if (is_link($dir) || is_file($dir)) {
            unlink($dir);
            return;
        }
        foreach ((array) scandir($dir) as $entry) {
            if ('.' !== $entry && '..' !== $entry) {
                $this->remove_tree($dir . '/' . $entry);
            }
        }
        rmdir($dir);
    }
}
