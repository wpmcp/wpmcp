<?php

namespace WPMCP\Tests\Free\Packages;

class PackagesAbilitiesRegistrationTest extends \WP_UnitTestCase
{
    private const NAMES = [
        'wpmcp/list-plugins',
        'wpmcp/activate-plugin',
        'wpmcp/deactivate-plugin',
        'wpmcp/install-plugin',
        'wpmcp/update-plugin',
        'wpmcp/delete-plugin',
        'wpmcp/list-themes',
        'wpmcp/switch-theme',
        'wpmcp/install-theme',
        'wpmcp/update-theme',
        'wpmcp/delete-theme',
        'wpmcp/search-plugins',
        'wpmcp/get-plugin-info',
        'wpmcp/search-themes',
        'wpmcp/manage-updates',
    ];

    public function test_all_package_tools_are_registered_as_free_abilities(): void
    {
        $names = array_keys(wp_get_abilities());

        foreach (self::NAMES as $name) {
            $this->assertContains($name, $names, "Expected {$name} to be registered");
        }
    }

    public function test_package_abilities_have_description_and_category(): void
    {
        $abilities = wp_get_abilities();

        foreach (self::NAMES as $name) {
            $ability = $abilities[ $name ];
            $this->assertNotEmpty($ability->get_description(), "Expected {$name} to have a description");
            $this->assertSame('wpmcp', $ability->get_category());
        }
    }

    /**
     * Exercises each mutation ability's actual permission_callback (via
     * check_permissions()) rather than merely asserting a capability string,
     * proving the per-operation capability gating in Plugin::boot() really
     * takes effect: a subscriber (holds none of these capabilities) must be
     * denied, and an administrator (holds all of them) must be allowed.
     */
    public function test_mutation_abilities_deny_subscriber_and_allow_administrator(): void
    {
        $names = [
            'wpmcp/activate-plugin',
            'wpmcp/deactivate-plugin',
            'wpmcp/install-plugin',
            'wpmcp/update-plugin',
            'wpmcp/delete-plugin',
            'wpmcp/switch-theme',
            'wpmcp/install-theme',
            'wpmcp/update-theme',
            'wpmcp/delete-theme',
            'wpmcp/manage-updates',
        ];

        $abilities = wp_get_abilities();

        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        foreach ($names as $name) {
            $this->assertFalse($abilities[ $name ]->check_permissions(), "{$name} must deny a subscriber");
        }

        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        foreach ($names as $name) {
            $this->assertTrue($abilities[ $name ]->check_permissions(), "{$name} must allow an administrator");
        }
    }

    /**
     * search-plugins and get-plugin-info are read-only wp.org lookups: they
     * require install_plugins (same gate as install-plugin, since browsing
     * the directory is part of the install workflow), so they must be
     * denied to a subscriber and allowed to an administrator exactly like
     * the mutation tools above.
     */
    public function test_search_abilities_are_gated_by_install_plugins_capability(): void
    {
        $names = ['wpmcp/search-plugins', 'wpmcp/get-plugin-info', 'wpmcp/search-themes'];

        $abilities = wp_get_abilities();

        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        foreach ($names as $name) {
            $this->assertFalse($abilities[ $name ]->check_permissions(), "{$name} must deny a subscriber");
        }

        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        foreach ($names as $name) {
            $this->assertTrue($abilities[ $name ]->check_permissions(), "{$name} must allow an administrator");
        }
    }
}
