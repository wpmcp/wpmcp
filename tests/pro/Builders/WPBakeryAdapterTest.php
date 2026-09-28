<?php

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Pro\Gate;
use WPMCP\Safety\{Rollback_Service, Snapshot_Store};
use WPMCP\Tools\Builders\{Detect_Builder, Get_Builder_Content, Update_Builder_Content, WPBakery_Content};

/**
 * End to end through the builder dispatcher pair: detection, the tree read,
 * each element operation, and exact rollback of post_content plus the two
 * WPBakery meta rows. WPBakery is a commercial plugin that is not installed
 * here, so its layout is written as the editor stores it and the plugin's
 * presence is toggled through the wpmcp_wpbakery_active filter.
 */
class WPBakeryAdapterTest extends \WP_UnitTestCase
{
    private const CSS_1 = '.vc_custom_1600000000001{padding-top: 40px !important;}';
    private const CSS_2 = '.vc_custom_1600000000002{margin-bottom: 0px !important;}';

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        remove_all_filters('wpmcp_wpbakery_active');
        parent::tearDown();
    }

    private function page(string $content, array $meta = []): int
    {
        $post_id = self::factory()->post->create(['post_type' => 'page', 'post_content' => wp_slash($content)]);
        foreach ($meta as $key => $value) {
            update_post_meta($post_id, $key, $value);
        }

        return $post_id;
    }

    private function wpbakery_page(): int
    {
        return $this->page(WPBakeryShortcodesTest::FIXTURE, [
            '_wpb_vc_js_status'          => 'true',
            '_wpb_shortcodes_custom_css' => self::CSS_1 . self::CSS_2,
            '_wpb_post_custom_css'       => '.page { color: red; }',
        ]);
    }

    private function update(int $post_id, array $args)
    {
        return (new Update_Builder_Content())->handle(['post_id' => $post_id, 'builder' => 'wpbakery'] + $args);
    }

    private function state(int $post_id): array
    {
        return [get_post($post_id)->post_content, get_post_meta($post_id)];
    }

    // ------------------------------------------------------------ detection

    public function test_detects_wpbakery_from_editor_flag_and_from_content_alone(): void
    {
        $flagged = $this->page('', ['_wpb_vc_js_status' => 'true']);
        $content = $this->page('[vc_row][vc_column][/vc_column][/vc_row]', ['_wpb_vc_js_status' => 'false']);
        $section = $this->page('[vc_section el_id="top"][/vc_section]');

        foreach ([$flagged, $content, $section] as $post_id) {
            $this->assertSame('wpbakery', (new Detect_Builder())->handle(['post_id' => $post_id])['builder']);
        }
    }

    public function test_other_builders_win_and_lookalikes_do_not_match(): void
    {
        $gutenberg = $this->page('<!-- wp:shortcode -->[vc_row][/vc_row]<!-- /wp:shortcode -->');
        $divi      = $this->page('[vc_row][/vc_row]', ['_et_pb_use_builder' => 'on']);
        $classic   = $this->page('<p>[vc_rowdy] is not a row</p>');

        $this->assertSame('gutenberg', (new Detect_Builder())->handle(['post_id' => $gutenberg])['builder']);
        $this->assertSame('divi', (new Detect_Builder())->handle(['post_id' => $divi])['builder']);
        $this->assertSame('classic', (new Detect_Builder())->handle(['post_id' => $classic])['builder']);
    }

    public function test_detection_is_the_same_whether_or_not_the_plugin_is_active(): void
    {
        $post_id = $this->wpbakery_page();
        $plain   = $this->page('<p>Plain</p>');

        foreach ([false, true] as $active) {
            add_filter('wpmcp_wpbakery_active', $active ? '__return_true' : '__return_false');
            $this->assertSame($active, WPBakery_Content::plugin_active());
            $this->assertSame('wpbakery', (new Detect_Builder())->handle(['post_id' => $post_id])['builder']);
            $this->assertSame('classic', (new Detect_Builder())->handle(['post_id' => $plain])['builder']);
            remove_all_filters('wpmcp_wpbakery_active');
        }
    }

    public function test_plugin_is_reported_inactive_in_this_environment(): void
    {
        $this->assertFalse(WPBakery_Content::plugin_active());
    }

    // ----------------------------------------------------------------- read

    public function test_read_returns_tree_content_meta_and_plugin_state(): void
    {
        add_filter('wpmcp_wpbakery_active', '__return_true');
        $post_id = $this->wpbakery_page();

        $out = (new Get_Builder_Content())->handle(['post_id' => $post_id]);

        $this->assertSame('wpbakery', $out['builder']);
        $this->assertTrue($out['plugin_active']);
        $this->assertSame(WPBakeryShortcodesTest::FIXTURE, $out['content']);
        $this->assertSame('true', $out['js_status']);
        $this->assertSame(self::CSS_1 . self::CSS_2, $out['custom_css']);
        $this->assertSame('vc_column_text', $out['tree'][0]['children'][0]['children'][0]['tag']);
    }

    // ---------------------------------------------------------- round trips

    public function test_update_text_round_trip_and_exact_rollback(): void
    {
        $post_id = $this->wpbakery_page();
        $before  = $this->state($post_id);

        $out = $this->update($post_id, ['operation' => 'update', 'path' => '0.0.0', 'text' => '<p>Rewritten \\ copy</p>']);

        $this->assertSame('0.0.0', $out['path']);
        $read = (new Get_Builder_Content())->handle(['post_id' => $post_id]);
        $this->assertSame('<p>Rewritten \\ copy</p>', $read['tree'][0]['children'][0]['children'][0]['text']);
        // Untouched css rules leave the compiled meta alone.
        $this->assertSame(self::CSS_1 . self::CSS_2, get_post_meta($post_id, '_wpb_shortcodes_custom_css', true));

        Rollback_Service::restore_operation($out['operation_id']);

        $this->assertSame($before, $this->state($post_id));
    }

    public function test_css_attribute_change_recompiles_the_custom_css_meta_and_rolls_back(): void
    {
        $post_id = $this->wpbakery_page();
        $before  = $this->state($post_id);
        $new_css = '.vc_custom_1700000000009{padding-top: 80px !important;}';

        $out = $this->update($post_id, ['operation' => 'update', 'path' => '0', 'attrs' => ['css' => $new_css]]);

        $this->assertSame($new_css . self::CSS_2, get_post_meta($post_id, '_wpb_shortcodes_custom_css', true));
        $this->assertSame('.page { color: red; }', get_post_meta($post_id, '_wpb_post_custom_css', true));

        Rollback_Service::restore_operation($out['operation_id']);

        $this->assertSame($before, $this->state($post_id));
    }

    public function test_add_remove_and_move_each_roll_back_exactly(): void
    {
        $post_id = $this->wpbakery_page();
        $before  = $this->state($post_id);

        $add = $this->update($post_id, ['operation' => 'add', 'to' => '0.0', 'index' => 0, 'element' => ['tag' => 'vc_column_text', 'text' => '<p>First</p>']]);
        $this->assertSame('0.0.0', $add['path']);
        $this->assertSame('<p>First</p>', (new Get_Builder_Content())->handle(['post_id' => $post_id])['tree'][0]['children'][0]['children'][0]['text']);
        Rollback_Service::restore_operation($add['operation_id']);
        $this->assertSame($before, $this->state($post_id));

        // Removing the element that carries a css rule drops it from the meta.
        $remove = $this->update($post_id, ['operation' => 'remove', 'path' => '0.0.0']);
        $this->assertSame(self::CSS_1, get_post_meta($post_id, '_wpb_shortcodes_custom_css', true));
        $this->assertArrayNotHasKey('path', $remove);
        Rollback_Service::restore_operation($remove['operation_id']);
        $this->assertSame($before, $this->state($post_id));

        $move = $this->update($post_id, ['operation' => 'move', 'path' => '1', 'to' => '', 'index' => 0]);
        $this->assertSame('vc_tta_tabs', (new Get_Builder_Content())->handle(['post_id' => $post_id])['tree'][0]['children'][0]['children'][0]['tag']);
        Rollback_Service::restore_operation($move['operation_id']);
        $this->assertSame($before, $this->state($post_id));
    }

    public function test_first_write_to_a_blank_page_sets_the_flag_and_rollback_removes_the_meta(): void
    {
        $post_id = $this->page('');
        $before  = $this->state($post_id);

        $out = $this->update($post_id, ['operation' => 'add', 'to' => '', 'element' => [
            'tag'      => 'vc_row',
            'attrs'    => ['css' => self::CSS_1],
            'children' => [['tag' => 'vc_column']],
        ]]);

        $this->assertSame('[vc_row css="' . self::CSS_1 . '"][vc_column][/vc_column][/vc_row]', get_post($post_id)->post_content);
        $this->assertSame('true', get_post_meta($post_id, '_wpb_vc_js_status', true));
        $this->assertSame(self::CSS_1, get_post_meta($post_id, '_wpb_shortcodes_custom_css', true));
        $this->assertSame('wpbakery', (new Detect_Builder())->handle(['post_id' => $post_id])['builder']);

        Rollback_Service::restore_operation($out['operation_id']);

        $this->assertSame($before, $this->state($post_id));
        $this->assertSame('', get_post_meta($post_id, '_wpb_vc_js_status', true));
    }

    public function test_whole_content_replace_keeps_an_existing_editor_flag_and_drops_emptied_css(): void
    {
        $post_id = $this->page('[vc_row css="' . self::CSS_1 . '"][/vc_row]', [
            '_wpb_vc_js_status'          => 'false',
            '_wpb_shortcodes_custom_css' => self::CSS_1,
        ]);
        $before = $this->state($post_id);

        $out = $this->update($post_id, ['content' => '[vc_row][vc_column][vc_column_text]Hi[/vc_column_text][/vc_column][/vc_row]']);

        $this->assertSame('wpbakery', $out['builder']);
        $this->assertSame('false', get_post_meta($post_id, '_wpb_vc_js_status', true));
        $this->assertSame('', get_post_meta($post_id, '_wpb_shortcodes_custom_css', true));

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($post_id));
    }

    // --------------------------------------------------------------- errors

    public function test_rejected_requests_write_nothing(): void
    {
        $post_id  = $this->wpbakery_page();
        $before   = $this->state($post_id);
        $elements = $this->page('', ['_elementor_edit_mode' => 'builder']);

        $cases = [
            [$post_id, [], 'invalid_wpbakery_content'],
            [$post_id, ['content' => ['x']], 'invalid_wpbakery_content'],
            [$post_id, ['operation' => 'explode'], 'invalid_wpbakery_operation'],
            [$post_id, ['operation' => 'update', 'path' => '0', 'attrs' => 'x'], 'invalid_wpbakery_operation'],
            [$post_id, ['operation' => 'update', 'path' => '0.0.0', 'text' => 5], 'invalid_wpbakery_operation'],
            [$post_id, ['operation' => 'add', 'to' => '0'], 'invalid_wpbakery_operation'],
            [$post_id, ['operation' => 'remove', 'path' => '7'], 'invalid_wpbakery_operation'],
            [$post_id, ['operation' => 'move', 'path' => '0', 'to' => '0.0'], 'invalid_wpbakery_operation'],
            [$elements, ['operation' => 'remove', 'path' => '0'], 'unsupported_builder'],
        ];
        foreach ($cases as [$id, $args, $code]) {
            $out = $this->update($id, $args);
            $this->assertInstanceOf(\WP_Error::class, $out);
            $this->assertSame($code, $out->get_error_code(), wp_json_encode($args));
        }

        $this->assertSame($before, $this->state($post_id));
    }

    public function test_read_of_a_non_builder_page_still_errors(): void
    {
        $out = (new Get_Builder_Content())->handle(['post_id' => $this->page('<p>Plain</p>')]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('unsupported_builder', $out->get_error_code());
    }
}
