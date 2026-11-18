<?php

namespace WPMCP\Tests\Pro\Elementor;

use WPMCP\Pro\Gate;
use WPMCP\Safety\{Rollback_Service, Snapshot_Store};
use WPMCP\Tools\Elementor\Update_Element;

class UpdateElementTest extends \WP_UnitTestCase
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
                        'settings'   => ['title' => 'Original Title'],
                        'elements'   => [],
                    ],
                ],
            ],
        ]));
        return $post_id;
    }

    public function test_updates_widget_setting(): void
    {
        if (! wpmcp_elementor_active()) {
            $this->markTestSkipped('Elementor not active');
        }

        $post_id = $this->make_page();

        $out = (new Update_Element())->handle([
            'post_id'    => $post_id,
            'element_id' => 'head001',
            'settings'   => ['title' => 'Updated Title'],
        ]);

        $this->assertArrayHasKey('operation_id', $out);

        $raw = json_decode(get_post_meta($post_id, '_elementor_data', true), true);
        $this->assertSame('Updated Title', $raw[0]['elements'][0]['settings']['title']);
    }

    public function test_rollback_restores_prior_elementor_data(): void
    {
        if (! wpmcp_elementor_active()) {
            $this->markTestSkipped('Elementor not active');
        }

        $post_id = $this->make_page();
        $before  = get_post_meta($post_id, '_elementor_data', true);

        $out = (new Update_Element())->handle([
            'post_id'    => $post_id,
            'element_id' => 'head001',
            'settings'   => ['title' => 'Updated Title'],
        ]);

        Rollback_Service::restore_operation($out['operation_id']);

        $after = get_post_meta($post_id, '_elementor_data', true);
        $this->assertSame($before, $after);

        $decoded = json_decode($after, true);
        $this->assertSame('Original Title', $decoded[0]['elements'][0]['settings']['title']);
    }

    public function test_returns_wp_error_when_post_id_missing(): void
    {
        if (! wpmcp_elementor_active()) {
            $this->markTestSkipped('Elementor not active');
        }

        $out = (new Update_Element())->handle(['element_id' => 'head001', 'settings' => []]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('missing_post_id', $out->get_error_code());
    }

    public function test_returns_wp_error_when_element_id_missing(): void
    {
        if (! wpmcp_elementor_active()) {
            $this->markTestSkipped('Elementor not active');
        }

        $post_id = $this->make_page();
        $out     = (new Update_Element())->handle(['post_id' => $post_id, 'settings' => []]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('missing_element_id', $out->get_error_code());
    }

    public function test_returns_wp_error_when_element_not_found(): void
    {
        if (! wpmcp_elementor_active()) {
            $this->markTestSkipped('Elementor not active');
        }

        $post_id = $this->make_page();
        $out     = (new Update_Element())->handle([
            'post_id'    => $post_id,
            'element_id' => 'does-not-exist',
            'settings'   => ['title' => 'x'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('element_not_found', $out->get_error_code());
    }
}
