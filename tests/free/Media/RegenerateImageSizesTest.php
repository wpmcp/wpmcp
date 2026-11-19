<?php

namespace WPMCP\Tests\Free\Media;

use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Media\Get_Media;
use WPMCP\Tools\Media\List_Media;
use WPMCP\Tools\Media\Resize_Media;

/**
 * Issue #716: after a theme change registers new image sizes, older uploads
 * lack those files. get-media and list-media (missing_sizes:true) report the
 * sizes WordPress would generate but has not, and resize-media without
 * "sizes" fills exactly those, for one attachment or a bounded media_ids
 * batch. Every regeneration stays snapshotted, and rollback also removes the
 * files it created.
 */
class RegenerateImageSizesTest extends \WP_UnitTestCase
{
    private int $media_id;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        // Uploaded first, size registered after: the theme-change scenario.
        $this->media_id = $this->upload();
        add_image_size('wpmcp-716-size', 110, 70, true);
    }

    protected function tearDown(): void
    {
        remove_image_size('wpmcp-716-size');
        parent::tearDown();
    }

    private function upload(): int
    {
        return (int) $this->factory->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
    }

    private function size_path(int $id, string $size): string
    {
        $meta = wp_get_attachment_metadata($id);
        return dirname((string) get_attached_file($id)) . '/' . $meta['sizes'][ $size ]['file'];
    }

    public function test_get_media_reports_missing_sizes(): void
    {
        $out = (new Get_Media())->handle(['media_id' => $this->media_id]);

        $this->assertContains('wpmcp-716-size', $out['missing_sizes']);
    }

    public function test_get_media_reports_no_missing_sizes_for_non_images(): void
    {
        $pdf = (int) $this->factory->attachment->create(['post_mime_type' => 'application/pdf']);

        $out = (new Get_Media())->handle(['media_id' => $pdf]);

        $this->assertSame([], $out['missing_sizes']);
    }

    public function test_list_media_missing_sizes_flag_reports_each_image(): void
    {
        $plain = (new List_Media())->handle([]);
        $this->assertArrayNotHasKey('missing_sizes', $plain['items'][0]);

        $out  = (new List_Media())->handle(['missing_sizes' => true]);
        $item = null;
        foreach ($out['items'] as $candidate) {
            if ($this->media_id === $candidate['media_id']) {
                $item = $candidate;
            }
        }

        $this->assertNotNull($item);
        $this->assertContains('wpmcp-716-size', $item['missing_sizes']);
        $this->assertGreaterThanOrEqual(1, $out['with_missing_sizes']);
    }

    public function test_resize_without_sizes_fills_the_missing_ones(): void
    {
        $out = (new Resize_Media())->handle(['media_id' => $this->media_id]);

        $this->assertArrayHasKey('wpmcp-716-size', $out['sizes']);
        $this->assertNotEmpty($out['operation_id']);
        $this->assertFileExists($this->size_path($this->media_id, 'wpmcp-716-size'));

        $after = (new Get_Media())->handle(['media_id' => $this->media_id]);
        $this->assertNotContains('wpmcp-716-size', $after['missing_sizes']);
    }

    public function test_resize_without_sizes_is_a_no_op_when_nothing_is_missing(): void
    {
        (new Resize_Media())->handle(['media_id' => $this->media_id]);

        $out = (new Resize_Media())->handle(['media_id' => $this->media_id]);

        $this->assertSame([], $out['sizes']);
        $this->assertNull($out['operation_id']);
    }

    public function test_rollback_restores_metadata_and_removes_the_created_files(): void
    {
        $out  = (new Resize_Media())->handle(['media_id' => $this->media_id]);
        $path = $this->size_path($this->media_id, 'wpmcp-716-size');
        $this->assertFileExists($path);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));

        $meta = wp_get_attachment_metadata($this->media_id);
        $this->assertArrayNotHasKey('wpmcp-716-size', (array) ($meta['sizes'] ?? []));
        $this->assertFileDoesNotExist($path);
        // The original survives the rollback.
        $this->assertFileExists((string) get_attached_file($this->media_id));
    }

    public function test_batch_regenerates_each_attachment_and_reports_failures(): void
    {
        // Also uploaded before the size existed.
        remove_image_size('wpmcp-716-size');
        $second = $this->upload();
        add_image_size('wpmcp-716-size', 110, 70, true);

        $out = (new Resize_Media())->handle([
            'media_ids'  => [$this->media_id, $second, 999999],
            'session_id' => 'batch-716',
        ]);

        $this->assertCount(3, $out['items']);
        $this->assertSame(2, $out['regenerated']);
        $this->assertSame(1, $out['failed']);

        $by_id = [];
        foreach ($out['items'] as $item) {
            $by_id[ $item['media_id'] ] = $item;
        }
        $this->assertArrayHasKey('wpmcp-716-size', $by_id[ $this->media_id ]['sizes']);
        $this->assertArrayHasKey('wpmcp-716-size', $by_id[ $second ]['sizes']);
        $this->assertNotEmpty($by_id[ $second ]['operation_id']);
        $this->assertNotSame($by_id[ $this->media_id ]['operation_id'], $by_id[ $second ]['operation_id']);
        $this->assertArrayHasKey('error', $by_id[999999]);

        // Each attachment's regeneration is undone on its own.
        $this->assertTrue(Rollback_Service::restore_operation($by_id[ $second ]['operation_id']));
        $meta = wp_get_attachment_metadata($second);
        $this->assertArrayNotHasKey('wpmcp-716-size', (array) ($meta['sizes'] ?? []));
    }

    public function test_batch_is_bounded(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Resize_Media())->handle(['media_ids' => range(1, Resize_Media::MAX_BATCH + 1)]);
    }

    public function test_media_id_and_media_ids_together_are_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Resize_Media())->handle(['media_id' => $this->media_id, 'media_ids' => [$this->media_id]]);
    }
}
