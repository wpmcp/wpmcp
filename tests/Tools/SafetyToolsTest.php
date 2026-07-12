<?php

namespace WPMCP\Tests\Tools;

use WPMCP\Tools\{List_Operations, Rollback_Operation, Update_Blocks};
use WPMCP\Safety\Snapshot_Store;

class SafetyToolsTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
    }

    public function test_list_and_rollback(): void
    {
        $id  = self::factory()->post->create(['post_type' => 'page', 'post_content' => 'V0']);
        $op  = (new Update_Blocks())->handle(['id' => $id, 'blocks' => '<!-- wp:paragraph --><p>V1</p><!-- /wp:paragraph -->', 'session_id' => 's']);
        $list = (new List_Operations())->handle([]);
        $this->assertSame($op['operation_id'], $list['operations'][0]['operation_id']);
        $this->assertArrayNotHasKey('before_blob', $list['operations'][0]);
        $res = (new Rollback_Operation())->handle(['operation_id' => $op['operation_id']]);
        $this->assertTrue($res['restored']);
        $this->assertSame('V0', get_post($id)->post_content);
    }
}
