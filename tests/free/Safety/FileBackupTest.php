<?php

namespace WPMCP\Tests\Free\Safety;

use WPMCP\Safety\File_Backup;

class FileBackupTest extends \WP_UnitTestCase
{
    private array $created_files = [];

    protected function tearDown(): void
    {
        foreach ($this->created_files as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->created_files = [];
        parent::tearDown();
    }

    /**
     * Write a minimal real (1x1 GIF) image file at an upload-dir-relative
     * path, so tests exercise real files on disk rather than mocks.
     */
    private function write_real_file(string $abs): void
    {
        wp_mkdir_p(dirname($abs));
        // Smallest possible valid GIF, well-formed bytes (not just filler).
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBTAA7');
        file_put_contents($abs, $gif);
        $this->created_files[] = $abs;
    }

    private function make_attachment_with_files(): int
    {
        $uploads = wp_upload_dir();
        $main    = trailingslashit($uploads['path']) . 'sunset-original.jpg';
        $thumb   = trailingslashit($uploads['path']) . 'sunset-original-150x150.jpg';

        $this->write_real_file($main);
        $this->write_real_file($thumb);

        $id = self::factory()->attachment->create_object([
            'file'           => $main,
            'post_mime_type' => 'image/jpeg',
            'post_title'     => 'Sunset',
        ]);

        wp_update_attachment_metadata($id, [
            'width'  => 300,
            'height' => 300,
            'file'   => _wp_relative_upload_path($main),
            'sizes'  => [
                'thumbnail' => [
                    'file'      => basename($thumb),
                    'width'     => 150,
                    'height'    => 150,
                    'mime-type' => 'image/jpeg',
                ],
            ],
        ]);

        return $id;
    }

    public function test_collects_main_file_and_every_intermediate_size(): void
    {
        $id = $this->make_attachment_with_files();

        $files = File_Backup::collect_attachment_files($id);

        $main  = get_attached_file($id);
        $thumb = trailingslashit(dirname($main)) . 'sunset-original-150x150.jpg';

        $this->assertContains($main, $files);
        $this->assertContains($thumb, $files);
        $this->assertCount(2, $files);
    }

    public function test_deduplicates_and_skips_missing_size_files(): void
    {
        $id   = $this->make_attachment_with_files();
        $main = get_attached_file($id);

        // Add a size entry in metadata whose file was never written to disk.
        $meta                     = wp_get_attachment_metadata($id);
        $meta['sizes']['missing'] = [
            'file'      => 'does-not-exist-anywhere.jpg',
            'width'     => 50,
            'height'    => 50,
            'mime-type' => 'image/jpeg',
        ];
        wp_update_attachment_metadata($id, $meta);

        $files = File_Backup::collect_attachment_files($id);

        $missing_path = trailingslashit(dirname($main)) . 'does-not-exist-anywhere.jpg';
        $this->assertNotContains($missing_path, $files);
        $this->assertSame(array_values(array_unique($files)), array_values($files));
    }

    public function test_backup_copies_files_into_protected_per_operation_dir(): void
    {
        $id    = $this->make_attachment_with_files();
        $files = File_Backup::collect_attachment_files($id);
        $op_id = 'op-' . $id;

        $manifest = File_Backup::backup($op_id, $files);

        $uploads = wp_upload_dir();
        $dir     = trailingslashit($uploads['basedir']) . '.wpmcp-backups/' . $op_id;
        $this->created_files[] = $dir . '/.htaccess';
        $this->created_files[] = $dir . '/index.php';

        $this->assertTrue(is_dir($dir));
        $this->assertFileExists($dir . '/.htaccess');
        $this->assertFileExists($dir . '/index.php');
        $this->assertStringContainsString('denied', strtolower((string) file_get_contents($dir . '/.htaccess')));

        $this->assertCount(2, $manifest);
        foreach ($files as $original) {
            $this->assertArrayHasKey($original, $manifest);
            $stored = $dir . '/' . $manifest[ $original ];
            $this->created_files[] = $stored;
            $this->assertFileExists($stored);
            $this->assertFileEquals($original, $stored);
        }

        // Clean up the whole backup dir tree for this operation.
        foreach (array_diff(scandir($dir), ['.', '..']) as $entry) {
            unlink($dir . '/' . $entry);
        }
        rmdir($dir);
        $parent = dirname($dir);
        if (is_dir($parent) && count(scandir($parent)) === 2) {
            rmdir($parent);
        }
    }

    public function test_backup_returns_empty_manifest_for_no_files(): void
    {
        $this->assertSame([], File_Backup::backup('op-empty', []));
    }

    public function test_restore_copies_files_back_to_original_paths_recreating_directories(): void
    {
        $id    = $this->make_attachment_with_files();
        $files = File_Backup::collect_attachment_files($id);

        // Relocate the "originals" under a throwaway subdirectory nested
        // inside the uploads dir, so this test can safely delete the whole
        // subtree afterward without touching files any other test created
        // in the shared uploads/<year>/<month>/ directory.
        $sub_dir       = trailingslashit(dirname($files[0])) . 'wpmcp-restore-test';
        $relocated     = [];
        wp_mkdir_p($sub_dir);
        foreach ($files as $original) {
            $dest = $sub_dir . '/' . basename($original);
            copy($original, $dest);
            $relocated[] = $dest;
        }

        $op_id    = 'op-restore-' . $id;
        $dir      = File_Backup::operation_dir($op_id);
        $manifest = File_Backup::backup($op_id, $relocated);

        // Simulate the irreversible delete: the originals (and the
        // directory they lived in) are gone entirely.
        foreach ($relocated as $path) {
            unlink($path);
        }
        rmdir($sub_dir);
        $this->assertDirectoryDoesNotExist($sub_dir);

        File_Backup::restore($op_id, $manifest);

        foreach ($relocated as $path) {
            $this->assertFileExists($path);
            unlink($path);
        }
        rmdir($sub_dir);

        // Clean up the backup dir tree.
        foreach (array_diff(scandir($dir), ['.', '..']) as $entry) {
            unlink($dir . '/' . $entry);
        }
        rmdir($dir);
        $parent = dirname($dir);
        if (is_dir($parent) && count(scandir($parent)) === 2) {
            rmdir($parent);
        }
    }

    public function test_restore_is_a_noop_for_empty_manifest(): void
    {
        // Must not throw or warn when there is nothing to restore.
        File_Backup::restore('op-nothing', []);
        $this->addToAssertionCount(1);
    }
}
