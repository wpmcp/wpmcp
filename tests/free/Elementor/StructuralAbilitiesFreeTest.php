<?php

namespace WPMCP\Tests\Free\Elementor;

/**
 * Free-tier refusal for the structural editing suite (issue #58): the live
 * registry is built with the Gate in its free state, so none of the pro
 * structural tools may be exposed to a free-tier site - an agent asking for
 * them simply does not find them.
 */
class StructuralAbilitiesFreeTest extends \WP_UnitTestCase
{
    private const PRO_STRUCTURAL_TOOLS = [
        'wpmcp/add-container',
        'wpmcp/update-container',
        'wpmcp/batch-update',
        'wpmcp/reorder-elements',
        'wpmcp/duplicate-element',
        'wpmcp/set-element-label',
        'wpmcp/find-element',
        'wpmcp/update-page-settings',
    ];

    public function test_no_structural_tool_is_registered_on_a_free_site(): void
    {
        $names = array_keys(wp_get_abilities());

        foreach (self::PRO_STRUCTURAL_TOOLS as $name) {
            $this->assertNotContains(
                $name,
                $names,
                "{$name} is pro-tier and must not be registered on a free-tier site"
            );
        }
    }
}
