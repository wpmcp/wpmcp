<?php

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Pro\Gate;
use WPMCP\Safety\{Rollback_Service, Snapshot_Store};
use WPMCP\Tools\Builders\{Avada_Cache, Builder_Design_Data, Get_Builder_Content, Update_Builder_Content};

/**
 * Issue #637: element-level editing for Avada (Fusion Builder) on the builder
 * pair, past the four tree operations AvadaAdapterTest covers: the page hash
 * and expected_hash guard, the builder version report, the element catalog,
 * the library (saved templates, elements, Layout sections and Layouts), adding
 * a library item into a page, saving part of a page to the library, and the
 * global design data (color palette, typography sets and other global
 * options), every write undone.
 *
 * Avada is a commercial theme and plugin pair that is not installed here. Its
 * element map is the slice recorded from the real Avada Builder 3.16.1 in
 * tests/support/avada-element-map.php, its library taxonomies are registered
 * the way Avada registers them, its global options are stored the way its
 * options panel stores them (one `fusion_options` array, the palette under
 * color_palette and the typography sets under typography_sets), and its
 * presence is toggled through the wpmcp_avada_active filter.
 * AvadaLiveTest runs the same paths against the real Avada.
 */
class AvadaElementEditingTest extends \WP_UnitTestCase
{
    private const SECTION = '[fusion_builder_container type="flex" fusion_global="77"][fusion_builder_row][fusion_builder_column type="1_1" layout="1_1"][fusion_text]<p>Saved section</p>[/fusion_text][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]';

    private const COLUMN = '[fusion_builder_column type="1_3" layout="1_3"][fusion_title size="3"]From the library[/fusion_title][/fusion_builder_column]';

    private const ELEMENT = '[fusion_separator style_type="single solid" /]';

    private int $admin = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        $this->admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->admin);
        foreach (['element_category' => 'fusion_element', 'template_category' => 'fusion_template', 'fusion_tb_category' => 'fusion_tb_section'] as $taxonomy => $type) {
            register_taxonomy($taxonomy, [$type], ['hierarchical' => true]);
        }
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        remove_all_filters('wpmcp_avada_active');
        unset($GLOBALS['all_fusion_builder_elements']);
        foreach (['fusion_options', 'fusion_dynamic_css_posts', 'fusion_dynamic_css_ids', 'fusion_tb_layout_default'] as $option) {
            delete_option($option);
        }
        foreach (['element_category', 'template_category', 'fusion_tb_category'] as $taxonomy) {
            unregister_taxonomy($taxonomy);
        }
        parent::tearDown();
    }

    private function active(): void
    {
        add_filter('wpmcp_avada_active', '__return_true');
    }

    private function avada_page(): int
    {
        $post_id = self::factory()->post->create(['post_type' => 'page', 'post_content' => wp_slash(AvadaShortcodesTest::FIXTURE)]);
        update_post_meta($post_id, 'fusion_builder_status', 'active');

        return $post_id;
    }

    /** A library post as Avada's save_layout() / create_layout() stores it. */
    private function library(string $type, string $title, string $content, array $meta = [], string $term = ''): int
    {
        $id = self::factory()->post->create(['post_type' => $type, 'post_title' => $title, 'post_status' => 'publish', 'post_content' => wp_slash($content)]);
        foreach ($meta as $key => $value) {
            add_post_meta($id, $key, $value);
        }
        if ('' !== $term) {
            wp_set_object_terms($id, $term, 'fusion_tb_section' === $type ? 'fusion_tb_category' : 'element_category');
        }

        return $id;
    }

    private function read(array $args)
    {
        return (new Get_Builder_Content())->handle($args);
    }

    private function update(array $args)
    {
        return (new Update_Builder_Content())->handle(['builder' => 'avada'] + $args);
    }

    private function state(int $post_id): array
    {
        return [get_post($post_id)->post_content, get_post_meta($post_id)];
    }

    private function assert_ok($out): void
    {
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_code() . ': ' . $out->get_error_message() : '');
    }

    private function assert_error(string $code, $out): void
    {
        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame($code, $out->get_error_code(), $out->get_error_message());
    }

    // ----------------------------------------------------- page hash, version

    public function test_page_read_carries_the_content_hash_and_the_builder_version(): void
    {
        $post_id = $this->avada_page();

        $out = $this->read(['post_id' => $post_id]);

        $this->assertSame(hash('sha256', AvadaShortcodesTest::FIXTURE), $out['hash']);
        // Not loaded here, so there is no version to report.
        $this->assertNull($out['plugin_version']);
    }

    public function test_a_stale_expected_hash_refuses_the_write_and_a_fresh_one_is_accepted(): void
    {
        $post_id = $this->avada_page();
        $before  = $this->state($post_id);
        $hash    = $this->read(['post_id' => $post_id])['hash'];

        $stale = $this->update(['post_id' => $post_id, 'operation' => 'update', 'path' => '0.0.0.0', 'text' => 'X', 'expected_hash' => hash('sha256', 'something else')]);
        $this->assert_error('stale_expected_hash', $stale);
        $this->assertSame($before, $this->state($post_id));

        $out = $this->update(['post_id' => $post_id, 'operation' => 'update', 'path' => '0.0.0.0', 'text' => 'Hello', 'expected_hash' => $hash]);
        $this->assert_ok($out);
        $this->assertSame($this->read(['post_id' => $post_id])['hash'], $out['hash']);
        $this->assertNotSame($hash, $out['hash']);

        // The hash read before the first write is stale now.
        $again = $this->update(['post_id' => $post_id, 'operation' => 'remove', 'path' => '1', 'expected_hash' => $hash]);
        $this->assert_error('stale_expected_hash', $again);
    }

    // ----------------------------------------------------- library into page

    public function test_adding_a_saved_column_into_a_row_inserts_its_markup_and_rolls_back(): void
    {
        $post_id = $this->avada_page();
        $column  = $this->library('fusion_element', 'Promo column', self::COLUMN, [], 'columns');
        $before  = $this->state($post_id);

        $out = $this->update(['post_id' => $post_id, 'operation' => 'add', 'to' => '0.0', 'index' => 2, 'element' => ['template' => $column]]);

        $this->assert_ok($out);
        $this->assertSame('0.0.2', $out['path']);
        $content = get_post($post_id)->post_content;
        $this->assertStringContainsString('[/fusion_builder_column]' . self::COLUMN . '[/fusion_builder_row]', $content);
        $this->assertSame('From the library', $this->read(['post_id' => $post_id])['tree'][0]['children'][0]['children'][2]['children'][0]['text']);

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($post_id));
    }

    public function test_a_global_library_element_is_inserted_linked_to_its_library_post(): void
    {
        $post_id = $this->avada_page();
        $global  = $this->library('fusion_element', 'Divider', self::ELEMENT, ['_fusion_is_global' => 'yes'], 'elements');

        $out = $this->update(['post_id' => $post_id, 'operation' => 'add', 'to' => '0.0.0', 'index' => 0, 'element' => ['template' => $global]]);

        $this->assert_ok($out);
        // The way Avada's load_layout() links a global: fusion_global names
        // the library post on the element's opening tag.
        $this->assertStringContainsString('[fusion_separator style_type="single solid" fusion_global="' . $global . '" /][fusion_title', get_post($post_id)->post_content);
    }

    public function test_a_page_template_is_added_at_the_top_level(): void
    {
        $post_id  = $this->avada_page();
        $template = $this->library('fusion_template', 'Landing', self::SECTION . "\n" . self::SECTION, ['fusion_builder_status' => 'active']);

        $out = $this->update(['post_id' => $post_id, 'operation' => 'add', 'to' => '', 'index' => 0, 'element' => ['template' => $template]]);

        $this->assert_ok($out);
        $this->assertSame('0', $out['path']);
        $tree = $this->read(['post_id' => $post_id])['tree'];
        $this->assertCount(4, $tree);
        $this->assertSame('<p>Saved section</p>', $tree[1]['children'][0]['children'][0]['children'][0]['text']);
    }

    public function test_library_items_are_refused_where_avada_cannot_hold_them(): void
    {
        $post_id = $this->avada_page();
        $before  = $this->state($post_id);
        $section = $this->library('fusion_element', 'Section', self::SECTION, [], 'sections');
        $element = $this->library('fusion_element', 'Divider', self::ELEMENT, [], 'elements');
        $page    = self::factory()->post->create(['post_type' => 'page', 'post_content' => self::ELEMENT]);

        $cases = [
            [['to' => '0.0.0', 'element' => ['template' => $section]], 'invalid_avada_operation'],
            [['to' => '', 'element' => ['template' => $element]], 'invalid_avada_operation'],
            [['to' => '0.0.0', 'element' => ['template' => $page]], 'invalid_avada_operation'],
            [['to' => '0.0.0', 'element' => ['template' => 999999]], 'invalid_avada_operation'],
        ];
        foreach ($cases as [$args, $code]) {
            $this->assert_error($code, $this->update(['post_id' => $post_id, 'operation' => 'add'] + $args));
        }
        $this->assertSame($before, $this->state($post_id));
    }

    // ----------------------------------------------------- page into library

    public function test_saving_an_element_creates_a_library_post_in_its_category_and_rollback_trashes_it(): void
    {
        $this->active();
        $post_id = $this->avada_page();
        $page    = $this->state($post_id);

        $out = $this->update(['post_id' => $post_id, 'operation' => 'save_template', 'path' => '0.0.1', 'element' => ['title' => 'Right column']]);

        $this->assert_ok($out);
        $saved = get_post($out['template_id']);
        $this->assertSame('fusion_element', $saved->post_type);
        $this->assertSame('Right column', $saved->post_title);
        $this->assertSame('publish', $saved->post_status);
        $this->assertStringStartsWith('[fusion_builder_column type="1_2" layout="1_2" first="false" last="true"][fusion_builder_row_inner]', $saved->post_content);
        $this->assertStringEndsWith('[/fusion_builder_row_inner][/fusion_builder_column]', $saved->post_content);
        $this->assertSame(['columns'], wp_get_object_terms($saved->ID, 'element_category', ['fields' => 'names']));
        $this->assertSame('columns', $out['category']);
        // The page itself is not touched.
        $this->assertSame($page, $this->state($post_id));

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame('trash', get_post_status($saved->ID));
    }

    public function test_saving_drops_global_links_and_maps_each_layout_level_to_avadas_category(): void
    {
        $this->active();
        $post_id = self::factory()->post->create(['post_type' => 'page', 'post_content' => self::SECTION]);

        $section = $this->update(['post_id' => $post_id, 'operation' => 'save_template', 'path' => '0', 'element' => ['title' => 'Hero']]);
        $element = $this->update(['post_id' => $post_id, 'operation' => 'save_template', 'path' => '0.0.0.0', 'element' => ['title' => 'Copy']]);

        $this->assertSame('sections', $section['category']);
        $this->assertSame(str_replace(' fusion_global="77"', '', self::SECTION), get_post($section['template_id'])->post_content);
        $this->assertSame('elements', $element['category']);
        $this->assertSame('[fusion_text]<p>Saved section</p>[/fusion_text]', get_post($element['template_id'])->post_content);
    }

    public function test_saving_the_whole_page_creates_a_page_template(): void
    {
        $this->active();
        $post_id = $this->avada_page();

        $out = $this->update(['post_id' => $post_id, 'operation' => 'save_template', 'element' => ['title' => 'Whole page']]);

        $this->assert_ok($out);
        $saved = get_post($out['template_id']);
        $this->assertSame('fusion_template', $saved->post_type);
        $this->assertSame(AvadaShortcodesTest::FIXTURE, $saved->post_content);
        $this->assertSame('active', get_post_meta($saved->ID, 'fusion_builder_status', true));
    }

    public function test_save_template_refusals(): void
    {
        $post_id = $this->avada_page();

        $this->assert_error('builder_not_loaded', $this->update(['post_id' => $post_id, 'operation' => 'save_template', 'element' => ['title' => 'X']]));

        $this->active();
        $this->assert_error('invalid_avada_operation', $this->update(['post_id' => $post_id, 'operation' => 'save_template', 'path' => '0']));
        $this->assert_error('invalid_avada_operation', $this->update(['post_id' => $post_id, 'operation' => 'save_template', 'path' => '9', 'element' => ['title' => 'X']]));
        $this->assertSame([], get_posts(['post_type' => ['fusion_element', 'fusion_template'], 'post_status' => 'any']));
    }

    // ----------------------------------------------------------- catalog read

    public function test_catalog_needs_the_builder_loaded(): void
    {
        $this->assert_error('builder_not_loaded', $this->read(['builder' => 'avada', 'scope' => 'catalog']));
    }

    public function test_catalog_lists_the_element_map_and_one_elements_params(): void
    {
        $this->active();
        $GLOBALS['all_fusion_builder_elements'] = require dirname(__DIR__, 2) . '/support/avada-element-map.php';

        $list = $this->read(['builder' => 'avada', 'scope' => 'catalog']);
        $this->assert_ok($list);
        $by_tag = array_column($list['elements'], null, 'tag');
        $this->assertSame(['fusion_builder_column', 'fusion_builder_container', 'fusion_checklist', 'fusion_li_item', 'fusion_separator', 'fusion_tb_content'], array_keys($by_tag));
        $this->assertSame('Separator', $by_tag['fusion_separator']['name']);
        $this->assertSame('fusion_li_item', $by_tag['fusion_checklist']['child']);
        $this->assertTrue($by_tag['fusion_tb_content']['layout_section_only']);

        $one = $this->read(['builder' => 'avada', 'scope' => 'catalog', 'element' => 'fusion_separator']);
        $this->assert_ok($one);
        $this->assertSame('fusion_separator', $one['element']['tag']);
        $this->assertSame('style_type', $one['element']['params'][0]['param_name']);
        $this->assertArrayHasKey('single solid', $one['element']['params'][0]['value']);

        $this->assert_error('element_not_found', $this->read(['builder' => 'avada', 'scope' => 'catalog', 'element' => 'fusion_nope']));
    }

    // --------------------------------------------------------- templates read

    public function test_templates_read_lists_the_library_layout_sections_and_layouts(): void
    {
        $template = $this->library('fusion_template', 'Landing', self::SECTION, ['fusion_builder_status' => 'active']);
        $global   = $this->library('fusion_element', 'Divider', self::ELEMENT, ['_fusion_is_global' => 'yes'], 'elements');
        $header   = $this->library('fusion_tb_section', 'Header', self::SECTION, [], 'header');
        $layout   = $this->library('fusion_tb_layout', 'Blog layout', '{"conditions":{"archives":{"label":"All Archives","type":"archives","mode":"include","archives":"archives"}},"template_terms":{"header":"' . $header . '"}}');
        update_option('fusion_tb_layout_default', '{"conditions":[],"template_terms":{"footer":"12"}}');

        $out = $this->read(['builder' => 'avada', 'scope' => 'templates']);

        $this->assert_ok($out);
        $by_id = array_column($out['templates'], null, 'id');
        $this->assertSame('template', $by_id[$template]['type']);
        $this->assertSame('Saved section', strip_tags($by_id[$template]['tree'][0]['children'][0]['children'][0]['children'][0]['text']));
        $this->assertSame('element', $by_id[$global]['type']);
        $this->assertSame('elements', $by_id[$global]['category']);
        $this->assertTrue($by_id[$global]['global']);
        $this->assertSame('layout_section', $by_id[$header]['type']);
        $this->assertSame('header', $by_id[$header]['category']);
        $this->assertSame('layout', $by_id[$layout]['type']);
        $this->assertSame(['header' => (string) $header], $by_id[$layout]['template_terms']);
        $this->assertSame('archives', $by_id[$layout]['conditions']['archives']['type']);
        $this->assertSame(['footer' => '12'], $by_id[0]['template_terms']);
        $this->assertSame('Global Layout', $by_id[0]['title']);
    }

    // ----------------------------------------------------- design system read

    /** Global options as Avada's options panel stores them. */
    private function seed_options(): array
    {
        $options = [
            'color_palette'   => [
                'color1'         => ['color' => 'rgba(255,255,255,1)', 'label' => 'Color 1'],
                'color4'         => ['color' => 'rgba(101,189,125,1)', 'label' => 'Color 4'],
                'custom_color_1' => ['color' => '#ff0000', 'label' => 'Brand red'],
            ],
            'typography_sets' => [
                'typography1' => ['label' => 'Headings', 'font-family' => 'Inter', 'font-size' => '48px', 'not_removable' => true],
            ],
            'site_width'      => '1200px',
            'header_sticky'   => '1',
            'social_media_icons' => ['icon' => ['facebook']],
        ];
        update_option('fusion_options', $options);

        return $options;
    }

    public function test_design_system_read_returns_palette_typography_options_and_hashes(): void
    {
        $this->seed_options();

        $out = $this->read(['builder' => 'avada', 'scope' => 'design_system']);

        $this->assert_ok($out);
        $this->assertSame('#ff0000', $out['palettes']['custom_color_1']['color']);
        $this->assertSame('Inter', $out['typography']['typography1']['font-family']);
        $this->assertSame('1200px', $out['options']['site_width']);
        $this->assertArrayNotHasKey('color_palette', $out['options']);
        $hash = Builder_Design_Data::option_hash('fusion_options');
        $this->assertSame(['palettes' => $hash, 'typography' => $hash, 'options' => $hash], $out['hashes']);
    }

    // ---------------------------------------------------- design system write

    private function design(array $args)
    {
        return $this->update(['scope' => 'design_system', 'expected_hash' => Builder_Design_Data::option_hash('fusion_options')] + $args);
    }

    public function test_palette_update_invalidates_every_pages_css_and_rolls_back_exactly(): void
    {
        $before = $this->seed_options();
        set_transient('fusion_dynamic_css_12', '/* compiled */');
        update_option('fusion_dynamic_css_posts', ['global' => true, 12 => true]);
        update_option('fusion_dynamic_css_ids', ['global' => 'abc', 12 => 'def']);

        $out = $this->design(['operation' => 'update', 'path' => 'palettes/color4', 'attrs' => ['color' => '#123456']]);

        $this->assert_ok($out);
        $this->assertSame('palettes/color4', $out['path']);
        $this->assertSame(['color' => '#123456', 'label' => 'Color 4'], get_option('fusion_options')['color_palette']['color4']);
        $this->assertSame(Builder_Design_Data::option_hash('fusion_options'), $out['hash']);
        $this->assertFalse(get_transient('fusion_dynamic_css_12'));
        $this->assertSame([], get_option('fusion_dynamic_css_posts'));
        $this->assertSame([], get_option('fusion_dynamic_css_ids'));

        update_option('fusion_dynamic_css_posts', ['global' => true]);
        Rollback_Service::restore_operation($out['operation_id']);

        $this->assertSame($before, get_option('fusion_options'));
        $this->assertSame([], get_option('fusion_dynamic_css_posts'));
    }

    public function test_palette_and_typography_add_and_remove_follow_avadas_keys(): void
    {
        $this->seed_options();

        $add = $this->design(['operation' => 'add', 'to' => 'palettes', 'element' => ['color' => '#00ff00', 'label' => 'Brand green']]);
        $this->assert_ok($add);
        $this->assertSame('palettes/custom_color_2', $add['path']);

        $typo = $this->design(['operation' => 'add', 'to' => 'typography', 'element' => ['label' => 'Eyebrow', 'font-family' => 'Inter', 'font-size' => '12px']]);
        $this->assert_ok($typo);
        $this->assertSame('typography/custom_typography_1', $typo['path']);

        $this->assert_ok($this->design(['operation' => 'remove', 'path' => 'palettes/custom_color_1']));
        $stored = get_option('fusion_options');
        $this->assertSame(['color1', 'color4', 'custom_color_2'], array_keys($stored['color_palette']));
        $this->assertSame('Eyebrow', $stored['typography_sets']['custom_typography_1']['label']);

        // Avada's own palette and sets cannot be removed, only changed.
        $this->assert_error('invalid_design_request', $this->design(['operation' => 'remove', 'path' => 'palettes/color1']));
        $this->assert_error('invalid_design_request', $this->design(['operation' => 'remove', 'path' => 'typography/typography1']));
    }

    public function test_global_option_update_changes_one_key_of_a_matching_type(): void
    {
        $this->seed_options();

        $out = $this->design(['operation' => 'update', 'path' => 'options/site_width', 'attrs' => ['value' => '1320px']]);
        $this->assert_ok($out);
        $this->assertSame('1320px', get_option('fusion_options')['site_width']);

        $cases = [
            [['operation' => 'update', 'path' => 'options/nope', 'attrs' => ['value' => 'x']], 'design_entry_not_found'],
            [['operation' => 'update', 'path' => 'options/color_palette', 'attrs' => ['value' => []]], 'invalid_design_path'],
            [['operation' => 'update', 'path' => 'options/social_media_icons', 'attrs' => ['value' => 'x']], 'invalid_design_entry'],
            [['operation' => 'update', 'path' => 'options/site_width'], 'invalid_design_request'],
            [['operation' => 'add', 'to' => 'options', 'element' => ['value' => 'x']], 'invalid_design_request'],
            [['operation' => 'update', 'path' => 'palettes/color9', 'attrs' => ['color' => '#000']], 'design_entry_not_found'],
            [['operation' => 'update', 'path' => 'palettes/color4', 'attrs' => ['color' => '']], 'invalid_design_entry'],
            [['operation' => 'add', 'to' => 'typography', 'element' => ['font-family' => 'Inter']], 'invalid_design_entry'],
            [['operation' => 'update', 'path' => 'classes/x', 'attrs' => ['a' => 'b']], 'invalid_design_path'],
        ];
        $stored = get_option('fusion_options');
        foreach ($cases as [$args, $code]) {
            $this->assert_error($code, $this->design($args));
        }
        $this->assertSame($stored, get_option('fusion_options'));
    }

    public function test_design_writes_need_a_fresh_hash_and_the_theme_options_capability(): void
    {
        $before = $this->seed_options();

        $this->assert_error('missing_expected_hash', $this->update(['scope' => 'design_system', 'operation' => 'update', 'path' => 'options/site_width', 'attrs' => ['value' => '1px']]));
        $this->assert_error('stale_expected_hash', $this->update(['scope' => 'design_system', 'operation' => 'update', 'path' => 'options/site_width', 'attrs' => ['value' => '1px'], 'expected_hash' => 'x']));

        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $this->assert_error('forbidden', $this->design(['operation' => 'update', 'path' => 'options/site_width', 'attrs' => ['value' => '1px']]));

        $this->assertSame($before, get_option('fusion_options'));
    }

    public function test_cache_reset_touches_nothing_on_a_site_without_avadas_rows(): void
    {
        Avada_Cache::invalidate_all();

        $this->assertFalse(get_option('fusion_dynamic_css_posts'));
        $this->assertFalse(get_option('fusion_dynamic_css_ids'));
    }
}
