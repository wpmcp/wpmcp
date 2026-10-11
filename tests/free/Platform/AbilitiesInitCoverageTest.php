<?php

namespace WPMCP\Tests\Free\Platform;

use WPMCP\Plugin;

/**
 * The registration Abilities_Init_Listener runs before the first test class
 * (issue #534) is part of the coverage report (issue #564). It runs outside
 * PHPUnit's per-test coverage window, so without its own measurement the
 * registration code and the file header of every tool class it loads read as
 * never executed.
 */
class AbilitiesInitCoverageTest extends \WP_UnitTestCase
{
    public function test_the_listener_registration_is_in_the_coverage_report(): void
    {
        $result   = $this->getTestResultObject();
        $coverage = null === $result ? null : $result->getCodeCoverage();
        if (null === $coverage) {
            $this->markTestSkipped('This run collects no coverage.');
        }

        $lines = $coverage->getData()->lineCoverage();

        // The category registration only ever runs inside the registry's
        // first build, which is the listener's.
        $plugin   = (string) realpath(dirname(__DIR__, 3) . '/src/Plugin.php');
        $category = (new \ReflectionMethod(Plugin::class, 'register_ability_category'))->getStartLine() + 2;
        $this->assertContains('abilities-init', $lines[ $plugin ][ $category ] ?? [], 'register_ability_category() is not measured.');

        // A tool class is first loaded by that registration, so its file
        // header runs there and nowhere else.
        $tool   = (string) (new \ReflectionClass(\WPMCP\Tools\Get_Page::class))->getFileName();
        $header = $this->first_line_containing($tool, "defined('ABSPATH')");
        $this->assertContains('abilities-init', $lines[ $tool ][ $header ] ?? [], 'Tool class file headers are not measured.');
    }

    private function first_line_containing(string $file, string $needle): int
    {
        foreach ((array) file($file) as $i => $line) {
            if (false !== strpos((string) $line, $needle)) {
                return $i + 1;
            }
        }
        $this->fail("No line of $file contains $needle.");
    }
}
