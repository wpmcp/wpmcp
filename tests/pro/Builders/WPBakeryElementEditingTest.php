<?php

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Pro\Gate;
use WPMCP\Safety\{Rollback_Service, Snapshot_Store};
use WPMCP\Tools\Builders\{Get_Builder_Content, Update_Builder_Content, WPBakery_Shortcodes};

/**
 * Issue #639: WPBakery element-level editing beyond the four element
 * operations, through the existing builder read/write pair. The read gains
 * the content hash every write can be pinned to, the plugin version and the
 * page's revisions; the write gains expected_hash, publish, restore (a
 * revision), add from a saved template, and a templates scope that reads
 * and writes WPBakery's saved templates. Scope reads also cover the design
 * options and the element catalog.
 *
 * WPBakery is a commercial plugin that is not installed here: pages are
 * written as its editor stores them, its saved templates as its templates
 * panel stores them (the wpb_js_templates option), and its element map comes
 * from a fixture recorded from WPBakery 9.1 through the wpmcp_wpbakery_map
 * filter. WPBakeryLiveTest runs the same paths against the real plugin.
 */
class WPBakeryElementEditingTest extends \WP_UnitTestCase
{
    private const TEMPLATES = 'wpb_js_templates';

    private const HERO = '[vc_row el_id="hero"][vc_column][vc_column_text]<p>Saved hero</p>[/vc_column_text][/vc_column][/vc_row]';

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        $this->admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->admin);
        delete_option(self::TEMPLATES);
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        remove_all_filters('wpmcp_wpbakery_active');
        remove_all_filters('wpmcp_wpbakery_version');
        remove_all_filters('wpmcp_wpbakery_map');
        delete_option(self::TEMPLATES);
        parent::tearDown();
    }

    private function page(string $content, string $status = 'publish'): int
    {
        $post_id = self::factory()->post->create([
            'post_type'    => 'page',
            'post_status'  => $status,
            'post_content' => wp_slash($content),
        ]);
        update_post_meta($post_id, '_wpb_vc_js_status', 'true');

        return $post_id;
    }

    private function read(int $post_id): array
    {
        return (new Get_Builder_Content())->handle(['post_id' => $post_id]);
    }

    private function update(int $post_id, array $args)
    {
        return (new Update_Builder_Content())->handle(['post_id' => $post_id, 'builder' => 'wpbakery'] + $args);
    }

    private function scope(string $scope, array $args = [])
    {
        return (new Get_Builder_Content())->handle(['scope' => $scope, 'builder' => 'wpbakery'] + $args);
    }

    private function write_templates(array $args)
    {
        return (new Update_Builder_Content())->handle(['scope' => 'templates', 'builder' => 'wpbakery'] + $args);
    }

    private function state(int $post_id): array
    {
        $post = get_post($post_id);

        return [$post->post_content, $post->post_status, get_post_meta($post_id)];
    }

    private function seed_templates(): void
    {
        add_option(self::TEMPLATES, [
            'hero_123' => ['name' => 'Hero', 'template' => self::HERO],
        ], '', false);
    }

    // ------------------------------------------------------- page read

    public function test_read_reports_the_content_hash_plugin_version_and_revisions(): void
    {
        $post_id = $this->page(WPBakeryShortcodesTest::FIXTURE);
        wp_update_post(['ID' => $post_id, 'post_content' => wp_slash(WPBakeryShortcodesTest::FIXTURE . '[vc_row][/vc_row]')]);

        $read = $this->read($post_id);

        $this->assertSame(hash('sha256', $read['content']), $read['hash']);
        $this->assertNull($read['plugin_version']);
        $this->assertNotEmpty($read['revisions']);
        $revision = $read['revisions'][0];
        $this->assertSame(['id', 'date_gmt', 'author', 'hash'], array_keys($revision));
        $this->assertSame($post_id, wp_is_post_revision($revision['id']));

        add_filter('wpmcp_wpbakery_version', static fn () => '9.1');
        $this->assertSame('9.1', $this->read($post_id)['plugin_version']);
    }

    // --------------------------------------------------- expected_hash

    public function test_a_stale_expected_hash_writes_nothing_and_a_current_one_writes(): void
    {
        $post_id = $this->page(WPBakeryShortcodesTest::FIXTURE);
        $before  = $this->state($post_id);
        $hash    = $this->read($post_id)['hash'];

        $stale = $this->update($post_id, ['operation' => 'update', 'path' => '0.0.0', 'text' => 'x', 'expected_hash' => str_repeat('0', 64)]);
        $this->assertWPError($stale);
        $this->assertSame('stale_expected_hash', $stale->get_error_code());
        $this->assertSame($before, $this->state($post_id));

        $out = $this->update($post_id, ['operation' => 'update', 'path' => '0.0.0', 'text' => '<p>New</p>', 'expected_hash' => $hash]);
        $this->assertIsArray($out);
        $this->assertSame($this->read($post_id)['hash'], $out['hash']);
        $this->assertNotSame($hash, $out['hash']);
    }

    // ------------------------------------------------------- publish

    public function test_publish_publishes_a_draft_and_rolls_back_to_draft(): void
    {
        $post_id = $this->page(WPBakeryShortcodesTest::FIXTURE, 'draft');
        $before  = $this->state($post_id);

        $out = $this->update($post_id, ['operation' => 'publish']);

        $this->assertIsArray($out);
        $this->assertSame('publish', get_post_status($post_id));
        $this->assertSame(WPBakeryShortcodesTest::FIXTURE, get_post($post_id)->post_content);

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($post_id));
    }

    public function test_publish_refuses_a_published_page_and_a_user_who_cannot_publish(): void
    {
        $published = $this->page(WPBakeryShortcodesTest::FIXTURE);
        $again     = $this->update($published, ['operation' => 'publish']);
        $this->assertWPError($again);
        $this->assertSame('already_published', $again->get_error_code());

        $draft = $this->page(WPBakeryShortcodesTest::FIXTURE, 'draft');
        $contributor = self::factory()->user->create(['role' => 'contributor']);
        wp_update_post(['ID' => $draft, 'post_author' => $contributor]);
        wp_set_current_user($contributor);

        $denied = $this->update($draft, ['operation' => 'publish']);
        $this->assertWPError($denied);
        $this->assertSame('forbidden', $denied->get_error_code());
        $this->assertSame('draft', get_post_status($draft));
    }

    // ------------------------------------------------------- restore

    public function test_restore_puts_a_revision_layout_back_recompiles_css_and_rolls_back(): void
    {
        $old     = '[vc_row css=".vc_custom_1600000000009{padding-top: 9px !important;}"][vc_column][vc_column_text]Old[/vc_column_text][/vc_column][/vc_row]';
        $post_id = $this->page('[vc_row][/vc_row]');
        wp_update_post(['ID' => $post_id, 'post_content' => wp_slash($old)]);
        update_post_meta($post_id, '_wpb_shortcodes_custom_css', '.vc_custom_1600000000009{padding-top: 9px !important;}');
        wp_update_post(['ID' => $post_id, 'post_content' => wp_slash('[vc_row][vc_column][vc_column_text]New[/vc_column_text][/vc_column][/vc_row]')]);
        delete_post_meta($post_id, '_wpb_shortcodes_custom_css');

        $revision = null;
        foreach ($this->read($post_id)['revisions'] as $candidate) {
            if (get_post($candidate['id'])->post_content === $old) {
                $revision = $candidate['id'];
            }
        }
        $this->assertNotNull($revision);
        $before = $this->state($post_id);

        $out = $this->update($post_id, ['operation' => 'restore', 'path' => (string) $revision]);

        $this->assertIsArray($out);
        $this->assertSame($old, get_post($post_id)->post_content);
        $this->assertSame('.vc_custom_1600000000009{padding-top: 9px !important;}', get_post_meta($post_id, '_wpb_shortcodes_custom_css', true));

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($post_id));
    }

    public function test_restore_refuses_a_revision_of_another_post(): void
    {
        $post_id = $this->page('[vc_row][/vc_row]');
        $other   = $this->page('[vc_row][/vc_row]');
        wp_update_post(['ID' => $other, 'post_content' => '[vc_section][/vc_section]']);
        $foreign = array_key_first(wp_get_post_revisions($other));
        $before  = $this->state($post_id);

        foreach ([(string) $foreign, '999999', 'abc', ''] as $path) {
            $out = $this->update($post_id, ['operation' => 'restore', 'path' => $path]);
            $this->assertWPError($out, $path);
            $this->assertSame('invalid_wpbakery_operation', $out->get_error_code());
        }
        $this->assertSame($before, $this->state($post_id));
    }

    // ------------------------------------------------ apply a template

    public function test_add_from_a_saved_template_inserts_it_byte_for_byte_and_rolls_back(): void
    {
        $this->seed_templates();
        $post_id = $this->page(WPBakeryShortcodesTest::FIXTURE);
        $before  = $this->state($post_id);

        $out = $this->update($post_id, ['operation' => 'add', 'to' => '', 'index' => 1, 'element' => ['template' => 'hero_123']]);

        $this->assertIsArray($out);
        $this->assertSame('1', $out['path']);
        $content = get_post($post_id)->post_content;
        $this->assertStringContainsString(self::HERO, $content);
        $tree = WPBakery_Shortcodes::tree($content);
        $this->assertCount(3, $tree);
        $this->assertSame('hero', $tree[1]['attrs']['el_id']);
        $this->assertSame(WPBakeryShortcodesTest::FIXTURE, str_replace(self::HERO, '', $content));

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($post_id));
    }

    public function test_add_from_an_unknown_template_or_into_a_leaf_writes_nothing(): void
    {
        $this->seed_templates();
        $post_id = $this->page(WPBakeryShortcodesTest::FIXTURE);
        $before  = $this->state($post_id);

        $unknown = $this->update($post_id, ['operation' => 'add', 'to' => '', 'element' => ['template' => 'nope']]);
        $this->assertWPError($unknown);
        $this->assertSame('invalid_wpbakery_operation', $unknown->get_error_code());

        $default = $this->update($post_id, ['operation' => 'add', 'to' => '', 'element' => ['template' => 'default:0']]);
        $this->assertWPError($default, 'default templates come from the plugin, which is not loaded here');

        $leaf = $this->update($post_id, ['operation' => 'add', 'to' => '0.0.0', 'element' => ['template' => 'hero_123']]);
        $this->assertWPError($leaf);

        $this->assertSame($before, $this->state($post_id));
    }

    // ------------------------------------------------ templates scope

    public function test_templates_scope_lists_saved_templates_with_trees_and_a_hash(): void
    {
        $empty = $this->scope('templates');
        $this->assertSame([], $empty['templates']);
        $this->assertSame([], $empty['defaults']);
        $this->assertIsString($empty['hash']);

        $this->seed_templates();
        $read = $this->scope('templates');

        $this->assertSame('wpbakery', $read['builder']);
        $this->assertSame('templates', $read['scope']);
        $this->assertSame('hero_123', $read['templates'][0]['id']);
        $this->assertSame('Hero', $read['templates'][0]['name']);
        $this->assertSame(self::HERO, $read['templates'][0]['content']);
        $this->assertSame('vc_row', $read['templates'][0]['tree'][0]['tag']);
        $this->assertNotSame($empty['hash'], $read['hash']);
    }

    public function test_template_add_needs_the_current_hash_and_rolls_back_to_no_option(): void
    {
        $hash = $this->scope('templates')['hash'];

        $missing = $this->write_templates(['operation' => 'add', 'element' => ['name' => 'Hero', 'content' => self::HERO]]);
        $this->assertWPError($missing);
        $this->assertSame('missing_expected_hash', $missing->get_error_code());

        $stale = $this->write_templates(['operation' => 'add', 'element' => ['name' => 'Hero', 'content' => self::HERO], 'expected_hash' => str_repeat('a', 64)]);
        $this->assertWPError($stale);
        $this->assertSame('stale_expected_hash', $stale->get_error_code());
        $this->assertFalse(get_option(self::TEMPLATES));

        $out = $this->write_templates(['operation' => 'add', 'element' => ['name' => '  My hero ', 'content' => self::HERO], 'expected_hash' => $hash]);

        $this->assertIsArray($out);
        $this->assertMatchesRegularExpression('/^my-hero_\d+$/', $out['path']);
        $stored = get_option(self::TEMPLATES);
        $this->assertSame(['name' => 'My hero', 'template' => self::HERO], $stored[ $out['path'] ]);
        $this->assertSame($this->scope('templates')['hash'], $out['hash']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertFalse(get_option(self::TEMPLATES));
    }

    public function test_template_add_from_a_page_element_copies_its_markup_exactly(): void
    {
        $post_id = $this->page(WPBakeryShortcodesTest::FIXTURE);
        $hash    = $this->scope('templates')['hash'];

        $out = $this->write_templates(['operation' => 'add', 'post_id' => $post_id, 'path' => '1', 'element' => ['name' => 'Tabs'], 'expected_hash' => $hash]);

        $this->assertIsArray($out);
        $tabs = '[vc_row][vc_column][vc_tta_tabs][vc_tta_section title="One" tab_id="t1"][vc_column_text]A[/vc_column_text][/vc_tta_section]'
            . '[vc_tta_section title="Two" tab_id="t2"][/vc_tta_section][/vc_tta_tabs][/vc_column][/vc_row]';
        $this->assertSame($tabs, get_option(self::TEMPLATES)[ $out['path'] ]['template']);
        $this->assertSame(WPBakeryShortcodesTest::FIXTURE, get_post($post_id)->post_content);

        $whole = $this->write_templates(['operation' => 'add', 'post_id' => $post_id, 'element' => ['name' => 'Page'], 'expected_hash' => $out['hash']]);
        $this->assertSame(WPBakeryShortcodesTest::FIXTURE, get_option(self::TEMPLATES)[ $whole['path'] ]['template']);
    }

    public function test_template_update_and_remove_each_roll_back_exactly(): void
    {
        $this->seed_templates();
        $before = get_option(self::TEMPLATES);

        $update = $this->write_templates(['operation' => 'update', 'path' => 'hero_123', 'element' => ['name' => 'Big hero'], 'expected_hash' => $this->scope('templates')['hash']]);
        $this->assertIsArray($update);
        $this->assertSame(['name' => 'Big hero', 'template' => self::HERO], get_option(self::TEMPLATES)['hero_123']);
        Rollback_Service::restore_operation($update['operation_id']);
        $this->assertSame($before, get_option(self::TEMPLATES));

        $remove = $this->write_templates(['operation' => 'remove', 'path' => 'hero_123', 'expected_hash' => $this->scope('templates')['hash']]);
        $this->assertIsArray($remove);
        $this->assertArrayNotHasKey('hero_123', (array) get_option(self::TEMPLATES));
        Rollback_Service::restore_operation($remove['operation_id']);
        $this->assertSame($before, get_option(self::TEMPLATES));
    }

    public function test_bad_template_requests_write_nothing(): void
    {
        $this->seed_templates();
        $before = get_option(self::TEMPLATES);
        $hash   = $this->scope('templates')['hash'];

        $cases = [
            ['operation' => 'add', 'element' => ['name' => 'Empty', 'content' => '  ']],
            ['operation' => 'add', 'element' => ['name' => 'No source']],
            ['operation' => 'update', 'path' => 'nope', 'element' => ['name' => 'x']],
            ['operation' => 'update', 'path' => 'hero_123', 'element' => []],
            ['operation' => 'remove', 'path' => 'nope'],
            ['operation' => 'move', 'path' => 'hero_123'],
        ];
        foreach ($cases as $case) {
            $out = $this->write_templates($case + ['expected_hash' => $hash]);
            $this->assertWPError($out, wp_json_encode($case));
        }
        $this->assertSame($before, get_option(self::TEMPLATES));

        $other = (new Update_Builder_Content())->handle(['scope' => 'templates', 'builder' => 'bricks', 'operation' => 'remove', 'path' => 'x']);
        $this->assertWPError($other);
        $this->assertSame('invalid_scope', $other->get_error_code());
    }

    // ------------------------------------------ catalog and design system

    public function test_catalog_needs_the_plugin_and_reads_its_element_map(): void
    {
        $off = $this->scope('catalog');
        $this->assertWPError($off);
        $this->assertSame('builder_not_loaded', $off->get_error_code());

        $map = require dirname(__DIR__, 2) . '/support/wpbakery-map.php';
        add_filter('wpmcp_wpbakery_map', static fn () => $map);

        $list = $this->scope('catalog');
        $this->assertSame(['vc_btn', 'vc_column_text', 'vc_row'], array_column($list['elements'], 'tag'));
        $row = $list['elements'][2];
        $this->assertSame(['tag' => 'vc_row', 'name' => 'Row', 'category' => 'Content', 'description' => 'Place content elements inside the row', 'container' => true, 'deprecated' => false], $row);
        $this->assertTrue($list['elements'][0]['deprecated']);

        $btn = $this->scope('catalog', ['element' => 'vc_btn']);
        $this->assertSame('vc_btn', $btn['element']['tag']);
        $this->assertSame(['title', 'link', 'color'], array_column($btn['element']['params'], 'param_name'));

        $missing = $this->scope('catalog', ['element' => 'vc_nope']);
        $this->assertWPError($missing);
        $this->assertSame('element_not_found', $missing->get_error_code());
    }

    public function test_design_system_reads_the_design_options_and_custom_css(): void
    {
        update_option('wpb_js_use_custom', '1');
        update_option('wpb_js_vc_color', '#123456');
        update_option('wpb_js_margin', '20px');
        update_option('wpb_js_custom_css', '.x{color:red}');

        $read = $this->scope('design_system');

        $this->assertSame('wpbakery', $read['builder']);
        $this->assertTrue($read['use_custom']);
        $this->assertSame('#123456', $read['colors']['vc_color']);
        $this->assertSame('20px', $read['layout']['margin']);
        $this->assertSame('.x{color:red}', $read['custom_css']);
    }

    public function test_scope_reads_detect_wpbakery_from_a_post(): void
    {
        $post_id = $this->page(WPBakeryShortcodesTest::FIXTURE);

        $read = (new Get_Builder_Content())->handle(['scope' => 'templates', 'post_id' => $post_id]);

        $this->assertSame('wpbakery', $read['builder']);
    }
}
