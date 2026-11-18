<?php

namespace WPMCP\Tests\Pro\Elementor;

use WPMCP\Tools\Elementor\Add_Container;
use WPMCP\Tools\Elementor\Add_Widget;
use WPMCP\Tools\Elementor\Update_Element;
use WPMCP\Tools\Rollback_Operation;

/**
 * Issue #868: a page-tree write on a page that was never opened in
 * Elementor stored `_elementor_data` but left `_elementor_edit_mode` unset,
 * so Elementor ignored the tree and rendered post_content. A write now
 * marks the page as built with Elementor the way Elementor's own editor
 * does (snapshotted, so undo removes the flag again), and refuses, with
 * nothing written, when the page has WordPress editor content that the
 * flag would hide.
 */
class BuilderModeInitTest extends Structural_Harness
{
    private function blank_page(): int
    {
        return self::factory()->post->create(['post_type' => 'page', 'post_content' => '', 'post_status' => 'publish']);
    }

    private function rendered(int $post_id): string
    {
        clean_post_cache($post_id);
        $level = ob_get_level();
        try {
            return (string) \Elementor\Plugin::instance()->frontend->get_builder_content($post_id);
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }

    public function test_add_widget_on_a_page_never_opened_in_elementor_marks_it_and_renders(): void
    {
        $post_id = $this->blank_page();

        $out = (new Add_Widget())->handle([
            'post_id'       => $post_id,
            'expected_hash' => hash('sha256', ''),
            'widget_type'   => 'heading',
            'params'        => ['title' => 'Issue868 Heading'],
        ]);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertSame('builder', get_post_meta($post_id, '_elementor_edit_mode', true));
        $this->assertNotSame('', (string) get_post_meta($post_id, '_elementor_version', true));
        $this->assertStringContainsString('Issue868 Heading', $this->rendered($post_id));
    }

    public function test_add_container_marks_the_page_and_rollback_removes_flag_and_elements(): void
    {
        $post_id = $this->blank_page();

        $out = (new Add_Container())->handle([
            'post_id'       => $post_id,
            'expected_hash' => hash('sha256', ''),
        ]);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertSame('builder', get_post_meta($post_id, '_elementor_edit_mode', true));

        $result = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($result['restored']);
        clean_post_cache($post_id);

        $this->assertFalse(metadata_exists('post', $post_id, '_elementor_edit_mode'));
        $this->assertFalse(metadata_exists('post', $post_id, '_elementor_version'));
        $this->assertSame('', $this->raw($post_id));
    }

    public function test_page_with_wordpress_content_is_refused_with_nothing_written(): void
    {
        $post_id = self::factory()->post->create(['post_type' => 'page', 'post_content' => '<p>Existing words</p>']);

        $out = (new Add_Widget())->handle([
            'post_id'       => $post_id,
            'expected_hash' => hash('sha256', ''),
            'widget_type'   => 'heading',
            'params'        => ['title' => 'Should not land'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('not_an_elementor_page', $out->get_error_code());
        $this->assertStringContainsString('Nothing was written', $out->get_error_message());
        $this->assertFalse(metadata_exists('post', $post_id, '_elementor_edit_mode'));
        $this->assertSame('', $this->raw($post_id));
    }

    public function test_page_switched_back_to_the_wordpress_editor_is_refused_by_the_direct_tools(): void
    {
        // Elementor's "Back to WordPress Editor" drops the flag but keeps the tree.
        $post_id = self::factory()->post->create(['post_type' => 'page', 'post_content' => '<p>Gutenberg now</p>']);
        update_post_meta($post_id, '_elementor_data', wp_slash(wp_json_encode([
            ['id' => 'w868a01', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['title' => 'Old'], 'elements' => []],
        ])));
        $before = $this->raw($post_id);

        $out = (new Update_Element())->handle([
            'post_id'    => $post_id,
            'element_id' => 'w868a01',
            'settings'   => ['title' => 'New'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('not_an_elementor_page', $out->get_error_code());
        $this->assertSame($before, $this->raw($post_id));
        $this->assertFalse(metadata_exists('post', $post_id, '_elementor_edit_mode'));
    }

    public function test_direct_tool_on_a_blank_page_with_a_stored_tree_marks_it(): void
    {
        $post_id = $this->blank_page();
        update_post_meta($post_id, '_elementor_data', wp_slash(wp_json_encode([
            ['id' => 'w868b01', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['title' => 'Old'], 'elements' => []],
        ])));

        $out = (new Update_Element())->handle([
            'post_id'    => $post_id,
            'element_id' => 'w868b01',
            'settings'   => ['title' => 'Now rendered'],
        ]);

        $this->assertIsArray($out);
        $this->assertSame('builder', get_post_meta($post_id, '_elementor_edit_mode', true));
        $this->assertStringContainsString('Now rendered', $this->rendered($post_id));
    }

    public function test_write_on_an_existing_elementor_page_keeps_its_meta(): void
    {
        $post_id = $this->make_page();
        update_post_meta($post_id, '_elementor_version', '3.0.0');

        $out = (new Add_Container())->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
        ]);

        $this->assertIsArray($out);
        $this->assertSame('builder', get_post_meta($post_id, '_elementor_edit_mode', true));
        // Document::save() records the running version on an Elementor page,
        // as it always has; the flag write must not add a second row.
        $this->assertCount(1, get_post_meta($post_id, '_elementor_edit_mode'));
    }
}
