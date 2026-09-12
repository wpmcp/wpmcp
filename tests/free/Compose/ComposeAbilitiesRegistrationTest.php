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

    /**
     * The dialect enum is build-specific: the directory cut ships
     * ['gutenberg'] only (issue #162), the full build adds 'elementor'. What
     * every build must document is asserted here; the elementor value is
     * asserted in tests/pro/Compose/ComposeAbilitiesBuilderTest.php and its
     * absence from the stripped schema in
     * tests/free/Flavors/WporgStripBuildPageTest.php.
     */
    public function test_build_page_input_schema_documents_the_block_dialect(): void
    {
        $ability = wp_get_abilities()['wpmcp/build-page'];
        $schema  = wp_json_encode($ability->get_input_schema());

        $this->assertStringContainsString('spec', $schema);
        $this->assertStringContainsString('gutenberg', $schema);
        $this->assertStringContainsString('children', $schema);
    }
}
