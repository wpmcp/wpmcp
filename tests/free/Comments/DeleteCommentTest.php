<?php

namespace WPMCP\Tests\Free\Comments;

use WPMCP\Tools\Comments\Delete_Comment;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Safety\Snapshot_Store;

class DeleteCommentTest extends \WP_UnitTestCase
{
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        // Restoring a comment snapshot takes moderate_comments (issue #348).
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        add_filter('wpmcp_enable_delete_comment', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_filter('wpmcp_enable_delete_comment', '__return_true');
        foreach ($this->created as $id) {
            wp_delete_comment($id, true);
        }
        $this->created = [];
        parent::tearDown();
    }

    private function comment(): int
    {
        $post_id = self::factory()->post->create();
        $id      = self::factory()->comment->create([
            'comment_post_ID'      => $post_id,
            'comment_content'      => 'Please keep me safe',
            'comment_author'       => 'Grace',
            'comment_author_email' => 'grace@example.com',
            'comment_approved'     => '1',
        ]);
        $this->created[] = $id;
        return $id;
    }

    public function test_delete_disabled_by_default(): void
    {
        remove_filter('wpmcp_enable_delete_comment', '__return_true');
        $id = $this->comment();

        try {
            (new Delete_Comment())->handle(['id' => $id, 'confirm' => true]);
            $this->fail('Expected a refusal while the tool is disabled.');
        } catch (\RuntimeException $e) {
            $this->assertNotNull(get_comment($id), 'Comment must be untouched.');
        }
    }

    public function test_delete_requires_confirm(): void
    {
        $id = $this->comment();
        $this->expectException(\InvalidArgumentException::class);
        (new Delete_Comment())->handle(['id' => $id]);
    }

    public function test_delete_not_found_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Delete_Comment())->handle(['id' => 999999, 'confirm' => true]);
    }

    public function test_delete_force_removes_comment(): void
    {
        $id  = $this->comment();
        $out = (new Delete_Comment())->handle(['id' => $id, 'confirm' => true]);

        $this->assertArrayHasKey('operation_id', $out);
        $this->assertNull(get_comment($id));
    }

    public function test_force_deleted_comment_can_be_rolled_back(): void
    {
        $id      = $this->comment();
        $post_id = (int) get_comment($id)->comment_post_ID;

        $out = (new Delete_Comment())->handle(['id' => $id, 'confirm' => true]);
        $this->assertNull(get_comment($id));

        $rolled_back = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled_back['restored']);

        // WordPress has no import_id for comments, so the ID may differ; the
        // content, author and status must come back on the same post.
        $matches = get_comments(['post_id' => $post_id, 'status' => 'approve']);
        $this->assertCount(1, $matches);
        $this->assertSame('Please keep me safe', $matches[0]->comment_content);
        $this->assertSame('Grace', $matches[0]->comment_author);
    }
}
