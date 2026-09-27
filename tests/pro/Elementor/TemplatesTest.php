<?php

namespace WPMCP\Tests\Pro\Elementor;

use WPMCP\Tools\Elementor\Export_Page;
use WPMCP\Tools\Elementor\Save_As_Template;
use WPMCP\Tools\Elementor\Apply_Template;
use WPMCP\Tools\Elementor\Import_Template;

/**
 * Cluster 2 (EMCP parity): the Elementor template library surface.
 *
 * Templates are ordinary `elementor_library` posts whose element tree lives in
 * `_elementor_data`. export-page reads a page to a portable structure;
 * save-as-template and import-template create library posts (a create destroys
 * nothing, so like create-post they are not snapshot-wrapped); apply-template
 * mutates a target page's `_elementor_data` and therefore writes snapshot-first
 * under the expected_hash guard, with every inserted element getting a fresh id.
 */
class TemplatesTest extends Structural_Harness
{
    private function template_tree(): array
    {
        return [[
            'id'       => 'tpl0001',
            'elType'   => 'container',
            'settings' => ['flex_direction' => 'column'],
            'elements' => [[
                'id'         => 'tplw001',
                'elType'     => 'widget',
                'settings'   => ['title' => 'From template'],
                'elements'   => [],
                'widgetType' => 'heading',
            ]],
            'isInner'  => false,
        ]];
    }

    private function make_template(?array $tree = null, string $type = 'section'): int
    {
        $id = self::factory()->post->create(['post_type' => 'elementor_library']);
        update_post_meta($id, '_elementor_data', wp_json_encode($tree ?? $this->template_tree()));
        update_post_meta($id, '_elementor_edit_mode', 'builder');
        update_post_meta($id, '_elementor_template_type', $type);
        return $id;
    }

    // ---- export-page --------------------------------------------------------

    public function test_export_page_returns_portable_structure(): void
    {
        $post_id = $this->make_page();
        update_post_meta($post_id, '_elementor_page_settings', ['background_background' => 'classic']);

        $out = (new Export_Page())->handle(['post_id' => $post_id]);

        $this->assertIsArray($out);
        $this->assertArrayHasKey('content', $out);
        $this->assertSame('cont001', $out['content'][0]['id']);
        $this->assertArrayHasKey('page_settings', $out);
        $this->assertSame('classic', $out['page_settings']['background_background']);
        $this->assertArrayHasKey('type', $out);
        $this->assertArrayHasKey('version', $out);
    }

    public function test_export_page_requires_post_id(): void
    {
        $out = (new Export_Page())->handle([]);
        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('missing_post_id', $out->get_error_code());
    }

    // ---- save-as-template ---------------------------------------------------

    public function test_save_as_template_creates_library_post(): void
    {
        $post_id = $this->make_page();

        $out = (new Save_As_Template())->handle([
            'post_id'       => $post_id,
            'title'         => 'My Hero',
            'template_type' => 'section',
        ]);

        $this->assertIsArray($out);
        $this->assertArrayHasKey('template_id', $out);
        $tid = $out['template_id'];
        $this->assertSame('elementor_library', get_post_type($tid));
        $this->assertSame('section', get_post_meta($tid, '_elementor_template_type', true));
        $this->assertSame('My Hero', get_the_title($tid));
        // The template carries the source page's element tree.
        $saved = json_decode(get_post_meta($tid, '_elementor_data', true), true);
        $this->assertSame('cont001', $saved[0]['id']);
    }

    public function test_save_as_template_defaults_invalid_type_to_page(): void
    {
        $post_id = $this->make_page();
        $out = (new Save_As_Template())->handle([
            'post_id'       => $post_id,
            'title'         => 'X',
            'template_type' => 'bogus',
        ]);
        $this->assertSame('page', get_post_meta($out['template_id'], '_elementor_template_type', true));
    }

    // ---- apply-template -----------------------------------------------------

    public function test_apply_template_appends_with_fresh_ids_snapshotted(): void
    {
        $post_id     = $this->make_page();
        $template_id = $this->make_template();
        $before_ids  = $this->all_ids($this->tree($post_id));

        $out = (new Apply_Template())->handle([
            'post_id'       => $post_id,
            'template_id'   => $template_id,
            'expected_hash' => $this->data_hash($post_id),
        ]);

        $this->assertIsArray($out);
        $this->assertArrayHasKey('operation_id', $out);
        $this->assertSame(1, $out['elements_added']);

        $after_ids = $this->all_ids($this->tree($post_id));
        $this->assertGreaterThan(count($before_ids), count($after_ids));
        // Template ids were regenerated, so none of the template's original ids leak in.
        $this->assertNotContains('tpl0001', $after_ids);
        $this->assertNotContains('tplw001', $after_ids);
        // The applied content is present (heading text survives).
        $this->assertStringContainsString('From template', $this->raw($post_id));
        $this->assert_builder_clean($post_id);
    }

    public function test_apply_template_replace_mode_swaps_whole_tree(): void
    {
        $post_id     = $this->make_page();
        $template_id = $this->make_template();

        (new Apply_Template())->handle([
            'post_id'       => $post_id,
            'template_id'   => $template_id,
            'mode'          => 'replace',
            'expected_hash' => $this->data_hash($post_id),
        ]);

        $ids = $this->all_ids($this->tree($post_id));
        // Original page containers are gone.
        $this->assertNotContains('cont001', $ids);
        $this->assertStringContainsString('From template', $this->raw($post_id));
    }

    public function test_apply_template_rejects_stale_hash(): void
    {
        $post_id     = $this->make_page();
        $template_id = $this->make_template();

        $out = (new Apply_Template())->handle([
            'post_id'       => $post_id,
            'template_id'   => $template_id,
            'expected_hash' => 'stale',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('stale_expected_hash', $out->get_error_code());
    }

    public function test_apply_template_rejects_non_template_post(): void
    {
        $post_id = $this->make_page();
        $other   = $this->make_page();

        $out = (new Apply_Template())->handle([
            'post_id'       => $post_id,
            'template_id'   => $other,
            'expected_hash' => $this->data_hash($post_id),
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('not_a_template', $out->get_error_code());
    }

    // ---- import-template ----------------------------------------------------

    public function test_import_template_round_trips_an_export(): void
    {
        $source = $this->make_page();
        $export = (new Export_Page())->handle(['post_id' => $source]);

        $out = (new Import_Template())->handle([
            'export'        => $export,
            'title'         => 'Imported',
            'template_type' => 'section',
        ]);

        $this->assertIsArray($out);
        $tid = $out['template_id'];
        $this->assertSame('elementor_library', get_post_type($tid));
        $this->assertSame('section', get_post_meta($tid, '_elementor_template_type', true));
        // Import regenerates every element id (issue #61) so a template carried
        // in from another site never collides with local content; the structure
        // is what has to survive, not the source ids.
        $saved = json_decode(get_post_meta($tid, '_elementor_data', true), true);
        $this->assertSame('container', $saved[0]['elType']);
        $this->assertNotSame('cont001', $saved[0]['id']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{7}$/', $saved[0]['id']);
    }

    public function test_import_template_rejects_a_malformed_element_tree(): void
    {
        // Untrusted JSON: a scalar where an element object belongs used to
        // reach regenerate_ids() and raise a TypeError. It must be a WP_Error.
        $out = (new Import_Template())->handle([
            'export' => ['content' => ['x']],
            'title'  => 'Bad',
        ]);
        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('invalid_content', $out->get_error_code());
    }

    public function test_import_template_rejects_a_malformed_nested_element_list(): void
    {
        $out = (new Import_Template())->handle([
            'export' => ['content' => [['elType' => 'container', 'elements' => [1]]]],
            'title'  => 'Bad',
        ]);
        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('invalid_content', $out->get_error_code());
    }

    public function test_import_template_rejects_an_object_shaped_content_map(): void
    {
        // array_map preserves string keys, so an object-shaped `content` would
        // be stored as a JSON object in _elementor_data instead of a list.
        $out = (new Import_Template())->handle([
            'export' => ['content' => ['a' => ['elType' => 'container']]],
            'title'  => 'Bad',
        ]);
        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('invalid_content', $out->get_error_code());
    }

    public function test_import_template_rejects_export_without_content(): void
    {
        $out = (new Import_Template())->handle([
            'export' => ['page_settings' => []],
            'title'  => 'Bad',
        ]);
        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('missing_content', $out->get_error_code());
    }
}
