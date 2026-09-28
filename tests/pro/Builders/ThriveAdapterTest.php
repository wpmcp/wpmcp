<?php

namespace {
    if (! function_exists('tcb_post')) {
        /**
         * Stand-in for Thrive Architect's post helper, recording the calls
         * wpmcp makes. Its plain-text refresh writes post_content from the
         * stored layout the way Thrive does (tags stripped to a readable
         * copy). Only reached when the wpmcp_thrive_active filter says the
         * plugin is loaded.
         *
         * @param int $post_id
         * @return object
         */
        function tcb_post($post_id = null) // phpcs:ignore
        {
            return new class ((int) $post_id) {
                private int $id;

                public function __construct(int $id)
                {
                    $this->id = $id;
                }

                public function update_plain_text_content($content = null)
                {
                    $GLOBALS['wpmcp_thrive_plain_text_calls'][] = $this->id;
                    $html = null === $content ? (string) get_post_meta($this->id, 'tve_updated_post', true) : (string) $content;
                    wp_update_post(['ID' => $this->id, 'post_content' => wp_slash(strip_tags($html, '<h1><h2><p><a><img><strong>'))]);
                    return $this;
                }
            };
        }
    }
}

namespace WPMCP\Tests\Pro\Builders {

    use WPMCP\Pro\Gate;
    use WPMCP\Safety\{Rollback_Service, Snapshot_Store};
    use WPMCP\Tools\Builders\{Detect_Builder, Get_Builder_Content, Thrive_Content, Update_Builder_Content};

    /**
     * Thrive Architect through the builder dispatcher pair: detection, the
     * path tree read, each element operation and a whole-layout write, exact
     * rollback of every stored row, and what Thrive needs after a write.
     *
     * Thrive Architect is commercial and not installed here, so its rows are
     * written the way the editor's save stores them: the layout HTML in
     * tve_updated_post, the part before a Read More split in
     * tve_content_before_more with tve_content_more_found, the generated
     * element CSS in tve_custom_css, the tcb2_ready and tcb_editor_enabled
     * flags, a plain-text copy in post_content, and the optimized-asset rows
     * (_tve_lightspeed_version, _tve_base_inline_css, _tve_js_modules). A
     * landing page stores the same keys with a `_<template>` suffix. The
     * plugin's presence is toggled through the wpmcp_thrive_active filter.
     */
    class ThriveAdapterTest extends \WP_UnitTestCase
    {
        private const CSS = '[data-css="tve-u-18a0c1f0a03"]{margin-bottom:0px !important;}[data-css="tve-u-18a0c1f0a0b"]{padding:10px;}';

        protected function setUp(): void
        {
            parent::setUp();
            Gate::set_pro_for_tests(true);
            Snapshot_Store::install();
            $GLOBALS['wpmcp_thrive_plain_text_calls'] = [];
        }

        protected function tearDown(): void
        {
            Gate::set_pro_for_tests(null);
            remove_all_filters('wpmcp_thrive_active');
            unset($GLOBALS['wpmcp_thrive_plain_text_calls']);
            parent::tearDown();
        }

        private function page(string $content, array $meta = []): int
        {
            $post_id = self::factory()->post->create(['post_type' => 'page', 'post_content' => wp_slash($content)]);
            foreach ($meta as $key => $value) {
                update_post_meta($post_id, $key, is_string($value) ? wp_slash($value) : $value);
            }

            return $post_id;
        }

        /** A page as the editor's save leaves it, optionally as a landing page. */
        private function thrive_page(string $template = '', array $extra = []): int
        {
            $suffix = '' === $template ? '' : '_' . $template;
            $meta   = [
                'tcb2_ready'                        => '1',
                'tve_updated_post' . $suffix        => ThriveHtmlTest::FIXTURE,
                'tve_content_before_more' . $suffix => ThriveHtmlTest::FIXTURE,
                'tve_content_more_found' . $suffix  => '',
                'tve_custom_css' . $suffix          => self::CSS,
                'tve_user_custom_css' . $suffix     => '',
                'tve_globals' . $suffix             => ['font_cls' => []],
                '_tve_lightspeed_version'           => '1',
                '_tve_base_inline_css'              => '.thrv_text_element{position:relative}',
                '_tve_js_modules'                   => ['button'],
            ];
            if ('' === $template) {
                $meta['tcb_editor_enabled'] = '1';
            } else {
                $meta['tve_landing_page'] = $template;
            }

            return $this->page('<h1>Welcome</h1><p>Paragraph <strong>two</strong></p>', $extra + $meta);
        }

        private function update(int $post_id, array $args)
        {
            return (new Update_Builder_Content())->handle(['post_id' => $post_id, 'builder' => 'thrive'] + $args);
        }

        private function state(int $post_id): array
        {
            clean_post_cache($post_id);
            return [get_post($post_id)->post_content, get_post_meta($post_id)];
        }

        private function layout(int $post_id, string $key = 'tve_updated_post'): string
        {
            return (string) get_post_meta($post_id, $key, true);
        }

        private function tree(int $post_id): array
        {
            return (new Get_Builder_Content())->handle(['post_id' => $post_id])['tree'];
        }

        private function assert_optimized_assets_dropped(int $post_id): void
        {
            $this->assertSame('0', get_post_meta($post_id, '_tve_lightspeed_version', true));
            $this->assertFalse(metadata_exists('post', $post_id, '_tve_js_modules'));
        }

        // -------------------------------------------------------- detection

        public function test_detects_thrive_from_the_editor_flag_and_from_a_landing_page(): void
        {
            foreach ([$this->thrive_page(), $this->thrive_page('tcb2-blank-page')] as $post_id) {
                $this->assertSame('thrive', (new Detect_Builder())->handle(['post_id' => $post_id])['builder']);
            }
        }

        public function test_thrive_wins_over_block_markers_its_plain_text_copy_keeps(): void
        {
            $post_id = $this->thrive_page('', ['tve_updated_post' => ThriveHtmlTest::TEXT]);
            wp_update_post(['ID' => $post_id, 'post_content' => "<!-- wp:paragraph -->\n<p>Copy</p>\n<!-- /wp:paragraph -->"]);

            $this->assertSame('thrive', (new Detect_Builder())->handle(['post_id' => $post_id])['builder']);
        }

        public function test_other_builders_win_and_a_disabled_editor_is_not_thrive(): void
        {
            $elementor = $this->page('', ['_elementor_edit_mode' => 'builder', 'tcb_editor_enabled' => '1', 'tve_updated_post' => ThriveHtmlTest::TEXT]);
            $disabled  = $this->page('<p>Plain</p>', ['tcb2_ready' => '1', 'tcb_editor_disabled' => '1', 'tve_updated_post' => ThriveHtmlTest::TEXT]);
            $leftover  = $this->page('<p>Plain</p>', ['tve_updated_post' => ThriveHtmlTest::TEXT]);

            $this->assertSame('elementor', (new Detect_Builder())->handle(['post_id' => $elementor])['builder']);
            $this->assertSame('classic', (new Detect_Builder())->handle(['post_id' => $disabled])['builder']);
            $this->assertSame('classic', (new Detect_Builder())->handle(['post_id' => $leftover])['builder']);
        }

        public function test_plugin_state_follows_the_filter_and_is_off_here(): void
        {
            $this->assertFalse(Thrive_Content::plugin_active());
            add_filter('wpmcp_thrive_active', '__return_true');
            $this->assertTrue(Thrive_Content::plugin_active());
        }

        // ------------------------------------------------------------- read

        public function test_read_returns_tree_layout_and_landing_page_state(): void
        {
            $post_id = $this->thrive_page();

            $out = (new Get_Builder_Content())->handle(['post_id' => $post_id]);

            $this->assertSame('thrive', $out['builder']);
            $this->assertSame($post_id, $out['post_id']);
            $this->assertFalse($out['plugin_active']);
            $this->assertSame('', $out['landing_page']);
            $this->assertSame(ThriveHtmlTest::FIXTURE, $out['content']);
            $this->assertSame('section', $out['tree'][0]['type']);
            $this->assertSame('acme-countdown', $out['tree'][1]['children'][0]['type']);
        }

        public function test_a_landing_page_is_read_and_written_under_its_template_keys(): void
        {
            $post_id = $this->thrive_page('tcb2-blank-page', ['tve_updated_post' => '<div class="thrv_wrapper thrv_text_element"><p>Stale</p></div>']);
            $before  = $this->state($post_id);

            $read = (new Get_Builder_Content())->handle(['post_id' => $post_id]);
            $this->assertSame('tcb2-blank-page', $read['landing_page']);
            $this->assertSame(ThriveHtmlTest::FIXTURE, $read['content']);

            $out = $this->update($post_id, ['operation' => 'remove', 'path' => '1.0']);

            $expected = str_replace(ThriveHtmlTest::UNKNOWN, '', ThriveHtmlTest::FIXTURE);
            $this->assertSame($expected, $this->layout($post_id, 'tve_updated_post_tcb2-blank-page'));
            $this->assertSame($expected, $this->layout($post_id, 'tve_content_before_more_tcb2-blank-page'));
            $this->assertSame('<div class="thrv_wrapper thrv_text_element"><p>Stale</p></div>', $this->layout($post_id));

            Rollback_Service::restore_operation($out['operation_id']);
            $this->assertSame($before, $this->state($post_id));
        }

        // ------------------------------------------------------ round trips

        public function test_update_keeps_every_other_byte_drops_optimized_assets_and_rolls_back_exactly(): void
        {
            $post_id = $this->thrive_page();
            $before  = $this->state($post_id);

            $out = $this->update($post_id, ['operation' => 'update', 'path' => '0.0', 'text' => '<h1 data-css="tve-u-18a0c1f0a04">New \\ "title"</h1>']);

            $this->assertSame('thrive', $out['builder']);
            $this->assertSame('0.0', $out['path']);
            $expected = str_replace(
                '<h1 data-css="tve-u-18a0c1f0a04" style="text-align: center;">Welcome to C:\\Sites\\thrive &amp; "friends"</h1>',
                '<h1 data-css="tve-u-18a0c1f0a04">New \\ "title"</h1>',
                ThriveHtmlTest::FIXTURE
            );
            $this->assertSame($expected, $this->layout($post_id));
            $this->assertSame($expected, $this->layout($post_id, 'tve_content_before_more'));
            $this->assertSame(self::CSS, $this->layout($post_id, 'tve_custom_css'), 'Element CSS is keyed by data-css and stays');
            $this->assert_optimized_assets_dropped($post_id);
            $this->assertSame([], $GLOBALS['wpmcp_thrive_plain_text_calls'], 'No plain-text refresh without the plugin');

            Rollback_Service::restore_operation($out['operation_id']);

            $this->assertSame($before, $this->state($post_id));
        }

        public function test_add_remove_and_move_each_roll_back_exactly(): void
        {
            $post_id = $this->thrive_page();
            $before  = $this->state($post_id);

            $add = $this->update($post_id, ['operation' => 'add', 'to' => '0.1.1', 'element' => ['attrs' => ['class' => 'thrv_wrapper thrv_text_element'], 'text' => '<p>Side</p>']]);
            $this->assertSame('0.1.1.0', $add['path']);
            $this->assertSame('<p>Side</p>', $this->tree($post_id)[0]['children'][1]['children'][1]['children'][0]['text']);
            Rollback_Service::restore_operation($add['operation_id']);
            $this->assertSame($before, $this->state($post_id));

            $remove = $this->update($post_id, ['operation' => 'remove', 'path' => '0.2']);
            $this->assertArrayNotHasKey('path', $remove);
            $this->assertStringNotContainsString('thrv-button', $this->layout($post_id));
            Rollback_Service::restore_operation($remove['operation_id']);
            $this->assertSame($before, $this->state($post_id));

            $move = $this->update($post_id, ['operation' => 'move', 'path' => '1.0', 'to' => '0.1.1', 'index' => 0]);
            $this->assertStringContainsString('<div class="tcb-col">' . ThriveHtmlTest::UNKNOWN . '</div>', $this->layout($post_id));
            Rollback_Service::restore_operation($move['operation_id']);
            $this->assertSame($before, $this->state($post_id));
        }

        public function test_whole_layout_write_and_rollback(): void
        {
            $post_id = $this->thrive_page();
            $before  = $this->state($post_id);

            $out = $this->update($post_id, ['content' => ThriveHtmlTest::BOX]);

            $this->assertSame(ThriveHtmlTest::BOX, $this->layout($post_id));
            $this->assertSame('content-box', $this->tree($post_id)[0]['type']);
            Rollback_Service::restore_operation($out['operation_id']);
            $this->assertSame($before, $this->state($post_id));
        }

        public function test_with_the_plugin_loaded_its_plain_text_copy_is_refreshed_and_rolled_back(): void
        {
            add_filter('wpmcp_thrive_active', '__return_true');
            $post_id = $this->thrive_page();
            $before  = $this->state($post_id);

            $out = $this->update($post_id, ['operation' => 'update', 'path' => '1.1', 'text' => '<p>Rewritten</p>']);

            $this->assertSame([$post_id], $GLOBALS['wpmcp_thrive_plain_text_calls']);
            $this->assertStringContainsString('<p>Rewritten</p>', get_post($post_id)->post_content);

            Rollback_Service::restore_operation($out['operation_id']);
            $this->assertSame($before, $this->state($post_id));
        }

        public function test_optimized_asset_rows_are_not_created_on_a_page_without_them(): void
        {
            $post_id = $this->page('', ['tcb2_ready' => '1', 'tcb_editor_enabled' => '1', 'tve_updated_post' => ThriveHtmlTest::FIXTURE]);

            $this->update($post_id, ['operation' => 'remove', 'path' => '1']);

            $this->assertFalse(metadata_exists('post', $post_id, '_tve_lightspeed_version'));
            $this->assertFalse(metadata_exists('post', $post_id, 'tve_content_before_more'));
        }

        // ---------------------------------------------------- read more split

        public function test_the_read_more_part_follows_edits_before_the_split_and_ignores_edits_after_it(): void
        {
            $post_id = $this->thrive_page('', [
                'tve_content_more_found'  => '1',
                'tve_content_before_more' => ThriveHtmlTest::SECTION,
            ]);

            $this->update($post_id, ['operation' => 'update', 'path' => '1.1', 'text' => '<p>After the split</p>']);
            $this->assertSame(ThriveHtmlTest::SECTION, $this->layout($post_id, 'tve_content_before_more'));

            $this->update($post_id, ['operation' => 'remove', 'path' => '0.2']);
            $this->assertSame(
                str_replace('<div class="thrv_wrapper thrv-button" data-css="tve-u-18a0c1f0a07">' . ThriveHtmlTest::BUTTON_INNER . '</div>', '', ThriveHtmlTest::SECTION),
                $this->layout($post_id, 'tve_content_before_more')
            );
        }

        public function test_an_edit_across_the_read_more_split_is_refused(): void
        {
            $post_id = $this->thrive_page('', [
                'tve_content_more_found'  => '1',
                'tve_content_before_more' => ThriveHtmlTest::SECTION,
            ]);
            $before = $this->state($post_id);

            $out = $this->update($post_id, ['operation' => 'move', 'path' => '1.0', 'to' => '0', 'index' => 0]);

            $this->assertInstanceOf(\WP_Error::class, $out);
            $this->assertSame('invalid_thrive_request', $out->get_error_code());
            $this->assertSame($before, $this->state($post_id));
        }

        // ----------------------------------------------------------- errors

        public function test_rejected_requests_write_nothing(): void
        {
            $post_id = $this->thrive_page();
            $before  = $this->state($post_id);
            $avada   = $this->page('[fusion_builder_container][/fusion_builder_container]', ['fusion_builder_status' => 'active']);
            $blank   = $this->page('');

            $cases = [
                [$post_id, [], 'invalid_thrive_request'],
                [$post_id, ['content' => ['x']], 'invalid_thrive_request'],
                [$post_id, ['content' => '<div class="thrv_wrapper"><p>unclosed</div>'], 'invalid_thrive_request'],
                [$post_id, ['operation' => 'explode'], 'invalid_thrive_request'],
                [$post_id, ['operation' => 'update', 'path' => '0.0', 'text' => 5], 'invalid_thrive_request'],
                [$post_id, ['operation' => 'update', 'path' => '1', 'text' => '<p>x</p>'], 'invalid_thrive_request'],
                [$post_id, ['operation' => 'add', 'to' => '1'], 'invalid_thrive_request'],
                [$post_id, ['operation' => 'remove', 'path' => '9'], 'invalid_thrive_request'],
                [$avada, ['operation' => 'remove', 'path' => '0'], 'unsupported_builder'],
                [$blank, ['content' => ThriveHtmlTest::TEXT], 'unsupported_builder'],
            ];
            foreach ($cases as [$id, $args, $code]) {
                $out = $this->update($id, $args);
                $this->assertInstanceOf(\WP_Error::class, $out);
                $this->assertSame($code, $out->get_error_code(), wp_json_encode($args));
            }

            // An Avada write is refused on a Thrive page too.
            $cross = (new Update_Builder_Content())->handle(['post_id' => $post_id, 'builder' => 'avada', 'operation' => 'remove', 'path' => '0']);
            $this->assertSame('unsupported_builder', $cross->get_error_code());

            $this->assertSame($before, $this->state($post_id));
        }
    }
}
