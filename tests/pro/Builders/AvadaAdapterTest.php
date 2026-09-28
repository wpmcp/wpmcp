<?php

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Pro\Gate;
use WPMCP\Safety\{Rollback_Service, Snapshot_Store};
use WPMCP\Tools\Builders\{Avada_Cache, Avada_Content, Detect_Builder, Get_Builder_Content, Update_Builder_Content};

/**
 * End to end through the builder dispatcher pair for Avada (Fusion Builder):
 * detection, the tree read, each element operation, exact rollback of
 * post_content and the fusion_builder_status flag, and invalidation of
 * Avada's per-page dynamic CSS cache after writes and rollbacks.
 *
 * Avada is a commercial theme and builder that is not installed here, so its
 * layout is written as the builder stores it, the cache rows are seeded the
 * way its dynamic CSS class keeps them (a `fusion_dynamic_css_<post id>`
 * transient, and the `fusion_dynamic_css_posts` / `fusion_dynamic_css_ids`
 * options keyed by post id), and the plugin's presence is toggled through
 * the wpmcp_avada_active filter.
 */
class AvadaAdapterTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        remove_all_filters('wpmcp_avada_active');
        delete_option('fusion_dynamic_css_posts');
        delete_option('fusion_dynamic_css_ids');
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

    private function avada_page(): int
    {
        return $this->page(AvadaShortcodesTest::FIXTURE, [
            'fusion_builder_status' => 'active',
            '_fusion'               => ['main_padding' => ['top' => '0px']],
        ]);
    }

    /** Seed the dynamic CSS cache as Avada leaves it once a page's CSS is compiled. */
    private function compiled_cache(int $post_id): void
    {
        set_transient('fusion_dynamic_css_' . $post_id, '/* compiled */');
        update_option('fusion_dynamic_css_posts', ['global' => true, $post_id => true, 999999 => true]);
        update_option('fusion_dynamic_css_ids', ['global' => 'abc', $post_id => 'd41d8cd98f00b204e9800998ecf8427e', 999999 => 'fff']);
    }

    private function assert_cache_invalidated(int $post_id): void
    {
        $this->assertFalse(get_transient('fusion_dynamic_css_' . $post_id));
        $this->assertSame(['global' => true, $post_id => false, 999999 => true], get_option('fusion_dynamic_css_posts'));
        $this->assertSame(['global' => 'abc', $post_id => false, 999999 => 'fff'], get_option('fusion_dynamic_css_ids'));
    }

    private function update(int $post_id, array $args)
    {
        return (new Update_Builder_Content())->handle(['post_id' => $post_id, 'builder' => 'avada'] + $args);
    }

    private function state(int $post_id): array
    {
        return [get_post($post_id)->post_content, get_post_meta($post_id)];
    }

    private function tree(int $post_id): array
    {
        return (new Get_Builder_Content())->handle(['post_id' => $post_id])['tree'];
    }

    // ------------------------------------------------------------ detection

    public function test_detects_avada_from_the_builder_flag_and_from_content_alone(): void
    {
        $flagged = $this->page('', ['fusion_builder_status' => 'active']);
        $content = $this->page('[fusion_builder_container type="flex"][fusion_builder_row][/fusion_builder_row][/fusion_builder_container]');

        foreach ([$flagged, $content] as $post_id) {
            $this->assertSame('avada', (new Detect_Builder())->handle(['post_id' => $post_id])['builder']);
        }
    }

    public function test_other_builders_win_and_lookalikes_do_not_match(): void
    {
        $elementor = $this->page('', ['_elementor_edit_mode' => 'builder', 'fusion_builder_status' => 'active']);
        $wpbakery  = $this->page('[vc_row][/vc_row]', ['_wpb_vc_js_status' => 'true', 'fusion_builder_status' => 'active']);
        $gutenberg = $this->page('<!-- wp:paragraph --><p>[fusion_builder_container]</p><!-- /wp:paragraph -->');
        $inactive  = $this->page('<p>Plain</p>', ['fusion_builder_status' => 'inactive']);
        $lookalike = $this->page('<p>[fusion_builder_containers] is not a container</p>');

        $this->assertSame('elementor', (new Detect_Builder())->handle(['post_id' => $elementor])['builder']);
        $this->assertSame('wpbakery', (new Detect_Builder())->handle(['post_id' => $wpbakery])['builder']);
        $this->assertSame('gutenberg', (new Detect_Builder())->handle(['post_id' => $gutenberg])['builder']);
        $this->assertSame('classic', (new Detect_Builder())->handle(['post_id' => $inactive])['builder']);
        $this->assertSame('classic', (new Detect_Builder())->handle(['post_id' => $lookalike])['builder']);
    }

    public function test_detection_is_the_same_whether_or_not_the_plugin_is_active(): void
    {
        $post_id = $this->avada_page();

        foreach ([false, true] as $active) {
            add_filter('wpmcp_avada_active', $active ? '__return_true' : '__return_false');
            $this->assertSame($active, Avada_Content::plugin_active());
            $this->assertSame('avada', (new Detect_Builder())->handle(['post_id' => $post_id])['builder']);
            remove_all_filters('wpmcp_avada_active');
        }
    }

    public function test_plugin_is_reported_inactive_in_this_environment(): void
    {
        $this->assertFalse(Avada_Content::plugin_active());
    }

    // ----------------------------------------------------------------- read

    public function test_read_returns_tree_content_flag_and_plugin_state(): void
    {
        add_filter('wpmcp_avada_active', '__return_true');
        $post_id = $this->avada_page();

        $out = (new Get_Builder_Content())->handle(['post_id' => $post_id]);

        $this->assertSame('avada', $out['builder']);
        $this->assertSame($post_id, $out['post_id']);
        $this->assertTrue($out['plugin_active']);
        $this->assertSame(AvadaShortcodesTest::FIXTURE, $out['content']);
        $this->assertSame('active', $out['builder_status']);
        $this->assertSame('fusion_builder_container', $out['tree'][0]['tag']);
        $this->assertSame('fusion_text', $out['tree'][0]['children'][0]['children'][0]['children'][1]['tag']);
    }

    // ---------------------------------------------------------- round trips

    public function test_update_round_trip_invalidates_the_css_cache_and_rolls_back_exactly(): void
    {
        $post_id = $this->avada_page();
        $this->compiled_cache($post_id);
        $before = $this->state($post_id);

        $out = $this->update($post_id, ['operation' => 'update', 'path' => '0.0.0.1', 'text' => '<p>Rewritten \\ copy</p>']);

        $this->assertSame('avada', $out['builder']);
        $this->assertSame('0.0.0.1', $out['path']);
        $this->assertSame('<p>Rewritten \\ copy</p>', $this->tree($post_id)[0]['children'][0]['children'][0]['children'][1]['text']);
        $this->assertStringContainsString(AvadaShortcodesTest::UNKNOWN, get_post($post_id)->post_content);
        $this->assert_cache_invalidated($post_id);

        $this->compiled_cache($post_id);
        Rollback_Service::restore_operation($out['operation_id']);

        $this->assertSame($before, $this->state($post_id));
        $this->assert_cache_invalidated($post_id);
    }

    public function test_attribute_update_on_a_self_closed_element_keeps_its_form(): void
    {
        $post_id = $this->avada_page();

        $this->update($post_id, ['operation' => 'update', 'path' => '0.0.0.3', 'attrs' => ['alignment' => 'left']]);

        $this->assertSame(
            str_replace('alignment="center" /]', 'alignment="left" /]', AvadaShortcodesTest::FIXTURE),
            get_post($post_id)->post_content
        );
    }

    public function test_add_remove_and_move_each_roll_back_exactly(): void
    {
        $post_id = $this->avada_page();
        $before  = $this->state($post_id);

        $add = $this->update($post_id, ['operation' => 'add', 'to' => '0.0.0', 'index' => 0, 'element' => ['tag' => 'fusion_text', 'text' => '<p>First</p>']]);
        $this->assertSame('0.0.0.0', $add['path']);
        $this->assertSame('<p>First</p>', $this->tree($post_id)[0]['children'][0]['children'][0]['children'][0]['text']);
        Rollback_Service::restore_operation($add['operation_id']);
        $this->assertSame($before, $this->state($post_id));

        $remove = $this->update($post_id, ['operation' => 'remove', 'path' => '1.0.0.2']);
        $this->assertArrayNotHasKey('path', $remove);
        $this->assertStringNotContainsString('acme_widget', get_post($post_id)->post_content);
        Rollback_Service::restore_operation($remove['operation_id']);
        $this->assertSame($before, $this->state($post_id));

        $move = $this->update($post_id, ['operation' => 'move', 'path' => '1', 'to' => '', 'index' => 0]);
        $this->assertSame('fusion_tabs', $this->tree($post_id)[0]['children'][0]['children'][0]['children'][0]['tag']);
        Rollback_Service::restore_operation($move['operation_id']);
        $this->assertSame($before, $this->state($post_id));
    }

    public function test_first_write_to_a_blank_page_sets_the_flag_and_rollback_removes_it(): void
    {
        $post_id = $this->page('');
        $this->compiled_cache($post_id);
        $before = $this->state($post_id);

        $out = $this->update($post_id, ['operation' => 'add', 'to' => '', 'element' => [
            'tag'      => 'fusion_builder_container',
            'attrs'    => ['type' => 'flex'],
            'children' => [['tag' => 'fusion_builder_row', 'children' => [['tag' => 'fusion_builder_column', 'attrs' => ['type' => '1_1']]]]],
        ]]);

        $this->assertSame('[fusion_builder_container type="flex"][fusion_builder_row][fusion_builder_column type="1_1"][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]', get_post($post_id)->post_content);
        $this->assertSame('active', get_post_meta($post_id, 'fusion_builder_status', true));
        $this->assertSame('avada', (new Detect_Builder())->handle(['post_id' => $post_id])['builder']);
        $this->assert_cache_invalidated($post_id);

        $this->compiled_cache($post_id);
        Rollback_Service::restore_operation($out['operation_id']);

        $this->assertSame($before, $this->state($post_id));
        $this->assertSame('', get_post_meta($post_id, 'fusion_builder_status', true));
        $this->assert_cache_invalidated($post_id);
    }

    public function test_whole_content_replace_keeps_an_existing_flag_and_rolls_back(): void
    {
        $post_id = $this->page('[fusion_builder_container][/fusion_builder_container]', ['fusion_builder_status' => 'inactive']);
        $before  = $this->state($post_id);
        $content = '[fusion_builder_container type="flex"][fusion_builder_row][fusion_builder_column type="1_1"][fusion_text]Hi[/fusion_text][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]';

        $out = $this->update($post_id, ['content' => $content]);

        $this->assertSame('avada', $out['builder']);
        $this->assertSame($content, get_post($post_id)->post_content);
        $this->assertSame('inactive', get_post_meta($post_id, 'fusion_builder_status', true));

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($post_id));
    }

    // ---------------------------------------------------------------- cache

    public function test_cache_invalidation_touches_only_that_page_and_creates_nothing(): void
    {
        $post_id = $this->avada_page();

        Avada_Cache::invalidate($post_id);
        $this->assertFalse(get_option('fusion_dynamic_css_posts'));
        $this->assertFalse(get_option('fusion_dynamic_css_ids'));

        $this->compiled_cache($post_id);
        Avada_Cache::invalidate($post_id);
        $this->assert_cache_invalidated($post_id);
    }

    public function test_rollback_of_a_non_avada_page_leaves_the_cache_alone(): void
    {
        $post_id = $this->page('<p>Plain</p>');
        $op      = (new Update_Builder_Content())->handle(['post_id' => $post_id, 'builder' => 'divi', 'content' => '[et_pb_section][/et_pb_section]']);
        $this->compiled_cache($post_id);

        Rollback_Service::restore_operation($op['operation_id']);

        $this->assertSame('/* compiled */', get_transient('fusion_dynamic_css_' . $post_id));
        $this->assertTrue(get_option('fusion_dynamic_css_posts')[ $post_id ]);
    }

    // --------------------------------------------------------------- errors

    public function test_rejected_requests_write_nothing(): void
    {
        $post_id  = $this->avada_page();
        $before   = $this->state($post_id);
        $wpbakery = $this->page('[vc_row][/vc_row]', ['_wpb_vc_js_status' => 'true']);

        $cases = [
            [$post_id, [], 'invalid_avada_content'],
            [$post_id, ['content' => ['x']], 'invalid_avada_content'],
            [$post_id, ['operation' => 'explode'], 'invalid_avada_operation'],
            [$post_id, ['operation' => 'update', 'path' => '0', 'attrs' => 'x'], 'invalid_avada_operation'],
            [$post_id, ['operation' => 'update', 'path' => '0.0.0.1', 'text' => 5], 'invalid_avada_operation'],
            [$post_id, ['operation' => 'update', 'path' => '0.0.0.3', 'text' => 'x'], 'invalid_avada_operation'],
            [$post_id, ['operation' => 'add', 'to' => '0'], 'invalid_avada_operation'],
            [$post_id, ['operation' => 'remove', 'path' => '7'], 'invalid_avada_operation'],
            [$post_id, ['operation' => 'move', 'path' => '0', 'to' => '0.0'], 'invalid_avada_operation'],
            [$wpbakery, ['operation' => 'remove', 'path' => '0'], 'unsupported_builder'],
            [$wpbakery, ['content' => '[fusion_builder_container][/fusion_builder_container]'], 'unsupported_builder'],
        ];
        foreach ($cases as [$id, $args, $code]) {
            $out = $this->update($id, $args);
            $this->assertInstanceOf(\WP_Error::class, $out);
            $this->assertSame($code, $out->get_error_code(), wp_json_encode($args));
        }

        // A WPBakery write is refused on an Avada page too.
        $cross = (new Update_Builder_Content())->handle(['post_id' => $post_id, 'builder' => 'wpbakery', 'operation' => 'remove', 'path' => '0']);
        $this->assertSame('unsupported_builder', $cross->get_error_code());

        $this->assertSame($before, $this->state($post_id));
    }
}
