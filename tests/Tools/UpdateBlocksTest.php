<?php

namespace WPMCP\Tests\Tools;

use WPMCP\Tools\Update_Blocks;
use WPMCP\Safety\{Snapshot_Store, Snapshot_Store as S, Mutation_Failed};

class UpdateBlocksTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
    }

    public function test_updates_content_and_returns_operation_id(): void
    {
        $id     = self::factory()->post->create(['post_type' => 'page', 'post_content' => 'OLD']);
        $blocks = '<!-- wp:paragraph --><p>new</p><!-- /wp:paragraph -->';
        $out    = (new Update_Blocks())->handle(['id' => $id, 'blocks' => $blocks, 'session_id' => 's1']);
        $this->assertArrayHasKey('operation_id', $out);
        $this->assertSame($blocks, get_post($id)->post_content);
        $this->assertNotNull(S::get_by_operation($out['operation_id']));
    }

    public function test_invalid_markup_rolls_back(): void
    {
        $original = '<!-- wp:paragraph --><p>ORIGINAL</p><!-- /wp:paragraph -->';
        $id       = self::factory()->post->create(['post_type' => 'page', 'post_content' => $original]);
        $this->expectException(Mutation_Failed::class);
        try {
            (new Update_Blocks())->handle(['id' => $id, 'blocks' => 'not a block at all', 'session_id' => 's1']);
        } finally {
            $this->assertSame($original, get_post($id)->post_content);
        }
    }
}
