<?php

namespace WPMCP\Tests\Free\Users;

use WPMCP\Tools\Users\Get_User;

class GetUserTest extends \WP_UnitTestCase
{
    public function test_get_user_returns_detail_and_is_admin_false_for_non_admin(): void
    {
        $id = self::factory()->user->create([
            'user_login'   => 'jane',
            'user_email'   => 'jane@example.com',
            'display_name' => 'Jane Doe',
            'role'         => 'author',
            'description'  => 'Writer',
        ]);

        $out = (new Get_User())->handle(['id' => $id]);

        $this->assertSame('Jane Doe', $out['display_name']);
        $this->assertSame('jane@example.com', $out['email']);
        $this->assertSame('Writer', $out['description']);
        $this->assertFalse($out['is_admin']);
    }

    public function test_get_user_flags_admin_true(): void
    {
        $id  = self::factory()->user->create(['role' => 'administrator']);
        $out = (new Get_User())->handle(['id' => $id]);

        $this->assertTrue($out['is_admin']);
    }

    public function test_get_user_never_leaks_password_hash(): void
    {
        $id   = self::factory()->user->create(['user_pass' => 'top-secret', 'role' => 'subscriber']);
        $json = wp_json_encode((new Get_User())->handle(['id' => $id]));

        $this->assertStringNotContainsStringIgnoringCase('user_pass', $json);
        $this->assertStringNotContainsStringIgnoringCase('password', $json);
    }

    public function test_get_user_not_found_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Get_User())->handle(['id' => 999999]);
    }

    public function test_get_user_requires_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Get_User())->handle([]);
    }
}
