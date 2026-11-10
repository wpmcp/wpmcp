<?php

namespace WPMCP\Tests\Free\Filesystem;

use WPMCP\Tools\Filesystem\Filesystem_Guard;
use WPMCP\Tools\Filesystem\Legacy_Backup_Migration;

/**
 * Issue #622. Before #609 the file tools backed a file up as
 * uploads/wpmcp-fs-backups/<Ymd-His>-<flattened path>, keeping its
 * extension, so a backed-up functions.php was a .php file under uploads,
 * protected only by an .htaccess that nginx ignores. The upgrade routine
 * renames every such backup to a name no server executes, records where it
 * went so a restore by the old name still works, and adds an index.php
 * guard and the .htaccess deny.
 */
class LegacyBackupMigrationTest extends \WP_UnitTestCase
{
    private const PHP = "<?php\n// backed-up theme code \x00\xff binary-safe\necho 'x';\n";

    /** @var string[] */
    private array $cleanup = [];

    public function setUp(): void
    {
        parent::setUp();
        delete_site_option(Legacy_Backup_Migration::DONE_OPTION);
        delete_site_option(Legacy_Backup_Migration::CURSOR_OPTION);
        delete_option(Legacy_Backup_Migration::RENAMES_OPTION);
    }

    public function tearDown(): void
    {
        foreach (array_reverse($this->cleanup) as $path) {
            if (is_link($path) || is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                @rmdir($path);
            }
        }
        if (is_multisite() && ms_is_switched()) {
            restore_current_blog();
        }
        parent::tearDown();
    }

    private static function backup_dir(): string
    {
        return trailingslashit(wp_upload_dir()['basedir']) . Filesystem_Guard::BACKUP_DIR;
    }

    /** A legacy backup exactly as origin/main before #609 wrote it. */
    private function legacy_backup(string $name, string $bytes): string
    {
        wp_mkdir_p(self::backup_dir());
        $path = self::backup_dir() . '/' . $name;
        file_put_contents($path, $bytes);
        $this->cleanup[] = $path;
        return $path;
    }

    /** @return string[] executable-looking names left in the folder (symlinks excluded) */
    private static function executable_names(string $dir): array
    {
        $left = [];
        foreach ((array) scandir($dir) as $entry) {
            $path = $dir . '/' . $entry;
            if (is_file($path) && ! is_link($path) && 'index.php' !== $entry
                && preg_match(Legacy_Backup_Migration::EXECUTABLE_PATTERN, $entry)) {
                $left[] = $entry;
            }
        }
        return $left;
    }

    private function track_renamed(): void
    {
        foreach ((array) get_option(Legacy_Backup_Migration::RENAMES_OPTION, []) as $new) {
            $this->cleanup[] = ABSPATH . $new;
        }
    }

    public function test_renames_executable_backups_and_keeps_the_bytes(): void
    {
        $php   = $this->legacy_backup('20261104-211016-wp-content-themes-t-functions.php', self::PHP);
        $phtml = $this->legacy_backup('20261104-211017-wp-content-x.phtml', self::PHP);
        $multi = $this->legacy_backup('20261104-211018-wp-content-a.php.inc.php5', self::PHP);
        $css   = $this->legacy_backup('20261104-211019-wp-content-style.css', 'body{}');

        Legacy_Backup_Migration::run();
        $this->track_renamed();

        $this->assertSame([], self::executable_names(self::backup_dir()));
        $map = (array) get_option(Legacy_Backup_Migration::RENAMES_OPTION, []);
        foreach ([$php, $phtml, $multi] as $old) {
            $this->assertFileDoesNotExist($old);
            $old_rel = Filesystem_Guard::to_relative($old);
            $this->assertArrayHasKey($old_rel, $map);
            $new = ABSPATH . $map[ $old_rel ];
            $this->assertSame(self::PHP, file_get_contents($new), 'The renamed backup must keep its bytes exactly.');
            $this->assertSame(0, preg_match('/\.(php\d?|phtml|phps|pht|phar)(\.|$)/i', basename($new)), 'No executable extension segment may remain: ' . basename($new));
        }
        $this->assertFileExists($css, 'A non-executable backup is left alone.');
        $this->assertSame(true, (bool) get_site_option(Legacy_Backup_Migration::DONE_OPTION));
    }

    public function test_restore_by_the_old_name_still_returns_the_original(): void
    {
        $old    = $this->legacy_backup('20261104-211016-wp-content-themes-t-functions.php', self::PHP);
        $target = WP_CONTENT_DIR . '/wpmcp-622-target.php';
        file_put_contents($target, "<?php // changed\n");
        $this->cleanup[] = $target;

        Legacy_Backup_Migration::run();
        $this->track_renamed();

        $this->assertTrue(Filesystem_Guard::restore($old, $target));
        $this->assertSame(self::PHP, file_get_contents($target));
    }

    /** Issue #730: read-file by the pre-upgrade name follows the rename, as restore does. */
    public function test_read_file_by_the_old_name_returns_the_content_and_the_new_path(): void
    {
        $text = "<?php\n// backed-up theme code\necho 'x';\n";
        $old  = $this->legacy_backup('20261104-211016-wp-content-themes-t-functions.php', $text);

        Legacy_Backup_Migration::run();
        $this->track_renamed();

        $old_rel = Filesystem_Guard::to_relative($old);
        $new_rel = (string) ((array) get_option(Legacy_Backup_Migration::RENAMES_OPTION, []))[ $old_rel ];
        $this->assertNotSame('', $new_rel);

        $result = (new \WPMCP\Tools\Filesystem\Read_File())->handle(['path' => $old_rel]);

        $this->assertSame($text, $result['content']);
        $this->assertSame($new_rel, $result['path'], 'The response names the new path.');
        $this->assertSame($old_rel, $result['renamed_from']);
    }

    /** Issue #730: a mapping that leaves the backup folder is not followed by read-file either. */
    public function test_read_file_ignores_a_mapping_outside_the_backup_folder(): void
    {
        $outside = WP_CONTENT_DIR . '/wpmcp-730-outside.txt';
        file_put_contents($outside, 'not a backup');
        $this->cleanup[] = $outside;
        wp_mkdir_p(self::backup_dir());
        $missing = self::backup_dir() . '/20261104-000000-gone.php';
        update_option(Legacy_Backup_Migration::RENAMES_OPTION, [Filesystem_Guard::to_relative($missing) => Filesystem_Guard::to_relative($outside)], false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('File not found.');
        (new \WPMCP\Tools\Filesystem\Read_File())->handle(['path' => Filesystem_Guard::to_relative($missing)]);
    }

    public function test_a_mapping_that_points_outside_the_backup_folder_is_ignored(): void
    {
        $outside = WP_CONTENT_DIR . '/wpmcp-622-outside.txt';
        file_put_contents($outside, 'not a backup');
        $this->cleanup[] = $outside;
        $target = WP_CONTENT_DIR . '/wpmcp-622-target2.txt';
        file_put_contents($target, 'kept');
        $this->cleanup[] = $target;
        $missing = self::backup_dir() . '/20261104-000000-gone.php';
        update_option(Legacy_Backup_Migration::RENAMES_OPTION, [Filesystem_Guard::to_relative($missing) => Filesystem_Guard::to_relative($outside)], false);

        $this->assertFalse(Filesystem_Guard::restore($missing, $target));
        $this->assertSame('kept', file_get_contents($target));
    }

    public function test_adds_the_index_php_guard_and_the_htaccess_deny(): void
    {
        $this->legacy_backup('20261104-211016-a.php', self::PHP);
        @unlink(self::backup_dir() . '/.htaccess');
        @unlink(self::backup_dir() . '/index.php');

        Legacy_Backup_Migration::run();
        $this->track_renamed();

        $this->assertFileExists(self::backup_dir() . '/index.php');
        $this->assertStringContainsString('Require all denied', (string) file_get_contents(self::backup_dir() . '/.htaccess'));
    }

    public function test_never_touches_symlinks(): void
    {
        $outside = WP_CONTENT_DIR . '/wpmcp-622-link-target.php';
        file_put_contents($outside, self::PHP);
        $this->cleanup[] = $outside;
        wp_mkdir_p(self::backup_dir());
        $link = self::backup_dir() . '/20261104-211016-link.php';
        if (! @symlink($outside, $link)) {
            $this->markTestSkipped('symlink() is unavailable.');
        }
        $this->cleanup[] = $link;

        Legacy_Backup_Migration::run();
        $this->track_renamed();

        $this->assertTrue(is_link($link), 'The symlink is left where it is.');
        $this->assertFileExists($outside);
        $this->assertSame(self::PHP, file_get_contents($outside));
    }

    public function test_runs_once(): void
    {
        Legacy_Backup_Migration::run();
        $later = $this->legacy_backup('20261104-211020-later.php', self::PHP);

        Legacy_Backup_Migration::maybe_run();

        $this->assertFileExists($later, 'Once done, the routine does not rescan on every request.');
    }

    public function test_converts_every_site_of_a_network(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('Multisite leg only.');
        }
        $this->setExpectedDeprecated('wpmu_new_blog');
        $blog = (int) self::factory()->blog->create();
        switch_to_blog($blog);
        $sub = $this->legacy_backup('20261104-211016-sub.php', self::PHP);
        $sub_dir = self::backup_dir();
        restore_current_blog();

        Legacy_Backup_Migration::run();

        switch_to_blog($blog);
        $this->track_renamed();
        restore_current_blog();
        $this->assertFileDoesNotExist($sub);
        $this->assertSame([], self::executable_names($sub_dir));
    }
}
