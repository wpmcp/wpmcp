<?php

namespace WPMCP\Tests\Free\Comments;

use WPMCP\Tools\Comments\Edit_Comment;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Safety\Snapshot_Store;

class EditCommentTest extends \WP_UnitTestCase
{
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        // Restoring a comment snapshot takes moderate_comments (issue #348).
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            wp_delete_comment($id, true);
        }
        $this->created = [];
        parent::tearDown();
    }

    private function comment(array $args = []): int
    {
        $post_id = self::factory()->post->create();
        $id      = self::factory()->comment->create(array_merge([
            'comment_post_ID'      => $post_id,
            'comment_content'      => 'Original body',
            'comment_author'       => 'Ada',
            'comment_author_email' => 'ada@example.com',
        ], $args));
        $this->created[] = $id;
        return $id;
    }

    public function test_edit_updates_content_and_author_fields(): void
    {
        $id = $this->comment();

        $out = (new Edit_Comment())->handle([
            'id'           => $id,
            'content'      => 'Revised body',
            'author'       => 'Ada Lovelace',
            'author_email' => 'ada.l@example.com',
        ]);

        $this->assertContains('content', $out['updated']);
        $this->assertContains('author', $out['updated']);

        $comment = get_comment($id);
        $this->assertSame('Revised body', $comment->comment_content);
        $this->assertSame('Ada Lovelace', $comment->comment_author);
        $this->assertSame('ada.l@example.com', $comment->comment_author_email);
    }

    public function test_edit_with_no_changes_is_a_noop(): void
    {
        $id  = $this->comment();
        $out = (new Edit_Comment())->handle(['id' => $id]);
        $this->assertSame([], $out['updated']);
        $this->assertArrayNotHasKey('operation_id', $out);
    }

    public function test_edit_requires_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Edit_Comment())->handle(['content' => 'x']);
    }

    public function test_edit_not_found_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Edit_Comment())->handle(['id' => 999999, 'content' => 'x']);
    }

    public function test_edit_is_snapshotted_and_rollback_restores_original_content(): void
    {
        $id = $this->comment();

        $out = (new Edit_Comment())->handle(['id' => $id, 'content' => 'Revised body']);
        $this->assertArrayHasKey('operation_id', $out);
        $this->assertSame('Revised body', get_comment($id)->comment_content);

        $rolled_back = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled_back['restored']);

        $this->assertSame('Original body', get_comment($id)->comment_content);
    }
}
