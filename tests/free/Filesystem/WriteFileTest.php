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

    public function test_overwriting_backs_up_the_original_and_is_restorable(): void
    {
        add_filter('wpmcp_enable_fs_writes', '__return_true');
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);

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
}
