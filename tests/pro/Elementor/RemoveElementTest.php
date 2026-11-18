<?php

namespace WPMCP\Tests\Pro\Elementor;

use WPMCP\Pro\Gate;
use WPMCP\Safety\{Rollback_Service, Snapshot_Store};
use WPMCP\Tools\Elementor\Remove_Element;

class RemoveElementTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
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
                'id'       => 'sect001',
                'elType'   => 'section',
                'settings' => [],
                'elements' => [
                    [
                        'id'         => 'head001',
                        'elType'     => 'widget',
                        'widgetType' => 'heading',
                        'settings'   => ['title' => 'Hello'],
                        'elements'   => [],
                    ],
                ],
            ],
        ]));
        return $post_id;
    }

    public function test_removes_element_and_its_children(): void
    {
        if (! wpmcp_elementor_active()) {
            $this->markTestSkipped('Elementor not active');
        }

        $post_id = $this->make_page();

        $out = (new Remove_Element())->handle([
            'post_id'    => $post_id,
            'element_id' => 'head001',
        ]);

        $this->assertArrayHasKey('operation_id', $out);

        $raw = json_decode(get_post_meta($post_id, '_elementor_data', true), true);
        $this->assertSame([], $raw[0]['elements']);
    }

    public function test_removing_parent_removes_its_children(): void
    {
        if (! wpmcp_elementor_active()) {
            $this->markTestSkipped('Elementor not active');
        }

        $post_id = $this->make_page();

        $out = (new Remove_Element())->handle([
            'post_id'    => $post_id,
            'element_id' => 'sect001',
        ]);

        $this->assertArrayHasKey('operation_id', $out);

        $raw = json_decode(get_post_meta($post_id, '_elementor_data', true), true);
        $this->assertSame([], $raw);
    }

    public function test_rollback_restores_removed_element(): void
    {
        if (! wpmcp_elementor_active()) {
            $this->markTestSkipped('Elementor not active');
        }

        $post_id = $this->make_page();
        $before  = get_post_meta($post_id, '_elementor_data', true);

        $out = (new Remove_Element())->handle([
            'post_id'    => $post_id,
            'element_id' => 'head001',
        ]);

        Rollback_Service::restore_operation($out['operation_id']);

        $after = get_post_meta($post_id, '_elementor_data', true);
        $this->assertSame($before, $after);
    }

    public function test_returns_wp_error_when_post_id_missing(): void
    {
        if (! wpmcp_elementor_active()) {
            $this->markTestSkipped('Elementor not active');
        }

        $out = (new Remove_Element())->handle(['element_id' => 'head001']);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('missing_post_id', $out->get_error_code());
    }

    public function test_returns_wp_error_when_element_id_missing(): void
    {
        if (! wpmcp_elementor_active()) {
            $this->markTestSkipped('Elementor not active');
        }

        $post_id = $this->make_page();
        $out     = (new Remove_Element())->handle(['post_id' => $post_id]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('missing_element_id', $out->get_error_code());
    }

    public function test_returns_wp_error_when_element_not_found(): void
    {
        if (! wpmcp_elementor_active()) {
            $this->markTestSkipped('Elementor not active');
        }

        $post_id = $this->make_page();
        $out     = (new Remove_Element())->handle([
            'post_id'    => $post_id,
            'element_id' => 'does-not-exist',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('element_not_found', $out->get_error_code());
    }
}
