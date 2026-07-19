<?php

namespace WPMCP\Tests\Pro\Elementor;

use WPMCP\Pro\Gate;
use WPMCP\Safety\{Rollback_Service, Snapshot_Store};
use WPMCP\Tools\Elementor\Generate_Widget;

/**
 * generate-widget builds a valid Elementor widget element from the curated
 * widget catalog (Widget_Catalog, issue #59) and inserts it into a post's
 * _elementor_data through the same undoable write path as the other
 * Elementor deep-editing tools. Any cataloged type is supported; the old
 * hardcoded 4-type limit is gone.
 */
class GenerateWidgetTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();

        if (! wpmcp_elementor_active()) {
            $this->markTestSkipped('Elementor not active');
        }
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function make_page(array $elements = []): int
    {
        $post_id = self::factory()->post->create(['post_type' => 'page']);
        update_post_meta($post_id, '_elementor_data', wp_json_encode($elements));
        return $post_id;
    }

    public function test_generates_heading_widget_and_appends_to_elementor_data(): void
    {
        $post_id = $this->make_page();

        $out = (new Generate_Widget())->handle([
            'post_id'     => $post_id,
            'widget_type' => 'heading',
            'settings'    => ['title' => 'Welcome'],
        ]);

        $this->assertArrayHasKey('operation_id', $out);
        $this->assertArrayHasKey('element_id', $out);
        $this->assertArrayHasKey('element', $out);
        $this->assertSame('widget', $out['element']['elType']);
        $this->assertSame('heading', $out['element']['widgetType']);
        $this->assertSame(['title' => 'Welcome'], $out['element']['settings']);
        $this->assertSame($out['element_id'], $out['element']['id']);

        $raw      = json_decode(get_post_meta($post_id, '_elementor_data', true), true);
        $appended = $raw[0];
        $this->assertSame($out['element_id'], $appended['id']);
        $this->assertSame('widget', $appended['elType']);
        $this->assertSame('heading', $appended['widgetType']);
        $this->assertSame('Welcome', $appended['settings']['title']);
    }

    public function test_generates_text_editor_widget(): void
    {
        $post_id = $this->make_page();

        $out = (new Generate_Widget())->handle([
            'post_id'     => $post_id,
            'widget_type' => 'text-editor',
            'settings'    => ['editor' => '<p>Body copy</p>'],
        ]);

        $this->assertSame('text-editor', $out['element']['widgetType']);
        $this->assertSame('<p>Body copy</p>', $out['element']['settings']['editor']);
    }

    public function test_generates_button_widget(): void
    {
        $post_id = $this->make_page();

        $out = (new Generate_Widget())->handle([
            'post_id'     => $post_id,
            'widget_type' => 'button',
            'settings'    => ['text' => 'Click me', 'link' => ['url' => 'https://example.com']],
        ]);

        $this->assertSame('button', $out['element']['widgetType']);
        $this->assertSame('Click me', $out['element']['settings']['text']);
        $this->assertSame('https://example.com', $out['element']['settings']['link']['url']);
    }

    public function test_generates_image_widget(): void
    {
        $post_id = $this->make_page();

        $out = (new Generate_Widget())->handle([
            'post_id'     => $post_id,
            'widget_type' => 'image',
            'settings'    => ['image' => ['url' => 'https://example.com/photo.jpg', 'id' => 42]],
        ]);

        $this->assertSame('image', $out['element']['widgetType']);
        $this->assertSame('https://example.com/photo.jpg', $out['element']['settings']['image']['url']);
        $this->assertSame(42, $out['element']['settings']['image']['id']);
    }

    public function test_generates_any_cataloged_type_beyond_the_old_four(): void
    {
        // counter was outside generate-widget's original 4-type set; the
        // catalog (issue #59) is now the single source of supported types.
        $post_id = $this->make_page();

        $out = (new Generate_Widget())->handle([
            'post_id'     => $post_id,
            'widget_type' => 'counter',
            'settings'    => ['ending_number' => 250, 'suffix' => '+', 'title' => 'Happy Clients'],
        ]);

        $this->assertSame('counter', $out['element']['widgetType']);
        $this->assertSame(250, $out['element']['settings']['ending_number']);
        $this->assertSame('+', $out['element']['settings']['suffix']);
    }

    public function test_returns_wp_error_for_unknown_param(): void
    {
        $post_id = $this->make_page();

        $out = (new Generate_Widget())->handle([
            'post_id'     => $post_id,
            'widget_type' => 'heading',
            'settings'    => ['title' => 'Welcome', 'bogus' => 'x'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('unknown_param', $out->get_error_code());
    }

    public function test_cataloged_pro_widget_degrades_cleanly_without_elementor_pro(): void
    {
        $widget = \Elementor\Plugin::instance()->widgets_manager->get_widget_types('form');
        if ($widget && 0 === strpos(get_class($widget), 'ElementorPro\\')) {
            $this->markTestSkipped('Elementor Pro is installed; the degrade path cannot be exercised.');
        }

        $post_id = $this->make_page();

        $out = (new Generate_Widget())->handle([
            'post_id'     => $post_id,
            'widget_type' => 'form',
            'settings'    => ['form_name' => 'Contact'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('requires_elementor_pro', $out->get_error_code());
    }

    public function test_inserts_under_parent_when_parent_id_given(): void
    {
        $post_id = $this->make_page([
            [
                'id'       => 'sect001',
                'elType'   => 'section',
                'settings' => [],
                'elements' => [],
            ],
        ]);

        $out = (new Generate_Widget())->handle([
            'post_id'     => $post_id,
            'parent_id'   => 'sect001',
            'widget_type' => 'heading',
            'settings'    => ['title' => 'Nested'],
        ]);

        $raw = json_decode(get_post_meta($post_id, '_elementor_data', true), true);
        $this->assertSame($out['element_id'], $raw[0]['elements'][0]['id']);
        $this->assertSame('Nested', $raw[0]['elements'][0]['settings']['title']);
    }

    public function test_rollback_removes_generated_widget(): void
    {
        $post_id = $this->make_page();
        $before  = get_post_meta($post_id, '_elementor_data', true);

        $out = (new Generate_Widget())->handle([
            'post_id'     => $post_id,
            'widget_type' => 'heading',
            'settings'    => ['title' => 'Welcome'],
        ]);

        Rollback_Service::restore_operation($out['operation_id']);

        $after = get_post_meta($post_id, '_elementor_data', true);
        $this->assertSame($before, $after);
    }

    public function test_ids_are_unique_and_deterministic_across_calls(): void
    {
        $post_id = $this->make_page();

        $first = (new Generate_Widget())->handle([
            'post_id'     => $post_id,
            'widget_type' => 'heading',
            'settings'    => ['title' => 'One'],
        ]);
        $second = (new Generate_Widget())->handle([
            'post_id'     => $post_id,
            'widget_type' => 'heading',
            'settings'    => ['title' => 'Two'],
        ]);

        $this->assertNotSame($first['element_id'], $second['element_id']);

        // Re-run the exact same scenario from scratch: same starting state
        // (empty page, first widget generated) must yield the same id, since
        // the id is derived from existing element count, not randomness.
        $other_post_id = $this->make_page();
        $repeat        = (new Generate_Widget())->handle([
            'post_id'     => $other_post_id,
            'widget_type' => 'heading',
            'settings'    => ['title' => 'One'],
        ]);
        $this->assertSame($first['element_id'], $repeat['element_id']);
    }

    public function test_seed_makes_id_deterministic_and_independent_of_element_count(): void
    {
        $post_id = $this->make_page();

        $out = (new Generate_Widget())->handle([
            'post_id'     => $post_id,
            'widget_type' => 'heading',
            'settings'    => ['title' => 'Welcome'],
            'seed'        => 'my-fixed-seed',
        ]);

        $other_post_id = $this->make_page([
            [ 'id' => 'x1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [], 'elements' => [] ],
            [ 'id' => 'x2', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [], 'elements' => [] ],
        ]);
        $repeat = (new Generate_Widget())->handle([
            'post_id'     => $other_post_id,
            'widget_type' => 'heading',
            'settings'    => ['title' => 'Welcome'],
            'seed'        => 'my-fixed-seed',
        ]);

        $this->assertSame($out['element_id'], $repeat['element_id']);
    }

    public function test_returns_wp_error_for_unknown_widget_type(): void
    {
        $post_id = $this->make_page();

        $out = (new Generate_Widget())->handle([
            'post_id'     => $post_id,
            'widget_type' => 'totally-fake-widget',
            'settings'    => [],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('unknown_widget_type', $out->get_error_code());
    }

    public function test_returns_wp_error_for_missing_required_setting(): void
    {
        $post_id = $this->make_page();

        $out = (new Generate_Widget())->handle([
            'post_id'     => $post_id,
            'widget_type' => 'heading',
            'settings'    => [],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('missing_required_param', $out->get_error_code());
    }

    public function test_returns_wp_error_for_invalid_post(): void
    {
        $out = (new Generate_Widget())->handle([
            'post_id'     => 999999,
            'widget_type' => 'heading',
            'settings'    => ['title' => 'Welcome'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('invalid_post', $out->get_error_code());
    }

    public function test_returns_wp_error_for_post_without_elementor_data(): void
    {
        $post_id = self::factory()->post->create(['post_type' => 'page']);

        $out = (new Generate_Widget())->handle([
            'post_id'     => $post_id,
            'widget_type' => 'heading',
            'settings'    => ['title' => 'Welcome'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('no_elementor_data', $out->get_error_code());
    }

    public function test_returns_wp_error_when_post_id_missing(): void
    {
        $out = (new Generate_Widget())->handle([
            'widget_type' => 'heading',
            'settings'    => ['title' => 'Welcome'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('missing_post_id', $out->get_error_code());
    }

    public function test_returns_wp_error_when_widget_type_missing(): void
    {
        $post_id = $this->make_page();

        $out = (new Generate_Widget())->handle([
            'post_id'  => $post_id,
            'settings' => ['title' => 'Welcome'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('missing_widget_type', $out->get_error_code());
    }

    public function test_returns_wp_error_when_parent_not_found(): void
    {
        $post_id = $this->make_page();

        $out = (new Generate_Widget())->handle([
            'post_id'     => $post_id,
            'parent_id'   => 'does-not-exist',
            'widget_type' => 'heading',
            'settings'    => ['title' => 'Welcome'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('parent_not_found', $out->get_error_code());
    }
}
