<?php

namespace {
    if (! class_exists('FLBuilderModel', false)) {
        /**
         * Stand-in for Beaver Builder's model class, recording the asset
         * cache calls wpmcp makes. Only reached when the
         * wpmcp_beaver_builder_active filter says the plugin is loaded.
         */
        class FLBuilderModel // phpcs:ignore
        {
            /** @var array<int,array{0:string,1:int}> */
            public static array $calls = [];

            public static function delete_all_asset_cache($post_id = false): void
            {
                self::$calls[] = ['delete_all_asset_cache', (int) $post_id];
            }

            public static function delete_node_template_asset_cache($post_id = false): void
            {
                self::$calls[] = ['delete_node_template_asset_cache', (int) $post_id];
            }

            /**
             * The builder UI is never open in a test. Other plugins probe
             * this whenever the class exists (Spectra does on every page view
             * in the live blocks leg), so the stand-in answers like the real one.
             */
            public static function is_builder_active(): bool
            {
                return false;
            }
        }
    }
}

namespace WPMCP\Tests\Pro\Builders {

    use WPMCP\Pro\Gate;
    use WPMCP\Safety\{Rollback_Service, Snapshot_Store};
    use WPMCP\Tools\Builders\{Beaver_Builder_Cache, Beaver_Builder_Content, Beaver_Builder_Nodes, Detect_Builder, Get_Builder_Content, Update_Builder_Content};

    /**
     * End to end through the builder dispatcher pair: detection, the tree
     * read, each node operation, whole-tree writes, published/draft
     * consistency, cache clearing and exact rollback of every Beaver Builder
     * meta row. Beaver Builder is not installed here, so its layout is stored
     * as the raw serialized rows its editor writes, and the plugin's presence
     * is toggled through the wpmcp_beaver_builder_active filter.
     */
    class BeaverBuilderAdapterTest extends \WP_UnitTestCase
    {
        private static function layout_settings(): string
        {
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
            return serialize((object) ['css' => '.fl-row { content: "\\2014"; }', 'js' => '']);
        }

        protected function setUp(): void
        {
            parent::setUp();
            Gate::set_pro_for_tests(true);
            Snapshot_Store::install();
            \FLBuilderModel::$calls = [];
        }

        protected function tearDown(): void
        {
            Gate::set_pro_for_tests(null);
            remove_all_filters('wpmcp_beaver_builder_active');
            parent::tearDown();
        }

        /** Store raw meta rows exactly as they sit in the database. */
        private function raw_meta(int $post_id, string $key, string $value): void
        {
            global $wpdb;
            $wpdb->insert($wpdb->postmeta, ['post_id' => $post_id, 'meta_key' => $key, 'meta_value' => $value]);
            wp_cache_delete($post_id, 'post_meta');
        }

        private function page(string $content = ''): int
        {
            return self::factory()->post->create(['post_type' => 'page', 'post_content' => $content]);
        }

        /** A published Beaver Builder page whose draft matches the live layout. */
        private function beaver_page(bool $with_draft = true): int
        {
            $post_id = $this->page('<div class="fl-builder-content"><h2>Welcome</h2></div>');
            $this->raw_meta($post_id, '_fl_builder_enabled', '1');
            $this->raw_meta($post_id, '_fl_builder_data', BeaverBuilderNodesTest::fixture_serialized());
            $this->raw_meta($post_id, '_fl_builder_data_settings', self::layout_settings());
            if ($with_draft) {
                $this->raw_meta($post_id, '_fl_builder_draft', BeaverBuilderNodesTest::fixture_serialized());
                $this->raw_meta($post_id, '_fl_builder_draft_settings', self::layout_settings());
            }

            return $post_id;
        }

        private function update(int $post_id, array $args)
        {
            return (new Update_Builder_Content())->handle(['post_id' => $post_id, 'builder' => 'beaver-builder'] + $args);
        }

        private function read(int $post_id): array
        {
            return (new Get_Builder_Content())->handle(['post_id' => $post_id]);
        }

        /** Every row as stored: post_content plus the raw meta values. */
        private function state(int $post_id): array
        {
            return [get_post($post_id)->post_content, get_post_meta($post_id)];
        }

        private function raw(int $post_id, string $key): ?string
        {
            global $wpdb;
            return $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $post_id, $key));
        }

        private function detect(int $post_id): string
        {
            return (new Detect_Builder())->handle(['post_id' => $post_id])['builder'];
        }

        // ------------------------------------------------------------ detection

        public function test_detects_beaver_builder_from_the_enabled_flag_only(): void
        {
            $enabled  = $this->beaver_page();
            $disabled = $this->page('<p>Back in the classic editor</p>');
            $this->raw_meta($disabled, '_fl_builder_enabled', '');
            $this->raw_meta($disabled, '_fl_builder_data', BeaverBuilderNodesTest::fixture_serialized());
            $elementor = $this->beaver_page();
            update_post_meta($elementor, '_elementor_edit_mode', 'builder');

            $this->assertSame('beaver-builder', $this->detect($enabled));
            $this->assertSame('classic', $this->detect($disabled));
            $this->assertSame('elementor', $this->detect($elementor));
        }

        public function test_detection_is_the_same_whether_or_not_the_plugin_is_active(): void
        {
            $post_id = $this->beaver_page();
            $plain   = $this->page('<p>Plain</p>');

            foreach ([false, true] as $active) {
                add_filter('wpmcp_beaver_builder_active', $active ? '__return_true' : '__return_false');
                $this->assertSame($active, Beaver_Builder_Cache::plugin_active());
                $this->assertSame('beaver-builder', $this->detect($post_id));
                $this->assertSame('classic', $this->detect($plain));
                remove_all_filters('wpmcp_beaver_builder_active');
            }
        }

        public function test_plugin_is_reported_inactive_in_this_environment(): void
        {
            $this->assertFalse(Beaver_Builder_Cache::plugin_active());
        }

        // ----------------------------------------------------------------- read

        public function test_read_returns_the_node_tree_and_state(): void
        {
            add_filter('wpmcp_beaver_builder_active', '__return_true');
            $out = $this->read($this->beaver_page());

            $this->assertSame('beaver-builder', $out['builder']);
            $this->assertTrue($out['plugin_active']);
            $this->assertFalse($out['draft_pending']);
            $this->assertSame('r4k8p2m6x1qa', $out['tree'][0]['node']);
            $this->assertSame('heading', $out['tree'][0]['children'][0]['children'][0]['children'][0]['settings']->type);
            $this->assertStringContainsString('"node":"m8y3n5c1p4ve"', (string) wp_json_encode($out));
        }

        // ---------------------------------------------------------- round trips

        public function test_update_writes_published_and_draft_and_rolls_back_exactly(): void
        {
            $post_id = $this->beaver_page();
            $before  = $this->state($post_id);

            $out = $this->update($post_id, ['operation' => 'update', 'path' => 'm8y3n5c1p4ve', 'attrs' => ['text' => '<p>Now in D:\\new "quoted"</p>']]);

            $this->assertSame('m8y3n5c1p4ve', $out['path']);
            $published = Beaver_Builder_Content::get_nodes($post_id);
            $this->assertSame('<p>Now in D:\\new "quoted"</p>', $published['m8y3n5c1p4ve']->settings->text);
            // Untouched nodes are stored exactly as before.
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
            $this->assertSame(serialize(BeaverBuilderNodesTest::fixture()['m5b1x8h3j6yg']), serialize($published['m5b1x8h3j6yg']));
            $this->assertSame($this->raw($post_id, '_fl_builder_data'), $this->raw($post_id, '_fl_builder_draft'));
            $this->assertFalse(Beaver_Builder_Content::draft_pending($post_id));
            // Layout settings rows are never touched.
            $this->assertSame(self::layout_settings(), $this->raw($post_id, '_fl_builder_data_settings'));

            Rollback_Service::restore_operation($out['operation_id']);

            $this->assertSame($before, $this->state($post_id));
            $this->assertSame(BeaverBuilderNodesTest::fixture_serialized(), $this->raw($post_id, '_fl_builder_data'));
            $this->assertSame(BeaverBuilderNodesTest::fixture_serialized(), $this->raw($post_id, '_fl_builder_draft'));
        }

        public function test_add_remove_and_move_each_roll_back_exactly(): void
        {
            $post_id = $this->beaver_page();
            $before  = $this->state($post_id);

            $add = $this->update($post_id, ['operation' => 'add', 'to' => 'c9t4r7a2k5wf', 'index' => 0, 'element' => [
                'type'     => 'module',
                'settings' => ['type' => 'rich-text', 'text' => '<p>First</p>'],
            ]]);
            $column = $this->read($post_id)['tree'][0]['children'][0]['children'][1];
            $this->assertSame($add['path'], $column['children'][0]['node']);
            $this->assertSame('m5b1x8h3j6yg', $column['children'][1]['node']);
            Rollback_Service::restore_operation($add['operation_id']);
            $this->assertSame($before, $this->state($post_id));

            $remove = $this->update($post_id, ['operation' => 'remove', 'path' => 'r4k8p2m6x1qa']);
            $this->assertArrayNotHasKey('path', $remove);
            $this->assertSame(['r2w6e9q4t8zh'], array_column($this->read($post_id)['tree'], 'node'));
            Rollback_Service::restore_operation($remove['operation_id']);
            $this->assertSame($before, $this->state($post_id));

            $move = $this->update($post_id, ['operation' => 'move', 'path' => 'm1q7w4e9r2fn', 'to' => 'c1h5j8w3e6tc', 'index' => 0]);
            $this->assertSame('button', $this->read($post_id)['tree'][0]['children'][0]['children'][0]['children'][0]['settings']->type);
            Rollback_Service::restore_operation($move['operation_id']);
            $this->assertSame($before, $this->state($post_id));
        }

        public function test_whole_tree_write_of_an_unchanged_read_is_byte_identical(): void
        {
            $post_id = $this->beaver_page();
            $before  = $this->state($post_id);

            $out = $this->update($post_id, ['content' => (string) wp_json_encode($this->read($post_id)['tree'])]);

            $this->assertSame('beaver-builder', $out['builder']);
            $this->assertSame($before, $this->state($post_id));
        }

        public function test_whole_tree_replace_rolls_back_exactly(): void
        {
            $post_id = $this->beaver_page();
            $before  = $this->state($post_id);

            $out = $this->update($post_id, ['content' => '[{"type":"row","children":[{"type":"column-group","children":[{"type":"column","settings":{"size":"100"},"children":[{"type":"module","settings":{"type":"html","html":"<b>a\\\\b</b>"}}]}]}]}]']);

            $tree = $this->read($post_id)['tree'];
            $this->assertCount(1, $tree);
            $this->assertSame('<b>a\\b</b>', $tree[0]['children'][0]['children'][0]['children'][0]['settings']->html);

            Rollback_Service::restore_operation($out['operation_id']);
            $this->assertSame($before, $this->state($post_id));
        }

        public function test_a_page_without_a_draft_keeps_none(): void
        {
            $post_id = $this->beaver_page(false);

            $this->update($post_id, ['operation' => 'update', 'path' => 'm2f6q9z3b7ud', 'attrs' => ['heading' => 'Hi']]);

            $this->assertFalse(metadata_exists('post', $post_id, '_fl_builder_draft'));
            $this->assertSame('Hi', Beaver_Builder_Content::get_nodes($post_id)['m2f6q9z3b7ud']->settings->heading);
        }

        public function test_first_write_to_a_blank_page_enables_the_builder_and_rollback_removes_it(): void
        {
            $post_id = $this->page();
            $before  = $this->state($post_id);

            $out = $this->update($post_id, ['operation' => 'add', 'to' => '', 'element' => [
                'type'     => 'row',
                'children' => [['type' => 'column-group', 'children' => [['type' => 'column', 'settings' => ['size' => '100']]]]],
            ]]);

            $this->assertSame('1', get_post_meta($post_id, '_fl_builder_enabled', true));
            $this->assertSame('beaver-builder', $this->detect($post_id));
            $this->assertCount(3, Beaver_Builder_Content::get_nodes($post_id));

            Rollback_Service::restore_operation($out['operation_id']);

            $this->assertSame($before, $this->state($post_id));
            $this->assertSame('classic', $this->detect($post_id));
        }

        // ---------------------------------------------------------------- cache

        public function test_writes_and_rollbacks_clear_the_asset_cache_only_when_active(): void
        {
            $post_id = $this->beaver_page();

            $out = $this->update($post_id, ['operation' => 'update', 'path' => 'm2f6q9z3b7ud', 'attrs' => ['heading' => 'A']]);
            Rollback_Service::restore_operation($out['operation_id']);
            $this->assertSame([], \FLBuilderModel::$calls);

            add_filter('wpmcp_beaver_builder_active', '__return_true');
            $out = $this->update($post_id, ['operation' => 'update', 'path' => 'm2f6q9z3b7ud', 'attrs' => ['heading' => 'B']]);
            $this->assertSame([['delete_all_asset_cache', $post_id], ['delete_node_template_asset_cache', $post_id]], \FLBuilderModel::$calls);

            \FLBuilderModel::$calls = [];
            Rollback_Service::restore_operation($out['operation_id']);
            $this->assertSame([['delete_all_asset_cache', $post_id], ['delete_node_template_asset_cache', $post_id]], \FLBuilderModel::$calls);
        }

        // --------------------------------------------------------------- errors

        public function test_unpublished_draft_edits_are_refused_and_nothing_is_written(): void
        {
            $post_id = $this->beaver_page();
            $draft   = Beaver_Builder_Nodes::update(BeaverBuilderNodesTest::fixture(), 'm2f6q9z3b7ud', ['heading' => 'Draft only']);
            delete_post_meta($post_id, '_fl_builder_draft');
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
            $this->raw_meta($post_id, '_fl_builder_draft', serialize($draft));
            $before = $this->state($post_id);

            $this->assertTrue($this->read($post_id)['draft_pending']);
            $out = $this->update($post_id, ['operation' => 'update', 'path' => 'm2f6q9z3b7ud', 'attrs' => ['heading' => 'X']]);

            $this->assertInstanceOf(\WP_Error::class, $out);
            $this->assertSame('beaver_builder_draft_pending', $out->get_error_code());
            $this->assertSame($before, $this->state($post_id));
        }

        public function test_rejected_requests_write_nothing(): void
        {
            $post_id   = $this->beaver_page();
            $before    = $this->state($post_id);
            $elementor = $this->page();
            update_post_meta($elementor, '_elementor_edit_mode', 'builder');
            $classic   = $this->page('<p>Existing classic content</p>');

            $cases = [
                [$post_id, [], 'invalid_beaver_builder_request'],
                [$post_id, ['content' => '{"not":"a list"}'], 'invalid_beaver_builder_request'],
                [$post_id, ['content' => 'not json'], 'invalid_beaver_builder_request'],
                [$post_id, ['content' => '[{"type":"column"}]'], 'invalid_beaver_builder_request'],
                [$post_id, ['operation' => 'explode'], 'invalid_beaver_builder_request'],
                [$post_id, ['operation' => 'update', 'path' => 'm2f6q9z3b7ud', 'attrs' => 'x'], 'invalid_beaver_builder_request'],
                [$post_id, ['operation' => 'add', 'to' => 'c1h5j8w3e6tc'], 'invalid_beaver_builder_request'],
                [$post_id, ['operation' => 'remove', 'path' => 'nope'], 'invalid_beaver_builder_request'],
                [$post_id, ['operation' => 'move', 'path' => 'r4k8p2m6x1qa', 'to' => 'c1h5j8w3e6tc'], 'invalid_beaver_builder_request'],
                [$elementor, ['operation' => 'remove', 'path' => 'r4k8p2m6x1qa'], 'unsupported_builder'],
                [$classic, ['content' => '[]'], 'unsupported_builder'],
            ];
            foreach ($cases as [$id, $args, $code]) {
                $out = $this->update($id, $args);
                $this->assertInstanceOf(\WP_Error::class, $out, (string) wp_json_encode($args));
                $this->assertSame($code, $out->get_error_code(), (string) wp_json_encode($args));
            }

            $this->assertSame($before, $this->state($post_id));
            $this->assertFalse(metadata_exists('post', $classic, '_fl_builder_data'));
        }
    }
}
