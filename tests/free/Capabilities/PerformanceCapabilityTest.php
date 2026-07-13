<?php

namespace WPMCP\Tests\Free\Capabilities;

use WPMCP\Plugin;

/**
 * Capability gating for the Performance domain (analyze-performance,
 * get-cache-status, clear-cache all share the 'performance' domain in
 * Plugin::boot()): every ability requires manage_options. Complements the
 * existing per-tool-namespace coverage in Performance/PerformanceAbilitiesTest
 * and Cache/CacheAbilitiesRegistrationTest with a single domain-level map.
 */
class PerformanceCapabilityTest extends \WP_UnitTestCase
{
    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    private const EXPECTED = [
        'wpmcp/analyze-performance' => 'manage_options',
        'wpmcp/get-cache-status'    => 'manage_options',
        'wpmcp/clear-cache'         => 'manage_options',
    ];

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

        foreach (self::EXPECTED as $name => $capability) {
            $this->assertArrayHasKey($name, $abilities, "Expected {$name} to be registered");
            $this->assertSame(
                $capability,
                $abilities[ $name ]->capability,
                "{$name} should require capability {$capability}"
            );
        }
    }

    public function test_read_ability_denies_editor_and_allows_manage_options(): void
    {
        $abilities = wp_get_abilities();

        $editor = self::factory()->user->create(['role' => 'editor']);
        wp_set_current_user($editor);
        $this->assertFalse(
            $abilities['wpmcp/analyze-performance']->check_permissions(),
            'wpmcp/analyze-performance must deny an editor (no manage_options)'
        );

        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        $this->assertTrue(
            $abilities['wpmcp/analyze-performance']->check_permissions(),
            'wpmcp/analyze-performance must allow a user holding manage_options'
        );
    }

    public function test_write_ability_denies_editor_and_allows_manage_options(): void
    {
        $abilities = wp_get_abilities();

        $editor = self::factory()->user->create(['role' => 'editor']);
        wp_set_current_user($editor);
        $this->assertFalse(
            $abilities['wpmcp/clear-cache']->check_permissions(),
            'wpmcp/clear-cache must deny an editor (no manage_options)'
        );

        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        $this->assertTrue(
            $abilities['wpmcp/clear-cache']->check_permissions(),
            'wpmcp/clear-cache must allow a user holding manage_options'
        );
    }
}
