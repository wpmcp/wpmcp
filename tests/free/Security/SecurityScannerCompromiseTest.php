<?php

namespace WPMCP\Tests\Free\Security;

use WPMCP\Tools\Security\Compromise_Audit;
use WPMCP\Tools\Security\Security_Finding;
use WPMCP\Tools\Security\Security_Scanner;

/**
 * Issue #845: "compromise" is a check name on scan-security, run only when
 * asked for, so a default scan keeps its time, its requests and its score.
 */
class SecurityScannerCompromiseTest extends \WP_UnitTestCase
{
    public function test_compromise_is_valid_but_not_a_default_check(): void
    {
        $scanner = new Security_Scanner();
        $this->assertSame(['compromise'], $scanner->resolve_checks(['compromise']));
        $this->assertSame(['hardening', 'compromise'], $scanner->resolve_checks(['compromise', 'hardening']));
        $this->assertNotContains('compromise', $scanner->resolve_checks(null));
    }

    public function test_scan_runs_the_compromise_audit_with_the_clamped_budget(): void
    {
        $captured   = [];
        $compromise = new class ($captured) extends Compromise_Audit {
            /** @var array<string,int> */
            private array $captured;

            public function __construct(array &$captured)
            {
                $this->captured = &$captured;
            }

            public function run(int $max_files, int $max_seconds): array
            {
                $this->captured = ['max_files' => $max_files, 'max_seconds' => $max_seconds];
                return [
                    'findings' => [Security_Finding::make('compromise_cloaking', 'compromise', 'C', 'critical', [], 'msg', 'rec')],
                    'stats'    => ['files_checked' => 7, 'truncated' => false, 'truncated_reason' => null, 'requests' => 3],
                ];
            }
        };
        $scanner = new Security_Scanner(null, null, null, null, null, $compromise);

        $report = $scanner->scan(['checks' => ['compromise'], 'max_files' => 999999, 'max_seconds' => 5]);

        $this->assertSame(['compromise'], $report['scan_meta']['checks_run']);
        $this->assertSame(['max_files' => 20000, 'max_seconds' => 5], $captured);
        $this->assertCount(1, $report['sections']['compromise']);
        $this->assertSame(7, $report['scan_meta']['compromise']['files_checked']);
        $this->assertSame(80, $report['summary']['score']);
    }
}
