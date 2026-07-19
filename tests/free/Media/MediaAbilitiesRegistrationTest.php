<?php

namespace WPMCP\Tests\Free\Media;

class MediaAbilitiesRegistrationTest extends \WP_UnitTestCase
{
    public function test_all_media_tools_are_registered_as_free_abilities(): void
    {
        $names = array_keys(wp_get_abilities());

        foreach ([
            'wpmcp/get-media',
            'wpmcp/update-media',
            'wpmcp/delete-media',
            'wpmcp/sideload-image',
            'wpmcp/list-media',
            'wpmcp/resize-media',
            'wpmcp/upload-svg',
            'wpmcp/set-stock-key',
            'wpmcp/search-stock-images',
            'wpmcp/import-stock-image',
        ] as $name) {
            $this->assertContains($name, $names, "Expected {$name} to be registered");
        }
    }

    public function test_insert_stock_image_is_not_registered_on_the_free_tier(): void
    {
        // PRO via Pro\Gate: the harness runs unlicensed, so the composite
        // builder-content insert must be absent from the free surface.
        $this->assertArrayNotHasKey('wpmcp/insert-stock-image', wp_get_abilities());
    }

    public function test_set_stock_key_requires_manage_options(): void
    {
        $ability = \WPMCP\Plugin::instance()->registrar()->get('wpmcp/set-stock-key');

        $this->assertNotNull($ability);
        $this->assertSame('manage_options', $ability->capability);
    }

    public function test_media_abilities_have_description_and_category(): void
    {
        $abilities = wp_get_abilities();

        foreach ([
            'wpmcp/get-media',
            'wpmcp/update-media',
            'wpmcp/delete-media',
            'wpmcp/sideload-image',
        ] as $name) {
            $ability = $abilities[ $name ];
            $this->assertNotEmpty($ability->get_description(), "Expected {$name} to have a description");
            $this->assertSame('wpmcp', $ability->get_category());
        }
    }
}
