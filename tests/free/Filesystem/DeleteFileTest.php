<?php

namespace WPMCP\Tests\Free\Filesystem;

use WPMCP\Tools\Filesystem\Delete_File;

class DeleteFileTest extends \WP_UnitTestCase
{
    private string $rel_dir = 'wp-content/wpmcp-fs-test';

    public function setUp(): void
    {
        parent::setUp();
        mkdir(ABSPATH . $this->rel_dir, 0777, true);
        file_put_contents(ABSPATH . $this->rel_dir . '/gone.txt', "delete me\n");
    }

    public function tearDown(): void
    {
        @unlink(ABSPATH . $this->rel_dir . '/gone.txt');
        @rmdir(ABSPATH . $this->rel_dir);
        parent::tearDown();
    }

    public function test_disabled_by_default(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Delete_File())->handle(['path' => $this->rel_dir . '/gone.txt', 'confirm' => true]);
    }

    public function test_requires_confirm_when_enabled(): void
    {
        add_filter('wpmcp_enable_fs_writes', '__return_true');
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);

        $this->expectException(\InvalidArgumentException::class);
        try {
            (new Delete_File())->handle(['path' => $this->rel_dir . '/gone.txt']);
        } finally {
            $this->assertFileExists(ABSPATH . $this->rel_dir . '/gone.txt');
        }
    }

    public function test_deletes_the_file_and_backs_it_up_so_it_is_restorable(): void
    {
        add_filter('wpmcp_enable_fs_writes', '__return_true');
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);

        $result = (new Delete_File())->handle(['path' => $this->rel_dir . '/gone.txt', 'confirm' => true]);

        $this->assertTrue($result['deleted']);
        $this->assertTrue($result['recoverable']);
        $this->assertNotNull($result['backup']);
        $this->assertFileDoesNotExist(ABSPATH . $this->rel_dir . '/gone.txt');

        $backup_abs = ABSPATH . $result['backup'];
        $this->assertSame("delete me\n", file_get_contents($backup_abs));

        $restored = \WPMCP\Tools\Filesystem\Filesystem_Guard::restore($backup_abs, ABSPATH . $this->rel_dir . '/gone.txt');
        $this->assertTrue($restored);
        $this->assertSame("delete me\n", file_get_contents(ABSPATH . $this->rel_dir . '/gone.txt'));

        @unlink($backup_abs);
    }

    public function test_refuses_to_delete_a_protected_file(): void
    {
        add_filter('wpmcp_enable_fs_writes', '__return_true');
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);

        add_filter('wpmcp_fs_protected_paths', function ($paths) {
            $paths[] = 'gone.txt';
            return $paths;
        });

        $this->expectException(\RuntimeException::class);
        try {
            (new Delete_File())->handle(['path' => $this->rel_dir . '/gone.txt', 'confirm' => true]);
        } finally {
            $this->assertFileExists(ABSPATH . $this->rel_dir . '/gone.txt');
        }
    }
}
