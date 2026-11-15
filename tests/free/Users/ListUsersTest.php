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
