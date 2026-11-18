<?php

namespace WPMCP\Tests\Pro\Elementor;

use WPMCP\Pro\Gate;
use WPMCP\Safety\{Rollback_Service, Snapshot_Store};
use WPMCP\Tools\Elementor\Update_Element;
use WPMCP\Tools\List_Operations;
use WPMCP\Tools\Rollback_Operation;

/**
 * End-to-end agent-realistic flow: update an Elementor element's settings
 * twice under one session, confirm list-operations surfaces both
 * mutations, then roll back the first operation and confirm the page's
 * _elementor_data is restored to its pre-edit state.
 */
class UpdateElementFlowTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();

        if (! wpmcp_elementor_active()) {
            $this->markTestSkipped('Elementor not active');
        }
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function make_page(): int
    {
        $post_id = self::factory()->post->create(['post_type' => 'page', 'meta_input' => ['_elementor_edit_mode' => 'builder']]);
        update_post_meta($post_id, '_elementor_data', wp_json_encode([
            [
                'id'       => 'head001',
                'elType'   => 'widget',
                'widgetType' => 'heading',
                'settings' => ['title' => 'Original Heading'],
                'elements' => [],
            ],
        ]));
        return $post_id;
    }

    public function test_update_element_list_and_rollback_operation_restores_elementor_data(): void
    {
        $post_id    = $this->make_page();
        $session_id = 'elementor-flow-session-' . uniqid();

        $original_data = get_post_meta($post_id, '_elementor_data', true);

        $first = (new Update_Element())->handle([
            'post_id'    => $post_id,
            'session_id' => $session_id,
            'element_id' => 'head001',
            'settings'   => ['title' => 'Changed Once'],
        ]);
        $this->assertArrayHasKey('operation_id', $first);

        $second = (new Update_Element())->handle([
            'post_id'    => $post_id,
            'session_id' => $session_id,
            'element_id' => 'head001',
            'settings'   => ['title' => 'Changed Twice'],
        ]);
        $this->assertArrayHasKey('operation_id', $second);

        $after_edits = json_decode(get_post_meta($post_id, '_elementor_data', true), true);
        $this->assertSame('Changed Twice', $after_edits[0]['settings']['title']);

        $ops = (new List_Operations())->handle(['session_id' => $session_id]);
        $this->assertSame(2, $ops['total_count']);
        foreach ($ops['operations'] as $op) {
            $this->assertSame('update-element', $op['tool_name']);
            $this->assertSame($post_id, $op['object_id']);
            $this->assertTrue($op['rollback_available']);
        }

        // Roll back to before EITHER edit happened (the first operation's
        // snapshot is the pre-session state).
        $result = (new Rollback_Operation())->handle(['operation_id' => $first['operation_id']]);
        $this->assertTrue($result['restored']);

        $restored_data = get_post_meta($post_id, '_elementor_data', true);
        $this->assertSame($original_data, $restored_data);

        $decoded = json_decode($restored_data, true);
        $this->assertSame('Original Heading', $decoded[0]['settings']['title']);
    }
}
