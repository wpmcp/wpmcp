<?php

namespace WPMCP\Tests\Free\Platform;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/.github/ci/src-scanners.php';

/**
 * Issue #1016: impact selection never ran OptInSettingsTest on a PR that
 * added an opt-in filter, because a test that reads the whole of src/ has no
 * coverage map entry for a new file. .github/ci/impact.php now runs every
 * test that scans src/ on every PR; this pins that the detection finds the
 * registry and guard tests it exists for, and nothing that does not scan.
 */
class ImpactSrcScannersTest extends TestCase
{
    public function test_the_registry_and_guard_tests_are_always_run(): void
    {
        $root  = dirname(__DIR__, 3);
        $cwd   = getcwd();
        chdir($root);
        try {
            $tests = [];
            foreach ([ 'tests/free', 'tests/pro' ] as $dir) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    if (str_ends_with($f->getFilename(), 'Test.php')) {
                        $tests[] = $f->getPathname();
                    }
                }
            }
            $found = array_map(static fn (string $p): string => basename($p, '.php'), source_tree_scanning_tests($tests));
        } finally {
            chdir((string) $cwd);
        }

        foreach ([ 'OptInSettingsTest', 'OptInRequiredCatalogTest', 'DataRegistryGuardTest', 'DirectPostWriteGuardTest', 'CronHookRegistryTest', 'Php81CompatibilityTest' ] as $name) {
            $this->assertContains($name, $found, $name . ' scans src/ and must run on every PR');
        }
        $this->assertNotContains('ImpactSrcScannersTest', $found, 'a test that only names src/ in prose is not a scanner');
    }
}
