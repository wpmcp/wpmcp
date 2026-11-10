<?php

namespace WPMCP\Tests\Free\Safety;

use WPMCP\Admin\Audit_Log_Page;
use WPMCP\Admin\History_Page;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Comments\Delete_Comment;
use WPMCP\Tools\Comments\Edit_Comment;
use WPMCP\Tools\Comments\Moderate_Comment;
use WPMCP\Tools\Content\Create_Post;
use WPMCP\Tools\Content\Update_Post;
use WPMCP\Tools\List_Operations;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\Rollback_Session;
use WPMCP\Tools\Settings\Update_Settings;

/**
 * Issue #593: an undo applied an operation that was already undone a second
 * time. A repeated rollback-operation of an edit wiped every newer edit of
 * the object, and a repeated rollback of a delete-comment inserted one more
 * copy of the comment each time. An undone operation is now recorded as
 * such in the ledger (rolled_back_at), refused with the reason on a second
 * rollback-operation, skipped by rollback-session, and reported as no longer
 * restorable by list-operations and the admin screens.
 */
class IdempotentRollbackTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        // Restoring a comment snapshot takes moderate_comments (issue #348);
        // update-settings takes manage_options.
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        add_filter('wpmcp_enable_delete_comment', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_filter('wpmcp_enable_delete_comment', '__return_true');
        parent::tearDown();
    }

    private function comment(int $post_id, string $content = 'Original body'): int
    {
        return self::factory()->comment->create([
            'comment_post_ID'      => $post_id,
            'comment_content'      => $content,
            'comment_author'       => 'Grace',
            'comment_author_email' => 'grace@example.com',
            'comment_approved'     => '1',
        ]);
    }

    private function undo(string $operation_id): array
    {
        return (new Rollback_Operation())->handle(['operation_id' => $operation_id]);
    }

    private function approved_comments(int $post_id): array
    {
        return get_comments(['post_id' => $post_id, 'status' => 'approve']);
    }

    // ---- rollback-operation -------------------------------------------------

    /** Case 1 of the issue: a repeated undo of an edit must not wipe a newer edit. */
    public function test_a_second_undo_of_a_comment_edit_is_refused_and_keeps_the_newer_edit(): void
    {
        $post_id = self::factory()->post->create();
        $id      = $this->comment($post_id);

        $a = (new Edit_Comment())->handle(['id' => $id, 'content' => 'Version A']);
        $this->assertTrue($this->undo($a['operation_id'])['restored']);
        $this->assertSame('Original body', get_comment($id)->comment_content);

        (new Edit_Comment())->handle(['id' => $id, 'content' => 'Version B']);
        (new Moderate_Comment())->handle(['id' => $id, 'status' => 'unapprove']);

        $again = $this->undo($a['operation_id']);

        $this->assertFalse($again['restored'], 'An already undone operation must not be applied again.');
        $this->assertNotEmpty($again['warnings']);
        $this->assertStringContainsString('already undone', $again['warnings'][0]);
        $this->assertStringContainsString($a['operation_id'], $again['warnings'][0]);
        $comment = get_comment($id);
        $this->assertSame('Version B', $comment->comment_content, 'The newer edit must survive.');
        $this->assertSame('0', $comment->comment_approved, 'The newer moderation must survive.');
    }

    /** Case 2 of the issue: a repeated undo of a delete must not duplicate the comment. */
    public function test_repeating_the_undo_of_a_comment_delete_does_not_create_copies(): void
    {
        $post_id = self::factory()->post->create();
        $id      = $this->comment($post_id, 'Please keep me safe');

        $out = (new Delete_Comment())->handle(['id' => $id, 'confirm' => true]);
        $this->assertCount(0, $this->approved_comments($post_id));

        $this->assertTrue($this->undo($out['operation_id'])['restored']);
        $second = $this->undo($out['operation_id']);
        $third  = $this->undo($out['operation_id']);

        $this->assertFalse($second['restored']);
        $this->assertFalse($third['restored']);
        $this->assertStringContainsString('already undone', $second['warnings'][0]);
        $comments = $this->approved_comments($post_id);
        $this->assertCount(1, $comments, 'One deleted comment comes back exactly once.');
        $this->assertSame('Please keep me safe', $comments[0]->comment_content);
        $this->assertSame('1', (string) get_post($post_id)->comment_count, 'The post comment count stays at one.');
    }

    /** Another object type through the same restore_operation(): a post. */
    public function test_a_second_undo_of_a_post_edit_is_refused_and_keeps_the_newer_edit(): void
    {
        $post_id = self::factory()->post->create(['post_content' => 'V0']);

        $a = (new Update_Post())->handle(['post_id' => $post_id, 'content' => 'V1']);
        $this->assertTrue(Rollback_Service::restore_operation($a['operation_id']));
        $this->assertSame('V0', get_post($post_id)->post_content);

        (new Update_Post())->handle(['post_id' => $post_id, 'content' => 'V2']);

        $this->assertFalse(Rollback_Service::restore_operation($a['operation_id']));
        $this->assertStringContainsString('already undone', Rollback_Service::take_warnings()[0]);
        $this->assertSame('V2', get_post($post_id)->post_content);
    }

    /** And an option, which has no numeric object id in the ledger. */
    public function test_a_second_undo_of_an_option_write_is_refused(): void
    {
        update_option('blogname', 'Before');
        $a = (new Update_Settings())->handle(['settings' => ['blogname' => 'First']]);
        $op = $a['operation_ids'][0] ?? null;
        $this->assertIsString($op);

        $this->assertTrue($this->undo($op)['restored']);
        $this->assertSame('Before', get_option('blogname'));
        update_option('blogname', 'Newer');

        $this->assertFalse($this->undo($op)['restored']);
        $this->assertSame('Newer', get_option('blogname'));
    }

    /**
     * Pinned existing behaviour, NOT changed by #593: undoing an older
     * operation that was never undone still restores its before-image over
     * a newer write to the same object. Whether that should warn or refuse
     * is a product decision left open in the issue.
     */
    public function test_undoing_an_older_never_undone_edit_still_overwrites_a_newer_edit(): void
    {
        $post_id = self::factory()->post->create();
        $id      = $this->comment($post_id);

        $a = (new Edit_Comment())->handle(['id' => $id, 'content' => 'Version A']);
        (new Edit_Comment())->handle(['id' => $id, 'content' => 'Version B']);

        $this->assertTrue($this->undo($a['operation_id'])['restored']);
        $this->assertSame('Original body', get_comment($id)->comment_content);
    }

    // ---- rollback-session ---------------------------------------------------

    public function test_a_second_session_rollback_restores_nothing_and_keeps_newer_work(): void
    {
        $post_id = self::factory()->post->create(['post_content' => 'V0']);
        $cid     = $this->comment($post_id, 'Keep me');

        (new Update_Post())->handle(['post_id' => $post_id, 'content' => 'V1', 'session_id' => 'run-1']);
        (new Delete_Comment())->handle(['id' => $cid, 'confirm' => true, 'session_id' => 'run-1']);

        $first = (new Rollback_Session())->handle(['session_id' => 'run-1']);
        $this->assertSame(2, $first['restored_count']);
        $this->assertSame('V0', get_post($post_id)->post_content);
        $this->assertCount(1, $this->approved_comments($post_id));

        // Newer work outside the session.
        wp_update_post(['ID' => $post_id, 'post_content' => 'Later']);

        $second = (new Rollback_Session())->handle(['session_id' => 'run-1']);

        $this->assertSame(0, $second['restored_count']);
        $this->assertNotEmpty($second['warnings']);
        $this->assertStringContainsString('already undone', implode(' ', $second['warnings']));
        $this->assertSame('Later', get_post($post_id)->post_content);
        $this->assertCount(1, $this->approved_comments($post_id), 'The deleted comment is not resurrected twice.');
    }

    public function test_a_session_rollback_skips_an_operation_already_undone_on_its_own(): void
    {
        $post_id = self::factory()->post->create();
        $cid     = $this->comment($post_id, 'Keep me');

        $del = (new Delete_Comment())->handle(['id' => $cid, 'confirm' => true, 'session_id' => 'run-2']);
        $this->assertTrue($this->undo($del['operation_id'])['restored']);
        $this->assertCount(1, $this->approved_comments($post_id));

        $out = (new Rollback_Session())->handle(['session_id' => 'run-2']);

        $this->assertSame(0, $out['restored_count']);
        $this->assertCount(1, $this->approved_comments($post_id), 'The single undo is not applied a second time.');
    }

    public function test_an_operation_undone_by_its_session_is_refused_on_its_own(): void
    {
        $post_id = self::factory()->post->create(['post_content' => 'V0']);
        $a       = (new Update_Post())->handle(['post_id' => $post_id, 'content' => 'V1', 'session_id' => 'run-3']);

        $this->assertSame(1, (new Rollback_Session())->handle(['session_id' => 'run-3'])['restored_count']);
        wp_update_post(['ID' => $post_id, 'post_content' => 'Later']);

        $again = $this->undo($a['operation_id']);

        $this->assertFalse($again['restored']);
        $this->assertStringContainsString('already undone', $again['warnings'][0]);
        $this->assertSame('Later', get_post($post_id)->post_content);
    }

    /**
     * Two edits in one run, the OLDER one undone on its own (which, as
     * pinned above, also put the object back before the newer edit). The
     * session rollback must not then restore the newer edit's before-image,
     * which is the undone edit's own content.
     */
    public function test_a_session_rollback_does_not_bring_back_an_edit_that_was_undone_on_its_own(): void
    {
        $post_id = self::factory()->post->create(['post_content' => 'V0']);
        $a       = (new Update_Post())->handle(['post_id' => $post_id, 'content' => 'V1', 'session_id' => 'run-4']);
        (new Update_Post())->handle(['post_id' => $post_id, 'content' => 'V2', 'session_id' => 'run-4']);

        $this->assertTrue(Rollback_Service::restore_operation($a['operation_id']));
        $this->assertSame('V0', get_post($post_id)->post_content);

        $out = (new Rollback_Session())->handle(['session_id' => 'run-4']);

        $this->assertSame(1, $out['restored_count']);
        $this->assertSame('V0', get_post($post_id)->post_content, 'V1 was undone and must not come back.');
    }

    /** A creation undone on its own keeps a later edit of the post from untrashing it. */
    public function test_a_session_rollback_after_undoing_a_creation_leaves_the_post_in_the_trash(): void
    {
        $created = (new Create_Post())->handle(['title' => 'Fresh', 'status' => 'publish', 'session_id' => 'run-5']);
        (new Update_Post())->handle(['post_id' => $created['post_id'], 'content' => 'Edited', 'session_id' => 'run-5']);

        $this->assertTrue(Rollback_Service::restore_operation($created['operation_id']));
        $this->assertSame('trash', get_post_status($created['post_id']));

        (new Rollback_Session())->handle(['session_id' => 'run-5']);

        $this->assertSame('trash', get_post_status($created['post_id']));
    }

    /** Writes added to a session after it was rolled back are undone by the next rollback, and only they. */
    public function test_a_second_session_rollback_undoes_only_writes_made_since_the_first(): void
    {
        $post_id = self::factory()->post->create(['post_content' => 'V0']);
        $cid     = $this->comment($post_id, 'Keep me');

        (new Delete_Comment())->handle(['id' => $cid, 'confirm' => true, 'session_id' => 'run-6']);
        $this->assertSame(1, (new Rollback_Session())->handle(['session_id' => 'run-6'])['restored_count']);

        (new Update_Post())->handle(['post_id' => $post_id, 'content' => 'V1', 'session_id' => 'run-6']);
        $out = (new Rollback_Session())->handle(['session_id' => 'run-6']);

        $this->assertSame(1, $out['restored_count']);
        $this->assertSame('V0', get_post($post_id)->post_content);
        $this->assertCount(1, $this->approved_comments($post_id), 'The earlier delete is not undone a second time.');
    }

    // ---- reporting ----------------------------------------------------------

    public function test_list_operations_reports_an_undone_operation_as_not_restorable(): void
    {
        $post_id = self::factory()->post->create(['post_content' => 'V0']);
        $undone  = (new Update_Post())->handle(['post_id' => $post_id, 'content' => 'V1']);
        $live    = (new Update_Post())->handle(['post_id' => $post_id, 'content' => 'V2']);
        $this->assertTrue(Rollback_Service::restore_operation($undone['operation_id']));

        $ops = [];
        foreach ((new List_Operations())->handle(['object_id' => $post_id])['operations'] as $op) {
            $ops[ $op['operation_id'] ] = $op;
        }

        $this->assertFalse($ops[ $undone['operation_id'] ]['rollback_available']);
        $this->assertNotNull($ops[ $undone['operation_id'] ]['rolled_back_at']);
        $this->assertTrue($ops[ $live['operation_id'] ]['rollback_available']);
        $this->assertNull($ops[ $live['operation_id'] ]['rolled_back_at']);
    }

    public function test_the_history_screen_offers_no_restore_for_an_undone_operation(): void
    {
        $post_id = self::factory()->post->create(['post_content' => 'V0']);
        $undone  = (new Update_Post())->handle(['post_id' => $post_id, 'content' => 'V1']);
        $live    = (new Update_Post())->handle(['post_id' => $post_id, 'content' => 'V2']);
        $this->assertTrue(Rollback_Service::restore_operation($undone['operation_id']));

        ob_start();
        (new History_Page())->render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('data-op="' . $live['operation_id'] . '"', $html);
        $this->assertStringNotContainsString('data-op="' . $undone['operation_id'] . '"', $html);
        $this->assertStringContainsString('Undone ', $html);
    }

    public function test_the_mutations_screen_offers_no_restore_for_an_undone_operation(): void
    {
        $post_id = self::factory()->post->create(['post_content' => 'V0']);
        $undone  = (new Update_Post())->handle(['post_id' => $post_id, 'content' => 'V1']);
        $live    = (new Update_Post())->handle(['post_id' => $post_id, 'content' => 'V2']);
        $this->assertTrue(Rollback_Service::restore_operation($undone['operation_id']));

        ob_start();
        (new Audit_Log_Page())->render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('data-op="' . $live['operation_id'] . '"', $html);
        $this->assertStringNotContainsString('data-op="' . $undone['operation_id'] . '"', $html);
        $this->assertStringContainsString('Undone ', $html);
    }

    /** A restore that throws leaves the operation restorable, so the undo can be retried. */
    public function test_a_failed_restore_releases_its_claim(): void
    {
        $post_id = self::factory()->post->create(['post_content' => 'V0']);
        $a       = (new Update_Post())->handle(['post_id' => $post_id, 'content' => 'V1']);

        $fail = static function (): void {
            throw new \RuntimeException('restore blew up');
        };
        add_action('wpmcp_rollback_post_restored', $fail);
        try {
            Rollback_Service::restore_operation($a['operation_id']);
            $this->fail('Expected the restore to throw.');
        } catch (\RuntimeException $e) {
            $this->assertSame('restore blew up', $e->getMessage());
        } finally {
            remove_action('wpmcp_rollback_post_restored', $fail);
        }

        $this->assertNull(Snapshot_Store::rolled_back_at(Snapshot_Store::get_by_operation($a['operation_id'])));
        $this->assertTrue(Rollback_Service::restore_operation($a['operation_id']));
        $this->assertSame('V0', get_post($post_id)->post_content);
    }

    /** The column the fix relies on is part of the installed schema. */
    public function test_the_ledger_has_a_rolled_back_at_column(): void
    {
        global $wpdb;
        $cols = $wpdb->get_col('DESC ' . Snapshot_Store::table_name(), 0);
        $this->assertContains('rolled_back_at', $cols);
    }
}
