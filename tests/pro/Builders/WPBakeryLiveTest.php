<?php

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Pro\Gate;
use WPMCP\Safety\{Rollback_Service, Snapshot_Store};
use WPMCP\Tools\Builders\{Detect_Builder, Get_Builder_Content, Update_Builder_Content};

/**
 * Issue #639: the WPBakery adapter against a real WPBakery Page Builder.
 *
 * It runs only in the local gate's live WPBakery leg
 * (bin/test-local.sh --live-wpbakery), which sets WPMCP_LIVE_WPBAKERY=1,
 * copies the plugin from WPMCP_WPBAKERY_SRC onto a separate WordPress
 * install (it is not on wordpress.org) and loads it in tests/bootstrap.php.
 *
 * The element catalog and built-in templates must come from the real
 * plugin; a page edited here must render through its own shortcodes; a
 * template saved here must be one its templates panel lists; and every
 * write must roll back exactly.
 *
 * Everywhere else WPBakery is absent and the test is skipped; the live leg
 * runs with --fail-on-skipped so it cannot pass vacuously.
 *
 * @group wpbakery-live
 */
class WPBakeryLiveTest extends \WP_UnitTestCase
{
    private const PAGE = '[vc_row][vc_column][vc_column_text]<p>Live text</p>[/vc_column_text][/vc_column][/vc_row]';

    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('WPB_VC_VERSION') || ! class_exists('WPBMap') || ! function_exists('wpbakery')) {
            $this->markTestSkipped('WPBakery Page Builder is not loaded; run bin/test-local.sh --live-wpbakery with WPMCP_WPBAKERY_SRC set.');
        }
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        delete_option('wpb_js_templates');
        // A front-end request registers the element shortcodes on
        // template_redirect; rendering here does the same.
        \WPBMap::addAllMappedShortcodes();
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        delete_option('wpb_js_templates');
        parent::tearDown();
    }

    private function page(string $content, string $status = 'publish'): int
    {
        return self::factory()->post->create(['post_type' => 'page', 'post_status' => $status, 'post_content' => wp_slash($content)]);
    }

    private function update(array $args)
    {
        // Page writes need the page's current hash (issue #639).
        if (isset($args['post_id']) && ! isset($args['expected_hash'])) {
            $args['expected_hash'] = hash('sha256', (string) get_post($args['post_id'])->post_content);
        }

        return (new Update_Builder_Content())->handle(['builder' => 'wpbakery'] + $args);
    }

    private function scope(string $scope, array $args = [])
    {
        return (new Get_Builder_Content())->handle(['scope' => $scope, 'builder' => 'wpbakery'] + $args);
    }

    public function test_catalog_comes_from_the_real_element_map(): void
    {
        $catalog = $this->scope('catalog');

        $this->assertSame(WPB_VC_VERSION, $catalog['plugin_version']);
        $tags = array_column($catalog['elements'], 'tag');
        foreach (['vc_row', 'vc_column', 'vc_column_text', 'vc_single_image', 'vc_tta_tabs'] as $tag) {
            $this->assertContains($tag, $tags);
        }

        $text = $this->scope('catalog', ['element' => 'vc_column_text']);
        $this->assertSame('Text block', $text['element']['name']);
        $this->assertContains('content', array_column($text['element']['params'], 'param_name'));
    }

    public function test_page_read_edit_render_and_rollback(): void
    {
        $post_id = $this->page(self::PAGE);
        $this->assertSame('wpbakery', (new Detect_Builder())->handle(['post_id' => $post_id])['builder']);

        $read = (new Get_Builder_Content())->handle(['post_id' => $post_id]);
        $this->assertTrue($read['plugin_active']);
        $this->assertSame(WPB_VC_VERSION, $read['plugin_version']);

        $out = $this->update([
            'post_id'       => $post_id,
            'operation'     => 'update',
            'path'          => '0.0.0',
            'text'          => '<p>Edited live</p>',
            'expected_hash' => $read['hash'],
        ]);
        $this->assertIsArray($out);

        $html = do_shortcode(get_post($post_id)->post_content);
        $this->assertStringContainsString('Edited live', $html);
        $this->assertStringContainsString('vc_row', $html);
        $this->assertStringNotContainsString('[vc_', $html);

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame(self::PAGE, get_post($post_id)->post_content);
    }

    public function test_saved_template_is_listed_by_the_plugin_and_applies_to_a_page(): void
    {
        $add = $this->update([
            'scope'         => 'templates',
            'operation'     => 'add',
            'element'       => ['name' => 'Live hero', 'content' => self::PAGE],
            'expected_hash' => $this->scope('templates')['hash'],
        ]);
        $this->assertIsArray($add);

        $panel = wpbakery()->templatesPanelEditor()->getUserTemplates();
        $this->assertSame(['name' => 'Live hero', 'template' => self::PAGE], $panel[ $add['path'] ]);

        $post_id = $this->page('[vc_row][vc_column][/vc_column][/vc_row]');
        $apply   = $this->update(['post_id' => $post_id, 'operation' => 'add', 'to' => '', 'element' => ['template' => $add['path']]]);
        $this->assertIsArray($apply);
        $this->assertStringContainsString('Live text', do_shortcode(get_post($post_id)->post_content));

        Rollback_Service::restore_operation($apply['operation_id']);
        Rollback_Service::restore_operation($add['operation_id']);
        $this->assertSame('[vc_row][vc_column][/vc_column][/vc_row]', get_post($post_id)->post_content);
        $this->assertFalse(get_option('wpb_js_templates'));
    }

    public function test_built_in_template_applies_and_publish_rolls_back(): void
    {
        $templates = $this->scope('templates');
        $this->assertNotEmpty($templates['defaults']);
        $this->assertSame('default:0', $templates['defaults'][0]['id']);

        $post_id = $this->page('', 'draft');
        $apply   = $this->update(['post_id' => $post_id, 'operation' => 'add', 'to' => '', 'element' => ['template' => 'default:0']]);
        $this->assertIsArray($apply);
        $default = wpbakery()->templatesPanelEditor()->getDefaultTemplate(0);
        $this->assertSame(trim($default['content']), trim(get_post($post_id)->post_content));

        $publish = $this->update(['post_id' => $post_id, 'operation' => 'publish']);
        $this->assertIsArray($publish);
        $this->assertSame('publish', get_post_status($post_id));

        Rollback_Service::restore_operation($publish['operation_id']);
        $this->assertSame('draft', get_post_status($post_id));
    }

    public function test_design_system_reads_the_plugin_settings(): void
    {
        update_option('wpb_js_vc_color', '#abcdef');

        $read = $this->scope('design_system');

        $this->assertSame('#abcdef', $read['colors']['vc_color']);
        $this->assertSame('#abcdef', vc_settings()->get('vc_color'));
    }
}
