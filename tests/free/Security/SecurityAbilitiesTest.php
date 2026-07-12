<?php

namespace WPMCP\Tests\Free\Security;

use WPMCP\Tools\Security\Scan_Security;
use WPMCP\Tools\Security\Security_Scanner;

class SecurityAbilitiesTest extends \WP_UnitTestCase
{
    public function test_scan_security_ability_is_registered(): void
    {
        $names = array_keys(wp_get_abilities());

        $this->assertContains('wpmcp/scan-security', $names);
    }

    public function test_scan_security_ability_has_description_and_category(): void
    {
        $ability = wp_get_abilities()['wpmcp/scan-security'];

        $this->assertNotEmpty($ability->get_description());
        $this->assertSame('wpmcp', $ability->get_category());
    }

    public function test_scan_security_denies_subscriber_and_allows_administrator(): void
    {
        $ability = wp_get_abilities()['wpmcp/scan-security'];

        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $this->assertFalse($ability->check_permissions());

        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        $this->assertTrue($ability->check_permissions());
    }

    public function test_handle_returns_the_report_shape(): void
    {
        // Inject a scanner double so no filesystem or HTTP is touched.
        $scanner = new class extends Security_Scanner {
            public function scan(array $input): array
            {
                return [
                    'summary'             => ['score' => 100, 'grade' => 'A', 'counts' => []],
                    'sections'            => [],
                    'scan_meta'           => [],
                    'top_recommendations' => [],
                ];
            }
        };

        $report = (new Scan_Security($scanner))->handle([]);

        $this->assertSame('A', $report['summary']['grade']);
        $this->assertArrayHasKey('scan_meta', $report);
    }
}
