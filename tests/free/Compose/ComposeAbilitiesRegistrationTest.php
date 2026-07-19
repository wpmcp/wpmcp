<?php

namespace WPMCP\Tests\Free\Compose;

class ComposeAbilitiesRegistrationTest extends \WP_UnitTestCase
{
    public function test_build_page_is_registered_as_a_free_ability(): void
    {
        $abilities = wp_get_abilities();

        $this->assertArrayHasKey('wpmcp/build-page', $abilities);

        $ability = $abilities['wpmcp/build-page'];
        $this->assertNotEmpty($ability->get_description());
        $this->assertSame('wpmcp', $ability->get_category());
    }

    public function test_build_page_input_schema_documents_both_dialects(): void
    {
        $ability = wp_get_abilities()['wpmcp/build-page'];
        $schema  = wp_json_encode($ability->get_input_schema());

        $this->assertStringContainsString('spec', $schema);
        $this->assertStringContainsString('gutenberg', $schema);
        $this->assertStringContainsString('elementor', $schema);
        $this->assertStringContainsString('children', $schema);
    }
}
