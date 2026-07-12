<?php

namespace WPMCP\Tests\Free\Elementor;

class ElementorAbilitiesRegistrationTest extends \WP_UnitTestCase
{
    private const TOOLS = [
        'wpmcp/list-widgets',
        'wpmcp/get-widget-schema',
    ];

    public function test_all_elementor_tools_are_registered_as_free_abilities(): void
    {
        $names = array_keys(wp_get_abilities());

        foreach (self::TOOLS as $name) {
            $this->assertContains($name, $names, "Expected {$name} to be registered");
        }
    }

    public function test_elementor_abilities_have_description_and_category(): void
    {
        $abilities = wp_get_abilities();

        foreach (self::TOOLS as $name) {
            $ability = $abilities[ $name ];
            $this->assertNotEmpty($ability->get_description(), "Expected {$name} to have a description");
            $this->assertSame('wpmcp', $ability->get_category());
        }
    }

    public function test_elementor_abilities_allow_edit_posts_capability(): void
    {
        $abilities = wp_get_abilities();

        $author = self::factory()->user->create(['role' => 'author']);
        wp_set_current_user($author);

        foreach (self::TOOLS as $name) {
            $this->assertTrue($abilities[ $name ]->check_permissions(), "{$name} must allow edit_posts");
        }

        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);

        foreach (self::TOOLS as $name) {
            $this->assertFalse($abilities[ $name ]->check_permissions(), "{$name} must deny a subscriber");
        }
    }
}
