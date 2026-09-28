<?php

namespace WPMCP\Tests\Pro\WidgetBuilder;

use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\BlockBuilder\Create_Custom_Block;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\Rollback_Session;
use WPMCP\Tools\Sync\Change_Set_Builder;
use WPMCP\Tools\WidgetBuilder\Create_Custom_Widget;
use WPMCP\Tools\WidgetBuilder\Set_Widget_Status;
use WPMCP\Tools\WidgetBuilder\Widget_Spec_Store;

/**
 * Issue #192: creating a custom widget or block spec writes a creation row
 * to the snapshot ledger, so a session-derived change set carries the spec,
 * and rolling the creation back deactivates the spec (post status draft, the
 * store's own "inactive") rather than deleting it.
 */
class SpecCreationRowTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function widget_spec(string $name = 'promo-box'): array
    {
        return [
            'name'     => $name,
            'title'    => 'Promo Box',
            'controls' => [
                ['name' => 'heading', 'type' => 'text', 'label' => 'Heading', 'default' => 'Hi'],
            ],
            'template' => '<div class="promo"><h3>{{heading}}</h3></div>',
        ];
    }

    private function block_spec(): array
    {
        return [
            'name'       => 'callout',
            'title'      => 'Callout',
            'category'   => 'widgets',
            'attributes' => [
                ['name' => 'heading', 'type' => 'string', 'label' => 'Heading', 'default' => 'Note'],
            ],
            'template'   => '<div class="callout"><h4>{{heading}}</h4></div>',
        ];
    }

    public function test_create_custom_widget_writes_a_creation_row(): void
    {
        $out  = (new Create_Custom_Widget())->handle(['spec' => $this->widget_spec(), 'session_id' => 'w']);
        $rows = Snapshot_Store::list_by_session('w');

        $this->assertCount(1, $rows);
        $this->assertSame('post_create', $rows[0]['object_type']);
        $this->assertSame('create-custom-widget', $rows[0]['tool_name']);
        $this->assertSame($out['widget_id'], (int) $rows[0]['object_id']);
        $this->assertSame($rows[0]['operation_id'], $out['operation_id']);
    }

    public function test_create_custom_block_writes_a_creation_row(): void
    {
        $out  = (new Create_Custom_Block())->handle(['spec' => $this->block_spec(), 'session_id' => 'b']);
        $rows = Snapshot_Store::list_by_session('b');

        $this->assertCount(1, $rows);
        $this->assertSame('post_create', $rows[0]['object_type']);
        $this->assertSame('create-custom-block', $rows[0]['tool_name']);
        $this->assertSame($out['block_id'], (int) $rows[0]['object_id']);
        $this->assertSame($rows[0]['operation_id'], $out['operation_id']);
    }

    public function test_a_session_change_set_lists_the_created_specs(): void
    {
        $widget = (new Create_Custom_Widget())->handle(['spec' => $this->widget_spec(), 'session_id' => 'specs']);
        $block  = (new Create_Custom_Block())->handle(['spec' => $this->block_spec(), 'session_id' => 'specs']);

        $set = (new Change_Set_Builder())->build(['session_id' => 'specs']);

        $ids = array_map('intval', wp_list_pluck($set['objects'], 'object_id'));
        sort($ids);
        $expected = [$widget['widget_id'], $block['block_id']];
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    public function test_rollback_operation_deactivates_a_created_widget_without_deleting_it(): void
    {
        $out = (new Create_Custom_Widget())->handle(['spec' => $this->widget_spec(), 'session_id' => 'w']);

        $result = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);

        $this->assertTrue($result['restored']);
        $this->assertSame('draft', get_post_status($out['widget_id']), 'Rollback deactivates the spec');
        $this->assertNotNull(Widget_Spec_Store::get($out['widget_id']), 'The spec itself survives');
    }

    public function test_rollback_session_deactivates_a_created_block(): void
    {
        $out = (new Create_Custom_Block())->handle(['spec' => $this->block_spec(), 'session_id' => 'b']);

        (new Rollback_Session())->handle(['session_id' => 'b']);

        $this->assertSame('draft', get_post_status($out['block_id']));
        $this->assertNotEmpty(get_post_meta($out['block_id'], '_wpmcp_block_spec', true));
    }

    public function test_rollback_session_of_a_mixed_session_restores_edits_and_deactivates_creations(): void
    {
        $existing = Widget_Spec_Store::create($this->widget_spec('existing-box'));
        $this->assertSame('publish', get_post_status($existing));

        $created = (new Create_Custom_Widget())->handle(['spec' => $this->widget_spec('new-box'), 'session_id' => 'mix']);
        (new Set_Widget_Status())->handle(['widget_id' => $existing, 'status' => 'draft', 'session_id' => 'mix']);

        (new Rollback_Session())->handle(['session_id' => 'mix']);

        $this->assertSame('publish', get_post_status($existing), 'The edit is undone exactly');
        $this->assertSame('draft', get_post_status($created['widget_id']), 'The creation is deactivated');
    }
}
