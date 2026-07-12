<?php

namespace WPMCP\Tests\Free\Media;

use WPMCP\Tools\Media\Update_Media;
use WPMCP\Safety\{Snapshot_Store, Rollback_Service};

class UpdateMediaTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
    }

    public function test_writes_only_passed_fields(): void
    {
        $id = self::factory()->attachment->create_object([
            'post_title'   => 'Sunset',
            'post_excerpt' => 'old caption',
            'post_content' => 'old description',
        ]);

        $out = (new Update_Media())->handle([
            'media_id' => $id,
            'alt'      => 'Sunset over the sea',
            'title'    => 'Sunset HQ',
        ]);

        $this->assertContains('alt', $out['updated']);
        $this->assertContains('title', $out['updated']);
        $this->assertNotContains('caption', $out['updated']);
        $this->assertSame('Sunset over the sea', get_post_meta($id, '_wp_attachment_image_alt', true));
        $this->assertSame('Sunset HQ', get_post($id)->post_title);
        $this->assertSame('old caption', get_post($id)->post_excerpt);
    }

    public function test_not_found_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Update_Media())->handle(['media_id' => 999999, 'alt' => 'x']);
    }

    public function test_rejects_non_attachment(): void
    {
        $id = self::factory()->post->create();
        $this->expectException(\InvalidArgumentException::class);
        (new Update_Media())->handle(['media_id' => $id, 'alt' => 'x']);
    }

    public function test_write_is_safe_wrapped_and_rollback_restores_alt_text(): void
    {
        $id = self::factory()->attachment->create_object(['post_title' => 'Sunset']);
        update_post_meta($id, '_wp_attachment_image_alt', 'original alt');

        $out = (new Update_Media())->handle(['media_id' => $id, 'alt' => 'changed alt', 'session_id' => 's1']);

        $this->assertNotNull(Snapshot_Store::get_by_operation($out['operation_id']));
        $this->assertSame('changed alt', get_post_meta($id, '_wp_attachment_image_alt', true));

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame('original alt', get_post_meta($id, '_wp_attachment_image_alt', true));
    }

    /**
     * Snapshot::capture() records ALL post meta, which includes
     * _wp_attachment_metadata (dimensions, sizes, file path). A rollback must
     * not disturb it even though update-media never touches that key itself.
     */
    public function test_rollback_leaves_attachment_metadata_untouched(): void
    {
        $id = self::factory()->attachment->create_object(['post_title' => 'Sunset']);
        $original_metadata = [
            'width'  => 1200,
            'height' => 800,
            'file'   => 'sunset.jpg',
        ];
        update_post_meta($id, '_wp_attachment_metadata', $original_metadata);

        $out = (new Update_Media())->handle(['media_id' => $id, 'title' => 'changed title', 'session_id' => 's1']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));

        $this->assertSame('Sunset', get_post($id)->post_title);
        $this->assertSame($original_metadata, get_post_meta($id, '_wp_attachment_metadata', true));
    }
}
