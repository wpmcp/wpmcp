<?php

namespace WPMCP\Tests\Free\Capabilities;

use WPMCP\Plugin;

/**
 * Capability gating for the Packages domain (plugins and themes): each
 * mutation requires WordPress core's own matching capability
 * (activate_plugins, install_plugins, update_plugins, delete_plugins,
 * switch_themes, install_themes, update_themes, delete_themes). Both list
 * reads (list-plugins, list-themes) are gated at activate_plugins, the
 * lowest of the package-management capabilities, rather than the default
 * edit_posts.
 */
class PackagesCapabilityTest extends \WP_UnitTestCase
{
    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    private const EXPECTED = [
        'wpmcp/list-plugins'      => 'activate_plugins',
        'wpmcp/activate-plugin'   => 'activate_plugins',
        'wpmcp/deactivate-plugin' => 'activate_plugins',
        'wpmcp/install-plugin'    => 'install_plugins',
        'wpmcp/update-plugin'     => 'update_plugins',
        'wpmcp/delete-plugin'     => 'delete_plugins',
        'wpmcp/list-themes'       => 'activate_plugins',
        'wpmcp/switch-theme'      => 'switch_themes',
        'wpmcp/install-theme'     => 'install_themes',
        'wpmcp/update-theme'      => 'update_themes',
        'wpmcp/delete-theme'      => 'delete_themes',
        'wpmcp/manage-updates'    => 'update_core',
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

    public function test_read_ability_denies_subscriber_and_allows_activate_plugins(): void
    {
        $abilities = wp_get_abilities();

        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $this->assertFalse(
            $abilities['wpmcp/list-plugins']->check_permissions(),
            'wpmcp/list-plugins must deny a subscriber'
        );

        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        $this->assertTrue(
            $abilities['wpmcp/list-plugins']->check_permissions(),
            'wpmcp/list-plugins must allow a user holding activate_plugins'
        );
    }

    public function test_write_ability_denies_subscriber_and_allows_delete_plugins(): void
    {
        $abilities = wp_get_abilities();

        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $this->assertFalse(
            $abilities['wpmcp/delete-plugin']->check_permissions(),
            'wpmcp/delete-plugin must deny a subscriber'
        );

        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        $this->assertTrue(
            $abilities['wpmcp/delete-plugin']->check_permissions(),
            'wpmcp/delete-plugin must allow a user holding delete_plugins'
        );
    }
}
