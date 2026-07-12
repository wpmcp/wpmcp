<?php

namespace WPMCP\Tests\Free\Content;

use WPMCP\Tools\Content\Update_Post;
use WPMCP\Safety\{Snapshot_Store, Rollback_Service};

class UpdatePostTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
    }

    public function test_merges_fields_and_clears_featured_image(): void
    {
        $attachment_id = self::factory()->attachment->create_object(['post_type' => 'attachment']);
        $id            = self::factory()->post->create(['post_title' => 'old']);
        set_post_thumbnail($id, $attachment_id);

        $out = (new Update_Post())->handle([
            'post_id'        => $id,
            'title'          => 'new',
            'featured_image' => null,
            'session_id'     => 's1',
        ]);

        $this->assertArrayHasKey('operation_id', $out);
        $this->assertSame($id, $out['post_id']);
        $this->assertSame('new', get_post($id)->post_title);
        $this->assertSame(0, (int) get_post_thumbnail_id($id));
    }

    public function test_rejects_protected_meta(): void
    {
        $id = self::factory()->post->create();
        $this->expectException(\InvalidArgumentException::class);
        (new Update_Post())->handle(['post_id' => $id, 'meta' => ['_edit_lock' => '1']]);
    }

    public function test_not_found_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Update_Post())->handle(['post_id' => 999999, 'title' => 'x']);
    }

    public function test_write_is_safe_wrapped_and_rollback_restores_original(): void
    {
        $id  = self::factory()->post->create(['post_title' => 'original']);
        $out = (new Update_Post())->handle(['post_id' => $id, 'title' => 'changed', 'session_id' => 's1']);

        $this->assertNotNull(Snapshot_Store::get_by_operation($out['operation_id']));
        $this->assertSame('changed', get_post($id)->post_title);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame('original', get_post($id)->post_title);
    }
}
