<?php

namespace WPMCP\Tests\Pro\Elementor;

use WPMCP\Pro\Gate;
use WPMCP\Safety\Post_Locked;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Elementor\Update_Element;

/**
 * The Elementor element tools write through the same post write path as the
 * content tools, so they refuse a document another user is editing and set
 * Elementor's changed-by-MCP marker after a write (issue #452).
 */
class ElementorEditLockTest extends \WP_UnitTestCase
{
    private const SYNC = '\\Elementor\\Modules\\Mcp\\Utils\\Editor_Sync_State';

    protected function setUp(): void
    {
        parent::setUp();
        if (! wpmcp_elementor_active() || ! class_exists(self::SYNC)) {
            $this->markTestSkipped('Elementor 4.3 or later is not active.');
        }
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        wp_set_current_user(0);
        parent::tearDown();
    }

    private function page(): int
    {
        $post = self::factory()->post->create(['post_type' => 'page']);
        update_post_meta($post, '_elementor_edit_mode', 'builder');
        update_post_meta($post, '_elementor_data', wp_slash(wp_json_encode([
            ['id' => 'head001', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['title' => 'Original'], 'elements' => []],
        ])));
        return $post;
    }

    public function test_update_element_refuses_a_document_another_user_is_editing(): void
    {
        $post  = $this->page();
        $other = self::factory()->user->create(['role' => 'editor', 'display_name' => 'Bea Lockholder']);
        update_post_meta($post, '_edit_lock', (time() - 5) . ':' . $other);

        try {
            (new Update_Element())->handle(['post_id' => $post, 'element_id' => 'head001', 'settings' => ['title' => 'Agent']]);
            $this->fail('update-element wrote a document another user is editing.');
        } catch (Post_Locked $e) {
            $this->assertStringContainsString('Bea Lockholder', $e->getMessage());
            $this->assertSame('wpmcp_post_locked', $e->error()->get_error_code());
        }

        $raw = json_decode((string) get_post_meta($post, '_elementor_data', true), true);
        $this->assertSame('Original', $raw[0]['settings']['title']);
    }

    public function test_update_element_sets_the_marker_after_a_write(): void
    {
        $post = $this->page();

        (new Update_Element())->handle(['post_id' => $post, 'element_id' => 'head001', 'settings' => ['title' => 'Agent']]);

        $sync = self::SYNC;
        $this->assertGreaterThan(0, (int) $sync::get_mcp_mutation_time($post));
    }
}
