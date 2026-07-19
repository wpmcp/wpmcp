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

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        add_image_size('wpmcp-spec-size', 120, 90, true);
        $this->media_id = (int) $this->factory->attachment->create_upload_object(
            DIR_TESTDATA . '/images/canola.jpg'
        );
    }

    protected function tearDown(): void
    {
        remove_image_size('wpmcp-spec-size');
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
}
