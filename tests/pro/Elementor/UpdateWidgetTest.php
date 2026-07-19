<?php

namespace WPMCP\Tests\Pro\Elementor;

use WPMCP\Safety\Rollback_Service;
use WPMCP\Tools\Elementor\Update_Widget;

/**
 * update-widget patches a cataloged widget's settings from curated,
 * validated params (issue #59): the same catalog schema add-widget inserts
 * with, applied as a merge into the existing settings through the
 * Element_Tree engine (snapshot-first, expected_hash concurrency).
 * Required params are NOT enforced here — a patch touches only what it
 * names. Non-cataloged widgets are refused toward update-element.
 */
class UpdateWidgetTest extends Structural_Harness
{
    private function handle(array $args)
    {
        return (new Update_Widget())->handle($args);
    }

    public function test_requires_expected_hash(): void
    {
        $post_id = $this->make_page();

        $out = $this->handle([
            'post_id'    => $post_id,
            'element_id' => 'wid0001',
            'params'     => ['title' => 'Renamed'],
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
            'element_id'    => 'wid0001',
            'params'        => ['title' => 'Renamed'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('stale_expected_hash', $out->get_error_code());
        $this->assertSame($before, $this->raw($post_id));
    }

    public function test_patches_cataloged_widget_and_preserves_untouched_settings(): void
    {
        $post_id = $this->make_page();

        $out = $this->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
            'element_id'    => 'wid0001',
            'params'        => ['title' => 'Renamed', 'header_size' => 'h1'],
        ]);

        $this->assertIsArray($out);
        $this->assertArrayHasKey('operation_id', $out);
        $this->assertSame($this->data_hash($post_id), $out['data_hash']);

        $element = $this->find_in($this->tree($post_id), 'wid0001');
        $this->assertSame('Renamed', $element['settings']['title']);
        $this->assertSame('h1', $element['settings']['header_size']);
        $this->assertSame('headline', $element['settings']['_css_classes'], 'Untouched settings must survive the patch.');

        $this->assert_builder_clean($post_id);
    }

    public function test_required_params_are_not_enforced_on_patch(): void
    {
        $post_id = $this->make_page();

        $out = $this->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
            'element_id'    => 'wid0001',
            'params'        => ['header_size' => 'h3'],
        ]);

        $this->assertIsArray($out);

        $element = $this->find_in($this->tree($post_id), 'wid0001');
        $this->assertSame('h3', $element['settings']['header_size']);
        $this->assertSame('Hello', $element['settings']['title'], 'The un-named title must be left alone.');
    }

    public function test_unknown_param_refused_with_no_write(): void
    {
        $post_id = $this->make_page();
        $before  = $this->raw($post_id);

        $out = $this->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
            'element_id'    => 'wid0001',
            'params'        => ['bogus' => 'x'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('unknown_param', $out->get_error_code());
        $this->assertSame($before, $this->raw($post_id));
    }

    public function test_enum_violation_refused(): void
    {
        $post_id = $this->make_page();

        $out = $this->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
            'element_id'    => 'wid0001',
            'params'        => ['header_size' => 'h9'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('invalid_param', $out->get_error_code());
    }

    public function test_missing_params_refused(): void
    {
        $post_id = $this->make_page();

        $out = $this->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
            'element_id'    => 'wid0001',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('missing_params', $out->get_error_code());
    }

    public function test_missing_element_id_refused(): void
    {
        $post_id = $this->make_page();

        $out = $this->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
            'params'        => ['title' => 'Renamed'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('missing_element_id', $out->get_error_code());
    }

    public function test_element_not_found_refused(): void
    {
        $post_id = $this->make_page();

        $out = $this->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
            'element_id'    => 'missing',
            'params'        => ['title' => 'Renamed'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('element_not_found', $out->get_error_code());
    }

    public function test_non_widget_target_refused(): void
    {
        $post_id = $this->make_page();

        $out = $this->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
            'element_id'    => 'cont001',
            'params'        => ['title' => 'Renamed'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('not_a_widget', $out->get_error_code());
    }

    public function test_non_cataloged_widget_refused_toward_update_element(): void
    {
        $post_id = $this->make_page([
            [
                'id'       => 'cont001',
                'elType'   => 'container',
                'settings' => [],
                'elements' => [
                    [
                        'id'         => 'wid0009',
                        'elType'     => 'widget',
                        'settings'   => [],
                        'elements'   => [],
                        'widgetType' => 'wp-widget-search',
                    ],
                ],
                'isInner'  => false,
            ],
        ]);

        $out = $this->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
            'element_id'    => 'wid0009',
            'params'        => ['title' => 'Find'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('not_cataloged', $out->get_error_code());
        $this->assertStringContainsString('update-element', $out->get_error_message());
    }

    public function test_rollback_restores_previous_settings(): void
    {
        $post_id = $this->make_page();
        $before  = $this->tree($post_id);

        $out = $this->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
            'element_id'    => 'wid0001',
            'params'        => ['title' => 'Temporary'],
        ]);

        $this->assertIsArray($out);
        Rollback_Service::restore_operation($out['operation_id']);

        $this->assertSame($before, $this->tree($post_id));
    }
}
