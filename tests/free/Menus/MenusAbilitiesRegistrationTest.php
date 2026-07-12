<?php

namespace WPMCP\Tests\Free\Menus;

class MenusAbilitiesRegistrationTest extends \WP_UnitTestCase
{
    private const TOOLS = [
        'wpmcp/list-menus',
        'wpmcp/get-menu',
        'wpmcp/list-menu-locations',
        'wpmcp/create-menu',
        'wpmcp/add-menu-item',
        'wpmcp/update-menu-item',
        'wpmcp/remove-menu-item',
        'wpmcp/assign-menu-to-location',
        'wpmcp/delete-menu',
    ];

    public function test_all_menu_tools_are_registered_as_free_abilities(): void
    {
        $names = array_keys(wp_get_abilities());

        foreach (self::TOOLS as $name) {
            $this->assertContains($name, $names, "Expected {$name} to be registered");
        }
    }

    public function test_menu_abilities_have_description_and_category(): void
    {
        $abilities = wp_get_abilities();

        foreach (self::TOOLS as $name) {
            $ability = $abilities[ $name ];
            $this->assertNotEmpty($ability->get_description(), "Expected {$name} to have a description");
            $this->assertSame('wpmcp', $ability->get_category());
        }
    }
}
