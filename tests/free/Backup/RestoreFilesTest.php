<?php

namespace WPMCP\Tests\Free\Backup;

use WPMCP\Tools\Backup\Restore_Files;
use WPMCP\Tools\Backup\Site_Archive_Builder;

/**
 * The staged wp-content swap (issue #190), against a throwaway content
 * directory so the test install's own plugins and themes are never moved.
 */
class RestoreFilesTest extends \WP_UnitTestCase
{
    private string $content;
    private string $zip;

    protected function setUp(): void
    {
        parent::setUp();
        $this->content = get_temp_dir() . 'wpmcp-files-' . wp_generate_password(6, false);
        wp_mkdir_p($this->content);
        $this->zip = $this->content . '-archive.zip';
    }

    protected function tearDown(): void
    {
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
        (new \WP_Filesystem_Direct(null))->delete($this->content, true);
        if (is_file($this->zip)) {
            unlink($this->zip);
        }
        parent::tearDown();
    }

    private function archive(array $entries): string
    {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($this->zip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString('manifest.json', '{}');
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
        return $this->zip;
    }

    private function entries(array $entries): array
    {
        $zip = new \ZipArchive();
        $zip->open($this->archive($entries));
        try {
            return Restore_Files::entries($zip);
        } finally {
            $zip->close();
        }
    }

    public function test_only_wp_content_entries_are_extracted(): void
    {
        $names = $this->entries([
            'db.sql'                          => '',
            'wp-content/themes/t/style.css'   => 'x',
            'wp-content/wpmcp-restore/x.txt'  => 'never restore the restore area',
        ]);

        $this->assertSame(['wp-content/themes/t/style.css'], $names);
    }

    public static function unsafe_names(): array
    {
        return [
            'parent segment' => ['wp-content/../wp-config.php', 'outside wp-content'],
            'dot segment'    => ['wp-content/./x.php', 'outside wp-content'],
            'backslash'      => ['wp-content/themes\\..\\x.php', 'unsafe name'],
            'drive letter'   => ['wp-content/C:/x.php', 'unsafe name'],
        ];
    }

    /** @dataProvider unsafe_names */
    public function test_unsafe_entry_names_refuse_the_whole_archive(string $name, string $reason): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($reason);
        $this->entries(['wp-content/ok.txt' => 'ok', $name => 'x']);
    }

    public function test_stage_then_swap_replaces_the_tree_and_keeps_the_old_one(): void
    {
        wp_mkdir_p($this->content . '/themes/old');
        file_put_contents($this->content . '/themes/old/style.css', 'old');
        file_put_contents($this->content . '/index.php', 'old index');
        wp_mkdir_p($this->content . '/languages');

        $files  = new Restore_Files($this->content);
        $staged = $files->stage($this->archive([
            'wp-content/themes/new/style.css' => 'new',
            'wp-content/index.php'            => 'new index',
        ]));

        $this->assertFileExists($staged . '/themes/new/style.css');
        $this->assertFileExists($this->content . '/themes/old/style.css', 'Staging touches nothing live.');

        $out = $files->swap($staged);
        $files->discard($staged);

        $this->assertEqualsCanonicalizing(['themes', 'index.php'], $out['swapped']);
        $this->assertSame('new', file_get_contents($this->content . '/themes/new/style.css'));
        $this->assertSame('new index', file_get_contents($this->content . '/index.php'));
        $this->assertDirectoryExists($this->content . '/languages', 'Entries the archive does not carry are left alone.');
        $this->assertSame('old', file_get_contents($out['previous_tree'] . '/themes/old/style.css'));
        $this->assertDirectoryDoesNotExist(dirname($staged));
        $this->assertFileExists($this->content . '/' . Restore_Files::DIR_NAME . '/.htaccess');
    }

    public function test_an_archive_without_wp_content_cannot_be_staged(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('holds no wp-content files');
        (new Restore_Files($this->content))->stage($this->archive(['db.sql' => '']));
    }

    public function test_a_failed_swap_rolls_every_move_back(): void
    {
        $content = $this->content;
        wp_mkdir_p($content . '/themes/old');
        file_put_contents($content . '/themes/old/style.css', 'old');
        wp_mkdir_p($content . '/uploads/wpmcp-site-backups');
        file_put_contents($content . '/uploads/wpmcp-site-backups/keep.txt', 'live backup');

        $uploads = static function (array $dirs) use ($content): array {
            $dirs['basedir'] = $content . '/uploads';
            return $dirs;
        };
        add_filter('upload_dir', $uploads);

        try {
            $files  = new Restore_Files($content);
            $staged = $files->stage($this->archive([
                'wp-content/themes/new/style.css'              => 'new',
                'wp-content/uploads/wpmcp-site-backups/x.txt' => 'archived copy',
            ]));

            // "themes" and "uploads" are swapped in order; carrying the live
            // backup directory back over the archived copy parks the copy
            // first, and a pre-existing park destination makes that move
            // fail after both swaps have already happened.
            wp_mkdir_p(dirname($staged) . '/replaced/uploads/wpmcp-site-backups');

            try {
                $files->swap($staged);
                $this->fail('The swap must fail when a move cannot complete.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Every file move was rolled back', $e->getMessage());
            }

            $this->assertSame('old', file_get_contents($content . '/themes/old/style.css'));
            $this->assertFileDoesNotExist($content . '/themes/new/style.css');
            $this->assertSame('live backup', file_get_contents($content . '/uploads/wpmcp-site-backups/keep.txt'));
            $this->assertFileDoesNotExist($content . '/uploads/wpmcp-site-backups/x.txt');
        } finally {
            remove_filter('upload_dir', $uploads);
        }
    }

    public function test_the_restore_area_is_never_archived(): void
    {
        $this->assertContains(Restore_Files::DIR_NAME, Site_Archive_Builder::EXCLUDED_DIRS);
        $this->assertFalse(Site_Archive_Builder::should_archive(WP_CONTENT_DIR . '/' . Restore_Files::DIR_NAME . '/previous-x/plugins/p.php'));
    }

    public function test_the_running_plugin_and_backup_directories_are_kept_live(): void
    {
        $keep = (new Restore_Files())->keep_live();

        $this->assertNotEmpty(array_filter($keep, static fn(string $p): bool => str_ends_with($p, '/wpmcp-site-backups')));
    }
}
