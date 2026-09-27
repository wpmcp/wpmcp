<?php

namespace WPMCP\Tests\Free\Backup;

use WPMCP\Auth\Client_Store;
use WPMCP\Auth\Refresh_Token_Store;
use WPMCP\Auth\Token_Store;
use WPMCP\Tools\Backup\Restore_Session;

/**
 * Session survival across a restore (issue #190). The "restore" here is
 * simulated by rewriting the credential rows the way an older dump would
 * leave them, which is exactly the state reapply() receives.
 */
class RestoreSessionTest extends \WP_UnitTestCase
{
    protected function tearDown(): void
    {
        delete_option(Token_Store::OPTION);
        delete_option(Refresh_Token_Store::OPTION);
        delete_option(Client_Store::OPTION);
        parent::tearDown();
    }

    /** Put the credential rows back the way a backup from before today had them. */
    private function simulate_older_backup(int $user_id): void
    {
        global $wpdb;
        $wpdb->update($wpdb->users, ['user_pass' => wp_hash_password('the old password')], ['ID' => $user_id]);
        delete_user_meta($user_id, 'session_tokens');
        delete_user_meta($user_id, '_application_passwords');
        delete_option(Token_Store::OPTION);
        delete_option(Refresh_Token_Store::OPTION);
        delete_option(Client_Store::OPTION);
        clean_user_cache($user_id);
        wp_cache_flush();
    }

    public function test_the_acting_users_credentials_survive(): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);

        $session = \WP_Session_Tokens::get_instance($admin)->create(time() + HOUR_IN_SECONDS);
        [$app_password] = \WP_Application_Passwords::create_new_application_password($admin, ['name' => 'restore test']);
        $client = Client_Store::create(['agent'], ['https://agent.example/cb']);
        $bearer = Token_Store::issue($client['client_id'], $admin, 'mcp');
        $refresh = Refresh_Token_Store::issue($client['client_id'], $admin, 'mcp');

        $captured = Restore_Session::capture($admin);
        $this->simulate_older_backup($admin);
        $this->assertNull(Token_Store::validate($bearer), 'Precondition: the simulated restore invalidated the token.');

        $out = Restore_Session::reapply($captured);

        $this->assertTrue($out['preserved']);
        $this->assertFalse($out['relogin_required']);
        $this->assertTrue($out['has_manage_options']);
        $this->assertContains('password', $out['kept']);
        $this->assertTrue(\WP_Session_Tokens::get_instance($admin)->verify($session));
        $passwords = \WP_Application_Passwords::get_user_application_passwords($admin);
        $this->assertCount(1, $passwords);
        $this->assertTrue(wp_check_password($app_password, $passwords[0]['password']));
        $this->assertNotNull(Token_Store::validate($bearer));
        $this->assertNotNull(Client_Store::get($client['client_id']));
        $this->assertArrayHasKey(hash('sha256', $refresh), (array) get_option(Refresh_Token_Store::OPTION));
        $this->assertSame($admin, get_current_user_id());
    }

    public function test_other_users_tokens_are_not_carried_across(): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        $other = self::factory()->user->create(['role' => 'administrator']);
        $client = Client_Store::create(['agent'], ['https://agent.example/cb']);
        $mine   = Token_Store::issue($client['client_id'], $admin, 'mcp');
        $theirs = Token_Store::issue($client['client_id'], $other, 'mcp');

        $captured = Restore_Session::capture($admin);
        delete_option(Token_Store::OPTION);
        wp_cache_flush();

        Restore_Session::reapply($captured);

        $this->assertNotNull(Token_Store::validate($mine));
        $this->assertNull(Token_Store::validate($theirs), 'Only the acting user is kept signed in.');
    }

    public function test_an_account_missing_from_the_backup_reports_a_relogin(): void
    {
        $admin    = self::factory()->user->create(['role' => 'administrator']);
        $captured = Restore_Session::capture($admin);
        wp_delete_user($admin);

        $out = Restore_Session::reapply($captured);

        $this->assertFalse($out['preserved']);
        $this->assertTrue($out['relogin_required']);
        $this->assertStringContainsString('does not exist in the restored database', $out['reason']);
    }

    public function test_a_different_login_at_the_same_id_is_not_trusted(): void
    {
        global $wpdb;
        $admin    = self::factory()->user->create(['role' => 'administrator', 'user_login' => 'restorer']);
        $captured = Restore_Session::capture($admin);
        $wpdb->update($wpdb->users, ['user_login' => 'someone_else'], ['ID' => $admin]);
        clean_user_cache($admin);

        $out = Restore_Session::reapply($captured);

        $this->assertTrue($out['relogin_required']);
        $this->assertStringContainsString('different login', $out['reason']);
    }

    public function test_a_non_admin_in_the_backup_is_reported(): void
    {
        $admin    = self::factory()->user->create(['role' => 'administrator']);
        $captured = Restore_Session::capture($admin);
        (new \WP_User($admin))->set_role('subscriber');

        $out = Restore_Session::reapply($captured);

        $this->assertTrue($out['preserved']);
        $this->assertFalse($out['has_manage_options']);
        $this->assertStringContainsString('not an administrator', $out['reason']);
    }

    public function test_no_acting_user_means_nothing_to_keep(): void
    {
        $this->assertNull(Restore_Session::capture(0));
        $this->assertNull(Restore_Session::capture(999999));

        $out = Restore_Session::reapply(null);

        $this->assertFalse($out['preserved']);
        $this->assertFalse($out['relogin_required']);
    }
}
