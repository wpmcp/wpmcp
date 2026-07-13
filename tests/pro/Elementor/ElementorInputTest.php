<?php

namespace WPMCP\Tests\Pro\Elementor;

use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Elementor\Add_Widget;
use WPMCP\Tools\Elementor\Update_Element;
use WPMCP\Tools\Elementor\Move_Element;
use WPMCP\Tools\Elementor\Remove_Element;

/**
 * Input-boundary tests for the Elementor domain: missing required args,
 * unknown widget types, and invalid/nonexistent element ids must all fail
 * cleanly as a WP_Error (this domain's convention), never a fatal or a
 * silent corrupt _elementor_data write.
 */
class ElementorInputTest extends \WP_UnitTestCase
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
        $post_id = self::factory()->post->create(['post_type' => 'page']);
        update_post_meta($post_id, '_elementor_data', wp_json_encode([
            [
                'id'       => 'sect001',
                'elType'   => 'section',
                'settings' => [],
                'elements' => [],
            ],
        ]));
        return $post_id;
    }

    public function test_add_widget_rejects_missing_post_id(): void
    {
        $result = (new Add_Widget())->handle(['parent_id' => 'sect001', 'widget_type' => 'heading']);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('missing_post_id', $result->get_error_code());
    }

    public function test_add_widget_rejects_missing_parent_id(): void
    {
        $post_id = $this->make_page();
        $result  = (new Add_Widget())->handle(['post_id' => $post_id, 'widget_type' => 'heading']);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('missing_parent_id', $result->get_error_code());
    }

    public function test_add_widget_rejects_missing_widget_type(): void
    {
        $post_id = $this->make_page();
        $result  = (new Add_Widget())->handle(['post_id' => $post_id, 'parent_id' => 'sect001']);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('missing_widget_type', $result->get_error_code());
    }

    public function test_add_widget_rejects_unknown_widget_type(): void
    {
        $post_id = $this->make_page();
        $result  = (new Add_Widget())->handle([
            'post_id'     => $post_id,
            'parent_id'   => 'sect001',
            'widget_type' => 'this-widget-type-does-not-exist',
        ]);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_widget_type', $result->get_error_code());
    }

    public function test_add_widget_rejects_nonexistent_parent_id(): void
    {
        $post_id = $this->make_page();
        $result  = (new Add_Widget())->handle([
            'post_id'     => $post_id,
            'parent_id'   => 'does-not-exist',
            'widget_type' => 'heading',
        ]);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('parent_not_found', $result->get_error_code());
    }

    public function test_update_element_rejects_missing_post_id(): void
    {
        $result = (new Update_Element())->handle(['element_id' => 'sect001', 'settings' => []]);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('missing_post_id', $result->get_error_code());
    }

    public function test_update_element_rejects_missing_element_id(): void
    {
        $post_id = $this->make_page();
        $result  = (new Update_Element())->handle(['post_id' => $post_id, 'settings' => []]);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('missing_element_id', $result->get_error_code());
    }

    public function test_update_element_rejects_nonexistent_element_id(): void
    {
        $post_id = $this->make_page();
        $result  = (new Update_Element())->handle([
            'post_id'    => $post_id,
            'element_id' => 'does-not-exist',
            'settings'   => [],
        ]);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('element_not_found', $result->get_error_code());
    }

    public function test_move_element_rejects_moving_a_section_into_its_own_child(): void
    {
        $post_id  = self::factory()->post->create(['post_type' => 'page']);
        update_post_meta($post_id, '_elementor_data', wp_json_encode([
            [
                'id'       => 'parent01',
                'elType'   => 'section',
                'settings' => [],
                'elements' => [
                    ['id' => 'child01', 'elType' => 'column', 'settings' => [], 'elements' => []],
                ],
            ],
        ]));

        $result = (new Move_Element())->handle([
            'post_id'    => $post_id,
            'element_id' => 'parent01',
            'parent_id'  => 'child01',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_move', $result->get_error_code());
    }

    public function test_remove_element_rejects_nonexistent_element_id(): void
    {
        $post_id = $this->make_page();
        $result  = (new Remove_Element())->handle(['post_id' => $post_id, 'element_id' => 'does-not-exist']);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('element_not_found', $result->get_error_code());
    }
}
