<?php

namespace WPMCP\Tests\Admin;

use WPMCP\Admin\Restore_Controller;
use WPMCP\Tools\Update_Blocks;
use WPMCP\Safety\Snapshot_Store;

class RestoreControllerTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
    }

    public function test_restore_returns_true_and_reverts(): void
    {
        $id  = self::factory()->post->create(['post_type' => 'page', 'post_content' => 'V0']);
        $op  = (new Update_Blocks())->handle(['id' => $id, 'blocks' => '<!-- wp:paragraph --><p>V1</p><!-- /wp:paragraph -->', 'session_id' => 's']);
        $res = (new Restore_Controller())->restore($op['operation_id']);
        $this->assertTrue($res['restored']);
        $this->assertSame('V0', get_post($id)->post_content);
    }
}
