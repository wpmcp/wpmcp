<?php

namespace WPMCP\Tests\Free\Platform;

use PHPUnit\Framework\TestCase;

/**
 * Post-conditions of the wp.org directory strip (issue #159).
 *
 * scripts/build-wporg-release.sh gates the staged tree at build time, but the
 * build only runs in CI's compliance job and on a release. This suite runs the
 * same strip against a throwaway stage on every test run, so a rename or a
 * reworded document that puts pay-to-unlock surface back into the directory cut
 * fails here first, with a diff, instead of at release time.
 *
 * The vocabulary is not restated here. Every pattern and path comes from
 * scripts/flavors/wporg/policy.php, the same file the strip and the build
 * script's gates read, so this suite cannot be stricter or laxer than the
 * build: a document that passes `composer build:wporg` and fails
 * `composer test` was the drift this arrangement exists to remove.
 *
 * The strip only ever touches src/, so a stage containing just src/ is enough
 * to exercise it. The two flavor templates (wpmcp.php, readme.txt) are not
 * staged: the build copies them through `sed s/{{VERSION}}/…/`, which changes
 * no word this suite scans for, so they are read where they live.
 */
class WporgStripTest extends TestCase
{
    private static string $stage = '';
    private static string $root = '';

    /** @var array<string,array<int,string>> */
    private static array $policy = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$root = dirname(__DIR__, 3);
        self::$policy = require self::$root . '/scripts/flavors/wporg/policy.php';

        $parent = sys_get_temp_dir() . '/wpmcp-strip-' . bin2hex(random_bytes(6));
        self::$stage = $parent . '/wpmcp';
        mkdir(self::$stage, 0777, true);

        exec(
            sprintf('cp -R %s %s', escapeshellarg(self::$root . '/src'), escapeshellarg(self::$stage . '/src')),
            $out,
            $status
        );
        if (0 !== $status) {
            self::fail('could not stage src/ for the strip');
        }

        exec(
            sprintf(
                'php %s %s 2>&1',
                escapeshellarg(self::$root . '/scripts/flavors/wporg/strip.php'),
                escapeshellarg(self::$stage)
            ),
            $strip_out,
            $strip_status
        );
        if (0 !== $strip_status) {
            self::fail("strip.php failed against a fresh stage:\n" . implode("\n", $strip_out));
        }
    }

    public static function tearDownAfterClass(): void
    {
        if ('' !== self::$stage && is_dir(self::$stage)) {
            exec(sprintf('rm -rf %s', escapeshellarg(dirname(self::$stage))));
        }
        parent::tearDownAfterClass();
    }

    /**
     * Every path the policy declares removed has to be gone, and still has to
     * exist upstream: a path that was renamed in src/ would otherwise quietly
     * stop being removed from anything.
     */
    public function test_every_declared_removed_path_is_absent_from_the_stage(): void
    {
        $removed = self::$policy['removed_paths'];
        $this->assertNotEmpty($removed, 'policy.php declares no removed paths');

        foreach ($removed as $relative) {
            $this->assertFileExists(
                self::$root . '/' . $relative,
                "policy.php names {$relative}, which no longer exists in the source tree"
            );
            $this->assertFileDoesNotExist(
                self::$stage . '/' . $relative,
                "{$relative} survived the wp.org strip"
            );
        }
    }

    public function test_pro_gate_and_licensing_bootstrap_are_absent(): void
    {
        $this->assertContains('src/Pro', self::$policy['removed_paths'], 'the policy stopped removing src/Pro');
        $this->assertFileDoesNotExist(self::$stage . '/src/Pro', 'src/Pro is still in the directory cut');
        $this->assertFileDoesNotExist(self::$stage . '/src/Pro/Gate.php', 'src/Pro/Gate.php is still in the directory cut');
        $this->assertFileDoesNotExist(self::$stage . '/src/Freemius', 'src/Freemius is still in the directory cut');
    }

    /**
     * The strip reports a path it could not delete instead of carrying on.
     * remove_path() used to ignore the return value of unlink()/rmdir(), which
     * made a partial removal silent until a later grep in a different script
     * happened to notice.
     */
    public function test_strip_fails_loudly_when_a_removed_path_cannot_be_deleted(): void
    {
        if (0 === posix_getuid()) {
            $this->markTestSkipped('running as root: directory permissions do not stop unlink()');
        }

        $parent = sys_get_temp_dir() . '/wpmcp-strip-ro-' . bin2hex(random_bytes(6));
        $stage = $parent . '/wpmcp';
        mkdir($stage, 0777, true);
        exec(sprintf('cp -R %s %s', escapeshellarg(self::$root . '/src'), escapeshellarg($stage . '/src')));
        // Read-only src/: the files inside src/Pro can still be unlinked, but
        // the rmdir() of src/Pro itself cannot succeed.
        chmod($stage . '/src', 0555);

        exec(
            sprintf(
                'php %s %s 2>&1',
                escapeshellarg(self::$root . '/scripts/flavors/wporg/strip.php'),
                escapeshellarg($stage)
            ),
            $out,
            $status
        );

        chmod($stage . '/src', 0755);
        exec(sprintf('rm -rf %s', escapeshellarg($parent)));

        $this->assertNotSame(0, $status, 'the strip reported success against a stage it could not prune');
        $this->assertStringContainsString(
            'could not be removed',
            implode("\n", $out),
            'the strip did not name the path it failed to remove'
        );
    }

    /** No paid predicate survives in the staged PHP. */
    public function test_staged_source_has_no_paid_predicate(): void
    {
        $hits = [];
        foreach (self::staged_files('php') as $file) {
            $hits = array_merge($hits, self::scan($file, self::$policy['paid_source_patterns']));
        }

        $this->assertSame([], $hits, "paid predicate survived the strip:\n" . implode("\n", $hits));
    }

    /**
     * The bundled playbooks ship inside src/, so they are in the zip. A
     * document that still tells the agent a feature needs a licence is the
     * same guideline 9 problem as the code that used to enforce it.
     */
    public function test_staged_documents_carry_no_pay_to_unlock_copy(): void
    {
        $hits = [];
        foreach (self::staged_files(null) as $file) {
            if ('php' === strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
                continue;
            }
            $hits = array_merge($hits, self::scan($file, self::$policy['document_copy_patterns']));
        }

        $this->assertSame([], $hits, "pay-to-unlock copy survived the strip:\n" . implode("\n", $hits));
    }

    /**
     * The copy an agent is actually shown. An Ability description goes out in
     * every tools/list response, and carries no Gate::/is_pro token for the
     * source patterns to catch, so it is scanned as string tokens on their
     * own. Literals rather than lines, so the docblocks that legitimately
     * discuss third-party paid plugins (WPML, Elementor Pro) stay out of it.
     */
    public function test_no_agent_facing_string_advertises_a_paid_tier(): void
    {
        $hits = [];
        foreach (self::staged_files('php') as $file) {
            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (! is_array($token)) {
                    continue;
                }
                if (! in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) {
                    continue;
                }
                foreach (self::$policy['string_literal_patterns'] as $pattern) {
                    if (preg_match('/' . $pattern . '/', $token[1])) {
                        $hits[] = sprintf('%s:%d matches %s', self::relative($file), $token[2], $pattern);
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($hits)), "an agent-facing string advertises a paid tier:\n" . implode("\n", $hits));
    }

    /**
     * `tier: pro` in SKILL.md frontmatter is the one machine-readable
     * pay-to-unlock marker in a non-PHP file that the shipped code consumes,
     * and this build parses no tier at all.
     */
    public function test_no_bundled_playbook_declares_a_paid_tier(): void
    {
        $documents = glob(self::$stage . '/src/Skills/library/*/SKILL.md') ?: [];
        $this->assertNotEmpty($documents, 'no bundled playbooks were staged');

        foreach ($documents as $document) {
            $raw = (string) file_get_contents($document);
            $this->assertMatchesRegularExpression('/\A---\R/', $raw, self::relative($document) . ' has no frontmatter');
            $this->assertDoesNotMatchRegularExpression(
                '/^[[:space:]]*tier:[[:space:]]*(?!free[[:space:]]*$)\S+/m',
                $raw,
                self::relative($document) . ' declares a tier other than free'
            );
        }
    }

    /**
     * The skill catalog an agent receives, and the admin screen a reviewer
     * reads, carry no tier and no lock: pinning is_locked() to false would
     * leave the projection key and the Tier column in place, which is the
     * claim, not the enforcement.
     */
    public function test_the_skill_catalog_has_no_tier_and_no_lock(): void
    {
        $library = (string) file_get_contents(self::$stage . '/src/Skills/Skill_Library.php');
        $this->assertStringNotContainsString('is_locked', $library, 'Skill_Library still has a lock predicate');
        $this->assertStringNotContainsString("'locked'", $library, 'Skill_Library still projects a locked flag');
        $this->assertStringNotContainsString("'tier'", $library, 'Skill_Library still parses or projects a per-skill tier');

        $admin = (string) file_get_contents(self::$stage . '/src/Admin/Skills_Settings_Page.php');
        $this->assertStringNotContainsString("'Tier'", $admin, 'the skills admin screen still renders a Tier column');
        $this->assertStringNotContainsString("\$skill['tier']", $admin, 'the skills admin screen still prints a per-skill tier');
        $this->assertStringNotContainsString("\$skill['locked']", $admin, 'the skills admin screen still renders a lock status');

        $get_skill = (string) file_get_contents(self::$stage . '/src/Tools/Skills/Get_Skill.php');
        $this->assertStringNotContainsString('locked', $get_skill, 'get-skill still handles a withheld body');
    }

    /**
     * The two flavor templates are in the zip as well: the bootstrap is what
     * gate 3 scans as $STAGE/wpmcp.php (issue #159 names its licensing vendor
     * reference at :15) and readme.txt is what the reviewer reads first.
     *
     * readme.txt is scanned with the narrower readme list on purpose.
     * Guideline 5 recommends "add-on plugins, hosted outside of
     * WordPress.org, in order to exclude the premium code", so a factual
     * pointer at the off-directory add-on is the recommended remedy rather
     * than a finding, and the required "License: GPLv2 or later" header and
     * the third-party image-licence URLs have to survive.
     */
    public function test_the_flavor_templates_carry_no_paid_surface(): void
    {
        $bootstrap = self::$root . '/scripts/flavors/wporg/wpmcp.php';
        $readme = self::$root . '/scripts/flavors/wporg/readme.txt';
        $this->assertFileExists($bootstrap);
        $this->assertFileExists($readme);

        $hits = array_merge(
            self::scan($bootstrap, self::$policy['paid_source_patterns']),
            self::scan($readme, self::$policy['readme_copy_patterns'])
        );

        $this->assertSame([], $hits, "the flavor templates carry paid surface:\n" . implode("\n", $hits));
    }

    /**
     * The build script has to read the same policy rather than restate it.
     * Two hand-copied lists that disagree is the failure this arrangement
     * replaced, so a pattern typed back into the shell is a test failure.
     */
    public function test_the_build_script_reads_its_vocabulary_from_the_policy(): void
    {
        $script = (string) file_get_contents(self::$root . '/scripts/build-wporg-release.sh');

        $this->assertStringContainsString('policy.php', $script, 'the build script no longer reads the shared policy');
        foreach (['paid_source_patterns', 'document_copy_patterns', 'readme_copy_patterns', 'string_literal_patterns', 'removed_paths'] as $key) {
            $this->assertStringContainsString(
                'policy ' . $key,
                $script,
                "the build script does not read {$key} from the policy"
            );
        }
        foreach (['can_use_premium_code', 'fs_dynamic_init', 'unlicensed'] as $pattern) {
            $this->assertStringNotContainsString(
                $pattern,
                $script,
                "the build script restates the {$pattern} pattern instead of reading it from the policy"
            );
        }
    }

    /**
     * The free-tier snapshot quota is the guideline 5 "quota that a payment
     * lifts". It has to be one flat filterable number, and no document may
     * describe a cap that only unlicensed sites get.
     */
    public function test_snapshot_retention_is_one_flat_filterable_number(): void
    {
        $store = (string) file_get_contents(self::$stage . '/src/Safety/Snapshot_Store.php');
        $this->assertStringContainsString('public static function history_limit(): int', $store);
        $this->assertStringContainsString('wpmcp_snapshot_history_limit', $store);
        $this->assertStringNotContainsString('Gate::history_limit', $store);
    }

    /**
     * @param string[] $patterns POSIX EREs, matched one line at a time so the
     *                           shell gates and this suite agree.
     * @return string[]
     */
    private static function scan(string $file, array $patterns): array
    {
        $hits = [];
        foreach (file($file) ?: [] as $number => $line) {
            foreach ($patterns as $pattern) {
                if (preg_match('/' . $pattern . '/', $line)) {
                    $hits[] = sprintf('%s:%d %s', self::relative($file), $number + 1, trim($line));
                    continue 2;
                }
            }
        }

        return $hits;
    }

    /**
     * @param string|null $extension Extension filter, or null for every file.
     * @return string[]
     */
    private static function staged_files(?string $extension): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            self::$stage,
            \FilesystemIterator::SKIP_DOTS
        ));
        foreach ($it as $file) {
            if (! $file->isFile()) {
                continue;
            }
            if (null !== $extension && strtolower($file->getExtension()) !== $extension) {
                continue;
            }
            $files[] = $file->getPathname();
        }
        sort($files);

        return $files;
    }

    private static function relative(string $path): string
    {
        return ltrim(str_replace([self::$stage, self::$root], '', $path), '/');
    }
}
