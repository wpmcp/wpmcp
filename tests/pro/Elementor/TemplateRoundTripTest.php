<?php

namespace WPMCP\Tests\Pro\Elementor;

use WPMCP\Tools\Elementor\Elementor_Template_Data;
use WPMCP\Tools\Elementor\Export_Template;
use WPMCP\Tools\Elementor\Import_Template;
use WPMCP\Tools\Elementor\Save_As_Template;

/**
 * Template JSON round-trip (issue #61): a page saved as a template exports to
 * the portable envelope, importing that envelope reproduces the structure with
 * freshly regenerated element ids, and the JSON survives a serialize/decode
 * cycle (the cross-site transport path).
 */
class TemplateRoundTripTest extends Structural_Harness
{
    public function test_export_import_round_trips_with_id_regeneration(): void
    {
        $post_id = $this->make_page();

        $saved = (new Save_As_Template())->handle([
            'post_id' => $post_id,
            'title'   => 'Round trip source',
        ]);
        $this->assertIsArray($saved);
        $template_id = (int) $saved['template_id'];

        $export = (new Export_Template())->handle(['template_id' => $template_id]);
        $this->assertIsArray($export);
        $this->assertNotEmpty($export['content']);

        // Cross-site transport: the envelope must survive a JSON cycle.
        $envelope = json_decode(wp_json_encode($export), true);
        $this->assertIsArray($envelope);

        $imported = (new Import_Template())->handle([
            'export' => $envelope,
            'title'  => 'Round trip copy',
        ]);
        $this->assertIsArray($imported);
        $copy_id = (int) $imported['template_id'];
        $this->assertNotSame($template_id, $copy_id);

        $original_tree = Elementor_Template_Data::data($template_id);
        $copy_tree     = Elementor_Template_Data::data($copy_id);

        $this->assertSame(
            $this->shape($original_tree),
            $this->shape($copy_tree),
            'Import must reproduce the exported structure.'
        );

        $original_ids = Elementor_Template_Data::collect_ids($original_tree);
        $copy_ids     = Elementor_Template_Data::collect_ids($copy_tree);

        $this->assertSame([], array_intersect($original_ids, $copy_ids), 'Every imported id must be regenerated.');
        $this->assertSame($copy_ids, array_unique($copy_ids), 'Regenerated ids must be unique.');
        foreach ($copy_ids as $id) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{7}$/', $id);
        }
    }

    public function test_round_trip_carries_conditions_and_page_settings(): void
    {
        $source = self::factory()->post->create([
            'post_type'  => 'elementor_library',
            'post_title' => 'Header source',
        ]);
        update_post_meta($source, '_elementor_edit_mode', 'builder');
        update_post_meta($source, '_elementor_template_type', 'header');
        update_post_meta($source, '_elementor_data', wp_json_encode($this->default_tree()));
        update_post_meta($source, '_elementor_conditions', ['include/general', 'exclude/singular/page']);
        update_post_meta($source, '_elementor_page_settings', ['triggers' => ['page_load' => 'yes'], 'hide_title' => 'yes']);

        $export = (new Export_Template())->handle(['template_id' => $source]);
        $this->assertIsArray($export);
        $this->assertSame(['include/general', 'exclude/singular/page'], $export['conditions']);
        $this->assertSame('yes', $export['page_settings']['hide_title']);

        $envelope = json_decode(wp_json_encode($export), true);
        $imported = (new Import_Template())->handle([
            'export'        => $envelope,
            'title'         => 'Header copy',
            'template_type' => 'header',
        ]);
        $this->assertIsArray($imported);
        $copy_id = (int) $imported['template_id'];

        $this->assertSame(
            ['include/general', 'exclude/singular/page'],
            Elementor_Template_Data::conditions($copy_id),
            'Display conditions must survive the round trip.'
        );
        $this->assertSame(
            ['triggers' => ['page_load' => 'yes'], 'hide_title' => 'yes'],
            get_post_meta($copy_id, '_elementor_page_settings', true),
            'Page settings (popup triggers live here) must survive the round trip.'
        );
    }

    public function test_export_rejects_an_unreadable_draft_template(): void
    {
        $template_id = self::factory()->post->create([
            'post_type'   => 'elementor_library',
            'post_status' => 'draft',
        ]);
        update_post_meta($template_id, '_elementor_template_type', 'page');

        // A subscriber-level caller may hold edit_posts on a site without
        // being allowed to read this particular unpublished template.
        wp_set_current_user(self::factory()->user->create(['role' => 'contributor']));

        $out = (new Export_Template())->handle(['template_id' => $template_id]);
        $this->assertWPError($out);
        $this->assertSame('cannot_read_template', $out->get_error_code());
    }

    public function test_import_honors_an_explicit_template_type_over_the_envelope(): void
    {
        $source = self::factory()->post->create([
            'post_type'  => 'elementor_library',
            'post_title' => 'Header source',
        ]);
        update_post_meta($source, '_elementor_template_type', 'header');
        update_post_meta($source, '_elementor_data', wp_json_encode($this->default_tree()));

        $export = (new Export_Template())->handle(['template_id' => $source]);
        $this->assertIsArray($export);
        $this->assertSame('header', $export['type']);

        $imported = (new Import_Template())->handle([
            'export'        => $export,
            'template_type' => 'footer',
        ]);
        $this->assertIsArray($imported);
        $this->assertSame('footer', $imported['template_type']);
        $this->assertSame(
            'footer',
            get_post_meta((int) $imported['template_id'], '_elementor_template_type', true)
        );
    }

    public function test_import_reports_an_envelope_that_carries_no_conditions_or_page_settings(): void
    {
        $source = self::factory()->post->create(['post_type' => 'elementor_library']);
        update_post_meta($source, '_elementor_template_type', 'page');
        update_post_meta($source, '_elementor_data', wp_json_encode($this->default_tree()));

        $export   = (new Export_Template())->handle(['template_id' => $source]);
        $imported = (new Import_Template())->handle(['export' => $export]);

        $this->assertIsArray($imported);
        $this->assertSame([], $imported['conditions']);
        $this->assertFalse($imported['page_settings']);
        $this->assertSame([], Elementor_Template_Data::conditions((int) $imported['template_id']));
    }

    public function test_export_rejects_a_non_template_post(): void
    {
        $post_id = self::factory()->post->create();

        $out = (new Export_Template())->handle(['template_id' => $post_id]);
        $this->assertWPError($out);
        $this->assertSame('not_a_template', $out->get_error_code());
    }

    /** The tree with ids stripped, so structure can be compared across id regeneration. */
    private function shape(array $elements): array
    {
        return array_map(
            function (array $element): array {
                unset($element['id']);
                if (! empty($element['elements']) && is_array($element['elements'])) {
                    $element['elements'] = $this->shape($element['elements']);
                }
                return $element;
            },
            $elements
        );
    }
}
