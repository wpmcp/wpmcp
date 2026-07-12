<?php

namespace WPMCP\Tests\Free\Filesystem;

use WPMCP\Tools\Filesystem\Filesystem_Guard;

class FilesystemGuardTest extends \WP_UnitTestCase
{
    private string $root;

    public function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/wpmcp-fs-' . uniqid();
        mkdir($this->root . '/wp-content/themes/x', 0777, true);
        file_put_contents($this->root . '/wp-content/themes/x/style.css', "a\nb\nc\n");
        file_put_contents($this->root . '/wp-config.php', '<?php // secrets');
        file_put_contents(dirname($this->root) . '/wpmcp-outside.txt', 'nope');
    }

    public function tearDown(): void
    {
        @unlink(dirname($this->root) . '/wpmcp-outside.txt');
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->root);
        parent::tearDown();
    }

    public function test_resolves_an_in_tree_path(): void
    {
        $out = Filesystem_Guard::resolve_path('wp-content/themes/x/style.css', $this->root);
        $this->assertSame(realpath($this->root . '/wp-content/themes/x/style.css'), $out);
    }

    public function test_rejects_parent_traversal(): void
    {
        $out = Filesystem_Guard::resolve_path('wp-content/../../wpmcp-outside.txt', $this->root);
        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('outside_root', $out->get_error_code());
    }

    public function test_rejects_absolute_path_outside_root(): void
    {
        $out = Filesystem_Guard::resolve_path(dirname($this->root) . '/wpmcp-outside.txt', $this->root);
        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('outside_root', $out->get_error_code());
    }
}
