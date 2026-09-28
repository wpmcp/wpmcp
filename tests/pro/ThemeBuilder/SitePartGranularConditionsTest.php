<?php

namespace WPMCP\Tests\Pro\ThemeBuilder;

use WPMCP\Pro\Gate;
use WPMCP\Tools\ThemeBuilder\Condition_Schema;
use WPMCP\Tools\ThemeBuilder\Render\Template_Renderer;
use WPMCP\Tools\ThemeBuilder\Resolve_Site_Part;
use WPMCP\Tools\ThemeBuilder\Template_Resolver;
use WPMCP\Tools\ThemeBuilder\Template_Store;

/**
 * Granular display conditions (issue #70 tier split): the term and user_role
 * rule types a licence unlocks. Validation, matching, where they sit in the
 * specificity order, and how both the resolve tool and the live renderer
 * derive the term and role context they match against.
 */
class SitePartGranularConditionsTest extends \WP_UnitTestCase
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

    public function test_granular_rules_validate_on_a_licensed_site(): void
    {
        $this->assertTrue(Condition_Schema::validate(['include' => [['type' => 'term', 'value' => 7]]]));
        $this->assertTrue(Condition_Schema::validate(['include' => [['type' => 'user_role', 'value' => 'editor']]]));
    }

    public function test_granular_rules_still_need_a_well_formed_value(): void
    {
        foreach (
            [
                ['type' => 'term'],
                ['type' => 'term', 'value' => 'news'],
                ['type' => 'term', 'value' => 0],
                ['type' => 'user_role'],
                ['type' => 'user_role', 'value' => ''],
                ['type' => 'user_role', 'value' => 3],
            ] as $rule
        ) {
            $this->assertInstanceOf(
                \WP_Error::class,
                Condition_Schema::validate(['include' => [$rule]]),
                wp_json_encode($rule)
            );
        }
    }

    public function test_term_rule_matches_on_term_ids_in_the_context(): void
    {
        $conditions = ['include' => [['type' => 'term', 'value' => 12]]];

        $this->assertTrue(Condition_Schema::matches($conditions, ['term_ids' => [3, 12]]));
        $this->assertFalse(Condition_Schema::matches($conditions, ['term_ids' => [3]]));
        $this->assertFalse(Condition_Schema::matches($conditions, []));
    }

    public function test_user_role_rule_matches_on_roles_in_the_context(): void
    {
        $conditions = ['include' => [['type' => 'user_role', 'value' => 'editor']]];

        $this->assertTrue(Condition_Schema::matches($conditions, ['user_roles' => ['editor']]));
        $this->assertFalse(Condition_Schema::matches($conditions, ['user_roles' => ['subscriber']]));
        $this->assertFalse(Condition_Schema::matches($conditions, []));
    }

    public function test_term_sits_between_post_type_and_singular_in_specificity(): void
    {
        $by_type = Template_Store::create('header', 'Type', '', ['include' => [['type' => 'post_type', 'value' => 'post']]], 90);
        $by_term = Template_Store::create('header', 'Term', '', ['include' => [['type' => 'term', 'value' => 12]]], 0);
        $single  = Template_Store::create('header', 'Single', '', ['include' => [['type' => 'singular', 'value' => 44]]], 0);

        $context = ['is_singular' => true, 'post_type' => 'post', 'post_id' => 44, 'term_ids' => [12]];
        $this->assertSame($single, Template_Resolver::resolve('header', $context)['winner']['template_id']);

        $context['post_id'] = 45;
        $this->assertSame($by_term, Template_Resolver::resolve('header', $context)['winner']['template_id']);

        unset($context['term_ids']);
        $this->assertSame($by_type, Template_Resolver::resolve('header', $context)['winner']['template_id']);
    }

    public function test_user_role_beats_entire_site_but_not_a_location_rule(): void
    {
        $site  = Template_Store::create('footer', 'Site', '', ['include' => [['type' => 'entire_site']]], 90);
        $role  = Template_Store::create('footer', 'Editors', '', ['include' => [['type' => 'user_role', 'value' => 'editor']]], 0);
        $front = Template_Store::create('footer', 'Front', '', ['include' => [['type' => 'front_page']]], 0);

        $this->assertSame($role, Template_Resolver::resolve('footer', ['user_roles' => ['editor']])['winner']['template_id']);
        $this->assertSame($front, Template_Resolver::resolve('footer', ['user_roles' => ['editor'], 'is_front_page' => true])['winner']['template_id']);
        $this->assertSame($site, Template_Resolver::resolve('footer', ['user_roles' => ['subscriber']])['winner']['template_id']);
    }

    public function test_resolve_tool_derives_post_type_and_terms_from_a_post_id(): void
    {
        $cat  = self::factory()->category->create();
        $post = self::factory()->post->create(['post_category' => [$cat]]);
        $id   = Template_Store::create('header', 'Cat', '', ['include' => [['type' => 'term', 'value' => $cat]]], 0);

        $out = (new Resolve_Site_Part())->handle([
            'part_type' => 'header',
            'context'   => ['is_singular' => true, 'post_id' => $post],
        ]);

        $this->assertSame($id, $out['winner']['template_id']);
    }

    public function test_live_context_carries_the_viewers_roles_and_the_posts_terms(): void
    {
        $cat  = self::factory()->category->create();
        $post = self::factory()->post->create(['post_category' => [$cat]]);
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $this->go_to(get_permalink($post));

        $context = Template_Renderer::context_from_query();

        $this->assertContains('editor', $context['user_roles']);
        $this->assertContains($cat, $context['term_ids']);
    }

    public function test_live_context_on_a_term_archive_carries_that_term(): void
    {
        $cat = self::factory()->category->create();
        self::factory()->post->create(['post_category' => [$cat]]);
        $this->go_to(get_category_link($cat));

        $this->assertContains($cat, Template_Renderer::context_from_query()['term_ids']);
    }
}
