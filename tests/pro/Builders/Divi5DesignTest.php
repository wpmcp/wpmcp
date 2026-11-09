<?php

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Pro\Gate;
use WPMCP\Tools\Builders\Get_Builder_Content;

/**
 * Issue #636: get-builder-content's Divi 5 scope reads. Templates are read
 * from the posts and meta Divi's Library and Theme Builder store; the
 * catalog from the loaded Divi 5's module metadata, stood in for here by
 * four modules recorded from Divi 5.18.0 (tests/support/divi5-module-metadata.json)
 * through the wpmcp_divi_module_metadata filter.
 */
class Divi5DesignTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        remove_all_filters('wpmcp_divi_module_metadata');
        remove_all_filters('wpmcp_divi_version');
        parent::tearDown();
    }

    private function read(array $args)
    {
        return (new Get_Builder_Content())->handle($args);
    }

    private function metadata(): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/support/divi5-module-metadata.json'), true);
    }

    public function test_templates_list_library_and_theme_builder_layouts_and_templates(): void
    {
        register_taxonomy('layout_type', 'et_pb_layout');
        $library = self::factory()->post->create(['post_type' => 'et_pb_layout', 'post_title' => 'Hero', 'post_content' => wp_slash(Divi5BlocksTest::FIXTURE)]);
        wp_set_object_terms($library, 'section', 'layout_type');
        $header = self::factory()->post->create(['post_type' => 'et_header_layout', 'post_title' => 'Header', 'post_content' => '[et_pb_section][/et_pb_section]']);
        $template = self::factory()->post->create(['post_type' => 'et_template', 'post_title' => 'Pages']);
        update_post_meta($template, '_et_enabled', '1');
        update_post_meta($template, '_et_header_layout_id', (string) $header);
        update_post_meta($template, '_et_header_layout_enabled', '1');
        add_post_meta($template, '_et_use_on', 'singular:post_type:page:all');
        add_post_meta($template, '_et_exclude_from', 'singular:post_type:page:id:7');

        $out = $this->read(['builder' => 'divi', 'scope' => 'templates']);

        $this->assertSame('templates', $out['scope']);
        $this->assertFalse($out['plugin_active']);
        $byId = array_column($out['layouts'], null, 'id');
        $this->assertSame('section', $byId[ $library ]['type']);
        $this->assertSame('divi5', $byId[ $library ]['format']);
        $this->assertSame('divi/section', $byId[ $library ]['tree'][0]['name']);
        $this->assertSame('header', $byId[ $header ]['type']);
        $this->assertSame('divi4', $byId[ $header ]['format']);
        $this->assertArrayNotHasKey('tree', $byId[ $header ]);

        $this->assertCount(1, $out['templates']);
        $this->assertSame($template, $out['templates'][0]['id']);
        $this->assertTrue($out['templates'][0]['enabled']);
        $this->assertFalse($out['templates'][0]['default']);
        $this->assertSame(['singular:post_type:page:all'], $out['templates'][0]['use_on']);
        $this->assertSame(['singular:post_type:page:id:7'], $out['templates'][0]['exclude_from']);
        $this->assertSame(['layout_id' => $header, 'enabled' => true], $out['templates'][0]['layouts']['header']);
        $this->assertSame(['layout_id' => 0, 'enabled' => false], $out['templates'][0]['layouts']['body']);
        unregister_taxonomy('layout_type');
    }

    public function test_catalog_lists_modules_and_one_module_attributes(): void
    {
        add_filter('wpmcp_divi_module_metadata', fn () => $this->metadata());

        $list = $this->read(['builder' => 'divi', 'scope' => 'catalog']);
        $this->assertSame(['divi/accordion', 'divi/accordion-item', 'divi/heading', 'divi/text'], array_column($list['elements'], 'name'));
        $this->assertSame(['divi/accordion-item'], $list['elements'][0]['children']);

        foreach (['heading', 'divi/heading'] as $element) {
            $one = $this->read(['builder' => 'divi', 'scope' => 'catalog', 'element' => $element]);
            $this->assertSame('divi/heading', $one['element']['name']);
            $this->assertSame('Heading', $one['element']['attributes']['title']['innerContent']);
            $this->assertContains('font', $one['element']['attributes']['title']['decoration']);
            $this->assertStringContainsString('innerContent', $one['element']['attrs_shape']);
        }

        $missing = $this->read(['builder' => 'divi', 'scope' => 'catalog', 'element' => 'nope']);
        $this->assertSame('element_not_found', $missing->get_error_code());
    }

    public function test_catalog_needs_divi5_loaded_and_design_system_is_not_read(): void
    {
        $this->assertSame('builder_not_loaded', $this->read(['builder' => 'divi', 'scope' => 'catalog'])->get_error_code());
        $this->assertSame('unsupported_builder', $this->read(['builder' => 'divi', 'scope' => 'design_system'])->get_error_code());
    }

    public function test_scope_reads_detect_divi_from_a_post(): void
    {
        $post_id = self::factory()->post->create(['post_type' => 'page', 'post_content' => wp_slash(Divi5BlocksTest::FIXTURE)]);

        $out = $this->read(['post_id' => $post_id, 'scope' => 'templates']);

        $this->assertSame('divi', $out['builder']);
    }
}
