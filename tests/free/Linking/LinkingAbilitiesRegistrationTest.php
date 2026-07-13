<?php

namespace WPMCP\Tests\Free\Linking;

class LinkingAbilitiesRegistrationTest extends \WP_UnitTestCase
{
    public function test_linking_tools_are_registered_as_free_abilities(): void
    {
        $names = array_keys(wp_get_abilities());

        foreach ([
            'wpmcp/find-orphan-posts',
            'wpmcp/suggest-internal-links',
            'wpmcp/get-link-map',
        ] as $name) {
            $this->assertContains($name, $names, "Expected {$name} to be registered");
        }
    }
}
