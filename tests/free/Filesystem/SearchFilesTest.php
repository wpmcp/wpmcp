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
        @rmdir(ABSPATH . $this->rel_dir);
        parent::tearDown();
    }

    public function test_finds_matching_lines_in_files_under_the_path(): void
    {
        $result = (new Search_Files())->handle(['query' => 'needle', 'path' => $this->rel_dir]);

        $this->assertCount(1, $result['matches']);
        $this->assertSame($this->rel_dir . '/a.php', $result['matches'][0]['file']);
        $this->assertSame(2, $result['matches'][0]['line']);
    }
}
