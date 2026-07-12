<?php

namespace WPMCP\Tests\Free\Filesystem;

use WPMCP\Tools\Filesystem\Search_Files;

class SearchFilesTest extends \WP_UnitTestCase
{
    private string $rel_dir = 'wp-content/wpmcp-fs-test';

    public function setUp(): void
    {
        parent::setUp();
        mkdir(ABSPATH . $this->rel_dir, 0777, true);
        file_put_contents(ABSPATH . $this->rel_dir . '/a.php', "<?php\necho 'needle here';\n");
        file_put_contents(ABSPATH . $this->rel_dir . '/b.txt', "nothing to find\n");
    }

    public function tearDown(): void
    {
        @unlink(ABSPATH . $this->rel_dir . '/a.php');
        @unlink(ABSPATH . $this->rel_dir . '/b.txt');
        @unlink(ABSPATH . $this->rel_dir . '/leak.txt');
        @rmdir(ABSPATH . $this->rel_dir);
        @unlink(ABSPATH . 'wp-config.php');
        parent::tearDown();
    }

    public function test_finds_matching_lines_in_files_under_the_path(): void
    {
        $result = (new Search_Files())->handle(['query' => 'needle', 'path' => $this->rel_dir]);

        $this->assertCount(1, $result['matches']);
        $this->assertSame($this->rel_dir . '/a.php', $result['matches'][0]['file']);
        $this->assertSame(2, $result['matches'][0]['line']);
    }

    public function test_requires_a_non_empty_query(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Search_Files())->handle(['path' => $this->rel_dir]);
    }

    public function test_extensions_filter_limits_which_files_are_searched(): void
    {
        file_put_contents(ABSPATH . $this->rel_dir . '/c.txt', "needle in a txt file\n");

        $result = (new Search_Files())->handle([
            'query'      => 'needle',
            'path'       => $this->rel_dir,
            'extensions' => ['php'],
        ]);

        $files = array_column($result['matches'], 'file');
        $this->assertContains($this->rel_dir . '/a.php', $files);
        $this->assertNotContains($this->rel_dir . '/c.txt', $files);

        @unlink(ABSPATH . $this->rel_dir . '/c.txt');
    }

    /**
     * Escape 2 (CRITICAL): an in-tree symlink pointing outside the root
     * must not be followed during traversal. RecursiveIteratorIterator
     * previously treated the symlink as a regular file and
     * file_get_contents() read through it, returning outside content in
     * the search results.
     */
    public function test_does_not_read_through_an_in_tree_symlink_to_outside_content(): void
    {
        $outside = sys_get_temp_dir() . '/wpmcp-escape2-' . uniqid() . '.txt';
        file_put_contents($outside, "top-secret-needle\n");

        $link = ABSPATH . $this->rel_dir . '/leak.txt';
        if (! @symlink($outside, $link)) {
            @unlink($outside);
            $this->markTestSkipped('symlink() unavailable in this environment');
        }

        try {
            $result = (new Search_Files())->handle(['query' => 'top-secret-needle', 'path' => $this->rel_dir]);

            $files = array_column($result['matches'], 'file');
            $this->assertNotContains($this->rel_dir . '/leak.txt', $files);
            $this->assertCount(0, $result['matches']);
        } finally {
            @unlink($link);
            @unlink($outside);
        }
    }

    /**
     * Escape 3 (CRITICAL): is_protected() was write-only. Search_Files
     * never called it, so a search could match and return the contents of
     * wp-config.php.
     */
    public function test_does_not_search_the_contents_of_a_protected_file(): void
    {
        file_put_contents(ABSPATH . 'wp-config.php', "<?php\ndefine('DB_PASSWORD', 'super-secret-needle');\n");

        try {
            $result = (new Search_Files())->handle(['query' => 'super-secret-needle', 'path' => '.']);

            $files = array_column($result['matches'], 'file');
            $this->assertNotContains('wp-config.php', $files);
        } finally {
            @unlink(ABSPATH . 'wp-config.php');
        }
    }
}
