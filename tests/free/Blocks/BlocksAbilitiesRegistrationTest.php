<?php

namespace WPMCP\Tests\Free\Blocks;

class BlocksAbilitiesRegistrationTest extends \WP_UnitTestCase
{
    private const NAMES = [
        'wpmcp/list-block-types',
        'wpmcp/get-block-type',
        'wpmcp/parse-blocks',
        'wpmcp/serialize-blocks',
        'wpmcp/convert-html-to-blocks',
        'wpmcp/add-block',
        'wpmcp/update-block',
        'wpmcp/remove-block',
        'wpmcp/move-block',
        'wpmcp/duplicate-block',
        'wpmcp/list-patterns',
        'wpmcp/insert-pattern',
    ];

    public function test_all_block_tools_are_registered_as_free_abilities(): void
    {
        $names = array_keys(wp_get_abilities());

        foreach (self::NAMES as $name) {
            $this->assertContains($name, $names, "Expected {$name} to be registered");
        }
    }

    public function test_block_abilities_have_description_and_category(): void
    {
        $abilities = wp_get_abilities();

        foreach (self::NAMES as $name) {
            $ability = $abilities[ $name ];
            $this->assertNotEmpty($ability->get_description(), "Expected {$name} to have a description");
            $this->assertSame('wpmcp', $ability->get_category());
        }
    }
}
