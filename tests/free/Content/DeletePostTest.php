<?php

namespace WPMCP\Tests\Free\Content;

use WPMCP\Tools\Content\Delete_Post;
use WPMCP\Safety\Snapshot_Store;

class DeletePostTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
    }

    public function test_trashes_by_default(): void
    {
        $id = self::factory()->post->create(['post_status' => 'publish']);

        $out = (new Delete_Post())->handle(['post_id' => $id]);

        $this->assertSame('trashed', $out['deleted']);
        $this->assertSame('trash', get_post($id)->post_status);
    }

    public function test_force_deletes_permanently(): void
    {
        $id = self::factory()->post->create(['post_status' => 'publish']);

        $out = (new Delete_Post())->handle(['post_id' => $id, 'force' => true, 'session_id' => 's1']);

        $this->assertSame('deleted', $out['deleted']);
        $this->assertArrayHasKey('operation_id', $out);
        $this->assertNull(get_post($id));
        $this->assertNotNull(Snapshot_Store::get_by_operation($out['operation_id']));
    }
}
