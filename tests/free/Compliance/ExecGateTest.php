<?php

namespace WPMCP\Tests\Free\Compliance;

use WPMCP\Compliance\Rules\Dangerous_Constructs_Rule;

require_once dirname(__DIR__, 3) . '/scripts/lib/exec-gate.php';

/**
 * The staging gate the release builds run over the staged tree (issue #167).
 *
 * The gate is the last thing standing between a strip script that silently
 * no-ops and a directory zip containing eval() or proc_open(), so its false
 * negatives are rejections and its false positives are broken builds. Both
 * directions are pinned here: every shape a real call site takes must be
 * found, and every look-alike (a method, a declaration, a constant, a class
 * name, a string, a comment) must not be.
 */
class ExecGateTest extends \WP_UnitTestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/wpmcp-exec-gate-' . uniqid('', true);
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->remove_tree($this->root);
        parent::tearDown();
    }

    /**
     * @return string[] the construct names the gate reported, in order
     */
    private function scan(string $code, string $name = 'file.php'): array
    {
        $path = $this->root . '/' . $name;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }
        file_put_contents($path, $code);
        return array_map(
            static fn (array $finding): string => $finding['construct'],
            \Exec_Gate::scan_file($path)
        );
    }

    private function remove_tree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }

    // ------------------------------------------------------------ call shapes

    public function test_plain_call_is_found(): void
    {
        $this->assertSame(['proc_open'], $this->scan('<?php proc_open("ls", [], $p);'));
    }

    public function test_fully_qualified_call_is_found(): void
    {
        // The house style of the vendor tree the gate now walks: composer and
        // the MCP adapter call globals with a leading backslash.
        $this->assertSame(
            ['proc_open'],
            $this->scan('<?php namespace A; $p = \proc_open("ls", [], $x);')
        );
    }

    public function test_namespace_qualified_call_is_found(): void
    {
        $this->assertSame(['system'], $this->scan('<?php namespace A; Foo\system("ls");'));
    }

    public function test_eval_is_found(): void
    {
        $this->assertSame(['eval'], $this->scan('<?php eval("1;");'));
    }

    public function test_backtick_shell_execution_is_found(): void
    {
        $this->assertSame(['`` (shell execution)'], $this->scan('<?php $out = `id`;'));
    }

    public function test_call_is_reported_with_its_line(): void
    {
        $path = $this->root . '/lines.php';
        file_put_contents($path, "<?php\n\n\nshell_exec('id');\n");
        $findings = \Exec_Gate::scan_file($path);
        $this->assertSame(4, $findings[0]['line']);
        $this->assertSame($path, $findings[0]['path']);
    }

    // ------------------------------------------------------------ look-alikes

    public function test_method_declaration_is_not_a_call(): void
    {
        $this->assertSame([], $this->scan('<?php class C { public function exec(string $c) {} }'));
    }

    public function test_interface_method_signature_is_not_a_call(): void
    {
        $this->assertSame([], $this->scan('<?php interface I { public function popen(); }'));
    }

    public function test_method_and_static_calls_are_not_global_calls(): void
    {
        $code = '<?php $o -> exec(); $o?->system(); C::passthru(); $x = new Popen();';
        $this->assertSame([], $this->scan($code));
    }

    public function test_constants_class_names_and_type_hints_are_not_calls(): void
    {
        $code = '<?php const EXEC = 1; class System {} '
            . 'function f(System $s): System { return $s; } '
            . 'class D { const SYSTEM = "x"; }';
        $this->assertSame([], $this->scan($code));
    }

    public function test_strings_and_comments_do_not_false_positive(): void
    {
        $code = "<?php\n// proc_open is a pattern Malware_Audit looks for.\n\$p = 'shell_exec(';\n";
        $this->assertSame([], $this->scan($code));
    }

    // ------------------------------------------------------------ walking

    public function test_directory_walk_covers_uppercase_and_inc_extensions(): void
    {
        file_put_contents($this->root . '/A.PHP', '<?php proc_open("x", [], $p);');
        file_put_contents($this->root . '/b.inc', '<?php system("x");');
        file_put_contents($this->root . '/c.phtml', '<?php passthru("x");');
        file_put_contents($this->root . '/notes.txt', 'proc_open("x");');

        $found = array_map(
            static fn (array $f): string => $f['construct'],
            \Exec_Gate::scan_paths([$this->root])
        );
        sort($found);
        $this->assertSame(['passthru', 'proc_open', 'system'], $found);
    }

    public function test_missing_path_throws_rather_than_passing_clean(): void
    {
        $this->expectException(\RuntimeException::class);
        \Exec_Gate::scan_paths([$this->root . '/never-staged']);
    }

    public function test_unreadable_file_throws_rather_than_scanning_as_empty(): void
    {
        $path = $this->root . '/locked.php';
        file_put_contents($path, '<?php proc_open("x", [], $p);');
        chmod($path, 0o000);
        if (is_readable($path)) {
            // Running as root: the permission bit cannot be enforced.
            $this->markTestSkipped('the test runner can read a 0000 file');
        }
        try {
            $this->expectException(\RuntimeException::class);
            \Exec_Gate::scan_file($path);
        } finally {
            chmod($path, 0o644);
        }
    }

    // ------------------------------------------------------------ one list

    public function test_execution_list_matches_the_compliance_rule(): void
    {
        // The build runs both: this gate over the staged tree, and the
        // compliance engine over the finished zip. If the lists drift, one
        // passes what the other rejects.
        $gate = \Exec_Gate::EXECUTION_CONSTRUCTS;
        $rule = Dangerous_Constructs_Rule::CONSTRUCTS;
        sort($gate);
        sort($rule);
        $this->assertSame($rule, $gate);
    }

    public function test_banned_list_adds_the_issue_167_constructs(): void
    {
        $this->assertContains('str_rot13', \Exec_Gate::banned());
        $this->assertContains('move_uploaded_file', \Exec_Gate::banned());
    }
}
