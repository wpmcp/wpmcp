<?php

namespace WPMCP\Tests\Free\Capabilities;

use WPMCP\Plugin;

/**
 * Capability gating for the Security domain: scan-security requires
 * manage_options, since it reports on core file integrity, malware
 * heuristics, and hardening configuration.
 */
class SecurityCapabilityTest extends \WP_UnitTestCase
{
    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function test_registered_capability_matches_expected_map(): void
    {
        $abilities = [];
        foreach (Plugin::instance()->registrar()->all() as $ability) {
            $abilities[ $ability->name ] = $ability;
        }

        $this->assertArrayHasKey('wpmcp/scan-security', $abilities);
        $this->assertSame('manage_options', $abilities['wpmcp/scan-security']->capability);
    }

    public function test_scan_security_denies_editor_and_allows_manage_options(): void
    {
        $abilities = wp_get_abilities();

        $editor = self::factory()->user->create(['role' => 'editor']);
        wp_set_current_user($editor);
        $this->assertNotTrue(
            $abilities['wpmcp/scan-security']->check_permissions(),
            'wpmcp/scan-security must deny an editor (no manage_options)'
        );

        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        $this->assertTrue(
            $abilities['wpmcp/scan-security']->check_permissions(),
            'wpmcp/scan-security must allow a user holding manage_options'
        );
    }
}
