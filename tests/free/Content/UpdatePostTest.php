<?php

namespace WPMCP\Tests\Free\Content;

use WPMCP\Tools\Content\Update_Post;
use WPMCP\Safety\Snapshot_Store;

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
}
