<?php

namespace WPMCP\Tests\Free\Build;

use WPMCP\MCP\Registrar;
use WPMCP\Plugin;

/**
 * The WordPress.org directory cut, asserted by running the real strip over a
 * real staging of this checkout.
 *
 * Guideline 5 forbids shipping functionality that is "restricted or locked,
 * only to be made available by payment or upgrade", so scripts/flavors/wporg/
 * strip.php physically removes the paid tier rather than gating it. Nothing
 * asserted that outcome before this file: the build script's gates only run
 * inside a release build, which needs composer network access, so a refactor
 * could regress the cut and only be found at submission time.
 *
 * The invariants here are generic, derived from tests/support/
 * ability-manifest.php rather than hardcoded per ability:
 *
 *   - the strip runs clean over the current src/
 *   - no ability the manifest tiers 'pro' is registered in the cut
 *   - no document the cut ships names an ability the cut does not register
 *   - issue #163 / finding B-07 specifically: insert-stock-image is gone from
 *     the cut entirely, and still registered as 'pro' in the full build
 *
 * These mirror gates 3b and 3c in scripts/build-wporg-release.sh, which make
 * the same assertions against the assembled zip.
 */
class WporgStripTest extends \WP_UnitTestCase
{
    /** Matches the ability name in a `new Ability( 'wpmcp/x', 'tier', ...` call. */
    private const ABILITY_RE = '/new\s+Ability\(\s*\n\s*[\'"]([^\'"]+)[\'"]/';

    private static ?string $stage = null;

    public static function tearDownAfterClass(): void
    {
        if (null !== self::$stage && is_dir(self::$stage)) {
            self::remove_tree(self::$stage);
        }
        self::$stage = null;
        parent::tearDownAfterClass();
    }

    public function test_the_strip_runs_clean_over_the_current_tree(): void
    {
        // stripped_stage() fails the test with the strip's own output when it
        // exits non-zero, so reaching an assertion here is the assertion: any
        // of the ~197 exact-string edits going stale breaks the build.
        $this->assertDirectoryExists(self::stripped_stage() . '/src');
    }

    public function test_no_pro_tier_ability_is_registered_in_the_cut(): void
    {
        $survivors = array_values(array_intersect(self::registered_in_cut(), self::pro_ability_names()));

        $this->assertSame(
            [],
            $survivors,
            'pro-tier abilities survived the wp.org strip as registrations: ' . implode(', ', $survivors)
        );
    }

    public function test_no_shipped_document_names_an_ability_the_cut_lacks(): void
    {
        $stage      = self::stripped_stage();
        $registered = array_flip(self::registered_in_cut());

        $docs = array_filter(
            array_merge(
                (array) glob($stage . '/src/Skills/library/*/SKILL.md'),
                (array) glob($stage . '/src/Skills/library/*/*/SKILL.md'),
                [$stage . '/readme.txt']
            ),
            'is_file'
        );
        $this->assertNotEmpty($docs, 'the cut ships no documents to check');

        $dangling = [];
        foreach ($docs as $doc) {
            preg_match_all('~wpmcp/[a-z0-9-]+~', (string) file_get_contents($doc), $named);
            foreach (array_unique($named[0]) as $name) {
                if (! isset($registered[$name])) {
                    $dangling[] = str_replace($stage . '/', '', $doc) . ' -> ' . $name;
                }
            }
        }

        $this->assertSame(
            [],
            $dangling,
            "a shipped document names an ability the cut does not register:\n" . implode("\n", $dangling)
        );
    }

    /** Issue #163, finding B-07: the composite insert flow leaves the cut. */
    public function test_insert_stock_image_is_absent_from_the_cut(): void
    {
        $stage = self::stripped_stage();

        $this->assertFileDoesNotExist($stage . '/src/Tools/Media/Stock/Insert_Stock_Image.php');
        $this->assertNotContains('wpmcp/insert-stock-image', self::registered_in_cut());

        // Every staged file, not just *.php: readme.txt and the bundled
        // SKILL.md library ship too, and a reviewer reads those.
        $survivors = [];
        foreach (self::staged_files($stage) as $path) {
            $contents = (string) file_get_contents($path);
            if (
                false !== stripos($contents, 'insert-stock-image')
                || false !== stripos($contents, 'Insert_Stock_Image')
            ) {
                $survivors[] = str_replace($stage . '/', '', $path);
            }
        }

        $this->assertSame(
            [],
            $survivors,
            'insert-stock-image survived the wp.org strip in: ' . implode(', ', $survivors)
        );
    }

    /**
     * The other half of issue #163's definition of done: the ability is only
     * absent from the directory cut, not deleted. Asserted through the
     * Registrar rather than by grepping Plugin.php, so re-tiering it or
     * dropping the register() call fails here.
     */
    public function test_the_full_build_still_registers_the_ability_as_pro(): void
    {
        $registrar = new Registrar();
        Plugin::instance()->register_abilities_into($registrar);

        $declared = [];
        foreach ($registrar->declared() as $ability) {
            $declared[$ability->name] = $ability->tier;
        }

        $this->assertArrayHasKey('wpmcp/insert-stock-image', $declared);
        $this->assertSame('pro', $declared['wpmcp/insert-stock-image']);
        $this->assertFileExists(self::repo_root() . '/src/Tools/Media/Stock/Insert_Stock_Image.php');
    }

    /** @return string[] ability names registered in the stripped cut. */
    private static function registered_in_cut(): array
    {
        $names = [];
        foreach (self::staged_files(self::stripped_stage() . '/src') as $path) {
            if ('php' !== pathinfo($path, PATHINFO_EXTENSION)) {
                continue;
            }
            preg_match_all(self::ABILITY_RE, (string) file_get_contents($path), $matches);
            foreach ($matches[1] as $name) {
                $names[$name] = true;
            }
        }

        return array_keys($names);
    }

    /** @return string[] every ability the manifest tiers 'pro'. */
    private static function pro_ability_names(): array
    {
        $manifest = require self::repo_root() . '/tests/support/ability-manifest.php';

        return array_keys(array_filter(
            $manifest['abilities'],
            static fn ($tier) => 'pro' === $tier
        ));
    }

    /** @return string[] absolute paths of every regular file under $root. */
    private static function staged_files(string $root): array
    {
        $paths    = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $paths[] = $file->getPathname();
            }
        }
        sort($paths);

        return $paths;
    }

    /**
     * Stage the same file set scripts/build-wporg-release.sh stages, then run
     * the real strip over it, once per class. Staging only src/ would run the
     * strip against a tree the build never produces, so the first edit keyed
     * to a path outside src/ would fail here for an unrelated reason.
     */
    private static function stripped_stage(): string
    {
        if (null !== self::$stage) {
            return self::$stage;
        }

        $root   = self::repo_root();
        $flavor = $root . '/scripts/flavors/wporg';
        $stage  = rtrim(sys_get_temp_dir(), '/') . '/wpmcp-wporg-strip-' . uniqid('', true);
        if (! mkdir($stage, 0777, true)) {
            self::fail('could not create the staging directory');
        }

        foreach (['LICENSE', 'composer.json', 'composer.lock'] as $file) {
            if (! copy($root . '/' . $file, $stage . '/' . $file)) {
                self::remove_tree($stage);
                self::fail("could not stage $file");
            }
        }
        self::copy_tree($root . '/src', $stage . '/src');

        // The build script renders these two from the flavor templates; the
        // version placeholder is irrelevant to what the strip does.
        foreach (['wpmcp.php', 'readme.txt'] as $rendered) {
            $body = str_replace(
                '{{VERSION}}',
                '0.0.0-test',
                (string) file_get_contents($flavor . '/' . $rendered)
            );
            if (false === file_put_contents($stage . '/' . $rendered, $body)) {
                self::remove_tree($stage);
                self::fail("could not stage $rendered");
            }
        }

        $output = [];
        $code   = 1;
        exec(
            escapeshellarg(PHP_BINARY) . ' '
                . escapeshellarg($flavor . '/strip.php') . ' '
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
        if (! is_dir($to) && ! mkdir($to, 0777, true) && ! is_dir($to)) {
            self::fail("could not create $to");
        }
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
            if (! copy($source, $target)) {
                self::fail("could not stage $source");
            }
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
