<?php

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Pro\Gate;
use WPMCP\Safety\{Rollback_Service, Snapshot_Store};
use WPMCP\Tools\Builders\{Builder_Design_Data, Detect_Builder, Get_Builder_Content, Update_Builder_Content};

/**
 * Issue #637: the Avada adapter against the real Avada theme, Avada Core and
 * Avada Builder.
 *
 * It runs only in the local gate's live Avada leg (bin/test-local.sh
 * --live-avada), which sets WPMCP_LIVE_AVADA=1, copies the Avada theme and
 * the fusion-builder and fusion-core plugins from WPMCP_AVADA_SRC onto a
 * separate WordPress install (none of them is on wordpress.org), loads both
 * plugins and makes Avada the active theme in tests/bootstrap.php.
 *
 * Element edits and library inserts must leave a page the real Avada
 * Builder renders, the element catalog must come from its element map, its
 * library categories and global options must be the ones it reads, and
 * every write must roll back exactly.
 *
 * Everywhere else Avada is absent and the test is skipped; the live leg runs
 * with --fail-on-skipped so it cannot pass vacuously.
 *
 * @group avada-live
 */
class AvadaLiveTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('FUSION_BUILDER_VERSION') || ! function_exists('AWB_Global_Colors') || 'Avada' !== get_template()) {
            $this->markTestSkipped('Needs the real Avada (bin/test-local.sh --live-avada, WPMCP_AVADA_SRC).');
        }
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        delete_option('fusion_options');
        parent::tearDown();
    }

    /** The page as the front end renders it, through Avada Builder's shortcodes. */
    private function render(int $post_id): string
    {
        $GLOBALS['post'] = get_post($post_id);
        setup_postdata($GLOBALS['post']);
        $html = do_shortcode(get_post($post_id)->post_content);
        wp_reset_postdata();

        return $html;
    }

    private function update(array $args)
    {
        $out = (new Update_Builder_Content())->handle(['builder' => 'avada'] + $args);
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_code() . ': ' . $out->get_error_message() : '');

        return $out;
    }

    public function test_element_edits_and_library_inserts_render_through_avada_and_roll_back(): void
    {
        $id = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish', 'post_content' => wp_slash(AvadaShortcodesTest::FIXTURE)]);
        update_post_meta($id, 'fusion_builder_status', 'active');
        $before = get_post($id)->post_content;

        $this->assertSame('avada', (new Detect_Builder())->handle(['post_id' => $id])['builder']);
        $read = (new Get_Builder_Content())->handle(['post_id' => $id]);
        $this->assertSame(FUSION_BUILDER_VERSION, $read['plugin_version']);
        $this->assertTrue($read['plugin_active']);
        $this->assertStringContainsString('Welcome', $this->render($id));

        $session = wp_generate_uuid4();
        $title   = $this->update(['post_id' => $id, 'operation' => 'update', 'path' => '0.0.0.0', 'text' => 'Edited through wpmcp', 'expected_hash' => $read['hash'], 'session_id' => $session]);
        $added   = $this->update(['post_id' => $id, 'operation' => 'add', 'to' => '0.0.0', 'index' => 1, 'element' => ['tag' => 'fusion_text', 'text' => '<p>Added paragraph</p>'], 'expected_hash' => $title['hash'], 'session_id' => $session]);

        // A column saved to the library through wpmcp, filed where Avada's own library lists columns.
        $saved = $this->update(['post_id' => $id, 'operation' => 'save_template', 'path' => '0.0.0', 'element' => ['title' => 'Saved column'], 'session_id' => $session]);
        $listed = get_posts(['post_type' => 'fusion_element', 'tax_query' => [['taxonomy' => 'element_category', 'field' => 'slug', 'terms' => 'columns']], 'fields' => 'ids']);
        $this->assertContains($saved['template_id'], $listed);

        $this->update(['post_id' => $id, 'operation' => 'add', 'to' => '1.0', 'element' => ['template' => $saved['template_id']], 'expected_hash' => $added['hash'], 'session_id' => $session]);

        $html = $this->render($id);
        $this->assertStringContainsString('Edited through wpmcp', $html);
        $this->assertStringContainsString('Added paragraph', $html);
        $this->assertStringNotContainsString('>Welcome<', $html);
        // The saved column renders twice: in place and where it was added.
        $this->assertSame(2, substr_count($html, 'Added paragraph'));
        $this->assertStringNotContainsString('[fusion_', $html);

        Rollback_Service::restore_session($session);

        $this->assertSame($before, get_post($id)->post_content);
        $this->assertSame('trash', get_post_status($saved['template_id']));
        $this->assertStringContainsString('Welcome', $this->render($id));
    }

    public function test_catalog_comes_from_avadas_element_map(): void
    {
        $list = (new Get_Builder_Content())->handle(['builder' => 'avada', 'scope' => 'catalog']);
        $this->assertIsArray($list, is_wp_error($list) ? $list->get_error_message() : '');
        $tags = array_column($list['elements'], 'tag');
        $this->assertGreaterThan(100, count($tags));
        $this->assertContains('fusion_separator', $tags);
        $this->assertSame(FUSION_BUILDER_VERSION, $list['plugin_version']);

        $one    = (new Get_Builder_Content())->handle(['builder' => 'avada', 'scope' => 'catalog', 'element' => 'fusion_title']);
        $params = array_column($one['element']['params'], null, 'param_name');
        $this->assertArrayHasKey('size', $params);
        $this->assertSame('Title', $one['element']['name']);
    }

    public function test_palette_write_is_what_avada_reads_and_rolls_back(): void
    {
        $read = (new Get_Builder_Content())->handle(['builder' => 'avada', 'scope' => 'design_system']);
        $this->assertArrayHasKey('color8', $read['palettes']);
        $before = get_option('fusion_options', 'absent');

        $out = $this->update(['scope' => 'design_system', 'operation' => 'add', 'to' => 'palettes', 'element' => ['color' => '#ab1234', 'label' => 'Brand'], 'expected_hash' => $read['hashes']['palettes']]);
        $this->assertSame('palettes/custom_color_1', $out['path']);

        // Stored under the option and key Avada's palette reads, with
        // Avada's own colors kept beside the new one.
        $this->assertSame('fusion_options', \Fusion_Settings::get_option_name());
        $palette = get_option('fusion_options')['color_palette'];
        $this->assertSame(['color' => '#ab1234', 'label' => 'Brand'], $palette['custom_color_1']);
        $this->assertArrayHasKey('color1', $palette);
        $this->assertSame(Builder_Design_Data::option_hash('fusion_options'), $out['hash']);

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, get_option('fusion_options', 'absent'));
    }

    public function test_layouts_avada_stores_are_read(): void
    {
        $header = self::factory()->post->create(['post_type' => 'fusion_tb_section', 'post_title' => 'Header', 'post_status' => 'publish', 'post_content' => '[fusion_builder_container][/fusion_builder_container]']);
        wp_set_object_terms($header, 'header', 'fusion_tb_category');
        // Stored the way Fusion_Template_Builder::update_layout_content() stores it.
        $layout = self::factory()->post->create(['post_type' => 'fusion_tb_layout', 'post_title' => 'Blog', 'post_status' => 'publish']);
        wp_update_post(['ID' => $layout, 'post_content' => wp_slash(str_replace("\\'", "'", wp_json_encode(['conditions' => ['archives' => ['label' => 'All Archives', 'type' => 'archives', 'mode' => 'include', 'archives' => 'archives']], 'template_terms' => ['header' => (string) $header]], JSON_UNESCAPED_UNICODE)))]);

        $out   = (new Get_Builder_Content())->handle(['builder' => 'avada', 'scope' => 'templates']);
        $by_id = array_column($out['templates'], null, 'id');

        $this->assertSame('header', $by_id[$header]['category']);
        $this->assertSame(['header' => (string) $header], $by_id[$layout]['template_terms']);
        // Avada reads the same document.
        $registered = \Fusion_Template_Builder::get_instance()->get_registered_layouts();
        $this->assertSame((string) $header, (string) $registered[$layout]['data']['template_terms']['header']);
    }
}
