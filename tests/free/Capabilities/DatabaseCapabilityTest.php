<?php

namespace WPMCP\Tests\Free\Capabilities;

use WPMCP\Plugin;

/**
 * Capability gating for the Database domain: every ability, reads included,
 * requires manage_options. Raw table/row access is equivalent to
 * phpMyAdmin-level access to the site, so even reads sit well above the
 * default edit_posts capability.
 */
class DatabaseCapabilityTest extends \WP_UnitTestCase
{
    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    private const EXPECTED = [
        'wpmcp/list-tables'    => 'manage_options',
        'wpmcp/describe-table' => 'manage_options',
        'wpmcp/query'          => 'manage_options',
        'wpmcp/insert-row'     => 'manage_options',
        'wpmcp/update-rows'    => 'manage_options',
        'wpmcp/delete-rows'    => 'manage_options',
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
            $abilities['wpmcp/query']->check_permissions(),
            'wpmcp/query must deny an editor (no manage_options)'
        );

        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        $this->assertTrue(
            $abilities['wpmcp/query']->check_permissions(),
            'wpmcp/query must allow a user holding manage_options'
        );
    }

    public function test_write_ability_denies_editor_and_allows_manage_options(): void
    {
        $abilities = wp_get_abilities();

        $editor = self::factory()->user->create(['role' => 'editor']);
        wp_set_current_user($editor);
        $this->assertFalse(
            $abilities['wpmcp/delete-rows']->check_permissions(),
            'wpmcp/delete-rows must deny an editor (no manage_options)'
        );

        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        $this->assertTrue(
            $abilities['wpmcp/delete-rows']->check_permissions(),
            'wpmcp/delete-rows must allow a user holding manage_options'
        );
    }
}
