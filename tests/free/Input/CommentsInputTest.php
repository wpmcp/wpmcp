<?php

namespace WPMCP\Tests\Free\Input;

use WPMCP\Tools\Comments\Get_Comment;
use WPMCP\Tools\Comments\Edit_Comment;
use WPMCP\Tools\Comments\Moderate_Comment;
use WPMCP\Tools\Comments\Delete_Comment;

/**
 * Input-boundary tests for the Comments domain: missing/invalid comment
 * ids, unknown moderation actions, and missing confirm/disabled gates must
 * all fail cleanly (InvalidArgumentException/RuntimeException), never a
 * fatal or a silent no-op.
 */
class CommentsInputTest extends \WP_UnitTestCase
{
    public function test_get_comment_rejects_missing_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Get_Comment())->handle([]);
    }

    public function test_get_comment_rejects_zero_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Get_Comment())->handle(['id' => 0]);
    }

    public function test_get_comment_rejects_nonexistent_id(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Get_Comment())->handle(['id' => 999999999]);
    }

    public function test_edit_comment_rejects_missing_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Edit_Comment())->handle(['content' => 'x']);
    }

    public function test_edit_comment_rejects_nonexistent_id(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Edit_Comment())->handle(['id' => 999999999, 'content' => 'x']);
    }

    public function test_moderate_comment_rejects_missing_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Moderate_Comment())->handle(['status' => 'approve']);
    }

    public function test_moderate_comment_rejects_unknown_status(): void
    {
        $post_id    = self::factory()->post->create();
        $comment_id = self::factory()->comment->create(['comment_post_ID' => $post_id]);

        $this->expectException(\InvalidArgumentException::class);
        (new Moderate_Comment())->handle(['id' => $comment_id, 'status' => 'not-a-real-action']);
    }

    public function test_moderate_comment_rejects_nonexistent_id(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Moderate_Comment())->handle(['id' => 999999999, 'status' => 'approve']);
    }

    public function test_delete_comment_disabled_by_default(): void
    {
        $post_id    = self::factory()->post->create();
        $comment_id = self::factory()->comment->create(['comment_post_ID' => $post_id]);

        $this->expectException(\RuntimeException::class);
        (new Delete_Comment())->handle(['id' => $comment_id, 'confirm' => true]);
    }

    public function test_delete_comment_requires_confirm_when_enabled(): void
    {
        add_filter('wpmcp_enable_delete_comment', '__return_true');
        // The handler checks edit_comment before confirm (issue #409), so the
        // caller must be someone allowed to delete this comment at all.
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $post_id    = self::factory()->post->create();
        $comment_id = self::factory()->comment->create(['comment_post_ID' => $post_id]);

        $this->expectException(\InvalidArgumentException::class);
        (new Delete_Comment())->handle(['id' => $comment_id]);
    }

    public function test_delete_comment_rejects_nonexistent_id_when_enabled(): void
    {
        add_filter('wpmcp_enable_delete_comment', '__return_true');

        $this->expectException(\InvalidArgumentException::class);
        (new Delete_Comment())->handle(['id' => 999999999, 'confirm' => true]);
    }
}
