<?php

namespace WPMCP\Tests\Free\Context;

class GetPageSnapshotAbilityRegistrationTest extends \WP_UnitTestCase
{
    private const NAME = 'wpmcp/get-page-snapshot';

    public function test_get_page_snapshot_is_registered_as_a_free_ability(): void
    {
        $this->assertContains(self::NAME, array_keys(wp_get_abilities()));
    }

    public function test_get_page_snapshot_ability_has_description_and_category(): void
    {
        $ability = wp_get_abilities()[ self::NAME ];

        $this->assertNotEmpty($ability->get_description());
        $this->assertSame('wpmcp', $ability->get_category());
    }

    public function test_get_page_snapshot_denies_a_visitor_and_allows_a_contributor(): void
    {
        $ability = wp_get_abilities()[ self::NAME ];

        wp_set_current_user(self::factory()->user->create(['role' => 'contributor']));
        $this->assertTrue($ability->check_permissions());

        wp_set_current_user(0);
        $this->assertFalse($ability->check_permissions());
    }
}
