<?php

namespace WPMCP\Tests\Pro\Elementor;

use WPMCP\Safety\Rollback_Service;
use WPMCP\Tools\Elementor\Add_Widget;

/**
 * add-widget accepts any cataloged widget type with curated, validated
 * params (issue #59), places the element through the Element_Tree engine
 * (snapshot-first, expected_hash concurrency, issue #58), keeps a raw
 * `settings` escape hatch for non-cataloged registered widgets, and degrades
 * cleanly when a cataloged widget needs Elementor Pro that is not installed.
 */
class AddWidgetTest extends Structural_Harness
{
    private function handle(array $args)
    {
        return (new Add_Widget())->handle($args);
    }

    public function test_requires_expected_hash(): void
    {
        $post_id = $this->make_page();

        $out = $this->handle([
            'post_id'     => $post_id,
            'parent_id'   => 'cont001',
            'widget_type' => 'heading',
            'params'      => ['title' => 'New Heading'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('missing_expected_hash', $out->get_error_code());
    }

    public function test_stale_expected_hash_refused_with_no_write(): void
    {
        $post_id = $this->make_page();
        $before  = $this->raw($post_id);

        $out = $this->handle([
            'post_id'       => $post_id,
            'expected_hash' => hash('sha256', 'stale'),
            'parent_id'     => 'cont001',
            'widget_type'   => 'heading',
            'params'        => ['title' => 'New Heading'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('stale_expected_hash', $out->get_error_code());
        $this->assertSame($before, $this->raw($post_id));
    }

    public function test_inserts_cataloged_widget_with_curated_params_under_parent(): void
    {
        $post_id = $this->make_page();

        $out = $this->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
            'parent_id'     => 'cont001',
            'widget_type'   => 'heading',
            'params'        => ['title' => 'New Heading', 'header_size' => 'h1'],
        ]);

        $this->assertIsArray($out);
        $this->assertArrayHasKey('operation_id', $out);
        $this->assertSame($this->data_hash($post_id), $out['data_hash']);

        $element = $this->find_in($this->tree($post_id), $out['element_id']);
        $this->assertSame('widget', $element['elType']);
        $this->assertSame('heading', $element['widgetType']);
        $this->assertSame('New Heading', $element['settings']['title']);
        $this->assertSame('h1', $element['settings']['header_size']);

        $this->assert_builder_clean($post_id);
    }

    public function test_inserts_at_top_level_and_position_when_parent_omitted(): void
    {
        $post_id = $this->make_page();

        $out = $this->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
            'widget_type'   => 'alert',
            'position'      => 0,
            'params'        => [
                'alert_title' => 'Heads up',
                'alert_type'  => 'warning',
            ],
        ]);

        $this->assertIsArray($out);

        $tree = $this->tree($post_id);
        $this->assertSame($out['element_id'], $tree[0]['id']);
        $this->assertSame('warning', $tree[0]['settings']['alert_type']);
    }

    public function test_builds_repeater_and_link_values(): void
    {
        $post_id = $this->make_page();

        $out = $this->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
            'parent_id'     => 'cont002',
            'widget_type'   => 'icon-list',
            'params'        => [
                'icon_list' => [
                    ['text' => 'One'],
                    ['text' => 'Two', 'link' => ['url' => 'https://example.com', 'is_external' => true]],
                ],
            ],
        ]);

        $this->assertIsArray($out);

        $element = $this->find_in($this->tree($post_id), $out['element_id']);
        $items   = $element['settings']['icon_list'];

        $this->assertCount(2, $items);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{7}$/', $items[0]['_id']);
        $this->assertSame('One', $items[0]['text']);
        $this->assertSame('https://example.com', $items[1]['link']['url']);
        $this->assertSame('on', $items[1]['link']['is_external']);

        $this->assert_builder_clean($post_id);
    }

    public function test_unknown_param_refused_with_no_write(): void
    {
        $post_id = $this->make_page();
        $before  = $this->raw($post_id);

        $out = $this->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
            'parent_id'     => 'cont001',
            'widget_type'   => 'heading',
            'params'        => ['title' => 'Hello', 'bogus' => 'x'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('unknown_param', $out->get_error_code());
        $this->assertSame($before, $this->raw($post_id));
    }

    public function test_missing_required_param_refused(): void
    {
        $post_id = $this->make_page();

        $out = $this->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
            'parent_id'     => 'cont001',
            'widget_type'   => 'heading',
            'params'        => ['header_size' => 'h1'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('missing_required_param', $out->get_error_code());
    }

    public function test_enum_violation_refused(): void
    {
        $post_id = $this->make_page();

        $out = $this->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
            'parent_id'     => 'cont001',
            'widget_type'   => 'heading',
            'params'        => ['title' => 'Hello', 'header_size' => 'h9'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('invalid_param', $out->get_error_code());
    }

    public function test_raw_settings_escape_hatch_for_non_cataloged_registered_widget(): void
    {
        $post_id = $this->make_page();

        $out = $this->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
            'parent_id'     => 'cont001',
            'widget_type'   => 'wp-widget-search',
            'settings'      => ['wp' => ['title' => 'Find']],
        ]);

        $this->assertIsArray($out);

        $element = $this->find_in($this->tree($post_id), $out['element_id']);
        $this->assertSame('wp-widget-search', $element['widgetType']);
        $this->assertSame(['wp' => ['title' => 'Find']], $element['settings']);
    }

    public function test_curated_params_refused_for_non_cataloged_widget(): void
    {
        $post_id = $this->make_page();

        $out = $this->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
            'parent_id'     => 'cont001',
            'widget_type'   => 'wp-widget-search',
            'params'        => ['title' => 'Find'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('not_cataloged', $out->get_error_code());
    }

    public function test_cataloged_pro_widget_degrades_cleanly_without_elementor_pro(): void
    {
        $widget = \Elementor\Plugin::instance()->widgets_manager->get_widget_types('form');
        if ($widget && 0 === strpos(get_class($widget), 'ElementorPro\\')) {
            $this->markTestSkipped('Elementor Pro is installed; the degrade path cannot be exercised.');
        }

        $post_id = $this->make_page();
        $before  = $this->raw($post_id);

        $out = $this->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
            'parent_id'     => 'cont001',
            'widget_type'   => 'form',
            'params'        => ['form_name' => 'Contact'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('requires_elementor_pro', $out->get_error_code());
        $this->assertSame($before, $this->raw($post_id));
    }

    public function test_unknown_widget_type_refused(): void
    {
        $post_id = $this->make_page();

        $out = $this->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
            'parent_id'     => 'cont001',
            'widget_type'   => 'totally-fake-widget',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('invalid_widget_type', $out->get_error_code());
    }

    public function test_widget_parent_refused(): void
    {
        $post_id = $this->make_page();

        $out = $this->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
            'parent_id'     => 'wid0001',
            'widget_type'   => 'heading',
            'params'        => ['title' => 'Nested?'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('invalid_parent', $out->get_error_code());
    }

    public function test_parent_not_found_refused(): void
    {
        $post_id = $this->make_page();

        $out = $this->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
            'parent_id'     => 'missing',
            'widget_type'   => 'heading',
            'params'        => ['title' => 'Hello'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('parent_not_found', $out->get_error_code());
    }

    public function test_missing_widget_type_refused(): void
    {
        $post_id = $this->make_page();

        $out = $this->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
            'parent_id'     => 'cont001',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('missing_widget_type', $out->get_error_code());
    }

    public function test_rollback_removes_inserted_widget(): void
    {
        $post_id = $this->make_page();
        $before  = $this->tree($post_id);

        $out = $this->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
            'parent_id'     => 'cont001',
            'widget_type'   => 'heading',
            'params'        => ['title' => 'Temporary'],
        ]);

        $this->assertIsArray($out);
        Rollback_Service::restore_operation($out['operation_id']);

        $this->assertSame($before, $this->tree($post_id));
    }
}
