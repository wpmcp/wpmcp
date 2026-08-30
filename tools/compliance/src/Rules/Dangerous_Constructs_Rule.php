<?php

namespace WPMCP\Compliance\Rules;

use WPMCP\Compliance\Rule_Context;
use WPMCP\Compliance\Severity;

/**
 * The execution family.
 *
 * eval and proc_open are on Plugin Check's Generic.PHP.ForbiddenFunctions
 * list (error, severity 7); shell_exec, exec, system and popen are not, but
 * reviewers close submissions over them under guidelines 8 and 9, so the
 * repo's own build gate already treats them as fatal. This rule is the
 * artifact-level half of scripts/lib/exec-gate.php, which the same builds run
 * over the staged tree: CONSTRUCTS below and Exec_Gate::EXECUTION_CONSTRUCTS
 * are the same list, pinned together by ExecGateTest, so the two steps cannot
 * enforce different policies. Both are token level, so pattern strings in
 * Malware_Audit and documentation comments cannot false-positive.
 *
 * The profile decides whether the two audited, default-off, environment-gated
 * call sites are an exception (distribution) or a hard failure (wporg-free).
 */
final class Dangerous_Constructs_Rule extends Base_Rule
{
    public const CONSTRUCTS = [
        'exec',
        'shell_exec',
        'system',
        'passthru',
        'proc_open',
        'popen',
        'pcntl_exec',
        'create_function',
        'assert',
    ];

    public function id(): string
    {
        return 'WPORG-09-EXEC';
    }

    public function guideline(): string
    {
        return 'Guideline 9 and Plugin Check Generic.PHP.ForbiddenFunctions';
    }

    public function title(): string
    {
        return 'Arbitrary code and shell execution';
    }

    public function explanation(): string
    {
        return 'eval, proc_open and create_function are Plugin Check errors outright. shell_exec, exec, '
            . 'system, popen and pcntl_exec are not on that list but are treated as remote code '
            . 'execution by reviewers under guideline 9. A directory build must contain none of them. '
            . 'Call sites that are audited, default-off and environment-gated can be allowlisted per '
            . 'profile; the allowlist is empty for wporg-free by design.';
    }

    public function check(Rule_Context $context): array
    {
        $findings = [];
        foreach ($context->php_files() as $file) {
            $sites = [];
            foreach ($file->lines_with_tokens([T_EVAL]) as $line) {
                $sites[] = ['name' => 'eval', 'line' => $line];
            }
            foreach ($file->find_calls(self::CONSTRUCTS, false) as $call) {
                $sites[] = $call;
            }
            foreach ($sites as $site) {
                $allowed = $context->profile()->allows_exec($file->relative_path(), $site['name']);
                $findings[] = $this->finding(
                    $file,
                    $site['line'],
                    $allowed
                        ? sprintf(
                            'audited execution site %s(): permitted by the %s profile, and must never reach a WordPress.org build',
                            $site['name'],
                            $context->profile()->name()
                        )
                        : sprintf('execution construct %s() must not ship', $site['name']),
                    $allowed ? Severity::BEST_PRACTICE : null
                );
            }
        }
        return $findings;
    }
}
