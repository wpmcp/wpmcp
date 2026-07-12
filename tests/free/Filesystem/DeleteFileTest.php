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
}
