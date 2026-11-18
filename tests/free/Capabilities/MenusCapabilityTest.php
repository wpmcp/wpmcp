<?php

namespace WPMCP\Tests\Free\Capabilities;

use WPMCP\Plugin;

/**
 * Capability gating for the Menus domain: every navigation-menu ability
 * requires edit_theme_options, WordPress core's own gate for managing menus.
 */
class MenusCapabilityTest extends \WP_UnitTestCase
{
    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    private const EXPECTED = [
        'wpmcp/list-menus'              => 'edit_theme_options',
        'wpmcp/get-menu'                => 'edit_theme_options',
        'wpmcp/list-menu-locations'     => 'edit_theme_options',
        'wpmcp/create-menu'             => 'edit_theme_options',
        'wpmcp/add-menu-item'           => 'edit_theme_options',
        'wpmcp/update-menu-item'        => 'edit_theme_options',
        'wpmcp/remove-menu-item'        => 'edit_theme_options',
        'wpmcp/assign-menu-to-location' => 'edit_theme_options',
        'wpmcp/delete-menu'             => 'edit_theme_options',
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

    public function test_read_ability_denies_subscriber_and_allows_edit_theme_options(): void
    {
        $abilities = wp_get_abilities();

        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $this->assertNotTrue(
            $abilities['wpmcp/list-menus']->check_permissions(),
            'wpmcp/list-menus must deny a subscriber'
        );

        $user = self::factory()->user->create(['role' => 'subscriber']);
        get_user_by('id', $user)->add_cap('edit_theme_options');
        wp_set_current_user($user);
        $this->assertTrue(
            $abilities['wpmcp/list-menus']->check_permissions(),
            'wpmcp/list-menus must allow a user holding edit_theme_options'
        );
    }

    public function test_write_ability_denies_subscriber_and_allows_edit_theme_options(): void
    {
        $abilities = wp_get_abilities();

        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $this->assertNotTrue(
            $abilities['wpmcp/create-menu']->check_permissions(),
            'wpmcp/create-menu must deny a subscriber'
        );

        $user = self::factory()->user->create(['role' => 'subscriber']);
        get_user_by('id', $user)->add_cap('edit_theme_options');
        wp_set_current_user($user);
        $this->assertTrue(
            $abilities['wpmcp/create-menu']->check_permissions(),
            'wpmcp/create-menu must allow a user holding edit_theme_options'
        );
    }
}
