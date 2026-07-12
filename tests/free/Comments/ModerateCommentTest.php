<?php

namespace WPMCP\Tests\Free\Comments;

use WPMCP\Tools\Comments\Moderate_Comment;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Safety\Snapshot_Store;

class ModerateCommentTest extends \WP_UnitTestCase
{
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            wp_delete_comment($id, true);
        }
        $this->created = [];
        parent::tearDown();
    }

    private function comment(string $approved = '1'): int
    {
        $post_id = self::factory()->post->create();
        $id      = self::factory()->comment->create([
            'comment_post_ID'  => $post_id,
            'comment_approved' => $approved,
        ]);
        $this->created[] = $id;
        return $id;
    }

    public function test_moderate_sets_status(): void
    {
        $id  = $this->comment('1');
        $out = (new Moderate_Comment())->handle(['id' => $id, 'status' => 'spam']);

        $this->assertSame('spam', $out['status']);
        $this->assertSame('spam', wp_get_comment_status($id));
    }

    public function test_moderate_rejects_unknown_status(): void
    {
        $id = $this->comment('1');
        $this->expectException(\InvalidArgumentException::class);
        (new Moderate_Comment())->handle(['id' => $id, 'status' => 'bogus']);
    }

    public function test_moderate_requires_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Moderate_Comment())->handle(['status' => 'approve']);
    }

    public function test_moderate_not_found_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Moderate_Comment())->handle(['id' => 999999, 'status' => 'approve']);
    }

    public function test_moderate_is_snapshotted_and_rollback_restores_prior_status(): void
    {
        $id = $this->comment('1');

        $out = (new Moderate_Comment())->handle(['id' => $id, 'status' => 'spam']);
        $this->assertArrayHasKey('operation_id', $out);
        $this->assertSame('spam', wp_get_comment_status($id));

        $this->assertNotNull(Snapshot_Store::get_by_operation($out['operation_id']));

        $rolled_back = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled_back['restored']);

        $this->assertSame('approved', wp_get_comment_status($id));
    }
}
