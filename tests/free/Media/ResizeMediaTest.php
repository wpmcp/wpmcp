<?php

namespace WPMCP\Tests\Free\Media;

use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Media\Resize_Media;

/**
 * resize-media (issue #64): regenerate specified registered sizes for an
 * image attachment and report the resulting files. Routed through
 * Safe_Mutation (attachment snapshot + physical-file backup) so the
 * operation is restorable like every other mutation.
 */
class ResizeMediaTest extends \WP_UnitTestCase
{
    private int $media_id;

    /** @var string[] */
    private array $cleanup_paths = [];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        // The attachment is uploaded FIRST, the size registered after: this
        // mirrors the real "a plugin/theme added a size later" scenario that
        // resize-media exists for (the size is registered but its file was
        // never generated for older uploads).
        $this->media_id = (int) $this->factory->attachment->create_upload_object(
            DIR_TESTDATA . '/images/canola.jpg'
        );
        add_image_size('wpmcp-spec-size', 120, 90, true);
    }

    protected function tearDown(): void
    {
        remove_image_size('wpmcp-spec-size');
        foreach ($this->cleanup_paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function test_regenerates_a_registered_size_and_reports_resulting_file(): void
    {
        $out = (new Resize_Media())->handle([
            'media_id' => $this->media_id,
            'sizes'    => ['wpmcp-spec-size'],
        ]);

        $this->assertSame($this->media_id, $out['media_id']);
        $this->assertNotEmpty($out['operation_id']);
        $this->assertArrayHasKey('wpmcp-spec-size', $out['sizes']);

        $size = $out['sizes']['wpmcp-spec-size'];
        $this->assertSame(120, $size['width']);
        $this->assertSame(90, $size['height']);
        $this->assertNotEmpty($size['file']);
        $this->assertNotEmpty($size['url']);

        // The generated file physically exists next to the original.
        $dir = dirname((string) get_attached_file($this->media_id));
        $this->assertFileExists($dir . '/' . $size['file']);

        // And the attachment metadata now records the regenerated size.
        $meta = wp_get_attachment_metadata($this->media_id);
        $this->assertArrayHasKey('wpmcp-spec-size', $meta['sizes']);
        $this->assertSame($size['file'], $meta['sizes']['wpmcp-spec-size']['file']);
    }

    public function test_rejects_unknown_size_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Resize_Media())->handle([
            'media_id' => $this->media_id,
            'sizes'    => ['no-such-registered-size'],
        ]);
    }

    public function test_requires_at_least_one_size(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Resize_Media())->handle(['media_id' => $this->media_id, 'sizes' => []]);
    }

    public function test_rejects_non_image_attachment(): void
    {
        $pdf = (int) $this->factory->attachment->create([
            'post_mime_type' => 'application/pdf',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        (new Resize_Media())->handle(['media_id' => $pdf, 'sizes' => ['wpmcp-spec-size']]);
    }

    public function test_rejects_missing_attachment(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Resize_Media())->handle(['media_id' => 999999, 'sizes' => ['wpmcp-spec-size']]);
    }

    public function test_rollback_restores_previous_attachment_metadata(): void
    {
        $before = wp_get_attachment_metadata($this->media_id);
        $this->assertArrayNotHasKey('wpmcp-spec-size', (array) ($before['sizes'] ?? []));

        $out = (new Resize_Media())->handle([
            'media_id' => $this->media_id,
            'sizes'    => ['wpmcp-spec-size'],
        ]);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));

        $after = wp_get_attachment_metadata($this->media_id);
        $this->assertArrayNotHasKey('wpmcp-spec-size', (array) ($after['sizes'] ?? []));
    }

    /**
     * Point the uploads base directory under a regular file so File_Backup
     * cannot create its backup directory. The attachment keeps an absolute
     * attached-file path, so its files are still found where they are.
     */
    private function make_backup_impossible(): void
    {
        update_post_meta($this->media_id, '_wp_attached_file', (string) get_attached_file($this->media_id));
        $blocker               = (string) tempnam(sys_get_temp_dir(), 'wpmcp-blocker-');
        $this->cleanup_paths[] = $blocker;
        add_filter('upload_dir', static function (array $dirs) use ($blocker): array {
            $dirs['basedir'] = $blocker . '/uploads';
            return $dirs;
        });
    }

    public function test_resize_is_refused_when_the_files_cannot_be_backed_up(): void
    {
        $main = (string) get_attached_file($this->media_id);
        $this->make_backup_impossible();

        $refusal = null;
        try {
            (new Resize_Media())->handle([
                'media_id' => $this->media_id,
                'sizes'    => ['wpmcp-spec-size'],
            ]);
        } catch (\RuntimeException $e) {
            $refusal = $e;
        }

        $this->assertNotNull($refusal, 'A resize whose files cannot be backed up must be refused.');
        $this->assertStringContainsString('allow_unrecoverable_files', $refusal->getMessage());
        $this->assertStringContainsString(basename($main), $refusal->getMessage());

        // Nothing changed: the size is not generated and the metadata is untouched.
        $meta = wp_get_attachment_metadata($this->media_id);
        $this->assertArrayNotHasKey('wpmcp-spec-size', (array) ($meta['sizes'] ?? []));
    }

    public function test_resize_with_failed_backup_reports_files_not_recoverable_when_allowed(): void
    {
        $main = (string) get_attached_file($this->media_id);
        $this->make_backup_impossible();

        $out = (new Resize_Media())->handle([
            'media_id'                  => $this->media_id,
            'sizes'                     => ['wpmcp-spec-size'],
            'allow_unrecoverable_files' => true,
        ]);
        $this->cleanup_paths[] = dirname($main) . '/' . $out['sizes']['wpmcp-spec-size']['file'];

        $this->assertArrayHasKey('wpmcp-spec-size', $out['sizes']);
        $this->assertFalse($out['files_recoverable']);
        $this->assertContains(basename($main), $out['unrecoverable_files']);
        $this->assertArrayHasKey('warning', $out);

        // The metadata is still snapshotted and restorable.
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $meta = wp_get_attachment_metadata($this->media_id);
        $this->assertArrayNotHasKey('wpmcp-spec-size', (array) ($meta['sizes'] ?? []));
    }

    public function test_successful_backup_reports_files_recoverable(): void
    {
        $out = (new Resize_Media())->handle([
            'media_id' => $this->media_id,
            'sizes'    => ['wpmcp-spec-size'],
        ]);

        $this->assertTrue($out['files_recoverable']);
        $this->assertArrayNotHasKey('unrecoverable_files', $out);
    }
}
