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
 * over the staged tree. Two things are pinned together by ExecGateTest so the
 * two steps cannot enforce different policies: CONSTRUCTS below equals
 * Exec_Gate::EXECUTION_CONSTRUCTS, and both matchers report the same lines
 * over one probe file, so a bare, `\qualified` or `Ns\qualified` call, eval
 * and a backtick operator are findings for both while a method, a
 * declaration, a constant or a class name is a finding for neither. Both are
 * token level, so pattern strings in Malware_Audit and documentation comments
 * cannot false-positive.
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
                $sites[] = ['name' => 'eval', 'line' => $line, 'label' => 'eval()'];
            }
            foreach ($file->find_calls(self::CONSTRUCTS, false) as $call) {
                $sites[] = ['name' => $call['name'], 'line' => $call['line'], 'label' => $call['name'] . '()'];
            }
            // The backtick operator is shell_exec() spelled differently, and
            // reviewers read it as such.
            foreach ($file->shell_backtick_lines() as $line) {
                $sites[] = ['name' => 'shell_exec', 'line' => $line, 'label' => 'the backtick operator (shell_exec)'];
            }
            usort($sites, static fn (array $a, array $b): int => $a['line'] <=> $b['line']);
            foreach ($sites as $site) {
                $allowed = $context->profile()->allows_exec($file->relative_path(), $site['name']);
                $findings[] = $this->finding(
                    $file,
                    $site['line'],
                    $allowed
                        ? sprintf(
                            'audited execution site %s: permitted by the %s profile, and must never reach a WordPress.org build',
                            $site['label'],
                            $context->profile()->name()
                        )
                        : sprintf('execution construct %s must not ship', $site['label']),
                    $allowed ? Severity::BEST_PRACTICE : null
                );
            }
        }
        return $findings;
    }
}
