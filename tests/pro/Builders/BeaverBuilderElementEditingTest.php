<?php

namespace {
    require_once dirname(__DIR__, 2) . '/support/beaver-builder-model-double.php';
}

namespace WPMCP\Tests\Pro\Builders {

    use WPMCP\Pro\Gate;
    use WPMCP\Safety\{Rollback_Service, Snapshot_Store};
    use WPMCP\Tools\Builders\{Beaver_Builder_Content, Beaver_Builder_Nodes, Get_Builder_Content, Update_Builder_Content};

    /**
     * Issue #638: Beaver Builder element-level editing on the builder pair.
     * The page hash every write is pinned to, the plugin version, revisions
     * and their restore, publishing a pending draft, saved row/column/module
     * and layout templates (read, insert, save), and the catalog and design
     * system scope reads. Beaver Builder is not installed here: its rows are
     * stored as its editor writes them and its presence is toggled through
     * the wpmcp_beaver_builder_active filter.
     */
    class BeaverBuilderElementEditingTest extends \WP_UnitTestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            Gate::set_pro_for_tests(true);
            Snapshot_Store::install();
            \FLBuilderModel::$calls   = [];
            \FLBuilderModel::$modules = [];
            wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        }

        protected function tearDown(): void
        {
            Gate::set_pro_for_tests(null);
            remove_all_filters('wpmcp_beaver_builder_active');
            \FLBuilderModel::$modules = [];
            if (post_type_exists('fl-builder-template')) {
                unregister_post_type('fl-builder-template');
            }
            if (taxonomy_exists('fl-builder-template-type')) {
                unregister_taxonomy('fl-builder-template-type');
            }
            parent::tearDown();
        }

        // ------------------------------------------------------------ fixtures

        private function raw_meta(int $post_id, string $key, string $value): void
        {
            global $wpdb;
            $wpdb->insert($wpdb->postmeta, ['post_id' => $post_id, 'meta_key' => $key, 'meta_value' => $value]);
            wp_cache_delete($post_id, 'post_meta');
        }

        private static function ser($value): string
        {
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
            return serialize($value);
        }

        private static function settings(string $css = '.a{}'): object
        {
            return (object) ['css' => $css, 'js' => ''];
        }

        private function beaver_page(string $status = 'publish', bool $with_draft = true): int
        {
            $post_id = self::factory()->post->create(['post_type' => 'page', 'post_status' => $status, 'post_content' => '<p>Welcome</p>']);
            $this->raw_meta($post_id, '_fl_builder_enabled', '1');
            $this->raw_meta($post_id, '_fl_builder_data', BeaverBuilderNodesTest::fixture_serialized());
            $this->raw_meta($post_id, '_fl_builder_data_settings', self::ser(self::settings()));
            if ($with_draft) {
                $this->raw_meta($post_id, '_fl_builder_draft', BeaverBuilderNodesTest::fixture_serialized());
                $this->raw_meta($post_id, '_fl_builder_draft_settings', self::ser(self::settings()));
            }

            return $post_id;
        }

        /** A revision row of the page holding a layout, as Beaver Builder's save_revision() copies it. */
        private function revision(int $post_id, array $nodes, ?object $settings = null, string $date = '2026-01-01 10:00:00'): int
        {
            $id = self::factory()->post->create(['post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $post_id, 'post_name' => "{$post_id}-revision-v1", 'post_date' => $date, 'post_date_gmt' => $date]);
            $this->raw_meta($id, '_fl_builder_data', self::ser($nodes));
            $this->raw_meta($id, '_fl_builder_data_settings', self::ser($settings ?? self::settings('.rev{}')));

            return $id;
        }

        private function node(string $id, string $type, ?string $parent, int $position, array $settings, array $extra = []): object
        {
            $node           = new \stdClass();
            $node->node     = $id;
            $node->type     = $type;
            $node->parent   = $parent;
            $node->position = $position;
            $node->settings = (object) $settings;
            foreach ($extra as $key => $value) {
                $node->{$key} = $value;
            }

            return $node;
        }

        /** A saved template post as Beaver Builder's save_node_template() stores it. */
        private function template(string $type, array $nodes, bool $global = false, ?object $settings = null, string $title = 'Saved'): int
        {
            $id = self::factory()->post->create(['post_type' => 'fl-builder-template', 'post_title' => $title]);
            if (taxonomy_exists('fl-builder-template-type')) {
                wp_set_object_terms($id, $type, 'fl-builder-template-type');
            }
            $this->raw_meta($id, '_fl_builder_template_id', 'tpl' . $id);
            $this->raw_meta($id, '_fl_builder_template_global', $global ? '1' : '');
            $this->raw_meta($id, '_fl_builder_enabled', '1');
            $map = [];
            foreach ($nodes as $node) {
                $map[ $node->node ] = $node;
            }
            $this->raw_meta($id, '_fl_builder_data', self::ser($map));
            $this->raw_meta($id, '_fl_builder_data_settings', self::ser($settings ?? self::settings('')));

            return $id;
        }

        private function register_templates(): void
        {
            register_post_type('fl-builder-template', ['public' => false]);
            register_taxonomy('fl-builder-template-type', 'fl-builder-template');
        }

        private function update(int $post_id, array $args)
        {
            return (new Update_Builder_Content())->handle(['post_id' => $post_id, 'builder' => 'beaver-builder'] + $args);
        }

        private function pinned(int $post_id, array $args)
        {
            return $this->update($post_id, $args + ['expected_hash' => $this->read($post_id)['hash']]);
        }

        private function read(int $post_id): array
        {
            return (new Get_Builder_Content())->handle(['post_id' => $post_id]);
        }

        private function scope(string $scope, string $element = '')
        {
            return (new Get_Builder_Content())->handle(['builder' => 'beaver-builder', 'scope' => $scope, 'element' => $element]);
        }

        private function state(int $post_id): array
        {
            $post = get_post($post_id);

            return [$post->post_status, $post->post_content, get_post_meta($post_id)];
        }

        // ---------------------------------------------------------- read, hash

        public function test_read_reports_hash_plugin_version_and_layout_revisions_newest_first(): void
        {
            $post_id = $this->beaver_page();
            $old     = $this->revision($post_id, BeaverBuilderNodesTest::fixture(), null, '2026-01-01 10:00:00');
            $new     = $this->revision($post_id, Beaver_Builder_Nodes::remove(BeaverBuilderNodesTest::fixture(), 'r2w6e9q4t8zh'), null, '2026-02-01 10:00:00');
            $plain   = self::factory()->post->create(['post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $post_id, 'post_name' => "{$post_id}-revision-v1"]);

            $out = $this->read($post_id);

            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $out['hash']);
            $this->assertArrayHasKey('plugin_version', $out);
            $this->assertNull($out['plugin_version']);
            $this->assertSame([$new, $old], array_column($out['revisions'], 'id'));
            $this->assertNotContains($plain, array_column($out['revisions'], 'id'));
            $this->assertSame(['id', 'date_gmt', 'author', 'hash'], array_keys($out['revisions'][0]));
            $this->assertNotSame($out['revisions'][0]['hash'], $out['revisions'][1]['hash']);
        }

        public function test_hash_changes_with_the_draft_as_well_as_the_published_layout(): void
        {
            $post_id = $this->beaver_page();
            $hash    = $this->read($post_id)['hash'];

            update_post_meta($post_id, '_fl_builder_draft', Beaver_Builder_Nodes::update(BeaverBuilderNodesTest::fixture(), 'm2f6q9z3b7ud', ['heading' => 'Draft']));

            $this->assertNotSame($hash, $this->read($post_id)['hash']);
        }

        public function test_writes_to_a_page_with_a_layout_need_its_current_hash(): void
        {
            $post_id = $this->beaver_page();
            $before  = $this->state($post_id);
            $args    = ['operation' => 'update', 'path' => 'm2f6q9z3b7ud', 'attrs' => ['heading' => 'X']];

            $missing = $this->update($post_id, $args);
            $this->assertInstanceOf(\WP_Error::class, $missing);
            $this->assertSame('missing_expected_hash', $missing->get_error_code());

            $stale = $this->update($post_id, $args + ['expected_hash' => str_repeat('0', 64)]);
            $this->assertInstanceOf(\WP_Error::class, $stale);
            $this->assertSame('stale_expected_hash', $stale->get_error_code());
            $this->assertSame($before, $this->state($post_id));

            $ok = $this->pinned($post_id, $args);
            $this->assertIsArray($ok);
            $this->assertSame($this->read($post_id)['hash'], $ok['hash']);
        }

        public function test_a_blank_page_takes_its_first_layout_without_a_hash(): void
        {
            $post_id = self::factory()->post->create(['post_type' => 'page', 'post_content' => '']);

            $out = $this->update($post_id, ['operation' => 'add', 'to' => '', 'element' => ['type' => 'row']]);

            $this->assertIsArray($out);
            $this->assertCount(1, Beaver_Builder_Content::get_nodes($post_id));
        }

        // ------------------------------------------------------------ restore

        public function test_restore_puts_a_revision_layout_and_settings_back_and_rolls_back_exactly(): void
        {
            $post_id  = $this->beaver_page();
            $nodes    = Beaver_Builder_Nodes::update(BeaverBuilderNodesTest::fixture(), 'm2f6q9z3b7ud', ['heading' => 'From the revision']);
            $revision = $this->revision($post_id, $nodes, self::settings('.rev{}'));
            $before   = $this->state($post_id);

            $out = $this->pinned($post_id, ['operation' => 'restore', 'path' => (string) $revision]);

            $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
            $this->assertSame('From the revision', Beaver_Builder_Content::get_nodes($post_id)['m2f6q9z3b7ud']->settings->heading);
            $this->assertSame('From the revision', Beaver_Builder_Content::get_nodes($post_id, '_fl_builder_draft')['m2f6q9z3b7ud']->settings->heading);
            $this->assertSame('.rev{}', get_post_meta($post_id, '_fl_builder_data_settings', true)->css);
            $this->assertSame('.rev{}', get_post_meta($post_id, '_fl_builder_draft_settings', true)->css);

            Rollback_Service::restore_operation($out['operation_id']);
            $this->assertSame($before, $this->state($post_id));
        }

        public function test_restore_refuses_what_is_not_a_layout_revision_of_this_page(): void
        {
            $post_id = $this->beaver_page();
            $other   = $this->beaver_page();
            $foreign = $this->revision($other, BeaverBuilderNodesTest::fixture());
            $plain   = self::factory()->post->create(['post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $post_id, 'post_name' => "{$post_id}-revision-v1"]);
            $before  = $this->state($post_id);

            foreach (['', 'abc', (string) $foreign, (string) $plain, (string) $post_id] as $path) {
                $out = $this->pinned($post_id, ['operation' => 'restore', 'path' => $path]);
                $this->assertInstanceOf(\WP_Error::class, $out, "path {$path}");
                $this->assertSame('invalid_beaver_builder_request', $out->get_error_code());
            }
            $this->assertSame($before, $this->state($post_id));
        }

        // ------------------------------------------------------------ publish

        public function test_publish_puts_the_pending_draft_live_and_rolls_back_exactly(): void
        {
            $post_id = $this->beaver_page();
            delete_post_meta($post_id, '_fl_builder_draft');
            delete_post_meta($post_id, '_fl_builder_draft_settings');
            $draft = Beaver_Builder_Nodes::update(BeaverBuilderNodesTest::fixture(), 'm2f6q9z3b7ud', ['heading' => 'Draft only']);
            $this->raw_meta($post_id, '_fl_builder_draft', self::ser($draft));
            $this->raw_meta($post_id, '_fl_builder_draft_settings', self::ser(self::settings('.draft{}')));
            $before = $this->state($post_id);

            $edit = $this->pinned($post_id, ['operation' => 'update', 'path' => 'm2f6q9z3b7ud', 'attrs' => ['heading' => 'X']]);
            $this->assertSame('beaver_builder_draft_pending', $edit->get_error_code());

            $out = $this->pinned($post_id, ['operation' => 'publish']);

            $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
            $this->assertSame('Draft only', Beaver_Builder_Content::get_nodes($post_id)['m2f6q9z3b7ud']->settings->heading);
            $this->assertSame('.draft{}', get_post_meta($post_id, '_fl_builder_data_settings', true)->css);
            $this->assertFalse($this->read($post_id)['draft_pending']);
            $this->assertSame('publish', get_post_status($post_id));

            Rollback_Service::restore_operation($out['operation_id']);
            $this->assertSame($before, $this->state($post_id));
        }

        public function test_publish_sets_a_draft_page_live(): void
        {
            $post_id = $this->beaver_page('draft');
            $before  = $this->state($post_id);

            $out = $this->pinned($post_id, ['operation' => 'publish']);

            $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
            $this->assertSame('publish', get_post_status($post_id));

            Rollback_Service::restore_operation($out['operation_id']);
            $this->assertSame('draft', get_post_status($post_id));
            $this->assertSame($before[2], get_post_meta($post_id));
        }

        public function test_publish_of_a_live_page_with_nothing_pending_is_refused(): void
        {
            $post_id = $this->beaver_page();

            $out = $this->pinned($post_id, ['operation' => 'publish']);

            $this->assertInstanceOf(\WP_Error::class, $out);
            $this->assertSame('already_published', $out->get_error_code());
        }

        // ---------------------------------------------------------- templates

        public function test_templates_scope_lists_saved_templates_with_type_global_flag_and_tree(): void
        {
            $this->register_templates();
            $row    = $this->template('row', [
                $this->node('tr1', 'row', null, 0, []),
                $this->node('tg1', 'column-group', 'tr1', 0, []),
                $this->node('tc1', 'column', 'tg1', 0, ['size' => '100']),
            ], false, null, 'Hero row');
            $module = $this->template('module', [$this->node('tm1', 'module', null, 0, ['type' => 'heading', 'heading' => 'Hi'], ['template_id' => 'tplx', 'template_node_id' => 'tm1', 'global' => true, 'template_root_node' => true])], true);

            $out = $this->scope('templates');

            $this->assertSame('beaver-builder', $out['builder']);
            $this->assertSame('templates', $out['scope']);
            $byId = array_column($out['templates'], null, 'id');
            $this->assertSame('Hero row', $byId[ $row ]['name']);
            $this->assertSame('row', $byId[ $row ]['type']);
            $this->assertFalse($byId[ $row ]['global']);
            $this->assertSame('tr1', $byId[ $row ]['tree'][0]['node']);
            $this->assertSame('module', $byId[ $module ]['type']);
            $this->assertTrue($byId[ $module ]['global']);
        }

        public function test_add_a_saved_row_template_copies_it_in_with_new_ids_and_its_css_and_rolls_back(): void
        {
            $template = $this->template('row', [
                $this->node('tr1', 'row', null, 0, ['width' => 'full']),
                $this->node('tg1', 'column-group', 'tr1', 0, []),
                $this->node('tc1', 'column', 'tg1', 0, ['size' => '100']),
                $this->node('tm1', 'module', 'tc1', 0, ['type' => 'heading', 'heading' => 'From the template']),
            ], false, self::settings('.tpl{}'));
            $post_id = $this->beaver_page();
            $before  = $this->state($post_id);

            $out = $this->pinned($post_id, ['operation' => 'add', 'to' => '', 'index' => 1, 'element' => ['template' => $template]]);

            $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
            $tree = $this->read($post_id)['tree'];
            $this->assertCount(3, $tree);
            $this->assertSame($out['path'], $tree[1]['node']);
            $this->assertNotSame('tr1', $tree[1]['node']);
            $this->assertSame('From the template', $tree[1]['children'][0]['children'][0]['children'][0]['settings']->heading);
            $this->assertSame('r2w6e9q4t8zh', $tree[2]['node']);
            $this->assertCount(18, Beaver_Builder_Content::get_nodes($post_id));
            $this->assertSame(".a{}\n.tpl{}", get_post_meta($post_id, '_fl_builder_data_settings', true)->css);
            $this->assertSame(".a{}\n.tpl{}", get_post_meta($post_id, '_fl_builder_draft_settings', true)->css);

            Rollback_Service::restore_operation($out['operation_id']);
            $this->assertSame($before, $this->state($post_id));
        }

        public function test_add_a_global_module_template_places_only_its_linked_root(): void
        {
            $template = $this->template('module', [
                $this->node('tm1', 'module', null, 0, ['type' => 'heading', 'heading' => 'Global'], ['template_id' => 'tplg', 'template_node_id' => 'tm1', 'global' => true, 'template_root_node' => true]),
            ], true);
            $post_id = $this->beaver_page();

            $out = $this->pinned($post_id, ['operation' => 'add', 'to' => 'c9t4r7a2k5wf', 'index' => 0, 'element' => ['template' => (string) $template]]);

            $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
            $node = Beaver_Builder_Content::get_nodes($post_id)[ $out['path'] ];
            $this->assertSame('tplg', $node->template_id);
            $this->assertSame('tm1', $node->template_node_id);
            $this->assertTrue($node->global);
            $this->assertSame('c9t4r7a2k5wf', $node->parent);
            $this->assertSame(0, $node->position);
            $this->assertSame('.a{}', get_post_meta($post_id, '_fl_builder_data_settings', true)->css);
        }

        public function test_add_a_layout_template_places_all_its_rows(): void
        {
            $template = $this->template('layout', [
                $this->node('la', 'row', null, 0, []),
                $this->node('lb', 'row', null, 1, []),
            ]);
            $post_id = $this->beaver_page();

            $out = $this->pinned($post_id, ['operation' => 'add', 'to' => '', 'element' => ['template' => $template]]);

            $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
            $tree = $this->read($post_id)['tree'];
            $this->assertCount(4, $tree);
            $this->assertSame($out['path'], $tree[2]['node']);
        }

        public function test_add_from_an_unknown_or_misplaced_template_writes_nothing(): void
        {
            $module  = $this->template('module', [$this->node('tm1', 'module', null, 0, ['type' => 'heading'])]);
            $plain   = self::factory()->post->create(['post_type' => 'page']);
            $post_id = $this->beaver_page();
            $before  = $this->state($post_id);

            $cases = [
                ['template' => 999999],
                ['template' => $plain],
                ['template' => 'abc'],
                ['template' => $module, 'title' => 'x'],
            ];
            foreach ($cases as $element) {
                $out = $this->pinned($post_id, ['operation' => 'add', 'to' => '', 'element' => $element]);
                $this->assertInstanceOf(\WP_Error::class, $out);
                $this->assertSame('invalid_beaver_builder_request', $out->get_error_code());
            }
            $row = $this->template('row', [$this->node('tr1', 'row', null, 0, [])]);
            $out = $this->pinned($post_id, ['operation' => 'add', 'to' => 'c1h5j8w3e6tc', 'element' => ['template' => $row]]);
            $this->assertSame('invalid_beaver_builder_request', $out->get_error_code());
            $this->assertSame($before, $this->state($post_id));
        }

        public function test_save_template_needs_beaver_builder_with_saved_templates(): void
        {
            $post_id = $this->beaver_page();

            $out = $this->update($post_id, ['operation' => 'save_template', 'path' => 'r4k8p2m6x1qa', 'element' => ['title' => 'Mine']]);

            $this->assertInstanceOf(\WP_Error::class, $out);
            $this->assertSame('plugin_missing', $out->get_error_code());
            $this->assertStringContainsString('Install and activate Beaver Builder', $out->get_error_message());
        }

        public function test_save_template_saves_a_column_as_a_new_template_and_rollback_trashes_it(): void
        {
            add_filter('wpmcp_beaver_builder_active', '__return_true');
            $this->register_templates();
            $post_id = $this->beaver_page();
            $before  = $this->state($post_id);

            $out = $this->update($post_id, ['operation' => 'save_template', 'path' => 'c1h5j8w3e6tc', 'element' => ['title' => 'Two modules']]);

            $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
            $id = $out['template_id'];
            $this->assertSame('fl-builder-template', get_post_type($id));
            $this->assertSame('Two modules', get_the_title($id));
            $this->assertSame(['column'], wp_get_object_terms($id, 'fl-builder-template-type', ['fields' => 'slugs']));
            $this->assertNotSame('', (string) get_post_meta($id, '_fl_builder_template_id', true));
            $nodes = Beaver_Builder_Content::get_nodes($id);
            $this->assertCount(3, $nodes);
            $this->assertArrayNotHasKey('c1h5j8w3e6tc', $nodes);
            $root = Beaver_Builder_Nodes::tree($nodes)[0];
            $this->assertNull($nodes[ $root['node'] ]->parent);
            $this->assertSame(100, (int) $root['settings']->size);
            $this->assertSame(['heading', 'rich-text'], array_map(static fn ($m) => $m['settings']->type, $root['children']));
            $this->assertSame($before, $this->state($post_id));

            // It is offered back as a template.
            $this->assertContains($id, array_column($this->scope('templates')['templates'], 'id'));

            Rollback_Service::restore_operation($out['operation_id']);
            $this->assertSame('trash', get_post_status($id));
        }

        public function test_save_template_needs_a_title_and_a_known_node(): void
        {
            add_filter('wpmcp_beaver_builder_active', '__return_true');
            $this->register_templates();
            $post_id = $this->beaver_page();

            foreach ([['path' => 'r4k8p2m6x1qa'], ['path' => 'nope', 'element' => ['title' => 'A']]] as $args) {
                $out = $this->update($post_id, ['operation' => 'save_template'] + $args);
                $this->assertInstanceOf(\WP_Error::class, $out);
                $this->assertSame('invalid_beaver_builder_request', $out->get_error_code());
            }
        }

        // -------------------------------------------- catalog and design system

        public function test_catalog_needs_beaver_builder_loaded_and_lists_its_modules(): void
        {
            $out = $this->scope('catalog');
            $this->assertInstanceOf(\WP_Error::class, $out);
            $this->assertSame('builder_not_loaded', $out->get_error_code());

            add_filter('wpmcp_beaver_builder_active', '__return_true');
            \FLBuilderModel::$modules = [
                'heading' => (object) ['slug' => 'heading', 'name' => 'Heading', 'category' => 'Basic', 'enabled' => true, 'form' => [
                    'general' => ['title' => 'General', 'sections' => ['general' => ['fields' => [
                        'heading' => ['type' => 'text', 'label' => 'Heading', 'default' => ''],
                        'tag'     => ['type' => 'select', 'label' => 'Tag', 'default' => 'h2', 'options' => ['h1' => 'h1', 'h2' => 'h2']],
                    ]]]],
                ]],
            ];

            $list = $this->scope('catalog');
            $this->assertSame([['slug' => 'heading', 'name' => 'Heading', 'category' => 'Basic', 'enabled' => true]], $list['elements']);

            $one = $this->scope('catalog', 'heading');
            $this->assertSame(['heading', 'tag'], array_column($one['element']['fields'], 'name'));
            $this->assertSame('h2', $one['element']['fields'][1]['default']);
            $this->assertSame(['h1', 'h2'], $one['element']['fields'][1]['options']);

            $missing = $this->scope('catalog', 'nope');
            $this->assertSame('element_not_found', $missing->get_error_code());
        }

        public function test_design_system_reads_global_settings_color_presets_and_global_styles(): void
        {
            update_option('_fl_builder_settings', (object) ['row_width' => '1100', 'responsive_enabled' => '1']);
            update_option('_fl_builder_color_presets', ['ff0000', '00ff00']);
            update_option('_fl_builder_styles', (object) ['colors' => [['label' => 'Brand', 'color' => '#123456']]]);

            $out = $this->scope('design_system');

            $this->assertSame('beaver-builder', $out['builder']);
            $this->assertSame('1100', $out['global_settings']['row_width']);
            $this->assertSame(['ff0000', '00ff00'], $out['color_presets']);
            $this->assertSame('Brand', $out['global_styles']['colors'][0]['label']);
        }
    }
}
