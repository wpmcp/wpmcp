<?php

namespace WPMCP\Tests\Free\Users;

use WPMCP\Tools\Users\List_Users;

class ListUsersTest extends \WP_UnitTestCase
{
    public function test_list_users_returns_masked_summary_rows(): void
    {
        $id = self::factory()->user->create([
            'user_login'   => 'jane',
            'user_email'   => 'jane@example.com',
            'display_name' => 'Jane Doe',
            'role'         => 'author',
        ]);

        $out = (new List_Users())->handle([]);

        $this->assertArrayHasKey('users', $out);
        $this->assertArrayHasKey('total', $out);

        $rows = [];
        foreach ($out['users'] as $row) {
            $rows[ $row['id'] ] = $row;
        }

        $this->assertSame('jane', $rows[ $id ]['username']);
        $this->assertTrue($out['masked'], 'A list read says its personal data is masked.');
        $this->assertSame('j***@example.com', $rows[ $id ]['email']);
        $this->assertSame('J***', $rows[ $id ]['display_name']);
        $this->assertStringNotContainsString('jane@example.com', wp_json_encode($out));
        $this->assertStringNotContainsString('Jane Doe', wp_json_encode($out));
        $this->assertContains('author', $rows[ $id ]['roles']);
    }

    public function test_list_users_masks_a_login_that_is_an_email_address(): void
    {
        $id = self::factory()->user->create([
            'user_login' => 'sam@example.com',
            'user_email' => 'sam-other@example.com',
            'role'       => 'subscriber',
        ]);
        $plain = self::factory()->user->create(['user_login' => 'plainlogin', 'role' => 'subscriber']);

        $out  = (new List_Users())->handle([]);
        $rows = array_column($out['users'], null, 'id');

        $this->assertSame('s***@example.com', $rows[ $id ]['username']);
        $this->assertSame('plainlogin', $rows[ $plain ]['username'], 'A login that is not an email stays readable.');
        $this->assertStringNotContainsString('sam@example.com', wp_json_encode($out));
    }

    public function test_list_users_never_leaks_secrets(): void
    {
        self::factory()->user->create([
            'user_login' => 'secretkeeper',
            'user_pass'  => 'super-secret-passphrase',
            'role'       => 'subscriber',
        ]);

        $out  = (new List_Users())->handle([]);
        $json = wp_json_encode($out);

        $this->assertStringNotContainsStringIgnoringCase('user_pass', $json);
        $this->assertStringNotContainsStringIgnoringCase('password', $json);
        $this->assertStringNotContainsString('$P$', $json, 'A phpass hash prefix must never appear in output.');
    }

    public function test_list_users_filters_by_role(): void
    {
        self::factory()->user->create(['role' => 'editor', 'user_login' => 'ed']);
        self::factory()->user->create(['role' => 'subscriber', 'user_login' => 'sub']);

        $out    = (new List_Users())->handle(['role' => 'editor']);
        $logins = array_column($out['users'], 'username');

        $this->assertContains('ed', $logins);
        $this->assertNotContains('sub', $logins);
    }
}
