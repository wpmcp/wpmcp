<?php

namespace WPMCP\Tests\Free\Filesystem;

use WPMCP\Tools\Filesystem\List_Directory;

class ListDirectoryTest extends \WP_UnitTestCase
{
    private string $rel_dir = 'wp-content/wpmcp-fs-test';

    public function setUp(): void
    {
        parent::setUp();
        mkdir(ABSPATH . $this->rel_dir . '/sub', 0777, true);
        file_put_contents(ABSPATH . $this->rel_dir . '/a.txt', 'a');
        file_put_contents(ABSPATH . $this->rel_dir . '/sub/b.txt', 'bb');
    }

    public function tearDown(): void
    {
        @unlink(ABSPATH . $this->rel_dir . '/sub/b.txt');
        @rmdir(ABSPATH . $this->rel_dir . '/sub');
        @unlink(ABSPATH . $this->rel_dir . '/a.txt');
        @rmdir(ABSPATH . $this->rel_dir);
        parent::tearDown();
    }

    public function test_lists_entries_of_a_directory(): void
    {
        $result = (new List_Directory())->handle(['path' => $this->rel_dir]);

        $names = array_column($result['entries'], 'name');
        $this->assertContains('a.txt', $names);
        $this->assertContains('sub', $names);
    }

    public function test_recursive_listing_includes_nested_files(): void
    {
        $result = (new List_Directory())->handle(['path' => $this->rel_dir, 'recursive' => true]);

        $names = array_column($result['entries'], 'name');
        $this->assertContains('b.txt', $names);
    }

    public function test_rejects_a_path_that_is_not_a_directory(): void
    {
        $this->expectException(\RuntimeException::class);
        (new List_Directory())->handle(['path' => $this->rel_dir . '/a.txt']);
    }
}
