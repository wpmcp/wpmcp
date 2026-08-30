<?php

namespace WPMCP\Tests\Pro\Compose;

/**
 * The build-page ability as the FULL build registers it (issue #57).
 *
 * The builder dialect is only offered by this build: the wp.org directory cut
 * strips the enum value, the composer and the handler branches (issue #162),
 * so this assertion belongs with the rest of the paid surface rather than in
 * the free suite, which used to make it.
 */
class ComposeAbilitiesBuilderTest extends \WP_UnitTestCase
{
    public function test_build_page_input_schema_offers_the_builder_dialect(): void
    {
        $ability = wp_get_abilities()['wpmcp/build-page'];
        $schema  = wp_json_encode($ability->get_input_schema());

        $this->assertStringContainsString('gutenberg', $schema);
        $this->assertStringContainsString('elementor', $schema);
    }
}
