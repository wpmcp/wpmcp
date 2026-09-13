<?php

namespace WPMCP\Tests\Free\Compliance;

use WPMCP\Compliance\Profile;
use WPMCP\Compliance\Rule_Context;
use WPMCP\Compliance\Rules\Direct_File_Access_Rule;

/**
 * Regression pin for issue #170 (finding B-21).
 *
 * Plugin Check's Direct_File_Access_Check accepts five exact guard shapes, and
 * only near the head of the file. src/Plugin.php failed it twice over: first
 * with a WPMCP_TESTING conjunct that matched none of the five shapes, then, once
 * the conjunct was dropped, with the bare guard parked at line 297 below 286 use
 * statements, out of the checker's reach. Both are now encoded in
 * Direct_File_Access_Rule, and these tests run that rule over the checkout.
 *
 * What is genuinely new here is the flavor entry files under scripts/flavors:
 * Plugin_Source::DEFAULT_EXCLUDES skips scripts/, so neither `composer
 * compliance` nor the zip gates ever look at them in the checkout, and the
 * woocommerce build had no compliance gate at all until this change. For
 * wpmcp.php and src/Plugin.php these tests overlap the checkout-wide
 * `composer compliance` run and build-wporg-release.sh's gate 6; they are kept
 * because they fail in seconds inside the unit suite and name the issue.
 *
 * These tests read the checkout, which is the source the builds derive their
 * zips from, not the zip bytes. The wporg zip's src/ is rewritten by
 * scripts/flavors/wporg/strip.php and the entry file is produced by
 * substituting {{VERSION}}, so the artifact itself is only ever certified by
 * the engine run inside the build scripts.
 */
class ShippedGuardRegressionTest extends Compliance_Test_Case
{
    /**
     * The two entry points the issue names, plus every flavor entry file found
     * on disk, so a new flavor is covered the day it lands rather than the day
     * someone remembers to extend a literal list.
     *
     * @return array<string,array{string,string}> case => [repo path, shipped path]
     */
    public static function shipped_file_provider(): array
    {
        $cases = [
            'main plugin file' => ['wpmcp.php', 'wpmcp.php'],
            'plugin bootstrap' => ['src/Plugin.php', 'src/Plugin.php'],
        ];
        foreach (self::flavor_entry_files() as $relative) {
            // The build scripts write each flavor entry file to the zip root
            // under its own basename, so that is the path the rule sees.
            $cases[basename(dirname($relative)) . ' flavor entry file'] = [$relative, basename($relative)];
        }
        return $cases;
    }

    /**
     * @dataProvider shipped_file_provider
     */
    public function test_shipped_file_carries_a_guard_plugin_check_accepts(
        string $repo_path,
        string $shipped_path
    ): void {
        $path = self::repo_root() . '/' . $repo_path;
        $this->assertFileExists($path, sprintf('%s should exist in the checkout', $repo_path));

        $contents = (string) file_get_contents($path);
        // An empty file is not a passing file: Direct_File_Access_Rule skips
        // blank sources outright, so without this the test would go green on a
        // truncated entry point.
        $this->assertNotSame('', trim($contents), sprintf('%s should not be empty', $repo_path));

        $findings = $this->findings(new Direct_File_Access_Rule(), [
            $shipped_path => $contents,
        ]);

        $this->assert_clean($findings);
    }

    /**
     * At least one flavor entry file has to exist, otherwise the provider above
     * quietly shrinks to the two hardcoded cases and the new coverage is gone.
     */
    public function test_the_flavor_entry_files_are_discovered(): void
    {
        $this->assertNotSame([], self::flavor_entry_files());
    }

    /**
     * The original bug in one assertion: every PHP file the plugin ships from
     * the checkout carries a guard Plugin Check accepts, in a place it looks.
     *
     * This runs the rule rather than a bespoke regex on purpose. A regex over
     * raw text is both too narrow (it has to guess at operand order, at WPINC
     * versus ABSPATH, at parenthesisation) and too wide (it matches the guard
     * shape quoted inside a docblock, which Direct_File_Access_Rule strips
     * before matching, as the checker does). The rule already draws the line
     * the issue is about.
     */
    public function test_no_shipped_source_file_carries_a_guard_plugin_check_rejects(): void
    {
        $context = Rule_Context::for_path(self::repo_root(), Profile::wporg_free());
        $this->assert_clean((new Direct_File_Access_Rule())->check($context));
    }

    /**
     * The specific shape of B-21's second half, pinned by position rather than
     * by the rule, so a future widening of the rule's window cannot silently
     * let the bootstrap slide back down under the use block.
     */
    public function test_the_bootstrap_guard_sits_above_the_use_block(): void
    {
        $lines = file(self::repo_root() . '/src/Plugin.php', FILE_IGNORE_NEW_LINES);
        $this->assertNotFalse($lines);

        $guard = null;
        $first_use = null;
        foreach ($lines as $index => $line) {
            if (null === $guard && preg_match("/^\s*if\s*\(\s*!\s*defined\(\s*'ABSPATH'\s*\)\s*\)/", $line)) {
                $guard = $index + 1;
            }
            if (null === $first_use && preg_match('/^use\s+WPMCP\\\\/', $line)) {
                $first_use = $index + 1;
            }
        }

        $this->assertNotNull($guard, 'src/Plugin.php should carry a bare ABSPATH guard');
        $this->assertNotNull($first_use, 'src/Plugin.php should still import its collaborators');
        $this->assertLessThan(
            $first_use,
            $guard,
            'the ABSPATH guard must stay above the use block; below it, Plugin Check cannot see it'
        );
    }

    /**
     * Entry files under scripts/flavors, identified by their plugin header so
     * that build tooling living in the same directory (strip.php) is not
     * mistaken for something that ships.
     *
     * @return string[] repo-relative paths
     */
    private static function flavor_entry_files(): array
    {
        $found = [];
        foreach (glob(self::repo_root() . '/scripts/flavors/*/*.php') ?: [] as $path) {
            $head = (string) file_get_contents($path, false, null, 0, 4096);
            if (false === strpos($head, 'Plugin Name:')) {
                continue;
            }
            $found[] = substr($path, strlen(self::repo_root()) + 1);
        }
        sort($found);
        return $found;
    }

    private static function repo_root(): string
    {
        return dirname(__DIR__, 3);
    }
}
