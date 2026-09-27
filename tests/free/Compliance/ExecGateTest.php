<?php

namespace WPMCP\Tests\Free\Compliance;

use WPMCP\Compliance\Finding;
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
 *
 * The gate is deliberately dependency-free (see its docblock), so it cannot
 * reuse Source_File. The last test runs it and Dangerous_Constructs_Rule over
 * one probe and pins the two matchers to the same lines.
 */
class ExecGateTest extends Compliance_Test_Case
{
    /**
     * @return string[] the construct names the gate reported, in order
     */
    private function scan(string $code, string $name = 'file.php'): array
    {
        $root = $this->make_plugin([$name => $code]);
        return array_map(
            static fn (array $finding): string => $finding['construct'],
            \Exec_Gate::scan_file($root . '/' . $name)
        );
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
        $path = $this->make_plugin(['lines.php' => "<?php\n\n\nshell_exec('id');\n"]) . '/lines.php';
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

    public function test_keyword_case_does_not_matter(): void
    {
        // PHP keywords are case-insensitive, and third-party code does write
        // them this way; the gate walks vendor, where a false positive is a
        // broken release build with no code of ours to fix.
        $code = '<?php $x = New Popen(); class B { Function system() {} FUNCTION exec() {} }';
        $this->assertSame([], $this->scan($code));
    }

    public function test_by_reference_declaration_and_attribute_are_not_calls(): void
    {
        $code = '<?php class B { public function &popen() {} } #[Assert()] class C {}';
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
        $root = $this->make_plugin([
            'A.PHP' => '<?php proc_open("x", [], $p);',
            'b.inc' => '<?php system("x");',
            'c.phtml' => '<?php passthru("x");',
            'notes.txt' => 'proc_open("x");',
        ]);

        $found = array_map(
            static fn (array $f): string => $f['construct'],
            \Exec_Gate::scan_paths([$root])
        );
        sort($found);
        $this->assertSame(['passthru', 'proc_open', 'system'], $found);
    }

    public function test_missing_path_throws_rather_than_passing_clean(): void
    {
        $this->expectException(\RuntimeException::class);
        \Exec_Gate::scan_paths([$this->make_plugin([]) . '/never-staged']);
    }

    public function test_unreadable_file_throws_rather_than_scanning_as_empty(): void
    {
        $path = $this->make_plugin(['locked.php' => '<?php proc_open("x", [], $p);']) . '/locked.php';
        chmod($path, 0o000);
        if (is_readable($path)) {
            // Running as root: the permission bit cannot be enforced.
            chmod($path, 0o644);
            $this->markTestSkipped('the test runner can read a 0000 file');
        }
        try {
            $this->expectException(\RuntimeException::class);
            \Exec_Gate::scan_file($path);
        } finally {
            chmod($path, 0o644);
        }
    }

    // ------------------------------------------------------------ one policy

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

    public function test_gate_and_compliance_rule_match_the_same_call_shapes(): void
    {
        // One list is not enough: the two matchers are separate code (the
        // gate is dependency-free on purpose), so a call shape one of them
        // learns to see and the other does not is the same drift as a list
        // entry. Every line of this probe is either a call site both must
        // report or a look-alike neither may.
        $probe = implode("\n", [
            '<?php',                                                    // 1
            'namespace A;',                                             // 2
            'proc_open("ls", [], $p);',                                 // 3  call
            '\shell_exec("id");',                                       // 4  call, fully qualified
            'Foo\system("ls");',                                        // 5  call, namespace qualified
            'eval("1;");',                                              // 6  call
            '$o = `id`;',                                               // 7  call, backtick
            '$o->exec(); $o?->popen(); C::passthru(); $x = New Popen();', // 8 look-alike
            'class B { Function exec() {} public function &popen() {} }', // 9 look-alike
            '#[Assert()] class C { const SYSTEM = 1; }',                // 10 look-alike
            "// proc_open is a pattern; \$s = 'shell_exec(';",          // 11 look-alike
        ]);
        $files = ['example-toolkit.php' => $this->main_file(), 'includes/probe.php' => $probe];
        $root = $this->make_plugin($files);

        $gate = array_map(
            static fn (array $f): int => $f['line'],
            \Exec_Gate::scan_file($root . '/includes/probe.php')
        );
        $rule = array_map(
            static fn (Finding $f): int => $f->line(),
            $this->findings(new Dangerous_Constructs_Rule(), $files)
        );
        sort($gate);
        sort($rule);

        $this->assertSame([3, 4, 5, 6, 7], $gate, 'the gate must see every call shape and no look-alike');
        $this->assertSame($gate, $rule, 'the compliance rule must report exactly the lines the gate does');
    }

    public function test_banned_list_adds_the_issue_167_constructs(): void
    {
        $this->assertContains('str_rot13', \Exec_Gate::banned());
        $this->assertContains('move_uploaded_file', \Exec_Gate::banned());
    }
}
