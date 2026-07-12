<?php

namespace WPMCP\Tests\Pro\Elementor;

use WPMCP\Pro\Gate;
use WPMCP\Tools\Elementor\Get_Elementor_Data;

class GetElementorDataTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function make_page_with_data(array $elements): int
    {
        $post_id = self::factory()->post->create(['post_type' => 'page']);
        update_post_meta($post_id, '_elementor_data', wp_json_encode($elements));
        return $post_id;
    }

    public function test_returns_element_tree_for_page(): void
    {
        if (! wpmcp_elementor_active()) {
            $this->markTestSkipped('Elementor not active');
        }

        $post_id = $this->make_page_with_data([
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
        ]);

        $out = (new Get_Elementor_Data())->handle(['post_id' => $post_id]);

        $this->assertIsArray($out);
        $this->assertArrayHasKey('elements', $out);
        $this->assertSame('sect001', $out['elements'][0]['id']);
        $this->assertSame('section', $out['elements'][0]['elType']);
        $this->assertSame('head001', $out['elements'][0]['elements'][0]['id']);
        $this->assertSame('heading', $out['elements'][0]['elements'][0]['widgetType']);
        $this->assertSame('Hello', $out['elements'][0]['elements'][0]['settings']['title']);
    }

    public function test_returns_wp_error_when_post_id_missing(): void
    {
        if (! wpmcp_elementor_active()) {
            $this->markTestSkipped('Elementor not active');
        }

        $out = (new Get_Elementor_Data())->handle([]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('missing_post_id', $out->get_error_code());
    }

    public function test_returns_empty_elements_for_page_with_no_elementor_data(): void
    {
        if (! wpmcp_elementor_active()) {
            $this->markTestSkipped('Elementor not active');
        }

        $post_id = self::factory()->post->create(['post_type' => 'page']);

        $out = (new Get_Elementor_Data())->handle(['post_id' => $post_id]);

        $this->assertIsArray($out);
        $this->assertSame([], $out['elements']);
    }
}
