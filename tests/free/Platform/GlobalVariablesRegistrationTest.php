<?php

namespace WPMCP\Tests\Free\Platform;

/**
 * The Elementor v4 global variable tools register like the global class
 * suite: pro tier, elementor domain, edit_posts to read and manage_options to
 * write (Elementor's own variables REST API asks for the same), and a delete
 * that is advertised as destructive. Registration is unconditional, so this
 * holds whether or not Elementor is installed.
 */
class GlobalVariablesRegistrationTest extends \WP_UnitTestCase
{
    public function test_the_four_tools_register_with_the_global_class_conventions(): void
    {
        $by_name = [];
        foreach (RegisteredAbilities::all() as $ability) {
            $by_name[ $ability->name ] = $ability;
        }

        $expected = [
            'wpmcp/list-global-variables'  => ['edit_posts', 'read'],
            'wpmcp/create-global-variable' => ['manage_options', 'create'],
            'wpmcp/update-global-variable' => ['manage_options', 'update'],
            'wpmcp/delete-global-variable' => ['manage_options', 'delete'],
        ];

        foreach ($expected as $name => [$capability, $operation]) {
            $this->assertArrayHasKey($name, $by_name, $name . ' is not registered.');
            $ability = $by_name[ $name ];
            $this->assertSame('pro', $ability->tier, $name);
            $this->assertSame('elementor', $ability->domain, $name);
            $this->assertSame($capability, $ability->capability, $name);
            $this->assertSame($operation, $ability->operation, $name);
        }

        $this->assertTrue($by_name['wpmcp/list-global-variables']->read_only_hint);
        $this->assertTrue($by_name['wpmcp/delete-global-variable']->destructive_hint);

        foreach (['create', 'update', 'delete'] as $verb) {
            $schema = $by_name['wpmcp/' . $verb . '-global-variable']->input_schema;
            $this->assertContains('expected_hash', $schema['required'], $verb);
        }

        $type = $by_name['wpmcp/create-global-variable']->input_schema['properties']['type'];
        $this->assertSame(['color', 'font', 'size', 'custom-size'], $type['enum']);
    }
}
