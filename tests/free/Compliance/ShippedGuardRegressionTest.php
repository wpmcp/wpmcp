<?php

namespace WPMCP\Tests\Free\Compliance;

use WPMCP\Compliance\Rules\Direct_File_Access_Rule;

/**
 * Regression pin for issue #170 (finding B-21): Plugin Check's
 * Direct_File_Access_Check accepts five exact guard shapes, and the guard
 * src/Plugin.php used to emit carried a WPMCP_TESTING conjunct that matched
 * none of them, so Plugin Check reported the file as unprotected.
 *
 * The fix is a bare `if (! defined('ABSPATH')) { exit; }`; the test bootstrap
 * gets ABSPATH from the WordPress test library, so no escape hatch is needed.
 * These tests run the repo's own Direct_File_Access_Rule (a verbatim port of
 * the checker's patterns) over the real shipped files, so the conjunct cannot
 * quietly come back in any of them.
 */
class ShippedGuardRegressionTest extends Compliance_Test_Case
{
    /**
     * Entry points and the file the original finding pointed at, as shipped.
     *
     * @return array<string,array{string,string}> case => [repo path, fixture path]
     */
    public function shipped_file_provider(): array
    {
        return [
            'main plugin file' => ['wpmcp.php', 'wpmcp.php'],
            'plugin bootstrap' => ['src/Plugin.php', 'src/Plugin.php'],
            'wporg flavor entry file' => ['scripts/flavors/wporg/wpmcp.php', 'wpmcp.php'],
            'woocommerce flavor entry file' => [
                'scripts/flavors/woocommerce/wpmcp-for-woocommerce.php',
                'wpmcp-for-woocommerce.php',
            ],
        ];
    }

    /**
     * @dataProvider shipped_file_provider
     */
    public function test_shipped_file_carries_a_guard_plugin_check_accepts(
        string $repo_path,
        string $fixture_path
    ): void {
        $contents = file_get_contents(self::repo_root() . '/' . $repo_path);
        $this->assertNotFalse($contents, sprintf('%s should exist in the checkout', $repo_path));

        // Each file is scanned exactly as it ships: the flavor entry files are
        // copied to the zip root by the build scripts, so they take their
        // shipped name in the fixture tree.
        $findings = $this->findings(new Direct_File_Access_Rule(), [
            $fixture_path => $contents,
        ]);

        $this->assert_clean($findings);
    }

    /**
     * The original bug in one line: no shipped source file may pair the
     * ABSPATH guard with another conjunct. This is stricter than the rule
     * above (which would also accept a file with two guards, one accepted and
     * one loose) and catches the exact regression the issue describes.
     */
    public function test_no_source_file_pairs_the_abspath_guard_with_a_conjunct(): void
    {
        $offenders = [];
        foreach ($this->source_files() as $path) {
            $contents = file_get_contents($path);
            if (false === $contents) {
                continue;
            }
            if (preg_match(
                "/defined\s*\(\s*['\"]ABSPATH['\"]\s*\)\s*(?:&&|\|\||and\b|or\b)\s*!?\s*defined/i",
                $contents
            )) {
                $offenders[] = substr($path, strlen(self::repo_root()) + 1);
            }
        }
        $this->assertSame(
            [],
            $offenders,
            'these files pair the ABSPATH guard with another defined() conjunct, '
            . 'which Plugin Check reports as a missing guard'
        );
    }

    /**
     * @return string[] absolute paths of every shipped PHP source file
     */
    private function source_files(): array
    {
        $files = [
            self::repo_root() . '/wpmcp.php',
            self::repo_root() . '/scripts/flavors/wporg/wpmcp.php',
            self::repo_root() . '/scripts/flavors/woocommerce/wpmcp-for-woocommerce.php',
        ];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                self::repo_root() . '/src',
                \FilesystemIterator::SKIP_DOTS
            )
        );
        foreach ($iterator as $file) {
            if ('php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }
        return $files;
    }

    private static function repo_root(): string
    {
        return dirname(__DIR__, 3);
    }
}
