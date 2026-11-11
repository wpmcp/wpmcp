<?php

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Pro\Gate;
use WPMCP\Safety\{Rollback_Service, Snapshot_Store};
use WPMCP\Tests\Support\Cornerstone_Engine_Double;
use WPMCP\Tools\Builders\{Builder_Detector, Cornerstone_Content, Cornerstone_Tree, Detect_Builder, Get_Builder_Content, Update_Builder_Content};

require_once __DIR__ . '/../../support/cornerstone-engine-double.php';

/**
 * Issue #646: Cornerstone (Themeco) pages on the builder pair, against rows
 * stored the way Cornerstone 7.8.12 stores them (the fixture is the
 * `_cornerstone_data` row the real plugin saved in the live leg, see
 * CornerstoneLiveTest) and an engine double standing in for its save
 * pipeline and element definitions. Detection, the read with its paths,
 * hash and revisions, the scope reads, the plugin_missing refusal, the
 * hash pin, every element operation with Cornerstone's placement rules,
 * publish, restore and template inserts; every write is undone here.
 */
class CornerstoneElementEditingTest extends \WP_UnitTestCase
{
    /** The row Cornerstone saved for a section > row > column > headline + text page. */
    public const DATA = '[{"_type":"section","_modules":[{"_type":"layout-row","_modules":[{"_type":"layout-column","_modules":[{"_type":"headline","text_content":"Hello live"},{"_type":"text","text_content":"<p>Body text</p>"}]}]}]}]';

    public const SETTINGS = '{"customCSS":".a{color:red}","customJS":"","layoutSingle":"default","layoutHeader":"default","layoutFooter":"default","responsive_text":[]}';

    private Cornerstone_Engine_Double $engine;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->engine = new Cornerstone_Engine_Double();
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        remove_all_filters('wpmcp_cornerstone_active');
        remove_all_filters('wpmcp_cornerstone_engine');
        parent::tearDown();
    }

    private function load(): void
    {
        add_filter('wpmcp_cornerstone_active', '__return_true');
        add_filter('wpmcp_cornerstone_engine', fn () => $this->engine);
    }

    private function page(string $data = self::DATA, string $status = 'publish'): int
    {
        $post_id = self::factory()->post->create([
            'post_type'    => 'page',
            'post_status'  => $status,
            'post_content' => '<!-- cs-content --><div class="x-section">Hello live</div><!-- cs-content-end -->',
        ]);
        update_post_meta($post_id, '_cornerstone_data', wp_slash($data));
        update_post_meta($post_id, '_cornerstone_settings', wp_slash(self::SETTINGS));

        return $post_id;
    }

    private function update(int $post_id, array $args)
    {
        if (! array_key_exists('expected_hash', $args)) {
            $args['expected_hash'] = hash('sha256', Cornerstone_Content::raw($post_id));
        }

        return (new Update_Builder_Content())->handle(['post_id' => $post_id, 'builder' => 'cornerstone'] + $args);
    }

    private function state(int $post_id): array
    {
        clean_post_cache($post_id);

        return [get_post($post_id)->post_status, get_post($post_id)->post_content, get_post_meta($post_id)];
    }

    private function assert_error(string $code, $out): void
    {
        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame($code, $out->get_error_code(), $out->get_error_message());
    }

    public function test_detects_a_cornerstone_page_as_cornerstone_does(): void
    {
        $saved = $this->page();
        $this->assertSame(['post_id' => $saved, 'builder' => 'cornerstone'], (new Detect_Builder())->handle(['post_id' => $saved]));

        $data_only = self::factory()->post->create(['post_type' => 'page', 'post_content' => '']);
        update_post_meta($data_only, '_cornerstone_data', wp_slash(self::DATA));
        $this->assertSame('cornerstone', Builder_Detector::detect($data_only));

        update_post_meta($data_only, '_cornerstone_override', '1');
        $this->assertSame('classic', Builder_Detector::detect($data_only), 'the owner went back to the WordPress editor');

        $empty = self::factory()->post->create(['post_type' => 'page', 'post_content' => '']);
        update_post_meta($empty, '_cornerstone_data', '[]');
        $this->assertSame('classic', Builder_Detector::detect($empty));
    }

    public function test_read_returns_elements_with_paths_hash_settings_and_revisions(): void
    {
        $post_id  = $this->page();
        $revision = (int) _wp_put_post_revision(get_post($post_id));
        add_metadata('post', $revision, '_cornerstone_data', wp_slash(self::DATA));
        $empty_revision = (int) _wp_put_post_revision(get_post($post_id));

        $out = (new Get_Builder_Content())->handle(['post_id' => $post_id]);

        $this->assertSame('cornerstone', $out['builder']);
        $this->assertFalse($out['plugin_active']);
        $this->assertNull($out['plugin_version']);
        $this->assertSame(hash('sha256', self::DATA), $out['hash']);
        $this->assertSame(json_decode(self::DATA, true), $out['content']);
        $this->assertSame(['custom_css' => '.a{color:red}', 'custom_js' => ''], $out['settings']);
        $this->assertSame('0.0.0.1', $out['tree'][0]['children'][0]['children'][0]['children'][1]['path']);
        $this->assertSame('text', $out['tree'][0]['children'][0]['children'][0]['children'][1]['type']);
        $this->assertSame([$revision], array_column($out['revisions'], 'id'));
        $this->assertNotContains($empty_revision, array_column($out['revisions'], 'id'));
    }

    public function test_writes_need_cornerstone_loaded_and_name_it(): void
    {
        $post_id = $this->page();
        $before  = $this->state($post_id);

        $out = $this->update($post_id, ['operation' => 'update', 'path' => '0', 'attrs' => ['_label' => 'Hero']]);

        $this->assert_error('plugin_missing', $out);
        $this->assertStringContainsString('Install and activate Cornerstone (Themeco)', $out->get_error_message());
        $this->assertSame('Cornerstone (Themeco)', $out->get_error_data()['plugin']);
        $this->assertSame($before, $this->state($post_id));
    }

    public function test_writes_to_a_page_with_a_layout_are_pinned_to_the_read_hash(): void
    {
        $this->load();
        $post_id = $this->page();

        $this->assert_error('missing_expected_hash', $this->update($post_id, ['operation' => 'remove', 'path' => '0', 'expected_hash' => '']));
        $this->assert_error('stale_expected_hash', $this->update($post_id, ['operation' => 'remove', 'path' => '0', 'expected_hash' => str_repeat('0', 64)]));
        $this->assertSame([], $this->engine->saved);
    }

    public function test_update_merges_values_and_rolls_back(): void
    {
        $this->load();
        $post_id = $this->page();
        $before  = $this->state($post_id);

        $out = $this->update($post_id, ['operation' => 'update', 'path' => '0.0.0.0', 'attrs' => ['text_content' => 'New headline', '_label' => 'Hero']]);

        $this->assertSame('0.0.0.0', $out['path']);
        $this->assertSame(Cornerstone_Content::hash($post_id), $out['hash']);
        $headline = Cornerstone_Tree::node(Cornerstone_Content::elements($post_id), '0.0.0.0');
        $this->assertSame(['_type' => 'headline', 'text_content' => 'New headline', '_label' => 'Hero'], $headline);
        $this->assertStringContainsString('New headline', get_post($post_id)->post_content);

        $out2 = $this->update($post_id, ['operation' => 'update', 'path' => '0.0.0.0', 'attrs' => ['_label' => null]]);
        $this->assertArrayNotHasKey('_label', Cornerstone_Tree::node(Cornerstone_Content::elements($post_id), '0.0.0.0'));

        Rollback_Service::restore_operation($out2['operation_id']);
        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($post_id));
    }

    public function test_update_refuses_text_type_and_children(): void
    {
        $this->load();
        $post_id = $this->page();

        $this->assert_error('invalid_cornerstone_request', $this->update($post_id, ['operation' => 'update', 'path' => '0.0.0.0', 'text' => 'Hi']));
        $this->assert_error('invalid_cornerstone_request', $this->update($post_id, ['operation' => 'update', 'path' => '0.0.0.0', 'attrs' => ['_type' => 'text']]));
        $this->assert_error('invalid_cornerstone_request', $this->update($post_id, ['operation' => 'update', 'path' => '0.0.0.0', 'attrs' => ['_modules' => []]]));
        $this->assert_error('invalid_cornerstone_request', $this->update($post_id, ['operation' => 'update', 'path' => '0.5', 'attrs' => ['a' => 1]]));
        $this->assertSame([], $this->engine->saved);
    }

    public function test_add_places_elements_by_cornerstones_rules_and_rolls_back(): void
    {
        $this->load();
        $post_id = $this->page();
        $before  = $this->state($post_id);

        $out = $this->update($post_id, [
            'operation' => 'add',
            'to'        => '0.0.0',
            'index'     => 1,
            'element'   => ['type' => 'button', 'attrs' => ['anchor_text_primary_content' => 'Buy']],
        ]);

        $this->assertSame('0.0.0.1', $out['path']);
        $column = Cornerstone_Tree::node(Cornerstone_Content::elements($post_id), '0.0.0');
        $this->assertSame(['headline', 'button', 'text'], array_column($column['_modules'], '_type'));
        $this->assertSame('Buy', $column['_modules'][1]['anchor_text_primary_content']);

        $section = $this->update($post_id, [
            'operation' => 'add',
            'to'        => '',
            'element'   => ['type' => 'section', 'children' => [['type' => 'layout-row', 'children' => [['type' => 'layout-column', 'children' => [['type' => 'text', 'attrs' => ['text_content' => 'Second']]]]]]]],
        ]);
        $this->assertSame('1', $section['path']);
        $this->assertSame('Second', Cornerstone_Tree::node(Cornerstone_Content::elements($post_id), '1.0.0.0')['text_content']);

        Rollback_Service::restore_operation($section['operation_id']);
        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($post_id));
    }

    /** @return array<string,array{0:string,1:array<string,mixed>,2:string}> */
    public function misplaced(): array
    {
        return [
            'column under a section'  => ['0', ['type' => 'layout-column'], 'goes only in a layout-row'],
            'text straight in a row'  => ['0.0', ['type' => 'text'], 'cannot go in a layout-row element, which takes layout-column'],
            'child of a headline'     => ['0.0.0.0', ['type' => 'text'], 'A headline element holds no child elements'],
            'section inside a column' => ['0.0.0', ['type' => 'section'], 'goes only in the top level'],
            'unknown type'            => ['0.0.0', ['type' => 'no-such-element'], 'no element type no-such-element'],
            'nested child misplaced'  => ['', ['type' => 'section', 'children' => [['type' => 'layout-column']]]
                , 'goes only in a layout-row'],
        ];
    }

    /** @dataProvider misplaced */
    public function test_add_refuses_a_placement_cornerstone_would_not_allow(string $to, array $element, string $message): void
    {
        $this->load();
        $post_id = $this->page();

        $out = $this->update($post_id, ['operation' => 'add', 'to' => $to, 'element' => $element]);

        $this->assert_error('invalid_cornerstone_request', $out);
        $this->assertStringContainsString($message, $out->get_error_message());
        $this->assertSame([], $this->engine->saved);
    }

    public function test_move_and_remove_and_roll_back(): void
    {
        $this->load();
        $post_id = $this->page();
        $before  = $this->state($post_id);

        $moved = $this->update($post_id, ['operation' => 'move', 'path' => '0.0.0.1', 'to' => '0.0.0', 'index' => 0]);
        $this->assertSame('0.0.0.0', $moved['path']);
        $this->assertSame(['text', 'headline'], array_column(Cornerstone_Tree::node(Cornerstone_Content::elements($post_id), '0.0.0')['_modules'], '_type'));

        $this->assert_error('invalid_cornerstone_request', $this->update($post_id, ['operation' => 'move', 'path' => '0', 'to' => '0.0.0']));
        $this->assert_error('invalid_cornerstone_request', $this->update($post_id, ['operation' => 'move', 'path' => '0.0.0.0', 'to' => '0.0']));

        $removed = $this->update($post_id, ['operation' => 'remove', 'path' => '0.0.0.1']);
        $this->assertSame(['text'], array_column(Cornerstone_Tree::node(Cornerstone_Content::elements($post_id), '0.0.0')['_modules'], '_type'));

        Rollback_Service::restore_operation($removed['operation_id']);
        Rollback_Service::restore_operation($moved['operation_id']);
        $this->assertSame($before, $this->state($post_id));
    }

    public function test_move_reads_target_and_index_against_the_list_before_the_move(): void
    {
        $list = [['_type' => 'a'], ['_type' => 'b', '_modules' => []], ['_type' => 'c']];
        $any  = static fn () => null;

        [$moved, $path] = Cornerstone_Tree::move($list, '0', '', 3, $any);
        $this->assertSame(['b', 'c', 'a'], array_column($moved, '_type'));
        $this->assertSame('2', $path);

        [$moved, $path] = Cornerstone_Tree::move($list, '0', '1', 0, $any);
        $this->assertSame(['b', 'c'], array_column($moved, '_type'));
        $this->assertSame('a', $moved[0]['_modules'][0]['_type']);
        $this->assertSame('0.0', $path);
    }

    public function test_whole_content_fills_a_blank_page_without_a_hash(): void
    {
        $this->load();
        $post_id = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'draft', 'post_content' => '']);
        $before  = $this->state($post_id);

        $out = (new Update_Builder_Content())->handle(['post_id' => $post_id, 'builder' => 'cornerstone', 'content' => self::DATA]);

        $this->assertSame(json_decode(self::DATA, true), Cornerstone_Content::elements($post_id));
        $this->assertSame('cornerstone', Builder_Detector::detect($post_id));
        $this->assertSame(Cornerstone_Content::hash($post_id), $out['hash']);

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($post_id));
    }

    public function test_whole_content_is_validated_and_classic_pages_are_left_alone(): void
    {
        $this->load();
        $blank = self::factory()->post->create(['post_type' => 'page', 'post_content' => '']);
        $this->assert_error('invalid_cornerstone_request', (new Update_Builder_Content())->handle(['post_id' => $blank, 'builder' => 'cornerstone', 'content' => '{"a":1}']));
        $this->assert_error('invalid_cornerstone_request', (new Update_Builder_Content())->handle(['post_id' => $blank, 'builder' => 'cornerstone', 'content' => '[{"_type":"layout-column"}]']));

        $classic = self::factory()->post->create(['post_type' => 'page', 'post_content' => '<p>Hand written</p>']);
        $this->assert_error('unsupported_builder', (new Update_Builder_Content())->handle(['post_id' => $classic, 'builder' => 'cornerstone', 'content' => self::DATA]));
        $this->assertSame([], $this->engine->saved);
    }

    public function test_cornerstones_permissions_are_honoured(): void
    {
        $this->load();
        $this->engine->allow = false;
        $post_id = $this->page();

        $this->assert_error('forbidden', $this->update($post_id, ['operation' => 'remove', 'path' => '0']));
        $this->assertSame([], $this->engine->saved);
    }

    public function test_publish_a_draft_and_roll_back(): void
    {
        $this->load();
        $post_id = $this->page(self::DATA, 'draft');
        $before  = $this->state($post_id);

        $out = $this->update($post_id, ['operation' => 'publish']);

        $this->assertSame('publish', get_post_status($post_id));
        $this->assertSame([], $this->engine->saved);
        $this->assert_error('already_published', $this->update($post_id, ['operation' => 'publish']));

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($post_id));
    }

    public function test_restore_puts_back_a_revisions_elements_and_rolls_back(): void
    {
        $this->load();
        $post_id  = $this->page();
        $old      = '[{"_type":"section","_modules":[{"_type":"layout-row","_modules":[{"_type":"layout-column","_modules":[{"_type":"text","text_content":"Older"}]}]}]}]';
        $revision = (int) _wp_put_post_revision(get_post($post_id));
        add_metadata('post', $revision, '_cornerstone_data', wp_slash($old));
        $empty    = (int) _wp_put_post_revision(get_post($post_id));
        $other    = (int) _wp_put_post_revision(get_post($this->page()));
        $before   = $this->state($post_id);

        $this->assert_error('invalid_cornerstone_request', $this->update($post_id, ['operation' => 'restore', 'path' => (string) $empty]));
        $this->assert_error('invalid_cornerstone_request', $this->update($post_id, ['operation' => 'restore', 'path' => (string) $other]));

        $out = $this->update($post_id, ['operation' => 'restore', 'path' => (string) $revision]);
        $this->assertSame(json_decode($old, true), Cornerstone_Content::elements($post_id));

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($post_id));
    }

    private function template(string $identifier, array $data): int
    {
        $id = self::factory()->post->create(['post_type' => 'cs_template', 'post_title' => 'Tpl ' . $identifier, 'post_content' => wp_slash((string) wp_json_encode(['identifier' => $identifier, 'preview' => '']))]);
        global $wpdb;
        $wpdb->update($wpdb->posts, ['post_status' => 'tco-data'], ['ID' => $id]);
        clean_post_cache($id);
        update_post_meta($id, '_cs_template_identifier', $identifier);
        update_post_meta($id, '_cs_template_data', wp_slash((string) wp_json_encode($data, JSON_UNESCAPED_SLASHES)));

        return $id;
    }

    public function test_templates_are_listed_and_added_from_and_roll_back(): void
    {
        $this->load();
        $section = $this->template('element|__multi__', ['elements' => [['_type' => 'section', '_modules' => [['_type' => 'layout-row', '_modules' => [['_type' => 'layout-column', '_modules' => [['_type' => 'headline', 'text_content' => 'From template']]]]]]]]]);
        $preset  = $this->template('element|headline', ['atts' => ['_type' => 'headline', 'text_tag' => 'h2']]);

        $list = (new Get_Builder_Content())->handle(['builder' => 'cornerstone', 'scope' => 'templates']);
        $this->assertSame([$section, $preset], array_column($list['templates'], 'id'));
        $this->assertSame([true, false], array_column($list['templates'], 'insertable'));
        $this->assertSame('section', $list['templates'][0]['tree'][0]['type']);

        $post_id = $this->page();
        $before  = $this->state($post_id);

        $out = $this->update($post_id, ['operation' => 'add', 'to' => '', 'index' => 0, 'element' => ['template' => $section]]);
        $this->assertSame('0', $out['path']);
        $this->assertSame('From template', Cornerstone_Tree::node(Cornerstone_Content::elements($post_id), '0.0.0.0')['text_content']);
        $this->assertCount(2, Cornerstone_Content::elements($post_id));

        $this->assert_error('invalid_cornerstone_request', $this->update($post_id, ['operation' => 'add', 'to' => '', 'element' => ['template' => $preset]]));
        $this->assert_error('invalid_cornerstone_request', $this->update($post_id, ['operation' => 'add', 'to' => '0.0.0', 'element' => ['template' => $section]]));
        $this->assert_error('invalid_cornerstone_request', $this->update($post_id, ['operation' => 'add', 'to' => '', 'element' => ['template' => $post_id]]));

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($post_id));
    }

    public function test_catalog_comes_from_cornerstone_and_design_system_from_its_options(): void
    {
        $out = (new Get_Builder_Content())->handle(['builder' => 'cornerstone', 'scope' => 'catalog']);
        $this->assert_error('builder_not_loaded', $out);

        $this->load();
        $list = (new Get_Builder_Content())->handle(['builder' => 'cornerstone', 'scope' => 'catalog']);
        $this->assertContains('headline', array_column($list['elements'], 'type'));
        $this->assertNotContains('region', array_column($list['elements'], 'type'));

        $one = (new Get_Builder_Content())->handle(['builder' => 'cornerstone', 'scope' => 'catalog', 'element' => 'headline']);
        $this->assertSame('markup:seo', $one['element']['designations']['text_content']);
        $this->assert_error('element_not_found', (new Get_Builder_Content())->handle(['builder' => 'cornerstone', 'scope' => 'catalog', 'element' => 'nope']));

        update_option('cornerstone_color_items', wp_slash('[{"_id":"c1","title":"Brand","value":"#ff0000"}]'));
        $design = (new Get_Builder_Content())->handle(['builder' => 'cornerstone', 'scope' => 'design_system']);
        $this->assertSame([['_id' => 'c1', 'title' => 'Brand', 'value' => '#ff0000']], $design['colors']);
        $this->assertSame([], $design['fonts']);
    }
}
