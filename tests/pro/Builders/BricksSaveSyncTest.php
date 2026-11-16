<?php

namespace Bricks {
    if (! defined('BRICKS_VERSION') && ! class_exists('Bricks\\Assets_Files')) {
        /**
         * Stand-in for Bricks' CSS file generator, recording the calls wpmcp
         * makes. Only reached when the wpmcp_bricks_active filter says the
         * theme is loaded. Never declared beside the real theme (the live
         * Bricks leg): Bricks' autoloader cannot resolve this class, so a
         * class_exists() check alone would let the stand-in shadow it.
         */
        class Assets_Files // phpcs:ignore
        {
            /** @param int $post_id @param string $content_type @param array $elements */
            public static function generate_post_css_file($post_id, $content_type, $elements) // phpcs:ignore
            {
                // Bricks returns early unless CSS loading is set to external files (files.php:507).
                if ('inline' === ($GLOBALS['wpmcp_bricks_css_loading'] ?? 'file')) {
                    return;
                }
                $GLOBALS['wpmcp_bricks_css_calls'][] = [(int) $post_id, (string) $content_type, count((array) $elements)];
            }
        }
    }
}

namespace WPMCP\Tests\Pro\Builders {

    use WPMCP\Pro\Gate;
    use WPMCP\Safety\{Rollback_Service, Snapshot_Store};
    use WPMCP\Tools\Builders\Update_Builder_Content;

    /**
     * Issue #914: a Bricks page write must leave the page the way Bricks' own
     * save does. It sets `_bricks_editor_mode` (Bricks ajax.php), regenerates
     * the page CSS file the way Bricks' save_post hook does (assets/files.php),
     * refuses a post Bricks cannot edit, and undo restores the editor mode and
     * the CSS state too.
     */
    class BricksSaveSyncTest extends \WP_UnitTestCase
    {
        private const ELEMENTS = [
            ['id' => 'abc123', 'name' => 'section', 'parent' => 0, 'children' => [], 'settings' => []],
        ];

        protected function setUp(): void
        {
            parent::setUp();
            Gate::set_pro_for_tests(true);
            Snapshot_Store::install();
            $GLOBALS['wpmcp_bricks_css_calls'] = [];
            unset($GLOBALS['wpmcp_bricks_css_loading']);
        }

        protected function tearDown(): void
        {
            Gate::set_pro_for_tests(null);
            remove_all_filters('wpmcp_bricks_active');
            parent::tearDown();
        }

        private function write(int $post_id, array $elements = self::ELEMENTS)
        {
            return (new Update_Builder_Content())->handle([
                'post_id' => $post_id,
                'builder' => 'bricks',
                'content' => wp_json_encode($elements),
            ]);
        }

        public function test_write_sets_editor_mode_bricks_on_a_wordpress_mode_page(): void
        {
            $id = self::factory()->post->create(['post_type' => 'page']);
            update_post_meta($id, '_bricks_editor_mode', 'wordpress');

            $this->assertIsArray($this->write($id));

            $this->assertSame('bricks', get_post_meta($id, '_bricks_editor_mode', true));
        }

        public function test_write_sets_editor_mode_on_a_page_with_no_mode_row(): void
        {
            $id = self::factory()->post->create(['post_type' => 'page']);

            $this->assertIsArray($this->write($id));

            $this->assertSame('bricks', get_post_meta($id, '_bricks_editor_mode', true));
        }

        public function test_writing_an_empty_layout_puts_the_page_back_to_wordpress_mode(): void
        {
            $id = self::factory()->post->create(['post_type' => 'page']);
            $this->write($id);

            $this->assertIsArray($this->write($id, []));

            $this->assertSame('wordpress', get_post_meta($id, '_bricks_editor_mode', true));
        }

        public function test_header_template_is_always_bricks_mode(): void
        {
            $id = self::factory()->post->create(['post_type' => 'bricks_template']);
            update_post_meta($id, '_bricks_template_type', 'header');

            $this->assertIsArray($this->write($id, []));

            $this->assertSame('bricks', get_post_meta($id, '_bricks_editor_mode', true));
        }

        public function test_write_regenerates_the_bricks_css_file_when_bricks_is_active(): void
        {
            add_filter('wpmcp_bricks_active', '__return_true');
            $id = self::factory()->post->create(['post_type' => 'page']);

            $this->assertIsArray($this->write($id));

            $this->assertSame([[$id, 'content', 1]], $GLOBALS['wpmcp_bricks_css_calls']);
        }

        public function test_write_does_not_touch_bricks_css_when_bricks_is_not_active(): void
        {
            add_filter('wpmcp_bricks_active', '__return_false');
            $id = self::factory()->post->create(['post_type' => 'page']);

            $this->assertIsArray($this->write($id));

            $this->assertSame([], $GLOBALS['wpmcp_bricks_css_calls']);
        }

        public function test_css_is_not_generated_for_a_page_left_in_wordpress_mode(): void
        {
            add_filter('wpmcp_bricks_active', '__return_true');
            $id = self::factory()->post->create(['post_type' => 'page']);

            $this->assertIsArray($this->write($id, []));

            $this->assertSame([], $GLOBALS['wpmcp_bricks_css_calls']);
        }

        public function test_rollback_restores_editor_mode_and_regenerates_css(): void
        {
            add_filter('wpmcp_bricks_active', '__return_true');
            $id = self::factory()->post->create(['post_type' => 'page']);
            update_post_meta($id, '_bricks_page_content_2', [['id' => 'old111', 'name' => 'section', 'parent' => 0, 'children' => [], 'settings' => []]]);
            update_post_meta($id, '_bricks_editor_mode', 'wordpress');

            $out = $this->write($id);
            $this->assertSame('bricks', get_post_meta($id, '_bricks_editor_mode', true));
            $GLOBALS['wpmcp_bricks_css_calls'] = [];

            Rollback_Service::restore_operation($out['operation_id']);

            $this->assertSame('wordpress', get_post_meta($id, '_bricks_editor_mode', true));
            $this->assertSame('old111', get_post_meta($id, '_bricks_page_content_2', true)[0]['id']);
            // Restored to WordPress mode, so Bricks would not render it and no CSS is built.
            $this->assertSame([], $GLOBALS['wpmcp_bricks_css_calls']);
        }

        public function test_rollback_rebuilds_css_from_the_restored_layout(): void
        {
            add_filter('wpmcp_bricks_active', '__return_true');
            $id = self::factory()->post->create(['post_type' => 'page']);
            update_post_meta($id, '_bricks_page_content_2', self::ELEMENTS);
            update_post_meta($id, '_bricks_editor_mode', 'bricks');

            $two = array_merge(self::ELEMENTS, [['id' => 'def456', 'name' => 'heading', 'parent' => 0, 'children' => [], 'settings' => []]]);
            $out = $this->write($id, $two);
            $this->assertSame([[$id, 'content', 2]], $GLOBALS['wpmcp_bricks_css_calls']);
            $GLOBALS['wpmcp_bricks_css_calls'] = [];

            Rollback_Service::restore_operation($out['operation_id']);

            $this->assertSame([[$id, 'content', 1]], $GLOBALS['wpmcp_bricks_css_calls']);
            $this->assertSame('bricks', get_post_meta($id, '_bricks_editor_mode', true));
        }

        public function test_rollback_of_a_first_write_removes_the_editor_mode_row(): void
        {
            $id = self::factory()->post->create(['post_type' => 'page']);

            $out = $this->write($id);
            Rollback_Service::restore_operation($out['operation_id']);

            $this->assertFalse(metadata_exists('post', $id, '_bricks_editor_mode'));
            $this->assertFalse(metadata_exists('post', $id, '_bricks_page_content_2'));
        }

        public function test_refuses_a_post_type_bricks_cannot_edit(): void
        {
            $id = self::factory()->post->create(['post_type' => 'post']);

            $out = $this->write($id);

            $this->assertWPError($out);
            $this->assertSame('not_a_bricks_page', $out->get_error_code());
            $this->assertStringContainsString('post', $out->get_error_message());
            $this->assertFalse(metadata_exists('post', $id, '_bricks_page_content_2'));
            $this->assertFalse(metadata_exists('post', $id, '_bricks_editor_mode'));
        }

        public function test_allows_a_post_type_enabled_in_the_bricks_settings(): void
        {
            update_option('bricks_global_settings', ['postTypes' => ['page', 'post']]);
            $id = self::factory()->post->create(['post_type' => 'post']);

            $this->assertIsArray($this->write($id));
        }

        public function test_allows_a_post_that_already_holds_bricks_content(): void
        {
            $id = self::factory()->post->create(['post_type' => 'post']);
            update_post_meta($id, '_bricks_page_content_2', self::ELEMENTS);

            $this->assertIsArray($this->write($id));
        }

        public function test_allows_a_bricks_template(): void
        {
            $id = self::factory()->post->create(['post_type' => 'bricks_template']);

            $this->assertIsArray($this->write($id));
        }

        public function test_header_template_writes_the_header_row_and_builds_css_from_it(): void
        {
            add_filter('wpmcp_bricks_active', '__return_true');
            $id = self::factory()->post->create(['post_type' => 'bricks_template']);
            update_post_meta($id, '_bricks_template_type', 'header');

            $this->assertIsArray($this->write($id));

            $this->assertSame(self::ELEMENTS, get_post_meta($id, '_bricks_page_header_2', true));
            $this->assertFalse(metadata_exists('post', $id, '_bricks_page_content_2'));
            $this->assertSame([[$id, 'header', 1]], $GLOBALS['wpmcp_bricks_css_calls']);
        }

        public function test_footer_template_writes_the_footer_row(): void
        {
            $id = self::factory()->post->create(['post_type' => 'bricks_template']);
            update_post_meta($id, '_bricks_template_type', 'footer');

            $this->assertIsArray($this->write($id));

            $this->assertSame(self::ELEMENTS, get_post_meta($id, '_bricks_page_footer_2', true));
            $this->assertFalse(metadata_exists('post', $id, '_bricks_page_content_2'));
        }

        public function test_header_template_undo_restores_the_header_row_and_mode(): void
        {
            add_filter('wpmcp_bricks_active', '__return_true');
            $id  = self::factory()->post->create(['post_type' => 'bricks_template']);
            $old = [['id' => 'old111', 'name' => 'section', 'parent' => 0, 'children' => [], 'settings' => []]];
            update_post_meta($id, '_bricks_template_type', 'header');
            update_post_meta($id, '_bricks_page_header_2', $old);

            $out = $this->write($id);
            $GLOBALS['wpmcp_bricks_css_calls'] = [];
            Rollback_Service::restore_operation($out['operation_id']);

            $this->assertSame($old, get_post_meta($id, '_bricks_page_header_2', true));
            $this->assertSame([[$id, 'header', 1]], $GLOBALS['wpmcp_bricks_css_calls']);
        }

        public function test_undo_of_a_first_write_with_bricks_active_clears_the_rows_and_rebuilds_css(): void
        {
            add_filter('wpmcp_bricks_active', '__return_true');
            $id = self::factory()->post->create(['post_type' => 'page']);

            $out = $this->write($id);
            $GLOBALS['wpmcp_bricks_css_calls'] = [];
            Rollback_Service::restore_operation($out['operation_id']);

            $this->assertFalse(metadata_exists('post', $id, '_bricks_page_content_2'));
            $this->assertFalse(metadata_exists('post', $id, '_bricks_editor_mode'));
            // Rebuilt from the now empty layout, so the stale file is replaced.
            $this->assertSame([[$id, 'content', 0]], $GLOBALS['wpmcp_bricks_css_calls']);
        }

        public function test_undo_to_an_empty_layout_restores_wordpress_mode_without_css(): void
        {
            add_filter('wpmcp_bricks_active', '__return_true');
            $id = self::factory()->post->create(['post_type' => 'page']);
            update_post_meta($id, '_bricks_page_content_2', []);
            update_post_meta($id, '_bricks_editor_mode', 'wordpress');

            $out = $this->write($id);
            $this->assertSame('bricks', get_post_meta($id, '_bricks_editor_mode', true));
            $GLOBALS['wpmcp_bricks_css_calls'] = [];
            Rollback_Service::restore_operation($out['operation_id']);

            $this->assertSame([], get_post_meta($id, '_bricks_page_content_2', true));
            $this->assertSame('wordpress', get_post_meta($id, '_bricks_editor_mode', true));
            $this->assertSame([], $GLOBALS['wpmcp_bricks_css_calls']);
        }

        public function test_nothing_is_generated_when_bricks_css_loading_is_inline(): void
        {
            add_filter('wpmcp_bricks_active', '__return_true');
            $GLOBALS['wpmcp_bricks_css_loading'] = 'inline';
            $id = self::factory()->post->create(['post_type' => 'page']);

            $this->assertIsArray($this->write($id));

            $this->assertSame([], $GLOBALS['wpmcp_bricks_css_calls']);
            $this->assertSame('bricks', get_post_meta($id, '_bricks_editor_mode', true));
        }
    }
}
