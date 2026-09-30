<?php

namespace WPMCP\Tests\Pro\Safety;

use WPMCP\Integrations\Ninja_Forms_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tests\Free\Safety\Raw_Post_Columns;
use WPMCP\Tools\BlockBuilder\Block_Spec_Store;
use WPMCP\Tools\BlockBuilder\Delete_Custom_Block;
use WPMCP\Tools\BlockBuilder\Set_Block_Status;
use WPMCP\Tools\Elementor\Delete_Code_Snippet;
use WPMCP\Tools\Elementor\Delete_Theme_Template;
use WPMCP\Tools\WidgetBuilder\Delete_Custom_Widget;
use WPMCP\Tools\WidgetBuilder\Set_Widget_Status;
use WPMCP\Tools\WidgetBuilder\Widget_Spec_Store;

require_once __DIR__ . '/../../support/ninjaforms-stubs.php';

/**
 * Issue #436, the add-on half: the widget and block status switches, their
 * spec title updates and deletes, the Elementor trash tools and the Ninja
 * Forms submission status change all re-save the whole row. For a user
 * without unfiltered_html they must leave the columns they do not change
 * byte for byte and still fire the status transition hooks.
 */
class StatusWriteFilterProTest extends \WP_UnitTestCase
{
    use Raw_Post_Columns;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        $this->assert_kses_would_change_it();
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        if (post_type_exists('nf_sub')) {
            unregister_post_type('nf_sub');
        }
        parent::tearDown();
    }

    private function widget(): int
    {
        $id = Widget_Spec_Store::create([
            'name'     => 'status-box',
            'title'    => 'Status Box',
            'controls' => [['name' => 'heading', 'type' => 'text', 'label' => 'Heading', 'default' => 'Hi']],
            'template' => '<div class="box"><h3>{{heading}}</h3></div>',
        ]);
        $this->assertIsInt($id);
        $this->make_raw($id);
        return $id;
    }

    private function block(): int
    {
        $id = Block_Spec_Store::create([
            'name'       => 'status-callout',
            'title'      => 'Status Callout',
            'category'   => 'widgets',
            'attributes' => [['name' => 'heading', 'type' => 'string', 'label' => 'Heading', 'default' => 'Note']],
            'template'   => '<div class="callout"><h4>{{heading}}</h4></div>',
        ]);
        $this->assertIsInt($id);
        $this->make_raw($id);
        return $id;
    }

    public function test_set_widget_status_changes_only_the_status(): void
    {
        $id = $this->widget();
        $this->act_as($this->author());
        $this->record_transitions();

        (new Set_Widget_Status())->handle(['widget_id' => $id, 'status' => 'draft']);
        $this->assertSame('draft', get_post_status($id));
        $this->assert_raw($id);
        $this->assert_transitioned($id, 'publish', 'draft');

        (new Set_Widget_Status())->handle(['widget_id' => $id, 'status' => 'publish']);
        $this->assertSame('publish', get_post_status($id));
        $this->assert_raw($id);
        $this->assert_transitioned($id, 'draft', 'publish');
    }

    public function test_set_block_status_changes_only_the_status(): void
    {
        $id = $this->block();
        $this->act_as($this->author());
        $this->record_transitions();

        (new Set_Block_Status())->handle(['block_id' => $id, 'status' => 'draft']);
        $this->assertSame('draft', get_post_status($id));
        $this->assert_raw($id);
        $this->assert_transitioned($id, 'publish', 'draft');

        (new Set_Block_Status())->handle(['block_id' => $id, 'status' => 'publish']);
        $this->assertSame('publish', get_post_status($id));
        $this->assert_raw($id);
        $this->assert_transitioned($id, 'draft', 'publish');
    }

    public function test_spec_title_updates_leave_the_other_columns_alone(): void
    {
        $widget = $this->widget();
        $block  = $this->block();
        $this->act_as($this->author());

        $spec          = Widget_Spec_Store::get($widget);
        $spec['title'] = 'Renamed <b>box</b>';
        $this->assertTrue(Widget_Spec_Store::update($widget, $spec));
        clean_post_cache($widget);
        $this->assertSame('Renamed box', get_post($widget)->post_title);
        $this->assert_raw($widget, ['post_content', 'post_excerpt']);

        $spec          = Block_Spec_Store::get($block);
        $spec['title'] = 'Renamed <b>callout</b>';
        $this->assertTrue(Block_Spec_Store::update($block, $spec));
        clean_post_cache($block);
        $this->assertSame('Renamed callout', get_post($block)->post_title);
        $this->assert_raw($block, ['post_content', 'post_excerpt']);
    }

    public function test_deleting_a_custom_widget_or_block_trashes_it_byte_for_byte(): void
    {
        $widget = $this->widget();
        $block  = $this->block();
        $this->act_as($this->author());
        $this->record_transitions();

        (new Delete_Custom_Widget())->handle(['widget_id' => $widget]);
        (new Delete_Custom_Block())->handle(['block_id' => $block]);

        foreach ([$widget, $block] as $id) {
            $this->assertSame('trash', get_post_status($id));
            $this->assert_raw($id);
            $this->assert_transitioned($id, 'publish', 'trash');
        }
    }

    public function test_deleting_elementor_snippets_and_templates_trashes_them_byte_for_byte(): void
    {
        if (! post_type_exists('elementor_snippet')) {
            register_post_type('elementor_snippet', ['public' => false, 'show_ui' => false]);
        }
        $snippet  = (int) self::factory()->post->create(['post_type' => 'elementor_snippet', 'post_status' => 'publish']);
        $template = (int) self::factory()->post->create(['post_type' => 'elementor_library', 'post_status' => 'publish']);
        $this->make_raw($snippet);
        $this->make_raw($template);
        $this->act_as($this->admin_without_unfiltered_html());
        $this->record_transitions();

        (new Delete_Code_Snippet())->handle(['snippet_id' => $snippet]);
        (new Delete_Theme_Template())->handle(['post_id' => $template]);

        foreach ([$snippet, $template] as $id) {
            $this->assertSame('trash', get_post_status($id));
            $this->assert_raw($id);
            $this->assert_transitioned($id, 'publish', 'trash');
        }
    }

    public function test_ninja_forms_entry_status_changes_leave_the_row_alone(): void
    {
        \NF_Test_Sub::register();
        \NF_Test_FormHandler::$forms = [
            8 => new \NF_Test_Form(8, ['title' => 'Contact'], [
                new \NF_Test_Field(80, ['type' => 'email', 'label' => 'Your email', 'key' => 'email']),
            ]),
        ];
        \NF_Test_FormHandler::$actions = [8 => []];
        $entry = (int) \NF_Test_Sub::seed(8, 1, [80 => 'old@example.test'], '2026-04-01 10:00:00');
        $this->make_raw($entry);
        $this->act_as($this->admin_without_unfiltered_html());
        $this->record_transitions();

        $i   = new Ninja_Forms_Integration();
        $out = $i->handle_write(['operation' => 'update-entry-status', 'args' => ['entry_id' => $entry, 'status' => 'trash']]);
        $this->assertTrue($out['result']['changed'], wp_json_encode($out));
        $this->assertSame('trash', get_post_status($entry));
        $this->assert_raw($entry);
        $this->assert_transitioned($entry, 'publish', 'trash');

        $i->handle_write(['operation' => 'update-entry-status', 'args' => ['entry_id' => $entry, 'status' => 'active']]);
        $this->assertSame('publish', get_post_status($entry));
        $this->assert_raw($entry);
        $this->assert_transitioned($entry, 'trash', 'publish');
    }
}
