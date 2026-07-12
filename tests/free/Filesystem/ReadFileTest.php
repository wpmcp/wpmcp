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
        @rmdir(ABSPATH . $this->rel_dir);
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
}
