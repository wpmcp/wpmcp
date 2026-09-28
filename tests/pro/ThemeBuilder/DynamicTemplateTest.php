<?php

namespace WPMCP\Tests\Pro\ThemeBuilder;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\ThemeBuilder\Dynamic\Dynamic_Templates;
use WPMCP\Tools\ThemeBuilder\Render\Block_Adapter;
use WPMCP\Tools\ThemeBuilder\Render\Classic_Adapter;
use WPMCP\Tools\ThemeBuilder\Render\Template_Renderer;
use WPMCP\Tools\ThemeBuilder\Template_Store;

/**
 * Single, archive and search templates with dynamic bindings (issue #290),
 * built on the site parts subsystem (#70): same wpmcp_template store, same
 * condition schema and resolver, same render adapters. Written through the
 * create-dynamic-template / update-dynamic-template ops on theme-write;
 * updates are snapshot-first and roll back exactly.
 */
class DynamicTemplateTest extends \WP_UnitTestCase
{
    /** @var mixed the block canvas content before a test composed one */
    private $canvas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->canvas = $GLOBALS['_wp_current_template_content'] ?? null;
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        Template_Store::ensure_post_type();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        Template_Renderer::set_current_part('');
        $GLOBALS['_wp_current_template_content'] = $this->canvas;
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function write(string $op, array $args): array
    {
        return (new Theme_Integration())->handle_write(['operation' => $op, 'args' => $args]);
    }

    private function read(string $op, array $args): array
    {
        return (new Theme_Integration())->handle_read(['operation' => $op, 'args' => $args]);
    }

    private function create(string $context, string $content, array $conditions, int $priority = 0): int
    {
        $out = $this->write('create-dynamic-template', [
            'context'    => $context,
            'title'      => ucfirst($context) . ' 290',
            'content'    => $content,
            'conditions' => $conditions,
            'priority'   => $priority,
        ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));

        return (int) $out['result']['template_id'];
    }

    // ---- create -------------------------------------------------------------

    public function test_create_stores_a_single_template_in_the_site_part_store(): void
    {
        $id = $this->create(
            'single',
            '<h1>{{post.title}}</h1><a href="{{post.permalink}}">x</a><script>alert(1)</script>',
            ['include' => [['type' => 'post_type', 'value' => 'post']]]
        );

        $stored = Template_Store::get($id);
        $this->assertSame('single', $stored['part_type']);
        $this->assertSame('publish', $stored['status']);
        $this->assertStringContainsString('{{post.title}}', $stored['content']);
        $this->assertStringContainsString('href="{{post.permalink}}"', $stored['content']);
        $this->assertStringNotContainsString('<script', $stored['content']);
    }

    public function test_create_refuses_an_unknown_context_an_unknown_token_and_a_rule_that_cannot_match(): void
    {
        $cases = [
            'context' => ['context' => 'header', 'content' => '<p>x</p>', 'conditions' => ['include' => [['type' => 'entire_site']]]],
            'token'   => ['context' => 'single', 'content' => '{{post.nope}}', 'conditions' => ['include' => [['type' => 'entire_site']]]],
            'rule'    => ['context' => 'single', 'content' => '<p>x</p>', 'conditions' => ['include' => [['type' => 'search']]]],
            'invalid' => ['context' => 'archive', 'content' => '<p>x</p>', 'conditions' => ['include' => [['type' => 'post_type']]]],
        ];
        foreach ($cases as $label => $args) {
            $out = $this->write('create-dynamic-template', $args + ['title' => 'x']);
            $this->assertArrayHasKey('error', $out, $label);
        }
        $this->assertSame([], Template_Store::all());
    }

    public function test_create_ops_are_not_offered_without_a_licence(): void
    {
        Gate::set_pro_for_tests(false);

        $out = $this->write('create-dynamic-template', [
            'context'    => 'single',
            'title'      => 'x',
            'content'    => '<p>x</p>',
            'conditions' => ['include' => [['type' => 'entire_site']]],
        ]);

        $this->assertSame('unknown_operation', $out['error']['code']);
    }

    // ---- update and rollback ------------------------------------------------

    public function test_update_is_snapshot_first_and_rolls_back_exactly(): void
    {
        $id     = $this->create('single', '<h1>{{post.title}}</h1>', ['include' => [['type' => 'post_type', 'value' => 'post']]], 3);
        $before = Template_Store::get($id);

        $out = $this->write('update-dynamic-template', [
            'template_id' => $id,
            'title'       => 'Renamed 290',
            'content'     => '<h2>{{post.excerpt}}</h2>',
            'conditions'  => ['include' => [['type' => 'singular']], 'exclude' => [['type' => 'front_page']]],
            'priority'    => 9,
        ]);

        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        $after = Template_Store::get($id);
        $this->assertSame('Renamed 290', $after['title']);
        $this->assertStringContainsString('{{post.excerpt}}', $after['content']);
        $this->assertSame(9, $after['priority']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before, Template_Store::get($id));
    }

    public function test_update_refuses_before_any_snapshot(): void
    {
        $id     = $this->create('single', '<h1>{{post.title}}</h1>', ['include' => [['type' => 'entire_site']]]);
        $header = Template_Store::create('header', 'Header', '<p>h</p>', ['include' => [['type' => 'entire_site']]], 0);
        $count  = count(Snapshot_Store::list_by_session('default'));

        $bad_token = $this->write('update-dynamic-template', ['template_id' => $id, 'content' => '{{loop.pagination}}']);
        $bad_rule  = $this->write('update-dynamic-template', ['template_id' => $id, 'conditions' => ['include' => [['type' => 'archive']]]]);
        $not_ours  = $this->write('update-dynamic-template', ['template_id' => $header, 'title' => 'x']);
        $nothing   = $this->write('update-dynamic-template', ['template_id' => $id]);

        foreach ([$bad_token, $bad_rule, $not_ours, $nothing] as $out) {
            $this->assertArrayHasKey('error', $out);
        }
        $this->assertSame($count, count(Snapshot_Store::list_by_session('default')));
        $this->assertStringContainsString('{{post.title}}', Template_Store::get($id)['content']);
    }

    // ---- front end ------------------------------------------------------------

    public function test_a_single_template_renders_bound_fields_on_the_front_end(): void
    {
        $this->create(
            'single',
            '<!-- wp:heading --><h2 class="wp-block-heading">{{post.title}}</h2><!-- /wp:heading --><a href="{{post.permalink}}">Read</a>',
            ['include' => [['type' => 'post_type', 'value' => 'post']]]
        );
        $post = self::factory()->post->create_and_get(['post_title' => 'Bound <i>Title</i> 290']);
        $this->go_to(get_permalink($post));

        $this->assertSame('single', Dynamic_Templates::request_context());

        // Classic themes: the document swap, then the body the document prints.
        $template = (new Classic_Adapter())->compose_document('single', 'theme-single.php');
        $this->assertSame(Template_Renderer::document_template(), $template);
        $body = Template_Renderer::render_current();
        $this->assertStringContainsString(esc_html(get_the_title($post)), $body);
        $this->assertStringNotContainsString('<i>Title</i>', $body);
        $this->assertStringContainsString('href="' . esc_url(get_permalink($post)) . '"', $body);

        // Block themes: the canvas keeps the theme's header and footer parts
        // and renders the template body in place of the theme's content.
        global $_wp_current_template_content;
        $previous = $_wp_current_template_content;
        $canvas   = (new Block_Adapter())->compose_document('single', ABSPATH . WPINC . '/template-canvas.php');
        $this->assertSame(ABSPATH . WPINC . '/template-canvas.php', $canvas);
        $this->assertStringContainsString('"slug":"header"', (string) $_wp_current_template_content);
        $this->assertStringContainsString('"slug":"footer"', (string) $_wp_current_template_content);
        // The body sits inside the theme-layout group that frames it.
        $body = '';
        foreach (parse_blocks((string) $_wp_current_template_content) as $block) {
            if ('core/group' === $block['blockName']) {
                $this->assertSame('wpmcp/site-part', $block['innerBlocks'][0]['blockName'] ?? null);
                $body = render_block($block);
            }
        }
        $_wp_current_template_content = $previous;
        $this->assertStringContainsString(esc_html(get_the_title($post)), $body);
        $this->assertStringContainsString('href="' . esc_url(get_permalink($post)) . '"', $body);
    }

    public function test_template_include_swaps_in_the_template_only_where_it_wins(): void
    {
        $this->create('single', '<p>{{post.title}}</p>', ['include' => [['type' => 'post_type', 'value' => 'page']]]);
        $post = self::factory()->post->create();
        $page = self::factory()->post->create(['post_type' => 'page']);

        $this->go_to(get_permalink($post));
        $this->assertSame('theme.php', Dynamic_Templates::template_include('theme.php'));

        $this->go_to(get_permalink($page));
        $this->assertNotSame('theme.php', Dynamic_Templates::template_include('theme.php'));
    }

    public function test_display_conditions_exclude_and_priority_pick_the_winner(): void
    {
        $post_a = self::factory()->post->create();
        $post_b = self::factory()->post->create();
        $generic  = $this->create('single', '<p>generic</p>', ['include' => [['type' => 'post_type', 'value' => 'post']], 'exclude' => [['type' => 'singular', 'value' => $post_b]]]);
        $specific = $this->create('single', '<p>specific</p>', ['include' => [['type' => 'singular', 'value' => $post_a]]]);

        $this->go_to(get_permalink($post_a));
        $this->assertSame($specific, Template_Renderer::winner('single')['template_id']);

        $this->go_to(get_permalink($post_b));
        $this->assertNull(Template_Renderer::winner('single'));

        $post_c = self::factory()->post->create();
        $this->go_to(get_permalink($post_c));
        $this->assertSame($generic, Template_Renderer::winner('single')['template_id']);
    }

    public function test_an_archive_template_loops_over_the_main_query(): void
    {
        $category = self::factory()->category->create(['name' => 'Archive 290']);
        self::factory()->post->create(['post_title' => 'First 290', 'post_category' => [$category]]);
        self::factory()->post->create(['post_title' => 'Second 290', 'post_category' => [$category]]);
        $this->create('archive', '<h1>{{archive.title}}</h1><ul>{{#loop}}<li>{{post.title}}</li>{{/loop}}</ul>', ['include' => [['type' => 'archive']]]);

        $this->go_to(get_category_link($category));
        $this->assertSame('archive', Dynamic_Templates::request_context());

        $body = Template_Renderer::render('archive');
        $this->assertStringContainsString('Archive 290', $body);
        $this->assertStringContainsString('<li>First 290</li>', $body);
        $this->assertStringContainsString('<li>Second 290</li>', $body);
    }

    public function test_a_search_template_prints_the_escaped_query(): void
    {
        self::factory()->post->create(['post_title' => 'Needle 290']);
        $this->create('search', '<h1>{{search.query}}</h1>{{#loop}}<p>{{post.title}}</p>{{/loop}}', ['include' => [['type' => 'search']]]);

        $this->go_to(home_url('/?s=' . rawurlencode('Needle <b>')));
        $this->assertSame('search', Dynamic_Templates::request_context());

        $body = Template_Renderer::render('search');
        $this->assertStringNotContainsString('<b>', $body);
        $this->assertStringContainsString('Needle', $body);
    }

    public function test_a_single_template_never_renders_on_an_archive_or_a_404(): void
    {
        $this->create('single', '<p>single only</p>', ['include' => [['type' => 'entire_site']]]);

        $this->go_to(home_url('/definitely-not-a-real-url-290/'));
        $GLOBALS['wp_query']->set_404();
        $this->assertNull(Dynamic_Templates::request_context());
        $this->assertSame('theme.php', Dynamic_Templates::template_include('theme.php'));

        $category = self::factory()->category->create();
        self::factory()->post->create(['post_category' => [$category]]);
        $this->go_to(get_category_link($category));
        $this->assertSame('theme.php', Dynamic_Templates::template_include('theme.php'));
    }

    public function test_preview_renders_a_template_against_a_chosen_post(): void
    {
        $id   = $this->create('single', '<h1>{{post.title}}</h1>', ['include' => [['type' => 'entire_site']]]);
        $post = self::factory()->post->create(['post_title' => 'Preview 290']);

        $out = $this->read('preview-dynamic-template', ['template_id' => $id, 'post_id' => $post]);

        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertStringContainsString('<h1>Preview 290</h1>', $out['result']['html']);
    }

    public function test_preview_refuses_an_unknown_template_or_post_and_loops_for_an_archive(): void
    {
        $header = Template_Store::create('header', 'Header', '<p>h</p>', ['include' => [['type' => 'entire_site']]], 0);
        $this->assertSame('wpmcp_template_not_found', $this->read('preview-dynamic-template', ['template_id' => $header])['error']['code']);

        $id = $this->create('archive', '{{#loop}}<b>{{post.title}}</b>{{/loop}}', ['include' => [['type' => 'archive']]]);
        $this->assertSame('wpmcp_post_not_found', $this->read('preview-dynamic-template', ['template_id' => $id, 'post_id' => 999999])['error']['code']);

        self::factory()->post->create(['post_title' => 'Looped 290']);
        $out = $this->read('preview-dynamic-template', ['template_id' => $id]);
        $this->assertStringContainsString('<b>Looped 290</b>', $out['result']['html']);
    }

    public function test_sources_op_refuses_nothing_and_lists_per_context(): void
    {
        $out = $this->read('list-dynamic-sources', ['context' => 'search']);

        $this->assertContains('search.query', array_column($out['result']['sources'], 'key'));
        $this->assertSame('invalid_args', $this->read('list-dynamic-sources', ['context' => 'header'])['error']['code']);
    }

    public function test_boot_hooks_template_include_on_the_front_end(): void
    {
        Dynamic_Templates::boot();

        $this->assertNotFalse(has_filter('template_include', [Dynamic_Templates::class, 'template_include']));
        remove_filter('template_include', [Dynamic_Templates::class, 'template_include'], Dynamic_Templates::PRIORITY);
    }

    public function test_the_runtime_hooks_are_wired_from_the_plugin(): void
    {
        \WPMCP\Plugin::instance()->register_dynamic_template_runtime_hooks();

        $this->assertNotFalse(has_action('wp', ['\\WPMCP\\Tools\\ThemeBuilder\\Dynamic\\Dynamic_Templates', 'boot']));
        $this->assertNotFalse(has_filter('wpmcp_site_part_rendered', ['\\WPMCP\\Tools\\ThemeBuilder\\Dynamic\\Binding_Resolver', 'filter_rendered']));
    }
}
