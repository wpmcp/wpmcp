<?php

namespace WPMCP\Tests\Free\ThemeBuilder;

use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Tools\ThemeBuilder\Condition_Schema;
use WPMCP\Tools\ThemeBuilder\Template_Store;
use WPMCP\Tools\ThemeBuilder\Update_Site_Part;

/**
 * wpmcp/update-site-part (issue #70): edit a site part's title, content,
 * conditions and priority, snapshot-first so the returned operation_id puts
 * every one of those back. Plus the free side of the granular-condition
 * split: the term and user_role rule types are refused without a licence.
 */
class UpdateSitePartTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(false);
        Template_Store::ensure_post_type();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function create(): int
    {
        $id = Template_Store::create(
            'header',
            'Original',
            '<!-- wp:paragraph --><p>Original copy</p><!-- /wp:paragraph -->',
            ['include' => [['type' => 'entire_site']]],
            5
        );
        $this->assertIsInt($id);

        return $id;
    }

    public function test_update_changes_every_editable_field(): void
    {
        $id  = $this->create();
        $out = (new Update_Site_Part())->handle([
            'template_id' => $id,
            'title'       => 'Renamed',
            'content'     => '<p>New copy</p>',
            'conditions'  => ['include' => [['type' => 'front_page']]],
            'priority'    => 9,
        ]);

        $this->assertIsArray($out);
        $this->assertNotEmpty($out['operation_id']);
        $stored = Template_Store::get($id);
        $this->assertSame('Renamed', $stored['title']);
        $this->assertStringContainsString('New copy', $stored['content']);
        $this->assertSame([['type' => 'front_page']], $stored['conditions']['include']);
        $this->assertSame(9, $stored['priority']);
        $this->assertSame('header', $stored['part_type']);
    }

    public function test_omitted_fields_are_left_untouched(): void
    {
        $id = $this->create();
        (new Update_Site_Part())->handle(['template_id' => $id, 'priority' => 1]);

        $stored = Template_Store::get($id);
        $this->assertSame('Original', $stored['title']);
        $this->assertStringContainsString('Original copy', $stored['content']);
        $this->assertSame([['type' => 'entire_site']], $stored['conditions']['include']);
        $this->assertSame(1, $stored['priority']);
    }

    public function test_update_is_snapshot_first_and_rolls_back_content_conditions_and_priority(): void
    {
        $id  = $this->create();
        $out = (new Update_Site_Part())->handle([
            'template_id' => $id,
            'content'     => '<p>New copy</p>',
            'conditions'  => ['include' => [['type' => 'search']]],
            'priority'    => 99,
        ]);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));

        $stored = Template_Store::get($id);
        $this->assertStringContainsString('Original copy', $stored['content']);
        $this->assertSame([['type' => 'entire_site']], $stored['conditions']['include']);
        $this->assertSame(5, $stored['priority']);
    }

    public function test_invalid_conditions_are_refused_before_any_write(): void
    {
        $id  = $this->create();
        $err = (new Update_Site_Part())->handle([
            'template_id' => $id,
            'title'       => 'Should not land',
            'conditions'  => ['include' => [['type' => 'post_type']]],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $err);
        $this->assertSame('Original', Template_Store::get($id)['title']);
    }

    public function test_content_is_filtered_on_update(): void
    {
        $id = $this->create();
        (new Update_Site_Part())->handle([
            'template_id' => $id,
            'content'     => '<p onclick="alert(1)">Hi</p><script>alert(2)</script>',
        ]);

        $content = Template_Store::get($id)['content'];
        $this->assertStringNotContainsString('<script', $content);
        $this->assertStringNotContainsString('onclick', $content);
    }

    public function test_part_type_cannot_be_changed_by_an_update(): void
    {
        // Moving a template to another part type would sidestep the
        // per-part-type cap; delete and recreate instead.
        $id  = $this->create();
        $err = (new Update_Site_Part())->handle(['template_id' => $id, 'part_type' => 'footer']);

        $this->assertInstanceOf(\WP_Error::class, $err);
        $this->assertSame('header', Template_Store::get($id)['part_type']);
    }

    public function test_an_update_with_nothing_to_change_is_refused(): void
    {
        $id = $this->create();

        $this->assertInstanceOf(\WP_Error::class, (new Update_Site_Part())->handle(['template_id' => $id]));
    }

    public function test_wrongly_typed_fields_are_refused_before_any_write(): void
    {
        $id = $this->create();

        $this->assertInstanceOf(\WP_Error::class, (new Update_Site_Part())->handle(['template_id' => $id, 'title' => ['x']]));
        $this->assertInstanceOf(\WP_Error::class, (new Update_Site_Part())->handle(['template_id' => $id, 'priority' => 'high']));
        $this->assertSame('Original', Template_Store::get($id)['title']);
        $this->assertSame(5, Template_Store::get($id)['priority']);
    }

    public function test_update_rejects_an_id_that_is_not_a_template(): void
    {
        $post = self::factory()->post->create();

        $this->assertInstanceOf(
            \WP_Error::class,
            (new Update_Site_Part())->handle(['template_id' => $post, 'title' => 'x'])
        );
    }

    // ---- granular conditions, free side --------------------------------------

    public function test_granular_rule_types_are_refused_without_a_licence(): void
    {
        foreach (
            [
                ['type' => 'term', 'value' => 1],
                ['type' => 'user_role', 'value' => 'editor'],
            ] as $rule
        ) {
            $err = Condition_Schema::validate(['include' => [$rule]]);
            $this->assertInstanceOf(\WP_Error::class, $err, $rule['type']);
            $this->assertSame('wpmcp_granular_condition', $err->get_error_code(), $rule['type']);
        }
    }

    public function test_granular_rule_in_an_exclude_is_refused_too(): void
    {
        $err = Condition_Schema::validate([
            'include' => [['type' => 'entire_site']],
            'exclude' => [['type' => 'user_role', 'value' => 'administrator']],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $err);
    }

    public function test_create_refuses_a_granular_rule_without_a_licence(): void
    {
        $err = Template_Store::create('footer', 'x', '', ['include' => [['type' => 'term', 'value' => 3]]], 0);

        $this->assertInstanceOf(\WP_Error::class, $err);
        $this->assertCount(0, Template_Store::all(false, 'footer'));
    }
}
