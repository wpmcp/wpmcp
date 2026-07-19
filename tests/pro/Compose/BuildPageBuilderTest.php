<?php

namespace WPMCP\Tests\Pro\Compose;

use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Compose\Build_Page;
use WPMCP\Tools\Rollback_Operation;

/**
 * Builder (Elementor) dialect of build-page (issue #57), gated PRO via
 * Pro\Gate. Same one-operation atomicity contract as the free dialect.
 */
class BuildPageBuilderTest extends \WP_UnitTestCase
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

    private function builder_spec(): array
    {
        return [
            'title'   => 'Builder Landing',
            'dialect' => 'elementor',
            'content' => [
                ['type' => 'container', 'settings' => ['flex_direction' => 'column'], 'children' => [
                    ['type' => 'container', 'children' => [
                        ['type' => 'widget', 'settings' => [
                            'widget'          => 'heading',
                            'widget_settings' => ['title' => 'Welcome'],
                        ]],
                        ['type' => 'widget', 'settings' => [
                            'widget'          => 'text-editor',
                            'widget_settings' => ['editor' => '<p>Built in one call.</p>'],
                        ]],
                    ]],
                ]],
            ],
        ];
    }

    private function page_count(): int
    {
        return count(get_posts(['post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1]));
    }

    public function test_elementor_dialect_requires_pro(): void
    {
        Gate::set_pro_for_tests(false);
        $pages = $this->page_count();

        try {
            (new Build_Page())->handle(['spec' => $this->builder_spec()]);
            $this->fail('Expected the builder dialect to be PRO-gated.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('PRO', $e->getMessage());
        }

        $this->assertSame($pages, $this->page_count());
    }

    public function test_builds_an_elementor_page_from_a_nested_builder_tree(): void
    {
        if (! wpmcp_elementor_active()) {
            $this->markTestSkipped('Elementor not active');
        }

        $out = (new Build_Page())->handle(['spec' => $this->builder_spec()]);

        $this->assertTrue($out['recoverable']);
        $this->assertSame(4, $out['created_elements']);

        $elements = json_decode(get_post_meta($out['post_id'], '_elementor_data', true), true);
        $this->assertSame('container', $elements[0]['elType']);
        $this->assertSame('column', $elements[0]['settings']['flex_direction']);
        $inner = $elements[0]['elements'][0];
        $this->assertSame('container', $inner['elType']);
        $this->assertSame('widget', $inner['elements'][0]['elType']);
        $this->assertSame('heading', $inner['elements'][0]['widgetType']);
        $this->assertSame('Welcome', $inner['elements'][0]['settings']['title']);
        $this->assertNotEmpty($inner['elements'][0]['id']);

        $this->assertSame('builder', get_post_meta($out['post_id'], '_elementor_edit_mode', true));
    }

    public function test_unknown_widget_type_is_rejected_before_any_write(): void
    {
        if (! wpmcp_elementor_active()) {
            $this->markTestSkipped('Elementor not active');
        }

        $pages = $this->page_count();
        $spec  = $this->builder_spec();

        $spec['content'][0]['children'][0]['children'][0]['settings']['widget'] = 'not-a-widget';

        try {
            (new Build_Page())->handle(['spec' => $spec]);
            $this->fail('Expected the unknown widget type to be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('content[0].children[0].children[0]', $e->getMessage());
            $this->assertStringContainsString('not-a-widget', $e->getMessage());
        }

        $this->assertSame($pages, $this->page_count());
    }

    public function test_rollback_removes_the_builder_page(): void
    {
        if (! wpmcp_elementor_active()) {
            $this->markTestSkipped('Elementor not active');
        }

        $out = (new Build_Page())->handle(['spec' => $this->builder_spec()]);

        $rolled = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);

        $this->assertTrue($rolled['restored']);
        $this->assertNull(get_post($out['post_id']));
    }
}
