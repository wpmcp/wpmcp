<?php

namespace WPMCP\Tests\Free\Comments;

use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tests\Free\Platform\RegisteredAbilities;
use WPMCP\Tools\Comments\Create_Comment;
use WPMCP\Tools\Comments\Moderate_Comment;
use WPMCP\Tools\Comments\Reply_To_Comment;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\Rollback_Session;

/**
 * create-comment and reply-to-comment (issue #284): post as the current
 * user, approved only for moderators, recorded so a rollback trashes the
 * created comment.
 */
class CreateCommentTest extends \WP_UnitTestCase
{
    private int $admin;
    private int $post;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        $this->admin = self::factory()->user->create(['role' => 'administrator', 'display_name' => 'Site Owner']);
        wp_set_current_user($this->admin);
        $this->post = self::factory()->post->create(['post_status' => 'publish']);
    }

    public function test_creates_a_comment_as_the_current_user(): void
    {
        $out = (new Create_Comment())->handle(['post_id' => $this->post, 'content' => 'Thanks for reading']);

        $comment = get_comment($out['id']);
        $this->assertSame($this->post, (int) $comment->comment_post_ID);
        $this->assertSame($this->admin, (int) $comment->user_id);
        $this->assertSame('Site Owner', $comment->comment_author);
        $this->assertSame(get_userdata($this->admin)->user_email, $comment->comment_author_email);
        $this->assertSame('Thanks for reading', $comment->comment_content);
        $this->assertSame('approved', $out['status'], 'A moderator posts approved by default');
        $this->assertArrayHasKey('operation_id', $out);
    }

    public function test_an_explicit_unapproved_status_is_held(): void
    {
        $out = (new Create_Comment())->handle(['post_id' => $this->post, 'content' => 'Held', 'status' => 'unapproved']);

        $this->assertSame('unapproved', wp_get_comment_status($out['id']));
    }

    public function test_approved_requires_moderate_comments(): void
    {
        $author = self::factory()->user->create(['role' => 'author']);
        wp_set_current_user($author);
        $own = self::factory()->post->create(['post_status' => 'publish', 'post_author' => $author]);

        $this->expectException(\RuntimeException::class);
        (new Create_Comment())->handle(['post_id' => $own, 'content' => 'Hi', 'status' => 'approved']);
    }

    public function test_a_non_moderator_defaults_to_unapproved(): void
    {
        $author = self::factory()->user->create(['role' => 'author']);
        wp_set_current_user($author);
        $own = self::factory()->post->create(['post_status' => 'publish', 'post_author' => $author]);

        $out = (new Create_Comment())->handle(['post_id' => $own, 'content' => 'Hi']);

        $this->assertSame('unapproved', $out['status']);
        $this->assertSame($author, (int) get_comment($out['id'])->user_id);
    }

    public function test_refuses_a_post_the_caller_cannot_edit(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'author']));

        $this->expectException(\RuntimeException::class);
        (new Create_Comment())->handle(['post_id' => $this->post, 'content' => 'Hi']);
    }

    public function test_refuses_a_draft_post(): void
    {
        $draft = self::factory()->post->create(['post_status' => 'draft']);

        $this->expectException(\InvalidArgumentException::class);
        (new Create_Comment())->handle(['post_id' => $draft, 'content' => 'Hi']);
    }

    public function test_refuses_empty_content_and_unknown_status(): void
    {
        try {
            (new Create_Comment())->handle(['post_id' => $this->post, 'content' => '  ']);
            $this->fail('Empty content must be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('content', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        (new Create_Comment())->handle(['post_id' => $this->post, 'content' => 'Hi', 'status' => 'spam']);
    }

    public function test_reply_threads_under_the_parent_on_its_post(): void
    {
        $parent = self::factory()->comment->create(['comment_post_ID' => $this->post, 'comment_approved' => '1']);

        $out = (new Reply_To_Comment())->handle(['id' => $parent, 'content' => 'Glad it helped', 'session_id' => 'r']);

        $reply = get_comment($out['id']);
        $this->assertSame($parent, (int) $reply->comment_parent);
        $this->assertSame($this->post, (int) $reply->comment_post_ID);
        $this->assertSame($this->admin, (int) $reply->user_id);

        $rows = Snapshot_Store::list_by_session('r');
        $this->assertCount(1, $rows);
        $this->assertSame('comment_create', $rows[0]['object_type']);
        $this->assertSame('reply-to-comment', $rows[0]['tool_name']);
        $this->assertSame($out['id'], (int) $rows[0]['object_id']);
    }

    public function test_reply_refuses_a_spam_parent_and_a_missing_one(): void
    {
        $spam = self::factory()->comment->create(['comment_post_ID' => $this->post, 'comment_approved' => 'spam']);

        try {
            (new Reply_To_Comment())->handle(['id' => $spam, 'content' => 'Hi']);
            $this->fail('A spam parent must be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('spam', $e->getMessage());
        }

        $this->expectException(\RuntimeException::class);
        (new Reply_To_Comment())->handle(['id' => 999999, 'content' => 'Hi']);
    }

    public function test_create_writes_a_creation_row_under_the_session(): void
    {
        $out = (new Create_Comment())->handle(['post_id' => $this->post, 'content' => 'Hi', 'session_id' => 'c']);

        $rows = Snapshot_Store::list_by_session('c');
        $this->assertCount(1, $rows);
        $this->assertSame('comment_create', $rows[0]['object_type']);
        $this->assertSame('create-comment', $rows[0]['tool_name']);
        $this->assertSame($out['operation_id'], $rows[0]['operation_id']);
        $this->assertContains('comment_create', Rollback_Service::restorable_object_types());
    }

    public function test_rollback_operation_trashes_the_created_comment(): void
    {
        $out = (new Create_Comment())->handle(['post_id' => $this->post, 'content' => 'Oops', 'session_id' => 's']);

        $result = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);

        $this->assertTrue($result['restored']);
        $this->assertNotNull(get_comment($out['id']), 'Rollback of a creation must never hard delete');
        $this->assertSame('trash', wp_get_comment_status($out['id']));
    }

    public function test_rollback_session_trashes_the_created_comment_even_after_moderation(): void
    {
        $out = (new Create_Comment())->handle(['post_id' => $this->post, 'content' => 'Oops', 'session_id' => 'm']);
        (new Moderate_Comment())->handle(['id' => $out['id'], 'status' => 'unapprove', 'session_id' => 'm']);

        (new Rollback_Session())->handle(['session_id' => 'm']);

        $this->assertSame('trash', wp_get_comment_status($out['id']), 'The creation is the oldest state of the comment');
    }

    public function test_rollback_leaves_a_comment_that_reclaimed_the_id_alone(): void
    {
        $out = (new Create_Comment())->handle(['post_id' => $this->post, 'content' => 'Mine', 'session_id' => 'x']);
        global $wpdb;
        $wpdb->update($wpdb->comments, ['comment_date_gmt' => '2001-01-01 00:00:00'], ['comment_ID' => $out['id']]);
        clean_comment_cache($out['id']);

        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);

        $this->assertSame('approved', wp_get_comment_status($out['id']));
    }

    public function test_both_are_registered_as_free_comment_create_abilities(): void
    {
        $found = [];
        foreach (RegisteredAbilities::all() as $ability) {
            if (in_array($ability->name, ['wpmcp/create-comment', 'wpmcp/reply-to-comment'], true)) {
                $found[ $ability->name ] = $ability;
            }
        }

        $this->assertCount(2, $found);
        foreach ($found as $name => $ability) {
            $this->assertSame('free', $ability->tier, $name);
            $this->assertSame('comments', $ability->domain, $name);
            $this->assertSame('create', $ability->operation, $name);
            $this->assertFalse($ability->read_only_hint, $name);
            $this->assertFalse($ability->destructive_hint, $name);
        }
    }
}
