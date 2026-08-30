<?php

namespace WPMCP\Tests\Free\Build;

/**
 * Issue #163 (finding B-07, guideline 5): insert-stock-image must not ship
 * in the WordPress.org directory build. The paid composite ability leaves
 * via scripts/flavors/wporg/strip.php like the rest of the pro group; this
 * test stages the real src/ tree, runs the real strip, and pins both halves
 * of the definition of done:
 *
 *   - absent from the directory cut (file gone, no registration, no
 *     residual string anywhere in the staged PHP)
 *   - still present in the full build this checkout represents
 */
class WporgStripInsertStockImageTest extends \WP_UnitTestCase
{
    private static ?string $stage = null;

    public static function tearDownAfterClass(): void
    {
        if (null !== self::$stage && is_dir(self::$stage)) {
            self::remove_tree(self::$stage);
        }
        self::$stage = null;
        parent::tearDownAfterClass();
    }

    public function test_full_build_still_carries_the_ability(): void
    {
        $root = self::repo_root();

        $this->assertFileExists($root . '/src/Tools/Media/Stock/Insert_Stock_Image.php');
        $this->assertStringContainsString(
            "'wpmcp/insert-stock-image'",
            (string) file_get_contents($root . '/src/Plugin.php'),
            'The full build must keep registering insert-stock-image'
        );
    }

    public function test_directory_cut_does_not_contain_the_ability(): void
    {
        $stage = self::stripped_stage();

        $this->assertFileDoesNotExist($stage . '/src/Tools/Media/Stock/Insert_Stock_Image.php');

        $survivors = [];
        $iterator  = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($stage . '/src'));
        foreach ($iterator as $file) {
            if ('php' !== $file->getExtension()) {
                continue;
            }
            $contents = (string) file_get_contents($file->getPathname());
            if (
                false !== stripos($contents, 'insert-stock-image')
                || false !== strpos($contents, 'Insert_Stock_Image')
            ) {
                $survivors[] = $file->getPathname();
            }
        }

        $this->assertSame(
            [],
            $survivors,
            'insert-stock-image survived the wp.org strip in: ' . implode(', ', $survivors)
        );
    }

    /** Stage src/ into a temp dir and run the real strip over it, once. */
    private static function stripped_stage(): string
    {
        if (null !== self::$stage) {
            return self::$stage;
        }

        $root  = self::repo_root();
        $stage = rtrim(sys_get_temp_dir(), '/') . '/wpmcp-wporg-strip-' . uniqid('', true);
        if (! mkdir($stage, 0777, true)) {
            self::fail('could not create the staging directory');
        }

        self::copy_tree($root . '/src', $stage . '/src');

        $output = [];
        $code   = 1;
        exec(
            escapeshellarg(PHP_BINARY) . ' '
                . escapeshellarg($root . '/scripts/flavors/wporg/strip.php') . ' '
                . escapeshellarg($stage) . ' 2>&1',
            $output,
            $code
        );
        if (0 !== $code) {
            self::remove_tree($stage);
            self::fail("wp.org strip failed:\n" . implode("\n", $output));
        }

        return self::$stage = $stage;
    }

    private static function repo_root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function copy_tree(string $from, string $to): void
    {
        mkdir($to, 0777, true);
        foreach (scandir($from) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $source = $from . '/' . $entry;
            $target = $to . '/' . $entry;
            if (is_dir($source)) {
                self::copy_tree($source, $target);
                continue;
            }
            copy($source, $target);
        }
    }

    private static function remove_tree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            self::remove_tree($path . '/' . $entry);
        }
        rmdir($path);
    }
}
