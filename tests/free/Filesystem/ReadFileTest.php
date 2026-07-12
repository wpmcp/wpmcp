<?php

namespace WPMCP\Tests\Free\Filesystem;

use WPMCP\Tools\Filesystem\Read_File;

class ReadFileTest extends \WP_UnitTestCase
{
    private string $rel_dir = 'wp-content/wpmcp-fs-test';

    public function setUp(): void
    {
        parent::setUp();
        mkdir(ABSPATH . $this->rel_dir, 0777, true);
        file_put_contents(ABSPATH . $this->rel_dir . '/hello.txt', "line one\nline two\nline three\n");
    }

    public function tearDown(): void
    {
        @unlink(ABSPATH . $this->rel_dir . '/hello.txt');
        @unlink(ABSPATH . $this->rel_dir . '/leak.txt');
        @rmdir(ABSPATH . $this->rel_dir);
        @unlink(ABSPATH . 'wp-config.php');
        parent::tearDown();
    }

    public function test_reads_an_in_tree_file(): void
    {
        $result = (new Read_File())->handle(['path' => $this->rel_dir . '/hello.txt']);

        $this->assertSame($this->rel_dir . '/hello.txt', $result['path']);
        $this->assertSame("line one\nline two\nline three\n", $result['content']);
    }

    public function test_rejects_a_path_escaping_the_root(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Read_File())->handle(['path' => '../../../../etc/hosts']);
    }

    public function test_errors_when_file_not_found(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Read_File())->handle(['path' => $this->rel_dir . '/does-not-exist.txt']);
    }

    public function test_flags_binary_content_instead_of_returning_it_as_text(): void
    {
        file_put_contents(ABSPATH . $this->rel_dir . '/binary.dat', "\xff\xfe\x00\x01binary");

        $result = (new Read_File())->handle(['path' => $this->rel_dir . '/binary.dat']);

        $this->assertTrue($result['binary']);
        $this->assertArrayNotHasKey('content', $result);

        @unlink(ABSPATH . $this->rel_dir . '/binary.dat');
    }

    /**
     * Escape 2 (CRITICAL): a symlink that lives inside the sandbox but
     * points outside it must not be read through. resolve_path()
     * previously canonicalized the path via realpath(), which follows
     * symlinks, so an in-tree symlink to an outside file resolved to an
     * "inside" real path and file_get_contents() then read the outside
     * file's content.
     */
    public function test_rejects_reading_through_an_in_tree_symlink_to_outside_content(): void
    {
        $outside = sys_get_temp_dir() . '/wpmcp-escape2-read-' . uniqid() . '.txt';
        file_put_contents($outside, "outside-secret\n");

        $link = ABSPATH . $this->rel_dir . '/leak.txt';
        if (! @symlink($outside, $link)) {
            @unlink($outside);
            $this->markTestSkipped('symlink() unavailable in this environment');
        }

        try {
            $this->expectException(\RuntimeException::class);
            (new Read_File())->handle(['path' => $this->rel_dir . '/leak.txt']);
        } finally {
            @unlink($link);
            @unlink($outside);
        }
    }

    /**
     * Escape 3 (CRITICAL): is_protected() was write-only. Read_File never
     * called it, so read-file path: "wp-config.php" returned DB
     * credentials/salts.
     */
    public function test_refuses_to_read_a_protected_file(): void
    {
        file_put_contents(ABSPATH . 'wp-config.php', "<?php\ndefine('DB_PASSWORD', 'super-secret');\n");

        $this->expectException(\RuntimeException::class);
        (new Read_File())->handle(['path' => 'wp-config.php']);
    }
}
