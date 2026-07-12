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
        @unlink(ABSPATH . $this->rel_dir . '/leak.txt');
        @rmdir(ABSPATH . $this->rel_dir);
        @unlink(ABSPATH . 'wp-config.php');
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

    /**
     * Escape 2 (CRITICAL): an in-tree symlink pointing outside the root
     * must not be dereferenced when listing. size/mtime must not be read
     * through the link to outside content.
     */
    public function test_does_not_list_through_an_in_tree_symlink_to_outside_content(): void
    {
        $outside = sys_get_temp_dir() . '/wpmcp-escape2-list-' . uniqid() . '.txt';
        file_put_contents($outside, str_repeat('x', 12345));

        $link = ABSPATH . $this->rel_dir . '/leak.txt';
        if (! @symlink($outside, $link)) {
            @unlink($outside);
            $this->markTestSkipped('symlink() unavailable in this environment');
        }

        try {
            $result = (new List_Directory())->handle(['path' => $this->rel_dir]);

            foreach ($result['entries'] as $entry) {
                if ('leak.txt' === $entry['name']) {
                    $this->assertNotSame(12345, $entry['size']);
                }
            }
            $names = array_column($result['entries'], 'name');
            $this->assertNotContains('leak.txt', $names);
        } finally {
            @unlink($link);
            @unlink($outside);
        }
    }

    /**
     * Escape 3 (CRITICAL): is_protected() was write-only. List_Directory
     * never called it, so a protected basename like wp-config.php was
     * listed like any other file.
     */
    public function test_does_not_list_a_protected_file(): void
    {
        file_put_contents(ABSPATH . 'wp-config.php', "<?php // secrets\n");

        try {
            $result = (new List_Directory())->handle(['path' => '.']);

            $names = array_column($result['entries'], 'name');
            $this->assertNotContains('wp-config.php', $names);
        } finally {
            @unlink(ABSPATH . 'wp-config.php');
        }
    }
}
