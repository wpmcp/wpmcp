<?php

namespace WPMCP\Tests\Free\Input;

use WPMCP\Tools\Users\Create_User;
use WPMCP\Tools\Users\Update_User;
use WPMCP\Tools\Users\Get_User;

/**
 * Input-boundary tests for the Users domain: missing required args,
 * forbidden/unknown roles, admin-capable targets, and invalid/out-of-range
 * ids must all fail cleanly, never a fatal or a privilege-escalating result.
 */
class UsersInputTest extends \WP_UnitTestCase
{
    public function test_create_user_rejects_missing_username_and_email(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Create_User())->handle([]);
    }

    public function test_create_user_rejects_missing_email(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Create_User())->handle(['username' => 'someone']);
    }

    public function test_create_user_rejects_empty_string_username(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Create_User())->handle(['username' => '', 'email' => 'someone@example.com']);
    }

    public function test_create_user_rejects_admin_capable_role(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Create_User())->handle([
            'username' => 'wouldbeadmin',
            'email'    => 'wouldbeadmin@example.com',
            'role'     => 'administrator',
        ]);
    }

    public function test_create_user_rejects_unknown_role(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Create_User())->handle([
            'username' => 'someone',
            'email'    => 'someone2@example.com',
            'role'     => 'this_role_does_not_exist',
        ]);
    }

    public function test_update_user_rejects_missing_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Update_User())->handle(['display_name' => 'x']);
    }

    public function test_update_user_rejects_zero_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Update_User())->handle(['id' => 0, 'display_name' => 'x']);
    }

    public function test_update_user_rejects_negative_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Update_User())->handle(['id' => -1, 'display_name' => 'x']);
    }

    public function test_update_user_rejects_nonexistent_id(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Update_User())->handle(['id' => 999999999, 'display_name' => 'x']);
    }

    public function test_update_user_refuses_admin_capable_target(): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);

        $this->expectException(\RuntimeException::class);
        (new Update_User())->handle(['id' => $admin, 'display_name' => 'Renamed']);
    }

    public function test_update_user_ignores_role_and_password_fields(): void
    {
        $user = self::factory()->user->create(['role' => 'subscriber']);

        $result = (new Update_User())->handle([
            'id'       => $user,
            'role'     => 'administrator',
            'password' => 'not-allowed',
        ]);

        // Neither field is in the allowlist, so nothing changes and nothing is reported updated.
        $this->assertSame([], $result['updated']);
        $this->assertSame(['subscriber'], get_userdata($user)->roles);
    }

    public function test_get_user_rejects_missing_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Get_User())->handle([]);
    }

    public function test_get_user_rejects_nonexistent_id(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Get_User())->handle(['id' => 999999999]);
    }
}
