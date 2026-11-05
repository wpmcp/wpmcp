<?php

namespace WPMCP\Tests\Free\Users;

use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\List_Operations;
use WPMCP\Tools\Users\Create_User;

/**
 * Issue #598: create-user records a creation row under the caller's
 * session_id and returns an operation_id. Undoing it deletes the user only
 * while the user has no content and nothing about the account changed
 * since; otherwise it refuses with the reason and deletes nothing. It takes
 * delete_users.
 */
class UserCreationUndoTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        $admin = self::factory()->user->create(['role' => 'administrator']);
        if (is_multisite()) {
            grant_super_admin($admin);
        }
        wp_set_current_user($admin);
    }

    private function create(string $login, string $session = 'users-598'): array
    {
        return (new Create_User())->handle(['username' => $login, 'email' => $login . '@example.com', 'role' => 'editor', 'session_id' => $session]);
    }

    public function test_create_user_returns_an_operation_that_is_listed(): void
    {
        $out = $this->create('h3editor');

        $this->assertArrayHasKey('operation_id', $out);
        $listed = (new List_Operations())->handle(['session_id' => 'users-598'])['operations'];
        $this->assertCount(1, $listed);
        $this->assertSame('create-user', $listed[0]['tool_name']);
        $this->assertSame($out['id'], $listed[0]['object_id']);
        $this->assertSame('users', $listed[0]['domain']);
        $this->assertTrue($listed[0]['rollback_available']);
    }

    public function test_undo_deletes_a_user_with_no_content_and_no_changes(): void
    {
        $out = $this->create('undo-me');

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));

        $this->assertFalse(get_userdata($out['id']));
        $listed = (new List_Operations())->handle(['session_id' => 'users-598'])['operations'];
        $this->assertNotNull($listed[0]['rolled_back_at']);
    }

    public function test_rollback_session_deletes_the_user_the_session_created(): void
    {
        $out = $this->create('session-user', 'users-598-session');

        $this->assertSame(1, Rollback_Service::restore_session('users-598-session'));

        $this->assertFalse(get_userdata($out['id']));
    }

    public function test_undo_refuses_a_user_who_has_content_and_deletes_nothing(): void
    {
        $out  = $this->create('author');
        $post = self::factory()->post->create(['post_author' => $out['id'], 'post_status' => 'draft']);

        $this->assertFalse(Rollback_Service::restore_operation($out['operation_id']));

        $this->assertNotFalse(get_userdata($out['id']));
        $this->assertNotNull(get_post($post));
        $this->assertSame($out['id'], (int) get_post($post)->post_author, 'Nothing was reassigned');
        $warnings = implode(' ', Rollback_Service::take_warnings());
        $this->assertStringContainsString('refused', $warnings);
        $this->assertStringContainsString('content', $warnings);
        $listed = (new List_Operations())->handle(['session_id' => 'users-598'])['operations'];
        $this->assertNull($listed[0]['rolled_back_at'], 'A refused undo stays retryable');
    }

    public function test_undo_refuses_a_user_who_has_a_comment(): void
    {
        $out = $this->create('commenter');
        self::factory()->comment->create(['user_id' => $out['id'], 'comment_post_ID' => self::factory()->post->create()]);

        $this->assertFalse(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertNotFalse(get_userdata($out['id']));
    }

    public function test_undo_refuses_a_user_changed_since(): void
    {
        $out = $this->create('changed');
        wp_update_user(['ID' => $out['id'], 'user_email' => 'new-address@example.com']);

        $this->assertFalse(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertNotFalse(get_userdata($out['id']));
        $this->assertStringContainsString('changed', implode(' ', Rollback_Service::take_warnings()));
    }

    public function test_undo_refuses_a_user_who_set_their_password(): void
    {
        $out = $this->create('activated');
        wp_set_password('a-password-they-chose', $out['id']);

        $this->assertFalse(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertNotFalse(get_userdata($out['id']));
    }

    public function test_undo_takes_delete_users(): void
    {
        $out = $this->create('gated');
        // Holds create_users but not delete_users.
        $creator = self::factory()->user->create(['role' => 'editor']);
        get_userdata($creator)->add_cap('create_users');
        wp_set_current_user($creator);

        $this->assertFalse(Rollback_Service::restore_operation($out['operation_id']));

        $this->assertNotFalse(get_userdata($out['id']));
        $this->assertStringContainsString('delete_users', implode(' ', Rollback_Service::take_warnings()));
    }
}
