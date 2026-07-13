<?php

namespace WPMCP\Tests\Free\Context;

class GetSiteContextAbilityRegistrationTest extends \WP_UnitTestCase
{
    private const NAME = 'wpmcp/get-site-context';

    public function test_get_site_context_is_registered_as_a_free_ability(): void
    {
        $this->assertContains(self::NAME, array_keys(wp_get_abilities()));
    }

    public function test_get_site_context_ability_has_description_and_category(): void
    {
        $ability = wp_get_abilities()[ self::NAME ];

        $this->assertNotEmpty($ability->get_description());
        $this->assertSame('wpmcp', $ability->get_category());
    }

    public function test_get_site_context_denies_a_visitor_and_allows_a_contributor(): void
    {
        $ability = wp_get_abilities()[ self::NAME ];

        $contributor = self::factory()->user->create(['role' => 'contributor']);
        wp_set_current_user($contributor);
        $this->assertTrue($ability->check_permissions(), 'get-site-context must allow a contributor (edit_posts)');

        wp_set_current_user(0);
        $this->assertFalse($ability->check_permissions(), 'get-site-context must deny a logged-out visitor');
    }
}
