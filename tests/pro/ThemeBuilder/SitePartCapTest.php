<?php

namespace WPMCP\Tests\Pro\ThemeBuilder;

use WPMCP\Pro\Gate;
use WPMCP\Tools\ThemeBuilder\Template_Resolver;
use WPMCP\Tools\ThemeBuilder\Template_Store;

/**
 * The tier split of the theme-builder subsystem (issue #70): the engine and
 * its conditions are free, the per-part-type cap is what a licence lifts.
 * The capped side is asserted in tests/free; this is the uncapped side.
 */
class SitePartCapTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        Template_Store::ensure_post_type();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    public function test_cap_is_unlimited_on_a_licensed_site(): void
    {
        $this->assertSame(0, Template_Store::cap_per_type());

        Gate::set_pro_for_tests(false);
        $this->assertSame(Template_Store::FREE_CAP_PER_TYPE, Template_Store::cap_per_type());
    }

    public function test_more_than_one_template_per_part_type_is_allowed(): void
    {
        $ids = [];
        foreach (['One', 'Two', 'Three'] as $title) {
            $id = Template_Store::create('header', $title, '', ['include' => [['type' => 'entire_site']]], 0);
            $this->assertIsInt($id, 'the cap must not bite on a licensed site');
            $ids[] = $id;
        }

        $this->assertCount(3, Template_Store::all(false, 'header'));
        $this->assertSame($ids[0], Template_Resolver::resolve('header', [])['winner']['template_id']);
    }

    public function test_the_resolver_still_picks_one_deterministic_winner_out_of_many(): void
    {
        Template_Store::create('header', 'Site wide', '', ['include' => [['type' => 'entire_site']]], 50);
        $front = Template_Store::create('header', 'Front only', '', ['include' => [['type' => 'front_page']]], 0);
        Template_Store::create('header', 'Singular', '', ['include' => [['type' => 'singular']]], 90);

        $out = Template_Resolver::resolve('header', ['is_front_page' => true, 'is_singular' => false]);

        $this->assertSame($front, $out['winner']['template_id']);
        $this->assertCount(3, $out['considered']);
    }

    public function test_the_cap_error_names_the_tool_that_frees_the_slot(): void
    {
        Gate::set_pro_for_tests(false);
        Template_Store::create('footer', 'One', '', ['include' => [['type' => 'entire_site']]], 0);

        $err = Template_Store::create('footer', 'Two', '', ['include' => [['type' => 'entire_site']]], 0);

        $this->assertInstanceOf(\WP_Error::class, $err);
        $this->assertStringContainsString('wpmcp/delete-site-part', $err->get_error_message());
    }
}
