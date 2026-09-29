<?php

namespace WPMCP\Tests\Pro\Elementor;

use WPMCP\Tools\Elementor\{Add_Container, Add_Widget, Batch_Update, Update_Element, Update_Page_Settings};

/**
 * Classic `dimensions` and `gaps` control values (padding, margin, border
 * radius, container gaps) must reach `_elementor_data` with every side as a
 * string, the form Elementor's own editor stores (issue #393).
 *
 * The editor panel fills an unlinked control with
 * `if ( _.isEmpty( value ) ) value = 0` per side, and underscore's isEmpty()
 * is true for every number, so a numeric side shows as 0 in the panel and is
 * saved back as 0 on the next edit of that control. The page itself renders
 * the right value, which is what hid the bug.
 */
class ClassicDimensionsTest extends Structural_Harness
{
    public function test_add_container_stores_dimension_sides_as_strings(): void
    {
        $post_id = $this->make_page();

        $out = (new Add_Container())->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
            'settings'      => [
                'padding'       => ['unit' => 'px', 'top' => 10, 'right' => 20, 'bottom' => 10.5, 'left' => 0, 'isLinked' => false],
                'margin_tablet' => ['unit' => 'em', 'top' => 1, 'right' => '', 'bottom' => 1, 'left' => '', 'isLinked' => false],
                'flex_gap'      => ['unit' => 'px', 'column' => 24, 'row' => 12, 'isLinked' => false],
            ],
        ]);

        $this->assertIsArray($out);
        $settings = $this->find_in($this->tree($post_id), $out['element_id'])['settings'];

        $this->assertSame(
            ['unit' => 'px', 'top' => '10', 'right' => '20', 'bottom' => '10.5', 'left' => '0', 'isLinked' => false],
            $settings['padding']
        );
        $this->assertSame(
            ['unit' => 'em', 'top' => '1', 'right' => '', 'bottom' => '1', 'left' => '', 'isLinked' => false],
            $settings['margin_tablet']
        );
        $this->assertSame(['unit' => 'px', 'column' => '24', 'row' => '12', 'isLinked' => false], $settings['flex_gap']);
    }

    public function test_add_widget_raw_settings_store_dimension_sides_as_strings(): void
    {
        $post_id = $this->make_page();

        $out = (new Add_Widget())->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
            'parent_id'     => 'cont001',
            'widget_type'   => 'heading',
            'settings'      => [
                'title'          => 'Hi',
                '_border_radius' => ['unit' => 'px', 'top' => 8, 'right' => 8, 'bottom' => 8, 'left' => 8, 'isLinked' => true],
            ],
        ]);

        $this->assertIsArray($out);
        $settings = $this->find_in($this->tree($post_id), $out['element_id'])['settings'];
        $this->assertSame(
            ['unit' => 'px', 'top' => '8', 'right' => '8', 'bottom' => '8', 'left' => '8', 'isLinked' => true],
            $settings['_border_radius']
        );
    }

    public function test_update_element_stores_dimension_sides_as_strings(): void
    {
        $post_id = $this->make_page();

        (new Update_Element())->handle([
            'post_id'    => $post_id,
            'element_id' => 'wid0001',
            'settings'   => [
                '_margin' => ['unit' => 'px', 'top' => 0, 'right' => 5, 'bottom' => 30, 'left' => 5, 'isLinked' => false],
            ],
        ]);

        $settings = $this->find_in($this->tree($post_id), 'wid0001')['settings'];
        $this->assertSame(
            ['unit' => 'px', 'top' => '0', 'right' => '5', 'bottom' => '30', 'left' => '5', 'isLinked' => false],
            $settings['_margin']
        );
    }

    public function test_batch_update_stores_dimension_sides_as_strings(): void
    {
        $post_id = $this->make_page();

        $out = (new Batch_Update())->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
            'updates'       => [
                ['element_id' => 'cont002', 'settings' => ['padding' => ['unit' => '%', 'top' => 2, 'right' => 4, 'bottom' => 2, 'left' => 4, 'isLinked' => false]]],
            ],
        ]);

        $this->assertIsArray($out);
        $settings = $this->find_in($this->tree($post_id), 'cont002')['settings'];
        $this->assertSame(
            ['unit' => '%', 'top' => '2', 'right' => '4', 'bottom' => '2', 'left' => '4', 'isLinked' => false],
            $settings['padding']
        );
    }

    public function test_update_page_settings_stores_dimension_sides_as_strings(): void
    {
        $post_id = $this->make_page();

        $out = (new Update_Page_Settings())->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->settings_hash($post_id),
            'settings'      => ['padding' => ['unit' => 'px', 'top' => 40, 'right' => 0, 'bottom' => 40, 'left' => 0, 'isLinked' => false]],
        ]);

        $this->assertIsArray($out);
        $stored = get_post_meta($post_id, '_elementor_page_settings', true);
        $this->assertSame(
            ['unit' => 'px', 'top' => '40', 'right' => '0', 'bottom' => '40', 'left' => '0', 'isLinked' => false],
            $stored['padding']
        );
    }

    public function test_values_that_are_not_dimensions_are_left_alone(): void
    {
        $post_id = $this->make_page();

        $out = (new Add_Container())->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
            'settings'      => [
                'gap'       => ['size' => 20, 'unit' => 'px'],
                'min_height' => ['unit' => 'px', 'size' => 400, 'sizes' => []],
                'z_index'   => 5,
                'position'  => ['top' => 3, 'label' => 'not a control value'],
            ],
        ]);

        $this->assertIsArray($out);
        $settings = $this->find_in($this->tree($post_id), $out['element_id'])['settings'];
        $this->assertSame(['size' => 20, 'unit' => 'px'], $settings['gap']);
        $this->assertSame(['unit' => 'px', 'size' => 400, 'sizes' => []], $settings['min_height']);
        $this->assertSame(5, $settings['z_index']);
        $this->assertSame(['top' => 3, 'label' => 'not a control value'], $settings['position']);
    }

    public function test_elementor_dimension_controls_default_to_string_sides(): void
    {
        $controls = \Elementor\Plugin::instance()->controls_manager;

        $this->assertSame(
            ['unit' => 'px', 'top' => '', 'right' => '', 'bottom' => '', 'left' => '', 'isLinked' => true],
            $controls->get_control('dimensions')->get_default_value(),
            'The contract this normalization follows: the editor stores each side as a string.'
        );
        $this->assertSame(
            ['column' => '', 'row' => '', 'isLinked' => true, 'unit' => 'px'],
            $controls->get_control('gaps')->get_default_value()
        );
    }
}
