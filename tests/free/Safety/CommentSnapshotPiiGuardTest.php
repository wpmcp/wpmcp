<?php

namespace WPMCP\Tests\Free\Safety;

use WPMCP\Memory\Session_Digest;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Snapshot;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Comments\Edit_Comment;
use WPMCP\Tools\List_Operations;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\Rollback_Session;
use WPMCP\Tools\Sync\Change_Set_Builder;

/**
 * Issue #348: a comment snapshot stores the whole comment row, author email
 * and IP included, and wpmcp/rollback-operation and wpmcp/rollback-session
 * run at edit_posts. Restoring one therefore takes moderate_comments, the
 * capability that reading those fields takes everywhere else, and no tool
 * response hands the stored email or IP to a caller without it. Change-set
 * exports never carry them, whoever the caller is.
 */
class CommentSnapshotPiiGuardTest extends \WP_UnitTestCase
{
    private const EMAIL = 'rita.private@example.test';
    private const IP    = '203.0.113.77';

    private int $post_id;
    private int $comment_id;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();

        $this->post_id    = self::factory()->post->create([ 'post_content' => 'before' ]);
        $this->comment_id = (int) wp_insert_comment([
            'comment_post_ID'      => $this->post_id,
            'comment_author'       => 'Rita',
            'comment_author_email' => self::EMAIL,
            'comment_author_IP'    => self::IP,
            'comment_agent'        => 'RitaBrowser/1.0',
            'comment_content'      => 'original text',
            'comment_approved'     => 1,
        ]);
        add_comment_meta($this->comment_id, 'akismet_as_submitted', [
            'comment_author_email' => self::EMAIL,
            'user_ip'              => self::IP,
        ]);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        parent::tearDown();
    }

    private function as_role(string $role): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => $role ]));
    }

    /** Edit the comment through the real tool, so a real comment snapshot row exists. */
    private function edit_comment(string $session = 'pii-348'): string
    {
        $this->as_role('administrator');
        $out = (new Edit_Comment())->handle([
            'id'         => $this->comment_id,
            'content'    => 'edited text',
            'session_id' => $session,
        ]);
        $this->assertSame('edited text', get_comment($this->comment_id)->comment_content);
        return $out['operation_id'];
    }

    private function delete_comment(string $session = 'pii-348'): string
    {
        $this->as_role('administrator');
        $id  = $this->comment_id;
        $out = Safe_Mutation::run(
            [
                'object_type' => 'comment',
                'object_id'   => $id,
                'session_id'  => $session,
                'tool_name'   => 'delete-comment',
                'args'        => [ 'id' => $id ],
            ],
            static function () use ($id): void {
                wp_delete_comment($id, true);
            }
        );
        $this->assertNull(get_comment($id));
        return $out['operation_id'];
    }

    private function assert_no_personal_data($response, string $surface): void
    {
        $json = (string) wp_json_encode($response);
        $this->assertStringNotContainsString(self::EMAIL, $json, $surface . ' exposes the stored author email');
        $this->assertStringNotContainsString(self::IP, $json, $surface . ' exposes the stored author IP');
    }

    public function test_an_author_without_moderate_comments_cannot_roll_back_a_comment_edit(): void
    {
        $operation_id = $this->edit_comment();

        $this->as_role('author');
        $this->assertTrue(current_user_can('edit_posts'));
        $this->assertFalse(current_user_can('moderate_comments'));

        $out = (new Rollback_Operation())->handle([ 'operation_id' => $operation_id ]);

        $this->assertFalse($out['restored'], 'A comment snapshot must not be restorable at edit_posts');
        $this->assertSame('edited text', get_comment($this->comment_id)->comment_content);
        $this->assertStringContainsString('moderate_comments', (string) wp_json_encode($out['warnings']));
        $this->assert_no_personal_data($out, 'rollback-operation');
    }

    public function test_an_author_cannot_resurrect_a_deleted_comment(): void
    {
        $operation_id = $this->delete_comment();
        $count        = (int) get_comments([ 'count' => true, 'status' => 'all' ]);

        $this->as_role('author');
        $out = (new Rollback_Operation())->handle([ 'operation_id' => $operation_id ]);

        $this->assertFalse($out['restored']);
        $this->assertSame($count, (int) get_comments([ 'count' => true, 'status' => 'all' ]), 'No comment is resurrected');
        $this->assert_no_personal_data($out, 'rollback-operation');
    }

    public function test_a_session_rollback_by_an_author_skips_the_comment_but_unwinds_the_rest(): void
    {
        $this->edit_comment('pii-348-session');
        $post = $this->post_id;
        Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $post,
                'session_id'  => 'pii-348-session',
                'tool_name'   => 'update-post',
                'args'        => [],
            ],
            static fn () => wp_update_post([ 'ID' => $post, 'post_content' => 'after' ])
        );

        $this->as_role('author');
        $out = (new Rollback_Session())->handle([ 'session_id' => 'pii-348-session' ]);

        $this->assertSame('before', get_post($post)->post_content, 'The ordinary post still unwinds');
        $this->assertSame('edited text', get_comment($this->comment_id)->comment_content, 'The comment is not restored');
        $this->assertSame(1, $out['restored_count']);
        $this->assertStringContainsString('moderate_comments', (string) wp_json_encode($out['warnings']));
        $this->assert_no_personal_data($out, 'rollback-session');
    }

    public function test_a_moderator_can_still_roll_a_comment_edit_back(): void
    {
        $operation_id = $this->edit_comment();

        // An editor holds moderate_comments: the people the comment tools
        // already admit keep the undo that snapshotting promises them.
        $this->as_role('editor');
        $out = (new Rollback_Operation())->handle([ 'operation_id' => $operation_id ]);

        $this->assertTrue($out['restored'], (string) wp_json_encode($out));
        $restored = get_comment($this->comment_id);
        $this->assertSame('original text', $restored->comment_content);
        $this->assertSame(self::EMAIL, $restored->comment_author_email, 'The restore itself keeps the real row intact');
        $this->assertSame(self::IP, $restored->comment_author_IP);
    }

    public function test_a_moderator_can_still_resurrect_a_deleted_comment(): void
    {
        $operation_id = $this->delete_comment();

        $this->as_role('editor');
        $this->assertTrue(Rollback_Service::restore_operation($operation_id));

        $found = get_comments([ 'post_id' => $this->post_id, 'status' => 'all' ]);
        $this->assertCount(1, $found);
        $this->assertSame(self::EMAIL, $found[0]->comment_author_email);
    }

    public function test_no_history_or_rollback_response_exposes_the_stored_email_or_ip_to_an_author(): void
    {
        $session = 'pii-348-history';
        $this->edit_comment($session);
        $this->delete_comment($session);

        $this->as_role('author');
        $this->assert_no_personal_data((new List_Operations())->handle([ 'limit' => 50 ]), 'list-operations');
        $this->assert_no_personal_data((new List_Operations())->handle([ 'session_id' => $session, 'object_type' => 'comment' ]), 'list-operations (filtered)');
        $digest = Session_Digest::build($session);
        $this->assert_no_personal_data($digest, 'session digest');
        $this->assert_no_personal_data(Session_Digest::text($digest), 'session digest text');
        $this->assert_no_personal_data((new Rollback_Session())->handle([ 'session_id' => $session ]), 'rollback-session');
    }

    public function test_a_change_set_never_carries_comment_email_or_ip_even_for_an_administrator(): void
    {
        $session = 'pii-348-changeset';
        $this->edit_comment($session);

        // Deleting the post snapshots it together with its comments, so the
        // post's before-image holds the author email and IP too.
        $post = self::factory()->post->create([ 'post_content' => 'doomed' ]);
        wp_insert_comment([
            'comment_post_ID'      => $post,
            'comment_author_email' => self::EMAIL,
            'comment_author_IP'    => self::IP,
            'comment_content'      => 'on the doomed post',
        ]);
        Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $post,
                'session_id'  => $session,
                'tool_name'   => 'delete-post',
                'args'        => [],
            ],
            static fn () => wp_delete_post($post, true)
        );

        $this->as_role('administrator');
        $this->assertTrue(current_user_can('moderate_comments'));
        $set = (new Change_Set_Builder())->build([ 'session_id' => $session ]);

        $this->assertNotEmpty($set['excluded'], 'The comment row is reported as excluded');
        $this->assert_no_personal_data($set, 'change set');
    }

    public function test_the_redacted_view_of_a_snapshot_drops_comment_personal_data(): void
    {
        $this->edit_comment();
        $comment = Snapshot::capture('comment', $this->comment_id);
        $this->assertStringContainsString(self::EMAIL, (string) wp_json_encode($comment), 'The stored row keeps the data rollback needs');

        $redacted = Snapshot::without_personal_data($comment);
        $this->assert_no_personal_data($redacted, 'redacted comment snapshot');
        $this->assertStringNotContainsString('RitaBrowser', (string) wp_json_encode($redacted));
        $this->assertSame('edited text', $redacted['data']['comment']['comment_content'], 'Non-personal fields survive');
        $this->assertArrayNotHasKey('akismet_as_submitted', $redacted['data']['meta']);

        $post = Snapshot::capture('post', $this->post_id);
        $this->assertStringContainsString(self::EMAIL, (string) wp_json_encode($post));
        $this->assert_no_personal_data(Snapshot::without_personal_data($post), 'redacted post snapshot');

        $other = Snapshot::capture('option', 'blogname');
        $this->assertSame($other, Snapshot::without_personal_data($other), 'Snapshots without comment data pass through untouched');
    }
}
