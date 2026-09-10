<?php

namespace WPMCP\Tests\Free\Release;

/**
 * The compatibility headers that ship are spread over four files: the root
 * readme, the two flavor readmes, and the wp.org loader. Nothing in the build
 * derives one from another, so they drift independently and Plugin Check only
 * complains about the one file that happens to be in the zip it is given.
 *
 * Issue #172 (finding B-23) is that drift: `Tested up to` trailing the current
 * WordPress release is a Plugin Check error and removes the plugin from
 * directory search. A checklist item cannot catch it, so these tests are the
 * gate and the checklist documents them.
 *
 * TESTED_UP_TO_FLOOR is the pin. Raise it when a WordPress major ships and the
 * smoke pass against it is recorded in docs/release-checklist.md; the run goes
 * red until every shipped header follows.
 */
class ReleaseHeadersTest extends \WP_UnitTestCase
{
    /**
     * The WordPress release the shipped headers must declare, at minimum.
     * Current release per api.wordpress.org stable-check at the time of the
     * 0.8.1 release.
     */
    private const TESTED_UP_TO_FLOOR = '7.1';

    /** Readmes whose header block reaches a user or a reviewer. */
    private const SHIPPED_READMES = [
        'readme.txt',
        'scripts/flavors/wporg/readme.txt',
        'scripts/flavors/woocommerce/readme.txt',
    ];

    /** Loader headers that carry their own Tested up to line. */
    private const SHIPPED_LOADERS = [
        'scripts/flavors/wporg/wpmcp.php',
    ];

    /**
     * Terms guideline 12 bars from a tag list, matched as substrings of the
     * slugified tag the way Trademark_Rule and Plugin Check match them, so
     * "claude mcp" is caught as well as "claude". The vendor marks mirror
     * Trademark_Rule::VENDOR_MARKS; "claude" is the one issue #169 removed
     * and this keeps removed.
     */
    private const RESTRICTED_TERMS = [
        'claude', 'anthropic', 'chatgpt', 'openai', 'gemini', 'copilot', 'wordpress', 'woo',
    ];

    /**
     * Tags allowed as a whole even though they carry a restricted term:
     * Trademark_Rule::FOR_USE_EXCEPTIONS, which is why the WooCommerce readme
     * may tag "woocommerce" while "woo" alone stays barred.
     */
    private const TAG_EXCEPTIONS = ['woocommerce'];

    private function repository(): string
    {
        return dirname(__DIR__, 3);
    }

    private function contents(string $relative): string
    {
        $path = $this->repository() . '/' . $relative;
        $this->assertFileExists($path, $relative . ' is a shipped file and must exist');

        return (string) file_get_contents($path);
    }

    private function readme_header(string $relative, string $header): string
    {
        $this->assertMatchesRegularExpression(
            '/^' . preg_quote($header, '/') . ':\s*(.+)$/mi',
            $this->contents($relative),
            $relative . ' is missing the "' . $header . '" header'
        );
        preg_match('/^' . preg_quote($header, '/') . ':\s*(.+)$/mi', $this->contents($relative), $matches);

        return trim($matches[1]);
    }

    private function loader_header(string $relative, string $header): string
    {
        preg_match('/^\s*\*\s*' . preg_quote($header, '/') . ':\s*(.+)$/mi', $this->contents($relative), $matches);
        $this->assertNotEmpty($matches, $relative . ' is missing the "' . $header . '" loader header');

        return trim($matches[1]);
    }

    /** Every shipped header declares the same WordPress version. */
    public function test_tested_up_to_agrees_across_every_shipped_file(): void
    {
        $declared = [];
        foreach (self::SHIPPED_READMES as $readme) {
            $declared[$readme] = $this->readme_header($readme, 'Tested up to');
        }
        foreach (self::SHIPPED_LOADERS as $loader) {
            $declared[$loader] = $this->loader_header($loader, 'Tested up to');
        }

        $this->assertCount(
            1,
            array_unique($declared),
            'Tested up to disagrees across shipped files: ' . wp_json_encode($declared)
        );
    }

    /** And that version is not behind the pinned WordPress release. */
    public function test_tested_up_to_is_not_behind_the_pinned_release(): void
    {
        foreach (array_merge(self::SHIPPED_READMES, self::SHIPPED_LOADERS) as $file) {
            $declared = str_ends_with($file, '.php')
                ? $this->loader_header($file, 'Tested up to')
                : $this->readme_header($file, 'Tested up to');

            $this->assertTrue(
                version_compare($declared, self::TESTED_UP_TO_FLOOR, '>='),
                sprintf(
                    '%s declares Tested up to %s, behind the pinned %s. Plugin Check errors and the '
                        . 'plugin drops out of directory search.',
                    $file,
                    $declared,
                    self::TESTED_UP_TO_FLOOR
                )
            );
        }
    }

    /** Plugin Check rejects anything but a bare numeric version. */
    public function test_tested_up_to_is_numeric_only(): void
    {
        foreach (array_merge(self::SHIPPED_READMES, self::SHIPPED_LOADERS) as $file) {
            $declared = str_ends_with($file, '.php')
                ? $this->loader_header($file, 'Tested up to')
                : $this->readme_header($file, 'Tested up to');

            $this->assertMatchesRegularExpression('/^\d+(\.\d+)*$/', $declared, $file . ' must carry numbers only');
        }
    }

    /**
     * Guideline 15: the readme Stable tag equals the Version in the main file.
     * The flavor readmes take {{VERSION}} from the build, so only the root pair
     * can drift, and a readme-only change that skipped its patch bump shows up
     * here as well.
     */
    public function test_root_stable_tag_matches_the_loader_version(): void
    {
        $loader = $this->contents('wpmcp.php');

        preg_match('/^\s*\*\s*Version:\s*(.+)$/mi', $loader, $header);
        preg_match("/define\(\s*'WPMCP_VERSION',\s*'([^']+)'\s*\)/", $loader, $constant);

        $this->assertNotEmpty($header, 'wpmcp.php is missing its Version header');
        $this->assertNotEmpty($constant, 'wpmcp.php is missing WPMCP_VERSION');

        $this->assertSame(trim($header[1]), $constant[1], 'the Version header and WPMCP_VERSION disagree');
        $this->assertSame(
            $constant[1],
            $this->readme_header('readme.txt', 'Stable tag'),
            'readme.txt Stable tag must equal WPMCP_VERSION'
        );
    }

    /** Requires at least and Requires PHP agree everywhere too. */
    public function test_requires_headers_agree_across_shipped_readmes(): void
    {
        foreach (['Requires at least', 'Requires PHP'] as $header) {
            $declared = [];
            foreach (self::SHIPPED_READMES as $readme) {
                $declared[$readme] = $this->readme_header($readme, $header);
            }
            $this->assertCount(1, array_unique($declared), $header . ' disagrees: ' . wp_json_encode($declared));
        }
    }

    /** No trademark or restricted term survives in a shipped tag list. */
    public function test_shipped_tag_lists_carry_no_restricted_term(): void
    {
        foreach (self::SHIPPED_READMES as $readme) {
            $tags = array_map('trim', explode(',', strtolower($this->readme_header($readme, 'Tags'))));

            $this->assertLessThanOrEqual(5, count($tags), $readme . ' exceeds the five tag maximum');
            foreach ($tags as $tag) {
                if (in_array($tag, self::TAG_EXCEPTIONS, true)) {
                    continue;
                }
                $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $tag), '-');
                foreach (self::RESTRICTED_TERMS as $term) {
                    $this->assertStringNotContainsString(
                        $term,
                        $slug,
                        sprintf('%s tag "%s" contains the restricted term "%s"', $readme, $tag, $term)
                    );
                }
            }
        }
    }

    /** The checklist that documents this gate has to keep naming every file it covers. */
    public function test_the_release_checklist_names_every_gated_file(): void
    {
        $checklist = $this->contents('docs/release-checklist.md');

        foreach (array_merge(self::SHIPPED_READMES, self::SHIPPED_LOADERS, ['wpmcp.php']) as $file) {
            // Backtick-delimited, as the checklist writes paths: a bare
            // "readme.txt" would also match inside "scripts/flavors/wporg/readme.txt".
            $this->assertStringContainsString(
                '`' . $file . '`',
                $checklist,
                'docs/release-checklist.md never mentions `' . $file . '`'
            );
        }
    }

    /**
     * The header says "tested"; this is what makes that true. CI installs the
     * WordPress release the headers declare, so raising TESTED_UP_TO_FLOOR
     * without moving the `wp:` matrix axis in ci.yml fails here rather than
     * shipping a claim the suite never exercised. The `Requires at least`
     * floor is pinned the same way, on its own matrix leg.
     */
    public function test_ci_installs_the_pinned_release_and_the_requires_floor(): void
    {
        $workflow = $this->contents('.github/workflows/ci.yml');

        preg_match_all('/^\s*(?:-\s*\{[^}]*)?\bwp:\s*\[?\s*\'?(\d+(?:\.\d+)+)\'?/m', $workflow, $matches);
        $installed = array_unique($matches[1]);

        $this->assertNotEmpty($installed, 'ci.yml no longer declares a wp: matrix axis for install-wp-tests.sh');
        $this->assertStringContainsString(
            'install-wp-tests.sh wordpress_test root root 127.0.0.1 ${{ matrix.wp }}',
            $workflow,
            'ci.yml must install the matrix WordPress version, not a hardcoded one'
        );
        $this->assertContains(
            self::TESTED_UP_TO_FLOOR,
            $installed,
            sprintf(
                'the headers declare Tested up to %s but CI installs %s; bump the wp: axis in ci.yml with the floor',
                self::TESTED_UP_TO_FLOOR,
                implode(', ', $installed)
            )
        );
        $this->assertContains(
            $this->readme_header('readme.txt', 'Requires at least'),
            $installed,
            'the Requires at least floor is not on any CI matrix leg: ' . implode(', ', $installed)
        );
    }
}
