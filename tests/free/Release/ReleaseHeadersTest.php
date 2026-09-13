<?php

namespace WPMCP\Tests\Free\Release;

use WPMCP\Compliance\Profile;
use WPMCP\Compliance\Rule_Context;
use WPMCP\Compliance\Rules\Trademark_Rule;
use WPMCP\Compliance\Severity;

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
 * red until every shipped header follows. The pin is tied in both directions:
 * every header must equal it (not merely reach it), and the CI matrix must
 * install both the pin and the release the headers declare, so a header bump
 * without a matching matrix change goes red rather than shipping an untested
 * claim.
 *
 * The display name is gated here too (issue #168, findings B-19 and L-04).
 * Each shipped readme has a loader beside it whose Plugin Name header must be
 * byte-identical to the readme title, or Plugin Check reports
 * mismatched_plugin_name against whichever zip it is handed, and neither may
 * carry a restricted term other than in the trailing "for <mark>" form that
 * guideline 17 permits.
 */
class ReleaseHeadersTest extends \WP_UnitTestCase
{
    /**
     * The WordPress release every shipped header must declare, exactly.
     * Current release per api.wordpress.org stable-check at the time of the
     * 0.8.1 release.
     */
    private const TESTED_UP_TO_FLOOR = '7.1';

    /**
     * Loaders that carry their own Requires at least and Requires PHP lines.
     * Core reads these from the loader when present, so they gate installs
     * the same way the readme headers gate the listing, and must agree.
     */
    private const REQUIRES_LOADERS = [
        'wpmcp.php',
        'scripts/flavors/wporg/wpmcp.php',
        'scripts/flavors/woocommerce/wpmcp-for-woocommerce.php',
    ];

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
     * The loader whose Plugin Name header each shipped readme's title has to
     * match, keyed by the readme. Keyed that way so this list and
     * SHIPPED_READMES cannot describe different artifacts:
     * test_every_shipped_readme_has_a_loader_in_the_name_gate holds the keys
     * to SHIPPED_READMES.
     */
    private const SHIPPED_NAME_PAIRS = [
        'readme.txt' => 'wpmcp.php',
        'scripts/flavors/wporg/readme.txt' => 'scripts/flavors/wporg/wpmcp.php',
        'scripts/flavors/woocommerce/readme.txt' => 'scripts/flavors/woocommerce/wpmcp-for-woocommerce.php',
    ];

    /**
     * The two artifacts that install into the same wpmcp/ directory under the
     * same text domain: the directory zip and the self-hosted zip. A divergent
     * Plugin Name between them renames the plugin on update.
     */
    private const WPMCP_SLUG_LOADERS = ['wpmcp.php', 'scripts/flavors/wporg/wpmcp.php'];

    /**
     * Terms guideline 12 bars from a tag list on top of the vendor marks:
     * "wordpress" (guideline 17) and the "woo" portmanteau Plugin Check
     * matches. The vendor marks themselves come from Trademark_Rule so this
     * test and the compliance engine cannot disagree about who is a vendor.
     */
    private const WPORG_RESTRICTED_TERMS = ['wordpress', 'woo'];

    /** The readme the wp.org submission build stages as the listing. */
    private const WPORG_LISTING_README = 'scripts/flavors/wporg/readme.txt';

    /** The document that pins the listing's tag list. */
    private const SUBMISSION_DOC = 'WPORG-SUBMISSION.md';

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

    /**
     * Terms guideline 12 bars from a tag list, matched as substrings of the
     * slugified tag the way Trademark_Rule and Plugin Check match them, so
     * "claude mcp" is caught as well as "claude". "claude" is the one issue
     * #169 removed and this keeps removed.
     *
     * @return string[]
     */
    private function restricted_terms(): array
    {
        return array_merge(Trademark_Rule::VENDOR_MARKS, self::WPORG_RESTRICTED_TERMS);
    }

    /**
     * The header block is everything above the first blank line. Reading a
     * header from there rather than from the whole file keeps a changelog or
     * FAQ line that happens to start with "Tags:" from being parsed as one.
     */
    private function readme_header_block(string $relative): string
    {
        $contents = str_replace("\r\n", "\n", $this->contents($relative));
        $parts = preg_split('/\n[ \t]*\n/', $contents, 2);

        return (string) $parts[0];
    }

    /**
     * The value is captured with [ \t]* and [^\r\n]+ rather than \s* and .+:
     * \s matches a newline, so an empty header would otherwise capture the
     * next line and the agreement tests would compare the wrong values.
     */
    private function readme_header(string $relative, string $header): string
    {
        $block = $this->readme_header_block($relative);
        $pattern = '/^' . preg_quote($header, '/') . ':[ \t]*([^\r\n]+)/mi';
        $this->assertMatchesRegularExpression(
            $pattern,
            $block,
            $relative . ' is missing the "' . $header . '" header or its value is empty'
        );
        preg_match($pattern, $block, $matches);

        return trim($matches[1]);
    }

    /** @return string[] the Tags header, split, trimmed and lowercased */
    private function readme_tags(string $relative): array
    {
        $tags = array_map('trim', explode(',', strtolower($this->readme_header($relative, 'Tags'))));

        return array_values(array_filter($tags, static fn (string $tag): bool => '' !== $tag));
    }

    /** The `=== Title ===` line, which is the listing's display name. */
    private function readme_title(string $relative): string
    {
        $block = $this->readme_header_block($relative);
        $matched = preg_match('/^===\s*(.+?)\s*===\s*$/m', $block, $matches);
        $this->assertSame(1, $matched, $relative . ' has no === title === line at the top of its header block');

        return $matches[1];
    }

    /**
     * Trademark_Rule run over a display name, on a two-file plugin tree that
     * is otherwise clean, so the findings that come back are about the name.
     * The tree carries the name as both the header and the readme title, the
     * way the shipped pairs do once parity holds.
     *
     * @return \WPMCP\Compliance\Finding[]
     */
    private function trademark_findings_for_name(string $name): array
    {
        $root = rtrim(sys_get_temp_dir(), '/') . '/name-gate-' . bin2hex(random_bytes(6));
        mkdir($root, 0777, true);
        $slug = basename($root);
        $main_file = $root . '/' . $slug . '.php';
        $readme = $root . '/readme.txt';

        file_put_contents(
            $main_file,
            "<?php\n/**\n * Plugin Name: {$name}\n * Version: 1.0.0\n * Text Domain: {$slug}\n */\n"
        );
        file_put_contents($readme, "=== {$name} ===\nStable tag: 1.0.0\n\nShort description.\n");

        try {
            return (new Trademark_Rule())->check(Rule_Context::for_path($root, Profile::wporg_free()));
        } finally {
            unlink($main_file);
            unlink($readme);
            rmdir($root);
        }
    }

    private function loader_header(string $relative, string $header): string
    {
        preg_match(
            '/^[ \t]*\*[ \t]*' . preg_quote($header, '/') . ':[ \t]*([^\r\n]+)/mi',
            $this->contents($relative),
            $matches
        );
        $this->assertNotEmpty($matches, $relative . ' is missing the "' . $header . '" loader header or its value is empty');

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

    /**
     * And that version is the pinned WordPress release, exactly. Equality
     * rather than ">=": a header ahead of the pin is a claim the suite has not
     * backed (the pin only moves once the smoke pass is recorded and the CI
     * matrix installs the release), and a header behind it is the Plugin Check
     * error issue #172 is about.
     */
    public function test_tested_up_to_equals_the_pinned_release(): void
    {
        foreach (array_merge(self::SHIPPED_READMES, self::SHIPPED_LOADERS) as $file) {
            $declared = str_ends_with($file, '.php')
                ? $this->loader_header($file, 'Tested up to')
                : $this->readme_header($file, 'Tested up to');

            $this->assertSame(
                self::TESTED_UP_TO_FLOOR,
                $declared,
                sprintf(
                    '%s declares Tested up to %s but TESTED_UP_TO_FLOOR pins %s. Behind the pin, Plugin Check '
                        . 'errors and the plugin drops out of directory search; ahead of it, the header claims a '
                        . 'release the suite has not run on. Move the pin, the CI wp: axis and every header together.',
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

    /**
     * Requires at least and Requires PHP agree everywhere too: the three
     * readmes and the three loaders. Core reads the loader's copy when it is
     * present, wp.org reads the readme's, so a drift between them gates
     * installs on one floor and advertises another.
     */
    public function test_requires_headers_agree_across_shipped_files(): void
    {
        foreach (['Requires at least', 'Requires PHP'] as $header) {
            $declared = [];
            foreach (self::SHIPPED_READMES as $readme) {
                $declared[$readme] = $this->readme_header($readme, $header);
            }
            foreach (self::REQUIRES_LOADERS as $loader) {
                $declared[$loader] = $this->loader_header($loader, $header);
            }
            $this->assertCount(1, array_unique($declared), $header . ' disagrees: ' . wp_json_encode($declared));
        }
    }

    /**
     * Every flavor directory's readme is in SHIPPED_READMES. The build scripts
     * stage `scripts/flavors/<flavor>/readme.txt` as the zip's readme, and
     * `Plugin_Source::readme()` only ever resolves the readme at the root of
     * the tree it scans, so a flavor readme that is not in the pinned set is
     * one no gate reads: not `composer compliance`, not this class.
     */
    public function test_every_flavor_readme_is_in_the_gated_set(): void
    {
        $root = $this->repository();
        $found = [];
        foreach (glob($root . '/scripts/flavors/*/readme.txt') ?: [] as $absolute) {
            $found[] = substr($absolute, strlen($root) + 1);
        }

        $this->assertNotEmpty($found, 'no flavor readme found under scripts/flavors/');
        foreach ($found as $readme) {
            $this->assertContains(
                $readme,
                self::SHIPPED_READMES,
                $readme . ' is a flavor readme the build can ship but SHIPPED_READMES does not gate it'
            );
        }
    }

    /**
     * Every readme the header tests gate is also in the name gate, and vice
     * versa, so a flavor added to one list cannot be forgotten by the other.
     */
    public function test_every_shipped_readme_has_a_loader_in_the_name_gate(): void
    {
        $this->assertSame(
            self::SHIPPED_READMES,
            array_keys(self::SHIPPED_NAME_PAIRS),
            'SHIPPED_NAME_PAIRS must pair exactly the readmes SHIPPED_READMES lists, in the same order'
        );
    }

    /**
     * Plugin Check reads the Plugin Name header and the readme title of
     * whichever zip it is handed and reports mismatched_plugin_name when they
     * differ. Each of the three builds stages a different pair, so each pair
     * has to agree with itself.
     */
    public function test_plugin_name_header_and_readme_title_are_byte_identical(): void
    {
        foreach (self::SHIPPED_NAME_PAIRS as $readme => $loader) {
            $this->assertSame(
                $this->loader_header($loader, 'Plugin Name'),
                $this->readme_title($readme),
                sprintf("Plugin Check reports mismatched_plugin_name when these differ:\n  %s\n  %s", $loader, $readme)
            );
        }
    }

    /**
     * The leading "WP" is deliberate and tolerated: Plugin Check warns on it,
     * and Trademark_Rule marks that finding best-practice (its
     * severity_override(), which no profile promotes to blocker; the
     * `distribution` profile that `composer compliance` runs prints it at
     * reviewer-discretion). WPORG-SUBMISSION.md records it as an accepted
     * cost. Anything the rule does not mark that way, "wordpress" included,
     * is a hard Plugin Check failure and must not ship.
     */
    public function test_no_shipped_display_name_carries_a_restricted_term(): void
    {
        foreach (self::SHIPPED_NAME_PAIRS as $loader) {
            $name = $this->loader_header($loader, 'Plugin Name');

            $hard = [];
            foreach ($this->trademark_findings_for_name($name) as $finding) {
                if (Severity::BEST_PRACTICE !== $finding->severity_override()) {
                    $hard[] = $finding->message();
                }
            }

            $this->assertSame([], $hard, $loader . ' ships a display name Trademark_Rule rejects');
            $this->assertStringNotContainsStringIgnoringCase('wordpress', $name, $loader);
        }
    }

    public function test_the_two_wpmcp_slug_artifacts_share_one_display_name(): void
    {
        $names = [];
        foreach (self::WPMCP_SLUG_LOADERS as $loader) {
            $names[$loader] = $this->loader_header($loader, 'Plugin Name');
        }

        $this->assertCount(
            1,
            array_unique($names),
            'the directory zip and the self-hosted zip install into the same wpmcp/ directory under the same '
            . 'text domain, so a divergent Plugin Name renames the plugin on update: ' . wp_json_encode($names)
        );
    }

    /**
     * The name WPORG-SUBMISSION.md tells the submitter to paste into the form
     * is read from its fenced block under "**Plugin name**", the same way the
     * tag list is, so the document and the submitted zip cannot drift apart.
     */
    public function test_the_documented_submission_name_is_the_name_that_ships(): void
    {
        $doc = $this->contents(self::SUBMISSION_DOC);

        preg_match('/^\*\*Plugin name\*\*[^\n]*(?:\n[^\n`]*)*\n```[^\n]*\n(.+?)\n```/m', $doc, $matches);
        $this->assertNotEmpty($matches, self::SUBMISSION_DOC . ' no longer has a fenced name block under **Plugin name**');

        $this->assertSame(
            trim($matches[1]),
            $this->loader_header(self::SHIPPED_NAME_PAIRS[self::WPORG_LISTING_README], 'Plugin Name'),
            'the name pasted into the submission form has to be the name in the submitted zip'
        );
    }

    /** No trademark or restricted term survives in a shipped tag list. */
    public function test_shipped_tag_lists_carry_no_restricted_term(): void
    {
        foreach (self::SHIPPED_READMES as $readme) {
            $tags = $this->readme_tags($readme);

            $this->assertNotEmpty($tags, $readme . ' has an empty Tags header');
            $this->assertLessThanOrEqual(5, count($tags), $readme . ' exceeds the five tag maximum');
            foreach ($tags as $tag) {
                if (in_array($tag, self::TAG_EXCEPTIONS, true)) {
                    continue;
                }
                $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $tag), '-');
                foreach ($this->restricted_terms() as $term) {
                    $this->assertStringNotContainsString(
                        $term,
                        $slug,
                        sprintf('%s tag "%s" contains the restricted term "%s"', $readme, $tag, $term)
                    );
                }
            }
        }
    }

    /**
     * The tag list WPORG-SUBMISSION.md documents for the directory listing is
     * the one the wp.org readme ships. The document is the source of truth:
     * this reads the fenced block under its "**Tags**" heading rather than
     * carrying a second copy, so the two cannot drift apart silently, which is
     * how a trademarked tag survived the first pass at issue #169.
     */
    public function test_the_wporg_listing_readme_carries_the_documented_tag_list(): void
    {
        $doc = $this->contents(self::SUBMISSION_DOC);

        preg_match('/^\*\*Tags\*\*[^\n]*\n+```[^\n]*\n(.+?)\n```/ms', $doc, $matches);
        $this->assertNotEmpty($matches, self::SUBMISSION_DOC . ' no longer has a fenced tag block under **Tags**');

        $documented = array_values(array_filter(
            array_map('trim', explode(',', strtolower(trim($matches[1])))),
            static fn (string $tag): bool => '' !== $tag
        ));

        $this->assertCount(5, $documented, self::SUBMISSION_DOC . ' must document exactly five tags');
        $this->assertSame(
            $documented,
            $this->readme_tags(self::WPORG_LISTING_README),
            self::WPORG_LISTING_README . ' does not carry the tag list ' . self::SUBMISSION_DOC . ' documents'
        );
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
        $installed = $this->ci_wordpress_versions($workflow);

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
                'TESTED_UP_TO_FLOOR pins %s but CI installs %s; bump the wp: axis in ci.yml with the pin',
                self::TESTED_UP_TO_FLOOR,
                implode(', ', $installed)
            )
        );

        // The pin is what the suite has run on; the headers are what ships.
        // Each is checked against the matrix on its own so that a header
        // bumped past the pin, or a pin bumped past the matrix, both go red.
        foreach (array_merge(self::SHIPPED_READMES, self::SHIPPED_LOADERS) as $file) {
            $declared = str_ends_with($file, '.php')
                ? $this->loader_header($file, 'Tested up to')
                : $this->readme_header($file, 'Tested up to');

            $this->assertContains(
                $declared,
                $installed,
                sprintf(
                    '%s declares Tested up to %s but CI installs %s; the header must not outrun the suite',
                    $file,
                    $declared,
                    implode(', ', $installed)
                )
            );
        }

        $this->assertContains(
            $this->readme_header('readme.txt', 'Requires at least'),
            $installed,
            'the Requires at least floor is not on any CI matrix leg: ' . implode(', ', $installed)
        );
    }

    /**
     * Every WordPress version the test matrix installs: each element of the
     * `wp:` axis list (not only the first, so moving a leg from include: into
     * the list does not hide it) plus the `wp:` of every flow-style include
     * entry.
     *
     * @return string[]
     */
    private function ci_wordpress_versions(string $workflow): array
    {
        $versions = [];

        preg_match_all('/^[ \t]*wp:[ \t]*\[([^\]]*)\]/m', $workflow, $axes);
        foreach ($axes[1] as $list) {
            preg_match_all('/\d+(?:\.\d+)+/', $list, $found);
            $versions = array_merge($versions, $found[0]);
        }

        preg_match_all('/^[ \t]*-[ \t]*\{[^}]*\bwp:[ \t]*\'?(\d+(?:\.\d+)+)\'?/m', $workflow, $legs);
        $versions = array_merge($versions, $legs[1]);

        return array_values(array_unique($versions));
    }
}
