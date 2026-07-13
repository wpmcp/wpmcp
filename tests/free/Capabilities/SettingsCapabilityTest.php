<?php

namespace WPMCP\Tests\Free\Capabilities;

use WPMCP\Plugin;

/**
 * Capability gating for the Settings domain: get-settings and
 * update-settings read/write core WordPress site-wide options (general,
 * reading, writing, discussion, media, permalinks). WordPress core gates its
 * own Settings screens at manage_options; both abilities require the same
 * capability rather than the default edit_posts, so an Author-tier user
 * cannot rewrite site-wide configuration (including the permalink
 * structure) via these tools.
 */
class SettingsCapabilityTest extends \WP_UnitTestCase
{
    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    private const EXPECTED = [
        'wpmcp/get-settings'    => 'manage_options',
        'wpmcp/update-settings' => 'manage_options',
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
            $abilities['wpmcp/get-settings']->check_permissions(),
            'wpmcp/get-settings must deny an editor (no manage_options)'
        );

        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        $this->assertTrue(
            $abilities['wpmcp/get-settings']->check_permissions(),
            'wpmcp/get-settings must allow a user holding manage_options'
        );
    }

    public function test_write_ability_denies_editor_and_allows_manage_options(): void
    {
        $abilities = wp_get_abilities();

        $editor = self::factory()->user->create(['role' => 'editor']);
        wp_set_current_user($editor);
        $this->assertFalse(
            $abilities['wpmcp/update-settings']->check_permissions(),
            'wpmcp/update-settings must deny an editor (no manage_options)'
        );

        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        $this->assertTrue(
            $abilities['wpmcp/update-settings']->check_permissions(),
            'wpmcp/update-settings must allow a user holding manage_options'
        );
    }
}
