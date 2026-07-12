<?php

namespace WPMCP\Tests\Free\Security;

use WPMCP\Tools\Security\Hardening_Audit;
use WPMCP\Tools\Security\Integrity_Audit;
use WPMCP\Tools\Security\Malware_Audit;
use WPMCP\Tools\Security\Security_Finding;
use WPMCP\Tools\Security\Security_Scanner;
use WPMCP\Tools\Security\Software_Audit;

class SecurityScannerScanTest extends \WP_UnitTestCase
{
    /**
     * Records the max_files/max_seconds it was handed so the test can assert the
     * scanner clamps them before delegating.
     */
    private function malware_double(array &$captured): Malware_Audit
    {
        return new class ($captured) extends Malware_Audit {
            /** @var array<string,int> */
            private array $captured;

            public function __construct(array &$captured)
            {
                $this->captured = &$captured;
            }

            public function run(bool $deep, int $max_files, int $max_seconds): array
            {
                $this->captured = ['max_files' => $max_files, 'max_seconds' => $max_seconds];
                return [
                    'findings' => [
                        Security_Finding::make('m', 'malware', 'M', 'critical', ['location' => 'x.php:1', 'snippet' => 's'], 'msg', 'rec'),
                    ],
                    'stats'    => [
                        'files_scanned'      => 3,
                        'files_skipped_size' => 0,
                        'truncated'          => false,
                        'truncated_reason'   => null,
                    ],
                ];
            }
        };
    }

    private function integrity_double(): Integrity_Audit
    {
        return new class extends Integrity_Audit {
            public function run(): array
            {
                return ['findings' => [], 'api' => ['ok' => true, 'error' => null]];
            }
        };
    }

    private function hardening_double(): Hardening_Audit
    {
        return new class extends Hardening_Audit {
            public function run(): array
            {
                return [
                    'findings'      => [Security_Finding::make('h', 'hardening', 'H', 'warning', true, 'msg', 'rec')],
                    'headers_fetch' => ['ok' => true, 'error' => null],
                ];
            }
        };
    }

    private function software_double(): Software_Audit
    {
        return new class extends Software_Audit {
            public function run(): array
            {
                return [Security_Finding::make('s', 'software', 'S', 'pass', true, 'ok')];
            }
        };
    }

    public function test_scan_assembles_a_scored_grouped_report_from_all_audits(): void
    {
        $captured = [];
        $scanner  = new Security_Scanner(
            $this->malware_double($captured),
            $this->integrity_double(),
            $this->hardening_double(),
            $this->software_double()
        );

        $report = $scanner->scan([]);

        $this->assertArrayHasKey('summary', $report);
        $this->assertArrayHasKey('sections', $report);
        $this->assertArrayHasKey('scan_meta', $report);
        $this->assertArrayHasKey('top_recommendations', $report);

        // One malware critical (-20) + one hardening warning (-5) => 75 => C.
        $this->assertSame(75, $report['summary']['score']);
        $this->assertSame('C', $report['summary']['grade']);

        $this->assertCount(1, $report['sections']['malware']);
        $this->assertCount(1, $report['sections']['hardening']);
        $this->assertSame(['malware', 'integrity', 'hardening', 'software'], $report['scan_meta']['checks_run']);
        $this->assertSame(3, $report['scan_meta']['files_scanned']);
    }

    public function test_scan_only_runs_the_requested_checks(): void
    {
        $captured = [];
        $scanner  = new Security_Scanner(
            $this->malware_double($captured),
            $this->integrity_double(),
            $this->hardening_double(),
            $this->software_double()
        );

        $report = $scanner->scan(['checks' => ['hardening']]);

        $this->assertSame(['hardening'], $report['scan_meta']['checks_run']);
        // Malware was not requested, so its double never populated $captured.
        $this->assertSame([], $captured);
        $this->assertCount(0, $report['sections']['malware']);
        $this->assertCount(1, $report['sections']['hardening']);
    }

    public function test_scan_clamps_out_of_range_malware_caps(): void
    {
        $captured = [];
        $scanner  = new Security_Scanner(
            $this->malware_double($captured),
            $this->integrity_double(),
            $this->hardening_double(),
            $this->software_double()
        );

        $scanner->scan(['checks' => ['malware'], 'max_files' => 999999, 'max_seconds' => 999999]);

        $this->assertSame(Malware_Audit::MAX_FILES_CEILING, $captured['max_files']);
        $this->assertSame(Malware_Audit::TIME_BUDGET_CEILING, $captured['max_seconds']);
    }

    public function test_scan_clamps_below_range_malware_caps_to_a_floor_of_one(): void
    {
        $captured = [];
        $scanner  = new Security_Scanner(
            $this->malware_double($captured),
            $this->integrity_double(),
            $this->hardening_double(),
            $this->software_double()
        );

        $scanner->scan(['checks' => ['malware'], 'max_files' => 0, 'max_seconds' => -5]);

        $this->assertSame(1, $captured['max_files']);
        $this->assertSame(1, $captured['max_seconds']);
    }
}
