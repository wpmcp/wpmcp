<?php

namespace WPMCP\Tests\Pro\WidgetBuilder;

use WPMCP\Pro\Gate;
use WPMCP\Tools\WidgetBuilder\Widget_Spec;
use WPMCP\Tools\WidgetBuilder\Widget_Renderer;
use WPMCP\Tools\WidgetBuilder\Create_Custom_Widget;
use WPMCP\Tools\WidgetBuilder\Update_Custom_Widget;
use WPMCP\Tools\WidgetBuilder\Get_Custom_Widget;
use WPMCP\Tools\WidgetBuilder\List_Custom_Widgets;
use WPMCP\Tools\WidgetBuilder\Delete_Custom_Widget;
use WPMCP\Tools\WidgetBuilder\Set_Widget_Status;
use WPMCP\Tools\WidgetBuilder\Validate_Widget_Spec;
use WPMCP\Tools\WidgetBuilder\List_Control_Types;

/**
 * Cluster 7 (EMCP parity): the custom Elementor widget builder, implemented
 * data-driven (no code generation, no eval, keeping wpmcp's single-eval-site
 * safety invariant). A spec is stored as a wpmcp_widget post; a single dynamic
 * Widget_Base renders it at runtime by interpolating control values into the
 * template. These tests cover validation, the pure renderer's per-control
 * escaping, and the spec store CRUD.
 */
class CustomWidgetBuilderTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function valid_spec(): array
    {
        return [
            'name'     => 'promo-box',
            'title'    => 'Promo Box',
            'icon'     => 'eicon-info-box',
            'keywords' => ['promo', 'cta'],
            'controls' => [
                ['name' => 'heading', 'type' => 'text', 'label' => 'Heading', 'default' => 'Hi'],
                ['name' => 'body', 'type' => 'wysiwyg', 'label' => 'Body', 'default' => ''],
                ['name' => 'link', 'type' => 'url', 'label' => 'Link', 'default' => ''],
            ],
            'template' => '<div class="promo"><h3>{{heading}}</h3><div>{{body}}</div><a href="{{link}}">Go</a></div>',
        ];
    }

    private function media_spec(): array
    {
        return [
            'name'     => 'media-box',
            'title'    => 'Media Box',
            'controls' => [
                ['name' => 'photo', 'type' => 'image', 'label' => 'Photo'],
                ['name' => 'glyph', 'type' => 'icon', 'label' => 'Glyph'],
            ],
            'template' => '<figure><img src="{{photo}}" alt="" /><i class="{{glyph}}"></i></figure>',
        ];
    }

    // ---- validate-widget-spec / list-control-types --------------------------

    public function test_valid_spec_passes(): void
    {
        $this->assertTrue(Widget_Spec::validate($this->valid_spec()));

        $out = (new Validate_Widget_Spec())->handle(['spec' => $this->valid_spec()]);
        $this->assertTrue($out['valid']);
    }

    public function test_spec_requires_title(): void
    {
        $spec = $this->valid_spec();
        unset($spec['title']);
        $this->assertInstanceOf(\WP_Error::class, Widget_Spec::validate($spec));
    }

    public function test_spec_rejects_unknown_control_type(): void
    {
        $spec = $this->valid_spec();
        $spec['controls'][0]['type'] = 'bogus';
        $err = Widget_Spec::validate($spec);
        $this->assertInstanceOf(\WP_Error::class, $err);
        $this->assertSame('invalid_control', $err->get_error_code());
    }

    public function test_spec_rejects_duplicate_control_names(): void
    {
        $spec = $this->valid_spec();
        $spec['controls'][1]['name'] = 'heading';
        $this->assertInstanceOf(\WP_Error::class, Widget_Spec::validate($spec));
    }

    public function test_spec_requires_template(): void
    {
        $spec = $this->valid_spec();
        unset($spec['template']);
        $this->assertInstanceOf(\WP_Error::class, Widget_Spec::validate($spec));
    }

    public function test_list_control_types(): void
    {
        $out = (new List_Control_Types())->handle([]);
        $types = array_column($out['control_types'], 'type');
        $this->assertContains('text', $types);
        $this->assertContains('wysiwyg', $types);
        $this->assertContains('url', $types);
        $this->assertContains('image', $types);
    }

    // ---- Widget_Renderer (pure) ---------------------------------------------

    public function test_renderer_interpolates_and_escapes_per_control(): void
    {
        $spec = $this->valid_spec();
        $html = Widget_Renderer::render($spec, [
            'heading' => 'Hello <script>x</script>',
            'body'    => '<strong>Bold</strong><script>evil()</script>',
            'link'    => 'https://example.com/a"onmouseover="x',
        ]);

        // text control: fully escaped.
        $this->assertStringNotContainsString('<script>x</script>', $html);
        $this->assertStringContainsString('Hello', $html);
        // wysiwyg: safe tags survive, the executable script tag is stripped
        // (wp_kses_post removes <script>, leaving only inert text).
        $this->assertStringContainsString('<strong>Bold</strong>', $html);
        $this->assertStringNotContainsString('<script>', $html);
        // url: esc_url strips the quote, so the attribute cannot be broken out
        // of (no `"onmouseover="` handler is injected).
        $this->assertStringNotContainsString('"onmouseover="', $html);
    }

    /**
     * Elementor hands control values back from get_settings_for_display() as
     * arrays for the URL, MEDIA and ICONS controls, not as plain strings. The
     * renderer has to reduce those to the member the template interpolates,
     * otherwise every such placeholder stringifies to the literal "Array".
     */
    public function test_renderer_unwraps_elementor_url_control_array(): void
    {
        $spec = $this->valid_spec();
        $html = Widget_Renderer::render($spec, [
            'link' => ['url' => 'https://example.com/go', 'is_external' => true, 'nofollow' => ''],
        ]);

        $this->assertStringContainsString('href="https://example.com/go"', $html);
        $this->assertStringNotContainsString('Array', $html);
    }

    public function test_renderer_unwraps_elementor_media_control_array(): void
    {
        $spec             = $this->media_spec();
        $html             = Widget_Renderer::render($spec, [
            'photo' => ['url' => 'https://example.com/cat.png', 'id' => 12],
            'glyph' => ['value' => 'fas fa-star', 'library' => 'fa-solid'],
        ]);

        $this->assertStringContainsString('src="https://example.com/cat.png"', $html);
        $this->assertStringNotContainsString('Array', $html);
    }

    public function test_renderer_unwraps_elementor_icons_control_array(): void
    {
        $spec = $this->media_spec();
        $html = Widget_Renderer::render($spec, [
            'photo' => ['url' => '', 'id' => 0],
            'glyph' => ['value' => 'fas fa-star', 'library' => 'fa-solid'],
        ]);

        $this->assertStringContainsString('class="fas fa-star"', $html);
        $this->assertStringNotContainsString('Array', $html);
    }

    public function test_renderer_blanks_non_scalar_control_value(): void
    {
        $spec = $this->valid_spec();
        $html = Widget_Renderer::render($spec, ['heading' => ['unexpected' => ['deep']]]);

        $this->assertStringNotContainsString('Array', $html);
        $this->assertStringContainsString('<h3></h3>', $html);
    }

    public function test_renderer_blanks_unknown_placeholder(): void
    {
        $spec             = $this->valid_spec();
        $spec['template'] = 'A{{heading}}B{{ghost}}C';
        $html             = Widget_Renderer::render($spec, ['heading' => 'X']);
        $this->assertSame('AXBC', $html);
    }

    public function test_renderer_uses_defaults_when_setting_absent(): void
    {
        $spec = $this->valid_spec();
        $html = Widget_Renderer::render($spec, []);
        $this->assertStringContainsString('Hi', $html); // heading default
    }

    // ---- store CRUD ---------------------------------------------------------

    public function test_create_stores_widget_spec(): void
    {
        $out = (new Create_Custom_Widget())->handle(['spec' => $this->valid_spec()]);
        $this->assertIsArray($out);
        $wid = $out['widget_id'];
        $this->assertSame('wpmcp_widget', get_post_type($wid));
        $this->assertSame('promo-box', $out['name']);
        $stored = get_post_meta($wid, '_wpmcp_widget_spec', true);
        $this->assertSame('Promo Box', $stored['title']);
    }

    public function test_create_rejects_invalid_spec(): void
    {
        $spec = $this->valid_spec();
        unset($spec['template']);
        $out = (new Create_Custom_Widget())->handle(['spec' => $spec]);
        $this->assertInstanceOf(\WP_Error::class, $out);
    }

    public function test_get_and_list_and_update_and_delete(): void
    {
        $wid = (new Create_Custom_Widget())->handle(['spec' => $this->valid_spec()])['widget_id'];

        $got = (new Get_Custom_Widget())->handle(['widget_id' => $wid]);
        $this->assertSame('promo-box', $got['spec']['name']);

        $list = (new List_Custom_Widgets())->handle([]);
        $this->assertContains($wid, array_column($list['widgets'], 'widget_id'));

        $spec          = $this->valid_spec();
        $spec['title'] = 'Renamed';
        (new Update_Custom_Widget())->handle(['widget_id' => $wid, 'spec' => $spec]);
        $this->assertSame('Renamed', get_post_meta($wid, '_wpmcp_widget_spec', true)['title']);

        $del = (new Delete_Custom_Widget())->handle(['widget_id' => $wid]);
        $this->assertSame('trashed', $del['deleted']);
    }

    /**
     * The renderer returns the template verbatim, so the template is the one
     * place a stored spec can carry markup. Elementor and core treat that as
     * author-trusted HTML, but only for users who actually hold
     * `unfiltered_html`: on multisite a site administrator has manage_options
     * and NOT unfiltered_html, and the abilities are gated on manage_options
     * alone. So the write path filters the template through wp_kses_post for
     * anyone without the capability, exactly as core does for post_content.
     */
    public function test_template_is_kses_filtered_for_users_without_unfiltered_html(): void
    {
        add_filter('map_meta_cap', [$this, 'deny_unfiltered_html'], 10, 3);

        $spec             = $this->valid_spec();
        $spec['template'] = '<div class="promo"><script>evil()</script>{{heading}}</div>';
        $wid              = (new Create_Custom_Widget())->handle(['spec' => $spec])['widget_id'];

        $stored = get_post_meta($wid, '_wpmcp_widget_spec', true);
        $this->assertStringNotContainsString('<script>', $stored['template']);
        $this->assertStringContainsString('{{heading}}', $stored['template']);
        $this->assertStringContainsString('<div class="promo">', $stored['template']);

        $spec['template'] = '<p><script>again()</script>{{heading}}</p>';
        (new Update_Custom_Widget())->handle(['widget_id' => $wid, 'spec' => $spec]);
        $this->assertStringNotContainsString('<script>', get_post_meta($wid, '_wpmcp_widget_spec', true)['template']);

        remove_filter('map_meta_cap', [$this, 'deny_unfiltered_html'], 10);
    }

    public function test_template_is_stored_verbatim_for_users_with_unfiltered_html(): void
    {
        $spec             = $this->valid_spec();
        $spec['template'] = '<div data-x="1">{{heading}}</div>';
        $wid              = (new Create_Custom_Widget())->handle(['spec' => $spec])['widget_id'];

        $this->assertSame($spec['template'], get_post_meta($wid, '_wpmcp_widget_spec', true)['template']);
    }

    /** Strips unfiltered_html the way multisite does for a site administrator. */
    public function deny_unfiltered_html(array $caps, string $cap, int $user_id): array
    {
        return 'unfiltered_html' === $cap ? ['do_not_allow'] : $caps;
    }

    public function test_set_widget_status(): void
    {
        $wid = (new Create_Custom_Widget())->handle(['spec' => $this->valid_spec()])['widget_id'];

        (new Set_Widget_Status())->handle(['widget_id' => $wid, 'status' => 'draft']);
        $this->assertSame('draft', get_post_status($wid));

        (new Set_Widget_Status())->handle(['widget_id' => $wid, 'status' => 'publish']);
        $this->assertSame('publish', get_post_status($wid));
    }
}
