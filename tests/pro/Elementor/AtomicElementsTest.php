<?php

namespace WPMCP\Tests\Pro\Elementor;

use WPMCP\Tools\Elementor\Detect_Elementor_Version;
use WPMCP\Tools\Elementor\Add_Flexbox;
use WPMCP\Tools\Elementor\Add_Div_Block;
use WPMCP\Tools\Elementor\Add_Atomic_Widget;
use WPMCP\Tools\Elementor\Update_Atomic_Widget;

/**
 * Cluster 4 (EMCP parity): Elementor 4.0+ atomic elements.
 *
 * Atomic containers use elType e-flexbox / e-div-block; atomic widgets are
 * elType widget with an e-* widgetType (e-heading, e-paragraph, ...). Their
 * settings are typed props: { "$$type": "string", "value": ... }. These write
 * raw to `_elementor_data` snapshot-first (not through Document::save, which
 * would drop atomic elements when the Editor-V4 experiment is off), so the
 * $$type props round-trip exactly and the change stays undoable.
 */
class AtomicElementsTest extends Structural_Harness
{
    private function atomic_page(): int
    {
        $post_id = self::factory()->post->create(['post_type' => 'page']);
        update_post_meta($post_id, '_elementor_data', wp_json_encode([[
            'id'       => 'flex001',
            'elType'   => 'e-flexbox',
            'settings' => ['classes' => ['$$type' => 'classes', 'value' => []]],
            'elements' => [],
        ]]));
        update_post_meta($post_id, '_elementor_edit_mode', 'builder');
        return $post_id;
    }

    // ---- detect-elementor-version -------------------------------------------

    public function test_detect_version_reports_atomic_support(): void
    {
        $out = (new Detect_Elementor_Version())->handle([]);

        $this->assertIsArray($out);
        $this->assertSame(ELEMENTOR_VERSION, $out['elementor_version']);
        $this->assertArrayHasKey('supports_atomic', $out);
        $this->assertTrue($out['supports_atomic'], 'Elementor 4.x test env supports atomic elements.');
        $this->assertSame('atomic', $out['recommended_mode']);
    }

    // ---- add-flexbox / add-div-block ----------------------------------------

    public function test_add_flexbox_inserts_atomic_container(): void
    {
        $post_id = $this->make_page([]);

        $out = (new Add_Flexbox())->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
        ]);

        $this->assertIsArray($out);
        $this->assertArrayHasKey('operation_id', $out);
        $tree = $this->tree($post_id);
        $this->assertSame('e-flexbox', $tree[0]['elType']);
        $this->assertSame('classes', $tree[0]['settings']['classes']['$$type']);
        $this->assertMatchesRegularExpression('/^[0-9a-z]{7}$/', $tree[0]['id']);
    }

    public function test_add_div_block_inserts_atomic_container(): void
    {
        $post_id = $this->make_page([]);

        (new Add_Div_Block())->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
        ]);

        $this->assertSame('e-div-block', $this->tree($post_id)[0]['elType']);
    }

    // ---- add-atomic-widget --------------------------------------------------

    public function test_add_atomic_heading_from_params(): void
    {
        $post_id = $this->atomic_page();

        $out = (new Add_Atomic_Widget())->handle([
            'post_id'       => $post_id,
            'parent_id'     => 'flex001',
            'widget_type'   => 'e-heading',
            'params'        => ['title' => 'Hello world', 'tag' => 'h1'],
            'expected_hash' => $this->data_hash($post_id),
        ]);

        $this->assertIsArray($out);
        $widget = $this->tree($post_id)[0]['elements'][0];
        $this->assertSame('widget', $widget['elType']);
        $this->assertSame('e-heading', $widget['widgetType']);
        $this->assertSame('html-v3', $widget['settings']['title']['$$type']);
        $this->assertSame('Hello world', $widget['settings']['title']['value']['content']['value']);
        $this->assertSame('h1', $widget['settings']['tag']['value']);
    }

    public function test_add_atomic_paragraph_uses_paragraph_prop(): void
    {
        $post_id = $this->atomic_page();

        (new Add_Atomic_Widget())->handle([
            'post_id'       => $post_id,
            'parent_id'     => 'flex001',
            'widget_type'   => 'e-paragraph',
            'params'        => ['content' => 'Body copy'],
            'expected_hash' => $this->data_hash($post_id),
        ]);

        $settings = $this->tree($post_id)[0]['elements'][0]['settings'];
        // e-paragraph's content prop is named `paragraph`, never `text`.
        $this->assertArrayHasKey('paragraph', $settings);
        $this->assertArrayNotHasKey('text', $settings);
        $this->assertSame('Body copy', $settings['paragraph']['value']['content']['value']);
    }

    public function test_add_atomic_widget_accepts_raw_settings(): void
    {
        $post_id = $this->atomic_page();

        (new Add_Atomic_Widget())->handle([
            'post_id'       => $post_id,
            'parent_id'     => 'flex001',
            'widget_type'   => 'e-custom',
            'settings'      => ['foo' => ['$$type' => 'string', 'value' => 'bar']],
            'expected_hash' => $this->data_hash($post_id),
        ]);

        $widget = $this->tree($post_id)[0]['elements'][0];
        $this->assertSame('e-custom', $widget['widgetType']);
        $this->assertSame('bar', $widget['settings']['foo']['value']);
    }

    public function test_add_atomic_widget_stores_a_style_class_that_survives_the_write(): void
    {
        $post_id = $this->atomic_page();

        $out = (new Add_Atomic_Widget())->handle([
            'post_id'       => $post_id,
            'parent_id'     => 'flex001',
            'widget_type'   => 'e-heading',
            'params'        => ['title' => 'Styled'],
            'style'         => ['color' => '#112233', 'font_size' => 32, 'padding' => ['top' => 8]],
            'expected_hash' => $this->data_hash($post_id),
        ]);

        $this->assertIsArray($out);

        $widget   = $this->tree($post_id)[0]['elements'][0];
        $class_id = $widget['settings']['classes']['value'][0];

        $this->assertStringStartsWith('e-' . $widget['id'] . '-', $class_id);
        $this->assertArrayHasKey($class_id, $widget['styles'], 'The classes ref must point at a stored style class.');

        $props = $widget['styles'][$class_id]['variants'][0]['props'];
        $this->assertSame('#112233', $props['color']['value']);
        // Read back through `_elementor_data`, so the numbers have been through
        // json_encode(), which writes a whole float as `32` and decodes it as an
        // int. Compare by value, not by type, or the assertion is about PHP's
        // JSON encoder rather than about the style that was stored.
        $this->assertEquals(32, $props['font-size']['value']['size']);
        $this->assertSame('px', $props['font-size']['value']['unit']);
        $this->assertEquals(8, $props['padding']['value']['block-start']['value']['size']);
    }

    public function test_add_atomic_widget_refuses_an_unusable_style_value(): void
    {
        $post_id = $this->atomic_page();

        $out = (new Add_Atomic_Widget())->handle([
            'post_id'       => $post_id,
            'parent_id'     => 'flex001',
            'widget_type'   => 'e-heading',
            'params'        => ['title' => 'Styled'],
            'style'         => ['color' => 'not-a-color'],
            'expected_hash' => $this->data_hash($post_id),
        ]);

        $this->assertWPError($out);
        $this->assertSame([], $this->tree($post_id)[0]['elements'], 'A refused style must not write a widget.');
    }

    public function test_update_atomic_widget_rewrites_the_generated_style_class(): void
    {
        $post_id = $this->atomic_page();

        (new Add_Atomic_Widget())->handle([
            'post_id'       => $post_id,
            'parent_id'     => 'flex001',
            'widget_type'   => 'e-heading',
            'params'        => ['title' => 'Styled'],
            'style'         => ['color' => '#112233'],
            'expected_hash' => $this->data_hash($post_id),
        ]);

        $element_id = $this->tree($post_id)[0]['elements'][0]['id'];

        $out = (new Update_Atomic_Widget())->handle([
            'post_id'       => $post_id,
            'element_id'    => $element_id,
            'style'         => ['color' => '#445566'],
            'expected_hash' => $this->data_hash($post_id),
        ]);

        $this->assertIsArray($out);

        $widget = $this->tree($post_id)[0]['elements'][0];
        $this->assertCount(1, $widget['styles'], 'A style update replaces the generated class instead of stacking a second one.');
        $this->assertCount(1, $widget['settings']['classes']['value']);

        $class_id = $widget['settings']['classes']['value'][0];
        $this->assertSame('#445566', $widget['styles'][$class_id]['variants'][0]['props']['color']['value']);
    }

    public function test_add_flexbox_accepts_style_props(): void
    {
        $post_id = $this->make_page([]);

        $out = (new Add_Flexbox())->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
            'style'         => ['gap' => 16, 'flex_direction' => 'column'],
        ]);

        $this->assertIsArray($out);

        $container = $this->tree($post_id)[0];
        $class_id  = $container['settings']['classes']['value'][0];
        $props     = $container['styles'][$class_id]['variants'][0]['props'];

        $this->assertEquals(16, $props['gap']['value']['size']);
        $this->assertSame('column', $props['flex-direction']['value']);
    }

    public function test_add_atomic_widget_rejects_stale_hash(): void
    {
        $post_id = $this->atomic_page();

        $out = (new Add_Atomic_Widget())->handle([
            'post_id'       => $post_id,
            'parent_id'     => 'flex001',
            'widget_type'   => 'e-heading',
            'params'        => ['title' => 'x'],
            'expected_hash' => 'stale',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('stale_expected_hash', $out->get_error_code());
    }

    public function test_add_atomic_widget_requires_widget_type(): void
    {
        $post_id = $this->atomic_page();

        $out = (new Add_Atomic_Widget())->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
        ]);
        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('missing_widget_type', $out->get_error_code());
    }

    // ---- update-atomic-widget -----------------------------------------------

    public function test_update_atomic_widget_merges_params(): void
    {
        $post_id = self::factory()->post->create(['post_type' => 'page']);
        update_post_meta($post_id, '_elementor_data', wp_json_encode([[
            'id'         => 'head001',
            'elType'     => 'widget',
            'widgetType' => 'e-heading',
            'settings'   => [
                'title' => ['$$type' => 'html-v3', 'value' => ['content' => ['$$type' => 'string', 'value' => 'Old'], 'children' => []]],
                'tag'   => ['$$type' => 'string', 'value' => 'h2'],
            ],
            'elements'   => [],
        ]]));
        update_post_meta($post_id, '_elementor_edit_mode', 'builder');

        (new Update_Atomic_Widget())->handle([
            'post_id'       => $post_id,
            'element_id'    => 'head001',
            'params'        => ['title' => 'New title'],
            'expected_hash' => $this->data_hash($post_id),
        ]);

        $settings = $this->tree($post_id)[0]['settings'];
        $this->assertSame('New title', $settings['title']['value']['content']['value']);
        // Untouched prop survives.
        $this->assertSame('h2', $settings['tag']['value']);
    }

    public function test_update_atomic_widget_rejects_missing_element(): void
    {
        $post_id = $this->atomic_page();
        $out = (new Update_Atomic_Widget())->handle([
            'post_id'       => $post_id,
            'element_id'    => 'nope999',
            'params'        => ['title' => 'x'],
            'expected_hash' => $this->data_hash($post_id),
        ]);
        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('element_not_found', $out->get_error_code());
    }
}
