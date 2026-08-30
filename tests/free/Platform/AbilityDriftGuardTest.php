<?php

namespace WPMCP\Tests\Free\Platform;

use PHPUnit\Framework\TestCase;

/**
 * bin/check-ability-drift.php is the WordPress-free half of the registration
 * drift guard (issue #86, wave 0 item #55). It runs in any CI job, so it has
 * to be right on its own: its whole job is to fail, and a guard whose matcher
 * is too loose passes a tree it should have failed.
 *
 * Every case here builds a small fixture tree and runs the real script against
 * it, because the failure modes worth pinning (substring collisions between
 * tool names, a class named only in a docblock, an import outside Plugin.php)
 * are exactly the ones a hand-read of the script does not catch.
 */
class AbilityDriftGuardTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/wpmcp-drift-' . uniqid();
        $this->write('wpmcp.php', "<?php\n\\WPMCP\\Plugin::instance()->boot();\n");
    }

    protected function tearDown(): void
    {
        if ('' !== $this->root && is_dir($this->root)) {
            $this->rmdir($this->root);
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------- fixtures

    private function write(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }

    private function rmdir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function tool(string $namespaceTail, string $short, string $body = ''): void
    {
        $this->write(
            'src/Tools/' . str_replace('\\', '/', $namespaceTail) . '/' . $short . '.php',
            "<?php\n\nnamespace WPMCP\\Tools\\{$namespaceTail};\n\nclass {$short}\n{\n{$body}}\n"
        );
    }

    /** A Plugin.php that imports and instantiates the given short tool names. */
    private function plugin(array $imports, string $extra = ''): void
    {
        $uses = '';
        $body = '';
        foreach ($imports as $fqcn) {
            $short = substr($fqcn, (int) strrpos($fqcn, '\\') + 1);
            $uses .= "use {$fqcn};\n";
            $body .= "        \$t = new {$short}();\n";
        }
        $this->write(
            'src/Plugin.php',
            "<?php\n\nnamespace WPMCP;\n\n{$uses}\nclass Plugin\n{\n"
            . "    public static function instance(): self { return new self(); }\n"
            . "    public function boot(): void\n    {\n{$body}{$extra}    }\n}\n"
        );
    }

    private function manifest(array $abilities): void
    {
        $lines = '';
        foreach ($abilities as $name => $tier) {
            $lines .= "        '{$name}' => '{$tier}',\n";
        }
        $this->write(
            'tests/support/ability-manifest.php',
            "<?php\n\nreturn [\n    'total' => " . count($abilities)
            . ",\n    'abilities' => [\n{$lines}    ],\n];\n"
        );
    }

    /** @return array{0:int,1:string} exit code and combined output */
    private function guard(string ...$args): array
    {
        $script = dirname(__DIR__, 3) . '/bin/check-ability-drift.php';
        $cmd    = 'php ' . escapeshellarg($script);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }
        $output = [];
        $code   = 0;
        exec($cmd . ' 2>&1', $output, $code);
        return [$code, implode("\n", $output)];
    }

    // -------------------------------------------------------------- the tree

    public function test_this_repository_passes_in_strict_mode(): void
    {
        [$code, $output] = $this->guard('--strict', dirname(__DIR__, 3));
        $this->assertSame(0, $code, "the guard should pass on this tree:\n" . $output);
        $this->assertStringContainsString('check-ability-drift: OK', $output);
    }

    public function test_clean_fixture_tree_passes(): void
    {
        $this->tool('Content', 'Get_Post');
        $this->plugin(['WPMCP\\Tools\\Content\\Get_Post']);

        [$code, $output] = $this->guard('--strict', $this->root);
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('check-ability-drift: OK', $output);
    }

    // --------------------------------------------- 1. named class must exist

    public function test_missing_class_imported_outside_plugin_php_fails(): void
    {
        // The import lives in a governance class, not Plugin.php. Scanning only
        // Plugin.php would pass this tree while the plugin fatals at load.
        $this->tool('Content', 'Get_Post');
        $this->plugin(['WPMCP\\Tools\\Content\\Get_Post']);
        $this->write(
            'src/Governance/Opt_In_Gates.php',
            "<?php\n\nnamespace WPMCP\\Governance;\n\nuse WPMCP\\Tools\\Cli\\Wp_Cli_Guard;\n\n"
            . "class Opt_In_Gates\n{\n    public function gate(): void { new Wp_Cli_Guard(); }\n}\n"
        );

        [$code, $output] = $this->guard($this->root);
        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('WPMCP\\Tools\\Cli\\Wp_Cli_Guard', $output);
    }

    public function test_aliased_import_of_a_missing_class_fails(): void
    {
        $this->tool('Content', 'Get_Post');
        $this->write(
            'src/Plugin.php',
            "<?php\n\nnamespace WPMCP;\n\nuse WPMCP\\Tools\\Content\\Get_Post;\n"
            . "use WPMCP\\Tools\\Content\\Gone_Missing as Missing;\n\nclass Plugin\n{\n"
            . "    public function boot(): void { new Get_Post(); new Missing(); }\n}\n"
        );

        [$code, $output] = $this->guard($this->root);
        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('Gone_Missing', $output);
    }

    public function test_group_import_of_a_missing_class_fails(): void
    {
        $this->tool('Content', 'Get_Post');
        $this->write(
            'src/Plugin.php',
            "<?php\n\nnamespace WPMCP;\n\nuse WPMCP\\Tools\\Content\\{Get_Post, Gone_Missing};\n\n"
            . "class Plugin\n{\n    public function boot(): void { new Get_Post(); new Gone_Missing(); }\n}\n"
        );

        [$code, $output] = $this->guard($this->root);
        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('Gone_Missing', $output);
    }

    public function test_class_named_only_behind_a_class_exists_probe_is_allowed(): void
    {
        $this->tool('Content', 'Get_Post');
        $this->plugin(
            ['WPMCP\\Tools\\Content\\Get_Post'],
            "        if (class_exists(\\WPMCP\\Uninstaller::class)) {\n"
            . "            \\WPMCP\\Uninstaller::uninstall();\n        }\n"
        );

        [$code, $output] = $this->guard('--strict', $this->root);
        $this->assertSame(0, $code, $output);
    }

    // ------------------------------------------------ 2. tools stay reachable

    public function test_a_prefix_of_another_tool_name_does_not_count_as_a_reference(): void
    {
        // Get_Post_Meta is wired; Get_Post is not. A substring match on the
        // short name reports this tree clean, which is the whole bug.
        $this->tool('Content', 'Get_Post');
        $this->tool('Content', 'Get_Post_Meta');
        $this->plugin(['WPMCP\\Tools\\Content\\Get_Post_Meta']);

        [$code, $output] = $this->guard('--strict', $this->root);
        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('src/Tools/Content/Get_Post.php', $output);
        $this->assertStringNotContainsString('Get_Post_Meta.php', $output);
    }

    public function test_a_docblock_mention_is_not_a_reference(): void
    {
        $this->tool('Content', 'Get_Post');
        $this->tool(
            'Content',
            'List_Posts',
            "    /** Pairs with Get_Post for the single-post read. */\n    public function handle(): void {}\n"
        );
        $this->plugin(['WPMCP\\Tools\\Content\\List_Posts']);

        [$code, $output] = $this->guard('--strict', $this->root);
        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('src/Tools/Content/Get_Post.php', $output);
    }

    public function test_a_dead_cluster_that_only_references_itself_is_unreachable(): void
    {
        // Tool plus its private helper, both dropped from Plugin.php. Mutual
        // mention is not wiring, so both must be reported.
        $this->tool('Content', 'Get_Post');
        $this->tool('Legacy', 'Old_Tool', "    public function handle(): void { new Old_Helper(); }\n");
        $this->tool('Legacy', 'Old_Helper', "    public function help(): void { new Old_Tool(); }\n");
        $this->plugin(['WPMCP\\Tools\\Content\\Get_Post']);

        [$code, $output] = $this->guard('--strict', $this->root);
        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('src/Tools/Legacy/Old_Tool.php', $output);
        $this->assertStringContainsString('src/Tools/Legacy/Old_Helper.php', $output);
    }

    public function test_a_tool_wired_only_from_a_flavor_script_is_reachable(): void
    {
        $this->tool('Content', 'Get_Post');
        $this->tool('Cloud', 'Cloud_Status');
        $this->plugin(['WPMCP\\Tools\\Content\\Get_Post']);
        $this->write(
            'scripts/flavors/wporg/strip.php',
            "<?php\n\$keep = [\\WPMCP\\Tools\\Cloud\\Cloud_Status::class];\n"
        );

        [$code, $output] = $this->guard('--strict', $this->root);
        $this->assertSame(0, $code, $output);
    }

    public function test_unreachable_tools_warn_without_strict_and_suppress_the_ok_line(): void
    {
        $this->tool('Content', 'Get_Post');
        $this->tool('Legacy', 'Old_Tool');
        $this->plugin(['WPMCP\\Tools\\Content\\Get_Post']);

        [$code, $output] = $this->guard($this->root);
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('WARN: ', $output);
        $this->assertStringNotContainsString('check-ability-drift: OK', $output);
    }

    // ------------------------------------------------- 3. abilities stay pinned

    public function test_a_re_tiered_ability_fails_against_the_manifest(): void
    {
        $this->tool('Content', 'Get_Post');
        $this->plugin(
            ['WPMCP\\Tools\\Content\\Get_Post'],
            "        \$r->register(new Ability('wpmcp/get-post', 'pro', 'Read a post'));\n"
        );
        $this->manifest(['wpmcp/get-post' => 'free']);

        [$code, $output] = $this->guard($this->root);
        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('re-tiered', $output);
    }

    public function test_an_ability_missing_from_the_manifest_fails(): void
    {
        $this->tool('Content', 'Get_Post');
        $this->plugin(
            ['WPMCP\\Tools\\Content\\Get_Post'],
            "        \$r->register(new Ability('wpmcp/get-post-renamed', 'free', 'Read a post'));\n"
        );
        $this->manifest(['wpmcp/get-post' => 'free']);

        [$code, $output] = $this->guard($this->root);
        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('wpmcp/get-post-renamed', $output);
    }

    public function test_abilities_are_checked_only_when_a_manifest_is_present(): void
    {
        $this->tool('Content', 'Get_Post');
        $this->plugin(
            ['WPMCP\\Tools\\Content\\Get_Post'],
            "        \$r->register(new Ability('wpmcp/get-post', 'free', 'Read a post'));\n"
        );

        [$code, $output] = $this->guard('--strict', $this->root);
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('skipped', $output);
    }
}
