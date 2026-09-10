<?php

namespace WPMCP\Tests\Free\ThemeBuilder;

use WPMCP\Tools\ThemeBuilder\Condition_Schema;
use WPMCP\Tools\ThemeBuilder\Create_Site_Part;
use WPMCP\Tools\ThemeBuilder\Delete_Site_Part;
use WPMCP\Tools\ThemeBuilder\List_Site_Parts;
use WPMCP\Tools\ThemeBuilder\Resolve_Site_Part;
use WPMCP\Tools\ThemeBuilder\Set_Site_Part_Status;
use WPMCP\Tools\ThemeBuilder\Template_Resolver;
use WPMCP\Tools\ThemeBuilder\Template_Store;
use WPMCP\Tools\ThemeBuilder\Render\Adapters;
use WPMCP\Tools\ThemeBuilder\Render\Block_Adapter;
use WPMCP\Tools\ThemeBuilder\Render\Classic_Adapter;
use WPMCP\Tools\ThemeBuilder\Render\Template_Renderer;
use WPMCP\Tools\Content\Content_Guard;

/**
 * The theme-builder site-part engine (issue #70): condition validation,
 * deterministic winner resolution (specificity > priority > id), the free cap,
 * and the render adapters' document swap. The free tier is the engine plus a
 * cap of one template per part type, so everything here runs uncapped only
 * where the test says so; the pro (uncapped) path lives in tests/pro.
 */
class SitePartEngineTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Template_Store::ensure_post_type();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    /** Store a template directly, bypassing the cap, so resolver cases can have several. */
    private function seed(string $part_type, array $conditions, int $priority = 0, string $status = 'publish'): int
    {
        $id = self::factory()->post->create([
            'post_type'    => Template_Store::POST_TYPE,
            'post_status'  => $status,
            'post_title'   => $part_type . '-' . wp_generate_password(6, false),
            'post_content' => '',
        ]);
        update_post_meta($id, '_wpmcp_template_type', $part_type);
        update_post_meta($id, '_wpmcp_template_conditions', $conditions);
        update_post_meta($id, '_wpmcp_template_priority', $priority);

        return (int) $id;
    }

    // ---- condition schema ---------------------------------------------------

    public function test_condition_set_needs_at_least_one_include_rule(): void
    {
        $this->assertInstanceOf(\WP_Error::class, Condition_Schema::validate([]));
        $this->assertInstanceOf(\WP_Error::class, Condition_Schema::validate(['include' => []]));
        $this->assertTrue(Condition_Schema::validate(['include' => [['type' => 'entire_site']]]));
    }

    public function test_post_type_rule_without_a_value_is_rejected(): void
    {
        $err = Condition_Schema::validate(['include' => [['type' => 'post_type']]]);
        $this->assertInstanceOf(\WP_Error::class, $err);
        $this->assertSame('wpmcp_invalid_conditions', $err->get_error_code());
    }

    public function test_singular_rule_with_a_non_numeric_value_is_rejected(): void
    {
        $this->assertInstanceOf(
            \WP_Error::class,
            Condition_Schema::validate(['include' => [['type' => 'singular', 'value' => 'about']]])
        );
        // No value at all means "any singular view", which is legitimate.
        $this->assertTrue(Condition_Schema::validate(['include' => [['type' => 'singular']]]));
    }

    public function test_valueless_rule_carrying_a_value_is_rejected(): void
    {
        $this->assertInstanceOf(
            \WP_Error::class,
            Condition_Schema::validate(['include' => [['type' => 'front_page', 'value' => 'home']]])
        );
    }

    public function test_unknown_rule_key_is_rejected(): void
    {
        $this->assertInstanceOf(
            \WP_Error::class,
            Condition_Schema::validate(['include' => [['type' => 'entire_site', 'valeu' => 1]]])
        );
    }

    public function test_malformed_rules_read_back_from_meta_are_skipped_not_fatal(): void
    {
        // A direct meta write (or a future update tool) can put anything here.
        $conditions = ['include' => ['not-an-array', ['type' => 'entire_site']], 'exclude' => 'nope'];

        $this->assertTrue(Condition_Schema::matches($conditions, []));
        $this->assertSame(0, Condition_Schema::specificity($conditions, []));
    }

    public function test_exclude_rule_beats_a_matching_include(): void
    {
        $conditions = [
            'include' => [['type' => 'entire_site']],
            'exclude' => [['type' => 'error_404']],
        ];

        $this->assertTrue(Condition_Schema::matches($conditions, ['is_404' => false]));
        $this->assertFalse(Condition_Schema::matches($conditions, ['is_404' => true]));
    }

    // ---- resolver ordering --------------------------------------------------

    public function test_more_specific_include_rule_wins(): void
    {
        $site  = $this->seed('header', ['include' => [['type' => 'entire_site']]], 99);
        $front = $this->seed('header', ['include' => [['type' => 'front_page']]], 0);

        $out = Template_Resolver::resolve('header', ['is_front_page' => true]);

        $this->assertSame($front, $out['winner']['template_id']);
        $this->assertCount(2, $out['considered']);
        $this->assertNotSame($site, $out['winner']['template_id']);
    }

    public function test_equal_specificity_falls_through_to_priority(): void
    {
        $low  = $this->seed('footer', ['include' => [['type' => 'entire_site']]], 1);
        $high = $this->seed('footer', ['include' => [['type' => 'entire_site']]], 5);

        $out = Template_Resolver::resolve('footer', []);

        $this->assertSame($high, $out['winner']['template_id']);
        $this->assertNotSame($low, $out['winner']['template_id']);
    }

    public function test_equal_specificity_and_priority_fall_through_to_lowest_id(): void
    {
        $first  = $this->seed('footer', ['include' => [['type' => 'entire_site']]], 3);
        $second = $this->seed('footer', ['include' => [['type' => 'entire_site']]], 3);

        $this->assertLessThan($second, $first);
        $this->assertSame($first, Template_Resolver::resolve('footer', [])['winner']['template_id']);
    }

    public function test_draft_templates_are_not_considered(): void
    {
        $this->seed('header', ['include' => [['type' => 'entire_site']]], 0, 'draft');

        $out = Template_Resolver::resolve('header', []);

        $this->assertNull($out['winner']);
        $this->assertSame([], $out['considered']);
    }

    public function test_non_matching_templates_are_reported_but_never_win(): void
    {
        $this->seed('header', ['include' => [['type' => 'front_page']]]);

        $out = Template_Resolver::resolve('header', ['is_front_page' => false]);

        $this->assertNull($out['winner']);
        $this->assertCount(1, $out['considered']);
        $this->assertFalse($out['considered'][0]['matches']);
        $this->assertNull($out['considered'][0]['specificity']);
    }

    public function test_resolution_is_scoped_to_the_part_type(): void
    {
        $header = $this->seed('header', ['include' => [['type' => 'entire_site']]]);
        $this->seed('footer', ['include' => [['type' => 'entire_site']]]);

        $this->assertSame($header, Template_Resolver::resolve('header', [])['winner']['template_id']);
        $this->assertCount(1, Template_Resolver::resolve('header', [])['considered']);
    }

    // ---- store: cap, part types, sanitization -------------------------------

    public function test_free_cap_allows_one_template_per_part_type(): void
    {
        $first = Template_Store::create('header', 'One', '', ['include' => [['type' => 'entire_site']]], 0);
        $this->assertIsInt($first);

        $second = Template_Store::create('header', 'Two', '', ['include' => [['type' => 'entire_site']]], 0);
        $this->assertInstanceOf(\WP_Error::class, $second);
        $this->assertSame('wpmcp_template_cap', $second->get_error_code());

        // The cap is per part type, not global.
        $this->assertIsInt(Template_Store::create('footer', 'Foot', '', ['include' => [['type' => 'entire_site']]], 0));
    }

    public function test_trashing_a_template_frees_the_capped_slot(): void
    {
        $first = Template_Store::create('header', 'One', '', ['include' => [['type' => 'entire_site']]], 0);
        (new Delete_Site_Part())->handle(['template_id' => $first]);

        $this->assertIsInt(Template_Store::create('header', 'Two', '', ['include' => [['type' => 'entire_site']]], 0));
    }

    public function test_unknown_part_type_is_rejected_on_create(): void
    {
        $err = Template_Store::create('head', 'Nope', '', ['include' => [['type' => 'entire_site']]], 0);
        $this->assertInstanceOf(\WP_Error::class, $err);
        $this->assertSame('wpmcp_invalid_part_type', $err->get_error_code());
    }

    public function test_content_is_filtered_on_store_but_block_markup_survives(): void
    {
        $markup = "<!-- wp:paragraph -->\n<p>Hello</p>\n<!-- /wp:paragraph -->"
            . '<script>alert(1)</script><a href="#" onclick="alert(2)">x</a>';

        $id      = Template_Store::create('footer', 'Foot', $markup, ['include' => [['type' => 'entire_site']]], 0);
        $content = Template_Store::get($id)['content'];

        $this->assertStringNotContainsString('<script', $content);
        $this->assertStringNotContainsString('onclick', $content);
        $this->assertStringContainsString('wp:paragraph', $content);
        $this->assertStringContainsString('<p>Hello</p>', $content);
    }

    public function test_the_template_cpt_is_closed_to_the_generic_content_tools(): void
    {
        // An edit_posts caller must not be able to rewrite markup that renders
        // on every page through wpmcp/update-post.
        $this->assertFalse(Content_Guard::is_writable_post_type(Template_Store::POST_TYPE));
    }

    // ---- tools --------------------------------------------------------------

    public function test_create_tool_returns_the_stored_template(): void
    {
        $out = (new Create_Site_Part())->handle([
            'part_type'  => 'header',
            'title'      => 'Main header',
            'content'    => '<p>hi</p>',
            'conditions' => ['include' => [['type' => 'entire_site']]],
            'priority'   => 4,
        ]);

        $this->assertSame('header', $out['part_type']);
        $this->assertSame('Main header', $out['title']);
        $this->assertSame(4, $out['priority']);
        $this->assertSame('publish', $out['status']);
    }

    public function test_read_tools_reject_an_unknown_part_type(): void
    {
        $resolved = (new Resolve_Site_Part())->handle(['part_type' => 'head']);
        $this->assertInstanceOf(\WP_Error::class, $resolved);
        $this->assertSame('wpmcp_invalid_part_type', $resolved->get_error_code());

        $listed = (new List_Site_Parts())->handle(['part_type' => 'head']);
        $this->assertInstanceOf(\WP_Error::class, $listed);
    }

    public function test_empty_part_type_filter_lists_everything(): void
    {
        $this->seed('header', ['include' => [['type' => 'entire_site']]]);
        $this->seed('footer', ['include' => [['type' => 'entire_site']]]);

        $this->assertCount(2, (new List_Site_Parts())->handle(['part_type' => ''])['templates']);
        $this->assertCount(2, (new List_Site_Parts())->handle([])['templates']);
    }

    public function test_set_status_is_snapshot_first_and_hides_the_template(): void
    {
        $id  = $this->seed('header', ['include' => [['type' => 'entire_site']]]);
        $out = (new Set_Site_Part_Status())->handle(['template_id' => $id, 'status' => 'draft']);

        $this->assertNotEmpty($out['operation_id']);
        $this->assertSame('draft', $out['status']);
        $this->assertNull(Template_Resolver::resolve('header', [])['winner']);
    }

    public function test_delete_is_snapshot_first_and_reversible(): void
    {
        $id  = $this->seed('header', ['include' => [['type' => 'entire_site']]]);
        $out = (new Delete_Site_Part())->handle(['template_id' => $id]);

        $this->assertNotEmpty($out['operation_id']);
        $this->assertSame('trashed', $out['deleted']);
        $this->assertSame('trash', get_post_status($id));
    }

    public function test_write_tools_reject_an_id_that_is_not_a_template(): void
    {
        $post = self::factory()->post->create();

        $this->assertInstanceOf(\WP_Error::class, (new Delete_Site_Part())->handle(['template_id' => $post]));
        $this->assertInstanceOf(\WP_Error::class, (new Set_Site_Part_Status())->handle(['template_id' => 0]));
    }

    // ---- render adapters ----------------------------------------------------

    public function test_exactly_one_adapter_supports_the_active_theme(): void
    {
        $supporting = array_filter(Adapters::all(), fn ($adapter) => $adapter->supports());

        $this->assertCount(1, $supporting);
        $this->assertInstanceOf(
            function_exists('wp_is_block_theme') && wp_is_block_theme() ? Block_Adapter::class : Classic_Adapter::class,
            Adapters::active()
        );
    }

    public function test_adapter_swaps_the_document_for_a_matching_404_part(): void
    {
        $this->seed('404', ['include' => [['type' => 'error_404']]]);
        $this->go_to(home_url('/definitely-not-a-real-url-70/'));
        $GLOBALS['wp_query']->set_404();
        $this->assertTrue(is_404());

        $adapter = Adapters::active();
        $adapter->register('404');

        $swapped = apply_filters('template_include', '/theme/404.php');
        $this->assertSame(Template_Renderer::document_template(), $swapped);
        $this->assertFileExists($swapped);
    }

    public function test_adapter_leaves_the_theme_alone_when_no_404_part_wins(): void
    {
        $this->go_to(home_url('/definitely-not-a-real-url-70/'));
        $GLOBALS['wp_query']->set_404();
        $adapter = Adapters::active();
        $adapter->register('404');

        $this->assertSame('/theme/404.php', apply_filters('template_include', '/theme/404.php'));
    }

    public function test_header_and_footer_parts_register_no_document_swap(): void
    {
        // The document swap replaces the whole page, which is only correct for
        // an error page; header/footer wait for the composition slice.
        $adapter = Adapters::active();
        $adapter->register('header');
        $adapter->register('footer');

        $this->assertFalse(has_filter('template_include', [$adapter, 'swap_document']));
    }

    public function test_context_from_query_mirrors_the_resolve_tool_argument(): void
    {
        $post = self::factory()->post->create(['post_type' => 'post']);
        $this->go_to(get_permalink($post));

        $context = Template_Renderer::context_from_query();

        $this->assertTrue($context['is_singular']);
        $this->assertFalse($context['is_404']);
        $this->assertSame('post', $context['post_type']);
        $this->assertSame($post, $context['post_id']);
    }

    public function test_renderer_returns_the_winning_markup_and_nothing_otherwise(): void
    {
        $id = Template_Store::create(
            'footer',
            'Foot',
            "<!-- wp:paragraph -->\n<p>Footer copy</p>\n<!-- /wp:paragraph -->",
            ['include' => [['type' => 'entire_site']]],
            0
        );
        $this->assertIsInt($id);

        $this->assertStringContainsString('Footer copy', Template_Renderer::render('footer', []));
        $this->assertSame('', Template_Renderer::render('header', []));
    }
}
