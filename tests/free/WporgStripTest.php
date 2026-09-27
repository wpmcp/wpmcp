<?php

namespace WPMCP\Tests\Free;

/**
 * The WordPress.org strip (issue #160), asserted against the tree it actually
 * produces rather than against the edit list it is written from.
 *
 * Guideline 5 forbids shipping functionality "restricted or locked, only to be
 * made available by payment or upgrade", so the directory build removes the
 * paid tier instead of gating it. Deleting the Registrar tier branch removed
 * the last runtime backstop, which makes the build-time invariants the thing
 * that keeps the artifact honest.
 *
 * Those invariants live in ONE place, scripts/flavors/wporg/assert-free-tier.php,
 * and this class shells out to it. That is deliberate: the scanner used to be
 * written twice, once inline in build-wporg-release.sh and once here, so the
 * copy these tests proved non-vacuous was not the copy that blocked a release
 * and the two had already drifted. Everything below therefore exercises the
 * enforcing code, including the negative cases.
 */
class WporgStripTest extends \WP_UnitTestCase
{
    /** @var string|null Stage shared by every test in the class; stripped once. */
    private static ?string $stage = null;

    public static function tearDownAfterClass(): void
    {
        if (null !== self::$stage) {
            exec('rm -rf ' . escapeshellarg(dirname(self::$stage)));
            self::$stage = null;
        }
        parent::tearDownAfterClass();
    }

    // ------------------------------------------------- the produced artifact

    public function test_the_stripped_tree_passes_every_free_tier_invariant(): void
    {
        [$status, $output] = $this->assert_free_tier($this->stage());

        $this->assertSame(
            0,
            $status,
            "The stripped tree must satisfy the invariants the release build gates on:\n" . $output
        );
    }

    /**
     * The other half of the issue's definition of done. Gate 2 proves every
     * ability the build constructs is free; this proves the SET is right: no
     * ability the drift-guard manifest tiers as paid survives under any name,
     * and no free one went missing because a strip edit took out more than it
     * meant to.
     */
    public function test_the_stripped_tree_registers_the_manifest_free_set_and_nothing_paid(): void
    {
        [$status, $output] = $this->assert_free_tier($this->stage(), $this->manifest());

        $this->assertSame(0, $status, $output);
    }

    public function test_the_manifest_check_reports_a_paid_ability_that_survived(): void
    {
        $manifest = $this->fixture_manifest(['wpmcp/kept-free' => 'free', 'wpmcp/withheld' => 'pro']);
        $tree = $this->fixture_tree([
            'src/Thing.php' => "<?php\n"
                . "\$a = new Ability('wpmcp/kept-free', 'free', 'd');\n"
                . "\$b = new Ability('wpmcp/withheld', 'free', 'd');\n",
        ]);

        [$status, $output] = $this->assert_free_tier($tree, $manifest);

        $this->assertSame(1, $status, 'a paid ability re-tiered to free still must not ship');
        $this->assertStringContainsString('wpmcp/withheld', $output);
    }

    public function test_the_manifest_check_reports_a_free_ability_that_went_missing(): void
    {
        $manifest = $this->fixture_manifest(['wpmcp/kept-free' => 'free', 'wpmcp/lost' => 'free']);
        $tree = $this->fixture_tree([
            'src/Thing.php' => "<?php\n\$a = new Ability('wpmcp/kept-free', 'free', 'd');\n",
        ]);

        [$status, $output] = $this->assert_free_tier($tree, $manifest);

        $this->assertSame(1, $status, 'the free surface must not shrink silently');
        $this->assertStringContainsString('wpmcp/lost', $output);
    }

    /**
     * The dispatcher pairs are assembled at runtime from each integration's
     * own slug, so a literal scan reports 32 free abilities missing that are
     * in fact registered. The check resolves the slug instead.
     */
    public function test_the_manifest_check_resolves_the_runtime_built_dispatcher_pairs(): void
    {
        $manifest = $this->fixture_manifest(['wpmcp/acf-read' => 'free', 'wpmcp/acf-write' => 'free']);
        $tree = $this->fixture_tree([
            'src/Integrations/ACF_Integration.php' => "<?php\nclass ACF_Integration {\n"
                . "    public function integration(): string\n    {\n        return 'acf';\n    }\n}\n",
        ]);

        [$status, $output] = $this->assert_free_tier($tree, $manifest);

        $this->assertSame(0, $status, $output);
    }

    public function test_the_stripped_registrar_documents_the_memory_guardrail_as_part_of_the_decision(): void
    {
        $registrar = (string) file_get_contents($this->stage() . '/src/MCP/Registrar.php');

        // The method applies Memory_Guard::blocking_rule() as a narrowing term
        // on top of capability + Governance + identity scope. A docblock that
        // says the decision has exactly three terms is false, and replacing a
        // wrong docblock with another wrong one is the defect this strip
        // exists to fix.
        $this->assertStringContainsString('Memory_Guard::blocking_rule(', $registrar);
        $this->assertStringNotContainsString('there is no fourth', $registrar);
        $this->assertStringContainsString('project-memory guardrail', $registrar);
    }

    public function test_the_stripped_tree_ships_no_async_wp_cli_execution_package(): void
    {
        $stage = $this->stage();

        // register_cli_job_abilities() lost its only caller when
        // register_cli_abilities() left, and a dead private method still holds
        // everything it instantiates out of the unreferenced-file sweep. The
        // worst of it: Run_Cli_Job defaults its executor to
        // Wp_Cli_Executor::class, a class the strip deletes.
        foreach (
            [
            'src/Tools/Cli/Dispatch_Cli_Job.php',
            'src/Tools/Cli/Get_Cli_Job.php',
            'src/Tools/Cli/List_Cli_Jobs.php',
            'src/Tools/Cli/Cancel_Cli_Job.php',
            'src/Tools/Cli/Cli_Job_Store.php',
            'src/Tools/Cli/Run_Cli_Job.php',
            'src/Tools/Cli/Wp_Cli_Executor.php',
            ] as $relative
        ) {
            $this->assertFileDoesNotExist($stage . '/' . $relative);
        }

        $plugin = (string) file_get_contents($stage . '/src/Plugin.php');
        $this->assertStringNotContainsString('Run_Cli_Job', $plugin, 'the cron hook for a deleted class must go too');
    }

    public function test_the_stripped_tree_serves_no_skill_document_that_names_a_licence_or_a_deleted_tool(): void
    {
        $library = $this->stage() . '/src/Skills/library';
        $this->assertDirectoryExists($library, 'the bundled skill library is part of the free build');

        $hits = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($library, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ('md' !== strtolower($file->getExtension())) {
                continue;
            }
            foreach (file($file->getPathname()) ?: [] as $i => $line) {
                // get-skill returns these bodies verbatim to the connecting
                // client, so they are shipped text, not comments.
                if (preg_match('/pro[- ]tier|unlicensed|run-wp-cli|run-php-snippet|memory-recall/i', $line)) {
                    $hits[] = $file->getFilename() . ':' . ($i + 1) . ' ' . trim($line);
                }
            }
        }

        $this->assertSame([], $hits, "A shipped skill document names a tier or a tool this build removes:\n  " . implode("\n  ", $hits));
    }

    public function test_the_handshake_never_points_an_agent_at_a_tool_this_build_removes(): void
    {
        $stage = $this->stage();

        // memory_block() is appended to the instructions every connecting
        // client reads, so this is runtime output rather than a docblock.
        $handshake = (string) file_get_contents($stage . '/src/MCP/Handshake_Instructions.php');
        $this->assertStringNotContainsString('memory-recall', $handshake);

        $this->assertDirectoryDoesNotExist($stage . '/src/Tools/Memory');

        // The enforcement half of the memory feature stays: a guardrail an
        // administrator published must keep applying on every install.
        $this->assertFileExists($stage . '/src/Memory/Memory_Config.php');
        $config = (string) file_get_contents($stage . '/src/Memory/Memory_Config.php');
        $this->assertStringContainsString('enforcement_enabled', $config);
        $this->assertStringNotContainsString('memory-propose', $config);
        $this->assertStringNotContainsString('tools_enabled', $config, 'an accessor with no caller left');
    }

    public function test_get_site_context_documents_no_response_field_the_build_never_returns(): void
    {
        $source = (string) file_get_contents($this->stage() . '/src/Tools/Context/Get_Site_Context.php');

        $this->assertStringNotContainsString('pro_active', $source);
        $this->assertStringNotContainsString('Pro status', $source);
    }

    // ------------------------------------------------- the gate's own honesty
    //
    // A gate is only worth its exit code if it reports the cases it exists to
    // catch. Each of these builds a deliberately bad tree and asserts the
    // enforcing script fails on it.

    public function test_the_gate_reports_a_tier_that_is_not_a_source_literal(): void
    {
        $tree = $this->fixture_tree([
            'src/Thing.php' => "<?php\n\$x = new Ability(\n    'wpmcp/demo',\n    \$this->tier(),\n    'desc'\n);\n",
        ]);

        [$status, $output] = $this->assert_free_tier($tree);

        $this->assertSame(1, $status, 'a computed tier must fail the gate');
        $this->assertStringContainsString('$this->tier()', $output);
    }

    /**
     * The depth counter used to be text-only. T_DOLLAR_OPEN_CURLY_BRACES has
     * the source text '${', which no bracket test matches, while its closing
     * '}' still decremented: depth hit zero early, the argument list collapsed
     * to one element, and the call was skipped as unsplittable.
     */
    public function test_the_gate_reports_a_tier_hidden_behind_string_interpolation(): void
    {
        $tree = $this->fixture_tree([
            'src/Thing.php' => "<?php\n\$x = new Ability(\n    \"a\${\$b}c\",\n    \$this->tier(),\n    'desc'\n);\n",
        ]);

        [$status, $output] = $this->assert_free_tier($tree);

        $this->assertSame(1, $status, 'interpolation before the tier must not hide it from the gate');
        $this->assertStringContainsString('src/Thing.php', $output);
    }

    /** An argument list the scanner cannot read is a finding, not a skip. */
    public function test_the_gate_reports_an_ability_call_it_cannot_read(): void
    {
        $tree = $this->fixture_tree([
            'src/Thing.php' => "<?php\n\$args = ['wpmcp/demo', 'pro'];\n\$x = new Ability(...\$args);\n",
        ]);

        [$status, $output] = $this->assert_free_tier($tree);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('could not read', $output);
    }

    /** Not PHP-only: get-skill serves markdown bodies and reviewers read readme.txt. */
    public function test_the_gate_reads_markdown_and_readme_not_only_php(): void
    {
        $tree = $this->fixture_tree([
            'src/Skills/library/demo/SKILL.md' => "# Demo\n\nThe Elementor tools are pro tier.\n",
        ]);
        [$status, $output] = $this->assert_free_tier($tree);
        $this->assertSame(1, $status, 'a skill document is shipped text');
        $this->assertStringContainsString('SKILL.md', $output);

        $tree = $this->fixture_tree([
            'readme.txt' => "=== WP MCP ===\n\nOn an unlicensed site the history is capped.\n",
        ]);
        [$status, $output] = $this->assert_free_tier($tree);
        $this->assertSame(1, $status, 'readme.txt is the first file a directory reviewer reads');
        $this->assertStringContainsString('readme.txt', $output);
    }

    public function test_the_gate_reports_an_orphaned_private_registration_method(): void
    {
        $tree = $this->fixture_tree([
            'src/Plugin.php' => "<?php\nclass Plugin {\n"
                . "    private function register_ghost_abilities(\$r): void\n    {\n"
                . "        \$x = new Withheld_Handler();\n    }\n}\n",
        ]);

        [$status, $output] = $this->assert_free_tier($tree);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('register_ghost_abilities', $output);
    }

    public function test_the_gate_reports_a_reference_to_a_withheld_registration_method(): void
    {
        $tree = $this->fixture_tree([
            'src/Plugin.php' => "<?php\n/** see register_analysis_abilities() for the deep scoring tools. */\nclass Plugin {}\n",
        ]);

        [$status, $output] = $this->assert_free_tier($tree);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('register_analysis_abilities', $output);
    }

    /** A tree with no PHP in it must not read as clean. */
    public function test_the_gate_refuses_to_pass_a_tree_with_no_php_in_it(): void
    {
        [$status] = $this->assert_free_tier($this->fixture_tree(['readme.txt' => "nothing here\n"], false));

        $this->assertSame(1, $status);
    }

    /** The control for every negative case above. */
    public function test_a_baseline_fixture_tree_is_clean(): void
    {
        [$status, $output] = $this->assert_free_tier($this->fixture_tree([
            'src/Thing.php' => "<?php\n\$x = new Ability('wpmcp/demo', 'free', 'desc');\n",
            'readme.txt'    => "=== WP MCP ===\n\nEverything here is free.\n",
        ]));

        $this->assertSame(0, $status, $output);
    }

    // ------------------------------------------------------------- helpers

    /**
     * Run the enforcing script over a tree.
     *
     * @return array{0: int, 1: string} [exit status, combined output]
     */
    private function assert_free_tier(string $tree, ?string $manifest = null): array
    {
        $script = dirname(__DIR__, 2) . '/scripts/flavors/wporg/assert-free-tier.php';
        $command = sprintf('php %s %s', escapeshellarg($script), escapeshellarg($tree));
        if (null !== $manifest) {
            $command .= ' ' . escapeshellarg($manifest);
        }
        exec($command . ' 2>&1', $output, $status);

        return [$status, implode("\n", $output)];
    }

    /** The real drift-guard manifest, the one the release build passes in. */
    private function manifest(): string
    {
        return dirname(__DIR__) . '/support/ability-manifest.php';
    }

    /**
     * A throwaway manifest in the real one's shape.
     *
     * @param array<string, string> $abilities name => tier
     */
    private function fixture_manifest(array $abilities): string
    {
        $path = $this->temp_dir('wpmcp-manifest-') . '/ability-manifest.php';
        file_put_contents($path, "<?php\n\nreturn " . var_export(['abilities' => $abilities], true) . ";\n");

        return $path;
    }

    /**
     * A throwaway tree of literal files, removed when the test method ends.
     *
     * A clean Registrar is added unless the caller supplies one, so the only
     * thing the gate can find is what the test injected: without it every
     * fixture would fail on the missing-Registrar finding and each negative
     * test would pass for the wrong reason. test_a_baseline_fixture_tree_is
     * _clean() pins that.
     *
     * @param array<string, string> $files relative path => contents
     */
    private function fixture_tree(array $files, bool $baseline = true): string
    {
        if ($baseline) {
            $files += ['src/MCP/Registrar.php' => "<?php\n\nclass Registrar\n{\n}\n"];
        }
        $dir = $this->temp_dir('wpmcp-fixture-');
        foreach ($files as $relative => $contents) {
            $path = $dir . '/' . $relative;
            $parent = dirname($path);
            if (! is_dir($parent) && ! mkdir($parent, 0700, true) && ! is_dir($parent)) {
                $this->fail('could not create ' . $parent);
            }
            file_put_contents($path, $contents);
        }

        return $dir;
    }

    /** Stage src/ into a temp dir and run the real strip over it, once. */
    private function stage(): string
    {
        if (null !== self::$stage) {
            return self::$stage;
        }

        $root = dirname(__DIR__, 2);
        // Assigned BEFORE the assertions below, so a staging failure still
        // leaves tearDownAfterClass() something to remove.
        $parent = $this->temp_dir('wpmcp-strip-');
        $stage = $parent . '/wpmcp';
        self::$stage = $stage;
        if (! mkdir($stage, 0700, true) && ! is_dir($stage)) {
            $this->fail('could not create ' . $stage);
        }

        exec(sprintf('cp -R %s %s', escapeshellarg($root . '/src'), escapeshellarg($stage . '/src')), $out, $status);
        $this->assertSame(0, $status, 'could not stage src/ for the strip');

        exec(
            sprintf('php %s %s 2>&1', escapeshellarg($root . '/scripts/flavors/wporg/strip.php'), escapeshellarg($stage)),
            $output,
            $status
        );
        $this->assertSame(0, $status, "the wp.org strip failed:\n" . implode("\n", $output));

        return $stage;
    }

    /**
     * A private temp directory. tempnam() + unlink() + mkdir() re-creates a
     * path an attacker has just seen, and 0o777 would leave the staged tree
     * the strip writes into world-writable, so neither is used.
     */
    private function temp_dir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/' . uniqid($prefix, true);
        if (! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            $this->fail('could not create ' . $dir);
        }
        $this->cleanup[] = $dir;

        return $dir;
    }

    /** @var string[] Temp directories to remove when the test method ends. */
    private array $cleanup = [];

    public function tear_down(): void
    {
        foreach ($this->cleanup as $dir) {
            if ($dir !== dirname((string) self::$stage)) {
                exec('rm -rf ' . escapeshellarg($dir));
            }
        }
        $this->cleanup = [];
        parent::tear_down();
    }
}
