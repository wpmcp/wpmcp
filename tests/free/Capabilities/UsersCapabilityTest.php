<?php

namespace WPMCP\Tests\Free\Capabilities;

use WPMCP\Plugin;

/**
 * Capability gating for the Users domain: reads require list_users, create
 * requires create_users, and update requires edit_users, matching WordPress
 * core's own per-operation user-management capabilities.
 */
class UsersCapabilityTest extends \WP_UnitTestCase
{
    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    private const EXPECTED = [
        'wpmcp/list-users'  => 'list_users',
        'wpmcp/get-user'    => 'list_users',
        'wpmcp/create-user' => 'create_users',
        'wpmcp/update-user' => 'edit_users',
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

    public function test_read_ability_denies_subscriber_and_allows_list_users(): void
    {
        $abilities = wp_get_abilities();

        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $this->assertFalse(
            $abilities['wpmcp/list-users']->check_permissions(),
            'wpmcp/list-users must deny a subscriber'
        );

        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        wpmcp_test_grant_network_admin(get_current_user_id());
        $this->assertTrue(
            $abilities['wpmcp/list-users']->check_permissions(),
            'wpmcp/list-users must allow a user holding list_users'
        );
    }

    public function test_create_ability_denies_subscriber_and_allows_create_users(): void
    {
        $abilities = wp_get_abilities();

        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $this->assertFalse(
            $abilities['wpmcp/create-user']->check_permissions(),
            'wpmcp/create-user must deny a subscriber'
        );

        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        wpmcp_test_grant_network_admin(get_current_user_id());
        $this->assertTrue(
            $abilities['wpmcp/create-user']->check_permissions(),
            'wpmcp/create-user must allow a user holding create_users'
        );
    }

    public function test_update_ability_denies_subscriber_and_allows_edit_users(): void
    {
        $abilities = wp_get_abilities();

        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $this->assertFalse(
            $abilities['wpmcp/update-user']->check_permissions(),
            'wpmcp/update-user must deny a subscriber'
        );

        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        wpmcp_test_grant_network_admin(get_current_user_id());
        $this->assertTrue(
            $abilities['wpmcp/update-user']->check_permissions(),
            'wpmcp/update-user must allow a user holding edit_users'
        );
    }
}
