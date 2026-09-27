<?php

namespace WPMCP\Tests\Free\Flavors;

/**
 * What the wp.org directory build actually receives for build-page (#162).
 *
 * Every other test in this repository runs against the checkout, which is the
 * FULL build: the Elementor dialect is present there and is supposed to be.
 * The directory cut is a different artifact, produced by
 * scripts/flavors/wporg/strip.php, and until this file existed nothing ever
 * executed it. The strip's exact-string pass fails loudly when a string it
 * expects has moved, but a string count says nothing about what the stripped
 * code then DOES, so the surgery inside Build_Page and Page_Spec was covered
 * by php -l and a grep.
 *
 * So: stage the tree once, strip it, and assert on the result. The Page_Spec
 * assertions run the stripped validator for real in a subprocess (it is a
 * pure class with no WordPress dependency), because "the constant now reads
 * ['gutenberg']" and "an elementor spec is rejected" are not the same claim.
 * The rest are structural: no paid predicate, no pay-to-unlock copy, and no
 * residue naming a dialect this build does not contain — a docblock counts,
 * since the directory reviewer reads those too.
 */
class WporgStripBuildPageTest extends \WP_UnitTestCase
{
    private static string $stage = '';
    private static string $root  = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$root  = dirname(__DIR__, 3);
        self::$stage = rtrim(sys_get_temp_dir(), '/') . '/wpmcp-strip-' . uniqid('', false) . '/wpmcp';

        mkdir(self::$stage, 0777, true);
        self::copy_tree(self::$root . '/src', self::$stage . '/src');
        copy(self::$root . '/scripts/flavors/wporg/wpmcp.php', self::$stage . '/wpmcp.php');

        $output = [];
        $status = 0;
        exec(
            sprintf(
                'php %s %s 2>&1',
                escapeshellarg(self::$root . '/scripts/flavors/wporg/strip.php'),
                escapeshellarg(self::$stage)
            ),
            $output,
            $status
        );

        if (0 !== $status) {
            self::fail("strip.php failed against the stage:\n" . implode("\n", $output));
        }
    }

    public static function tearDownAfterClass(): void
    {
        if ('' !== self::$stage && is_dir(dirname(self::$stage))) {
            self::remove_tree(dirname(self::$stage));
        }
        parent::tearDownAfterClass();
    }

    private static function copy_tree(string $from, string $to): void
    {
        mkdir($to, 0777, true);
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($items as $item) {
            $target = $to . '/' . $items->getSubPathName();
            if ($item->isDir()) {
                mkdir($target, 0777, true);
                continue;
            }
            copy($item->getPathname(), $target);
        }
    }

    private static function remove_tree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    private function stripped(string $relative): string
    {
        $path = self::$stage . '/' . $relative;
        $this->assertFileExists($path, $relative . ' should still be in the directory build');

        return (string) file_get_contents($path);
    }

    /**
     * Run the STRIPPED Page_Spec in a fresh process and report what
     * validate() did with the given spec. Page_Spec is pure and has no
     * WordPress dependency beyond the ABSPATH guard, so it loads standalone;
     * a subprocess is needed only because this process has already autoloaded
     * the full-build class of the same name.
     *
     * @param array<string, mixed> $spec
     */
    private function validate_with_stripped_page_spec(array $spec): string
    {
        $harness = <<<'PHP'
define('ABSPATH', __DIR__);
// The only WordPress function Page_Spec reaches for; it is used to measure
// the encoded spec against MAX_BYTES, nothing more.
function wp_json_encode($data) { return json_encode($data); }
require $argv[1];
try {
    \WPMCP\Tools\Compose\Page_Spec::validate(json_decode($argv[2], true));
    echo 'OK';
} catch (\Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage();
}
PHP;

        $output = [];
        exec(
            sprintf(
                'php -r %s %s %s 2>&1',
                escapeshellarg($harness),
                escapeshellarg(self::$stage . '/src/Tools/Compose/Page_Spec.php'),
                escapeshellarg((string) wp_json_encode($spec))
            ),
            $output
        );

        return implode("\n", $output);
    }

    public function test_stripped_page_spec_rejects_the_builder_dialect_with_the_neutral_error(): void
    {
        $result = $this->validate_with_stripped_page_spec([
            'title'   => 'Builder Landing',
            'dialect' => 'elementor',
            'content' => [['type' => 'container', 'children' => []]],
        ]);

        $this->assertStringContainsString('InvalidArgumentException', $result);
        $this->assertStringContainsString('spec.dialect', $result);
        $this->assertStringContainsString('gutenberg', $result);
        $this->assertStringNotContainsStringIgnoringCase('pro', $result);
        $this->assertStringNotContainsStringIgnoringCase('upgrade', $result);
        $this->assertStringNotContainsStringIgnoringCase('license', $result);
    }

    public function test_stripped_page_spec_still_validates_a_gutenberg_spec(): void
    {
        $result = $this->validate_with_stripped_page_spec([
            'title'   => 'Plain Page',
            'content' => [
                ['type' => 'heading', 'settings' => ['text' => 'Hello', 'level' => 2]],
                ['type' => 'paragraph', 'settings' => ['text' => 'Body copy.']],
            ],
        ]);

        $this->assertSame('OK', $result);
    }

    public function test_stripped_page_spec_ships_no_builder_validation_code_or_docs(): void
    {
        $source = $this->stripped('src/Tools/Compose/Page_Spec.php');

        $this->assertStringContainsString("private const DIALECTS = ['gutenberg'];", $source);
        $this->assertStringNotContainsString('ELEMENTOR_CONTAINERS', $source);
        $this->assertStringNotContainsString('elementor_node', $source);
        $this->assertStringNotContainsString('Elementor dialect node types', $source);
        $this->assertStringNotContainsString('both dialects', $source);
        $this->assertStringNotContainsString('Elementor widget known', $source);
    }

    public function test_stripped_build_page_ships_no_paid_predicate_and_no_pay_to_unlock_copy(): void
    {
        $source = $this->stripped('src/Tools/Compose/Build_Page.php');

        $this->assertStringNotContainsString('build-page-builder', $source);
        $this->assertStringNotContainsString('Gate::', $source);
        $this->assertStringNotContainsString('Pro\\Gate', $source);
        $this->assertStringNotContainsString('PRO', $source);
    }

    public function test_stripped_build_page_ships_no_builder_code_or_residue(): void
    {
        $source = $this->stripped('src/Tools/Compose/Build_Page.php');

        // Code the dialect needed.
        $this->assertStringNotContainsString('Elementor_Composer', $source);
        $this->assertStringNotContainsString('Elementor_Page_Data', $source);
        $this->assertStringNotContainsString('widget_problem', $source);
        $this->assertStringNotContainsString('Widget_Catalog', $source);
        $this->assertStringNotContainsString('Atomic_Prop_Schema', $source);
        $this->assertStringNotContainsString("'elementor'", $source);

        // Reply keys Block_Composer can never populate, and the comments and
        // docblocks that described them.
        $this->assertStringNotContainsString('unknown_widgets', $source);
        $this->assertStringNotContainsString('coerced', $source);
        $this->assertStringNotContainsString('builder dialect', $source);
        $this->assertStringNotContainsString('Elementor widgets known', $source);
    }

    public function test_the_builder_composer_is_absent_from_the_directory_build(): void
    {
        $this->assertFileDoesNotExist(self::$stage . '/src/Tools/Compose/Elementor_Composer.php');
        $this->assertFileExists(self::$stage . '/src/Tools/Compose/Block_Composer.php');
    }

    public function test_stripped_build_page_ability_offers_one_dialect_and_no_paid_copy(): void
    {
        $source = $this->stripped('src/Plugin.php');

        $start = strpos($source, "'wpmcp/build-page'");
        $this->assertNotFalse($start, 'the build-page ability should still be registered');
        $registration = substr($source, $start, 4000);

        $this->assertStringContainsString("'enum' => [ 'gutenberg' ]", $registration);
        $this->assertStringNotContainsString('elementor', $registration);
        $this->assertStringNotContainsString('(PRO', $registration);
        $this->assertStringNotContainsString('unknown widget types', $registration);
        $this->assertStringNotContainsString('atomic props', $registration);
    }

    public function test_every_file_the_strip_rewrote_still_parses(): void
    {
        foreach (
            [
            'src/Plugin.php',
            'src/Tools/Compose/Build_Page.php',
            'src/Tools/Compose/Page_Spec.php',
            ] as $relative
        ) {
            $status = 0;
            $output = [];
            exec(sprintf('php -l %s 2>&1', escapeshellarg(self::$stage . '/' . $relative)), $output, $status);
            $this->assertSame(0, $status, $relative . " does not parse after the strip:\n" . implode("\n", $output));
        }
    }
}
