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

        $result = (new Write_File())->handle(['path' => $this->rel_dir . '/new.txt', 'content' => 'hello world']);

        $this->assertSame('created', $result['action']);
        $this->assertNull($result['backup']);
        $this->assertFalse($result['recoverable']);
        $this->assertSame('hello world', file_get_contents(ABSPATH . $this->rel_dir . '/new.txt'));
    }
}
