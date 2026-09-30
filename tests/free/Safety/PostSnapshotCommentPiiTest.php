<?php

namespace WPMCP\Tests\Free\Safety;

use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Content\Delete_Post;
use WPMCP\Tools\Content\Update_Post;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\Rollback_Session;

/**
 * Issue #362: a force-delete snapshot of a post keeps the post's comments so
 * the rollback can bring them back, and those rows hold each commenter's
 * email, IP and user agent. wpmcp/rollback-operation and
 * wpmcp/rollback-session run at edit_posts, so without moderate_comments the
 * post and its comments come back with those fields blank and the response
 * says so. A moderator gets the exact restore. Refusing the whole rollback is
 * not an option: an author must be able to undo deleting their own post.
 */
class PostSnapshotCommentPiiTest extends \WP_UnitTestCase
{
    private const EMAIL = 'omar.private@example.test';
    private const IP    = '198.51.100.23';
    private const AGENT = 'OmarBrowser/2.0';

    private int $author;
    private int $post_id;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        add_filter('wpmcp_enable_delete_post', '__return_true');

        $this->author  = self::factory()->user->create([ 'role' => 'author' ]);
        $this->post_id = self::factory()->post->create([
            'post_title'  => 'Author post',
            'post_status' => 'publish',
            'post_author' => $this->author,
        ]);
        $comment_id = (int) wp_insert_comment([
            'comment_post_ID'      => $this->post_id,
            'comment_author'       => 'Omar',
            'comment_author_email' => self::EMAIL,
            'comment_author_IP'    => self::IP,
            'comment_agent'        => self::AGENT,
            'comment_content'      => 'Nice post',
            'comment_approved'     => 1,
        ]);
        add_comment_meta($comment_id, 'akismet_as_submitted', [
            'comment_author_email' => self::EMAIL,
            'user_ip'              => self::IP,
        ]);
        add_comment_meta($comment_id, 'helpful_votes', '3');
    }

    protected function tearDown(): void
    {
        remove_filter('wpmcp_enable_delete_post', '__return_true');
        wp_set_current_user(0);
        parent::tearDown();
    }

    /** The author force-deletes their own post through the real tool. */
    private function delete_post(string $session, ?int $user = null): string
    {
        wp_set_current_user($user ?? $this->author);
        $out = (new Delete_Post())->handle([
            'post_id'    => $this->post_id,
            'force'      => true,
            'confirm'    => true,
            'session_id' => $session,
        ]);
        $this->assertNull(get_post($this->post_id));
        return $out['operation_id'];
    }

    private function restored_comment(): \WP_Comment
    {
        $found = get_comments([ 'post_id' => $this->post_id, 'status' => 'all' ]);
        $this->assertCount(1, $found, 'The comment comes back with the post');
        $this->assertSame('Omar', $found[0]->comment_author);
        $this->assertSame('Nice post', $found[0]->comment_content);
        $this->assertSame('3', get_comment_meta((int) $found[0]->comment_ID, 'helpful_votes', true));
        return $found[0];
    }

    private function assert_redacted(array $out): void
    {
        $this->assertSame('Author post', get_post($this->post_id)->post_title, 'The post itself is restored');
        $comment = $this->restored_comment();
        $this->assertSame('', $comment->comment_author_email);
        $this->assertSame('', $comment->comment_author_IP);
        $this->assertSame('', $comment->comment_agent);
        $this->assertSame('', get_comment_meta((int) $comment->comment_ID, 'akismet_as_submitted', true));

        $json = (string) wp_json_encode($out);
        $this->assertStringContainsString('moderate_comments', $json, 'The response says the comments were redacted');
        $this->assertStringNotContainsString(self::EMAIL, $json);
        $this->assertStringNotContainsString(self::IP, $json);
    }

    private function assert_exact(array $out): void
    {
        $comment = $this->restored_comment();
        $this->assertSame(self::EMAIL, $comment->comment_author_email);
        $this->assertSame(self::IP, $comment->comment_author_IP);
        $this->assertSame(self::AGENT, $comment->comment_agent);
        $this->assertSame(self::EMAIL, get_comment_meta((int) $comment->comment_ID, 'akismet_as_submitted', true)['comment_author_email'] ?? null);
        $this->assertSame([], $out['warnings']);
    }

    public function test_an_author_undoes_deleting_their_post_with_commenter_data_blanked(): void
    {
        $operation_id = $this->delete_post('pii-362');

        wp_set_current_user($this->author);
        $this->assertFalse(current_user_can('moderate_comments'));
        $out = (new Rollback_Operation())->handle([ 'operation_id' => $operation_id ]);

        $this->assertTrue($out['restored'], (string) wp_json_encode($out));
        $this->assert_redacted($out);
    }

    public function test_a_moderator_restores_the_comments_exactly(): void
    {
        $operation_id = $this->delete_post('pii-362');

        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $out = (new Rollback_Operation())->handle([ 'operation_id' => $operation_id ]);

        $this->assertTrue($out['restored'], (string) wp_json_encode($out));
        $this->assert_exact($out);
    }

    public function test_an_author_session_rollback_blanks_commenter_data(): void
    {
        $this->delete_post('pii-362-session');

        wp_set_current_user($this->author);
        $out = (new Rollback_Session())->handle([ 'session_id' => 'pii-362-session' ]);

        $this->assertSame(1, $out['restored_count']);
        $this->assert_redacted($out);
    }

    public function test_a_moderator_session_rollback_restores_the_comments_exactly(): void
    {
        // The editor's own session: a session belongs to whoever started it
        // (issue #450).
        $editor = self::factory()->user->create([ 'role' => 'editor' ]);
        $this->delete_post('pii-362-session', $editor);

        wp_set_current_user($editor);
        $out = (new Rollback_Session())->handle([ 'session_id' => 'pii-362-session' ]);

        $this->assertSame(1, $out['restored_count']);
        $this->assert_exact($out);
    }

    public function test_undoing_a_post_edit_leaves_live_comments_alone_and_warns_nothing(): void
    {
        wp_set_current_user($this->author);
        $out = (new Update_Post())->handle([
            'post_id'    => $this->post_id,
            'title'      => 'Edited',
            'session_id' => 'pii-362-edit',
        ]);

        $rolled = (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);

        $this->assertTrue($rolled['restored']);
        $this->assertSame('Author post', get_post($this->post_id)->post_title);
        $this->assertSame([], $rolled['warnings']);
        $this->assertSame(self::EMAIL, $this->restored_comment()->comment_author_email, 'A live comment is never rewritten');
    }
}
