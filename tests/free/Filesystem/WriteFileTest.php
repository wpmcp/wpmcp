<?php

namespace WPMCP\Tests\Free\Filesystem;

use WPMCP\Tools\Filesystem\Write_File;

class WriteFileTest extends \WP_UnitTestCase
{
    private string $rel_dir = 'wp-content/wpmcp-fs-test';

    public function setUp(): void
    {
        parent::setUp();
        mkdir(ABSPATH . $this->rel_dir, 0777, true);
    }

    public function tearDown(): void
    {
        @unlink(ABSPATH . $this->rel_dir . '/new.txt');
        @unlink(ABSPATH . $this->rel_dir . '/evil');
        @rmdir(ABSPATH . $this->rel_dir);
        parent::tearDown();
    }

    public function test_disabled_by_default(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Write_File())->handle(['path' => $this->rel_dir . '/new.txt', 'content' => 'hello']);
    }

    public function test_creates_a_new_file_when_enabled(): void
    {
        add_filter('wpmcp_enable_fs_writes', '__return_true');
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        wpmcp_test_grant_network_admin(get_current_user_id());

        $result = (new Write_File())->handle(['path' => $this->rel_dir . '/new.txt', 'content' => 'hello world']);

        $this->assertSame('created', $result['action']);
        $this->assertNull($result['backup']);
        $this->assertFalse($result['recoverable']);
        $this->assertSame('hello world', file_get_contents(ABSPATH . $this->rel_dir . '/new.txt'));
    }

    public function test_overwriting_backs_up_the_original_and_is_restorable(): void
    {
        add_filter('wpmcp_enable_fs_writes', '__return_true');
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        wpmcp_test_grant_network_admin(get_current_user_id());

        file_put_contents(ABSPATH . $this->rel_dir . '/new.txt', 'original content');

        $result = (new Write_File())->handle(['path' => $this->rel_dir . '/new.txt', 'content' => 'new content']);

        $this->assertSame('overwritten', $result['action']);
        $this->assertNotNull($result['backup']);
        $this->assertTrue($result['recoverable']);
        $this->assertSame('new content', file_get_contents(ABSPATH . $this->rel_dir . '/new.txt'));

        $this->assertStringStartsNotWith('/', $result['backup']);
        $backup_abs = ABSPATH . $result['backup'];
        $this->assertFileExists($backup_abs);
        $this->assertSame('original content', file_get_contents($backup_abs));

        $restored = \WPMCP\Tools\Filesystem\Filesystem_Guard::restore($backup_abs, ABSPATH . $this->rel_dir . '/new.txt');
        $this->assertTrue($restored);
        $this->assertSame('original content', file_get_contents(ABSPATH . $this->rel_dir . '/new.txt'));

        @unlink($backup_abs);
    }

    public function test_refuses_a_subscriber_without_edit_files_capability(): void
    {
        add_filter('wpmcp_enable_fs_writes', '__return_true');
        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);

        $this->expectException(\RuntimeException::class);
        try {
            (new Write_File())->handle(['path' => $this->rel_dir . '/new.txt', 'content' => 'nope']);
        } finally {
            $this->assertFileDoesNotExist(ABSPATH . $this->rel_dir . '/new.txt');
        }
    }

    public function test_refuses_to_write_a_protected_file(): void
    {
        add_filter('wpmcp_enable_fs_writes', '__return_true');
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        wpmcp_test_grant_network_admin(get_current_user_id());

        add_filter('wpmcp_fs_protected_paths', function ($paths) {
            $paths[] = 'new.txt';
            return $paths;
        });

        $this->expectException(\RuntimeException::class);
        try {
            (new Write_File())->handle(['path' => $this->rel_dir . '/new.txt', 'content' => 'nope']);
        } finally {
            $this->assertFileDoesNotExist(ABSPATH . $this->rel_dir . '/new.txt');
        }
    }

    /**
     * Escape 1 (CRITICAL): a symlink whose leaf name lives inside the
     * sandbox but whose target does not exist yet (a "dangling" symlink)
     * must not be usable to write outside the WordPress install.
     * resolve_path() previously canonicalized only dirname($candidate) in
     * the not-yet-existing branch and trusted basename($candidate)
     * verbatim, so sandbox/evil -> <outside path> passed containment as
     * ABSPATH/sandbox/evil, and file_put_contents() then followed the
     * symlink and wrote outside root.
     */
    public function test_refuses_to_write_through_a_dangling_symlink_leaf(): void
    {
        add_filter('wpmcp_enable_fs_writes', '__return_true');
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        wpmcp_test_grant_network_admin(get_current_user_id());

        $outside_target = sys_get_temp_dir() . '/wpmcp-escape1-' . uniqid() . '.txt';
        $link            = ABSPATH . $this->rel_dir . '/evil';

        if (! @symlink($outside_target, $link)) {
            $this->markTestSkipped('symlink() unavailable in this environment');
        }

        try {
            $this->expectException(\RuntimeException::class);
            (new Write_File())->handle(['path' => $this->rel_dir . '/evil', 'content' => 'pwned']);
        } finally {
            $this->assertFileDoesNotExist($outside_target);
            @unlink($link);
            @unlink($outside_target);
        }
    }
}
