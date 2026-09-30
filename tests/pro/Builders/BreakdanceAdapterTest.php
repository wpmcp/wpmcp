<?php

namespace Breakdance\Render {
    if (! function_exists('Breakdance\\Render\\generateCacheForPost')) {
        /**
         * Stand-in for Breakdance's cache generator, recording the calls
         * wpmcp makes. Only reached when the wpmcp_breakdance_active filter
         * says the plugin is loaded.
         *
         * @param int $postId
         * @return array<string,string>
         */
        function generateCacheForPost($postId) // phpcs:ignore
        {
            $GLOBALS['wpmcp_breakdance_cache_calls'][] = (int) $postId;
            return [];
        }
    }
}

namespace WPMCP\Tests\Pro\Builders {

    use WPMCP\Pro\Gate;
    use WPMCP\Safety\{Rollback_Service, Snapshot_Store};
    use WPMCP\Tools\Builders\{Breakdance_Cache, Breakdance_Content, Breakdance_Tree, Detect_Builder, Get_Builder_Content, Update_Builder_Content};

    /**
     * End to end through the builder dispatcher pair: detection, the tree
     * read, each node operation, whole-tree writes, cache handling and exact
     * rollback of every Breakdance meta row. Breakdance is not installed
     * here, so its rows are stored as the raw values its editor writes
     * (`_breakdance_data` plus the two generated cache rows), and the
     * plugin's presence is toggled through the wpmcp_breakdance_active filter.
     */
    class BreakdanceAdapterTest extends \WP_UnitTestCase
    {
        private const CSS_CACHE = '{"postDefaultsCssFilePath":"post-%d-defaults.css?v=1155f31da02cad46c9e5b6720833b74d","postCssFilePath":"post-%d.css?v=c22edc53a2414462781adf3a29c69c6a"}';
        private const DEPENDENCY_CACHE = '{"scripts":[],"inlineScripts":[],"styles":[],"inlineStyles":[],"googleFonts":["Space Grotesk"]}';

        protected function setUp(): void
        {
            parent::setUp();
            Gate::set_pro_for_tests(true);
            Snapshot_Store::install();
            $GLOBALS['wpmcp_breakdance_cache_calls'] = [];
        }

        protected function tearDown(): void
        {
            Gate::set_pro_for_tests(null);
            remove_all_filters('wpmcp_breakdance_active');
            remove_all_filters('wpmcp_breakdance_meta_prefix');
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

        /** A Breakdance page with its generated cache rows in place. */
        private function breakdance_page(): int
        {
            $post_id = $this->page('<!-- wp:paragraph --><p>Written before Breakdance took over</p><!-- /wp:paragraph -->');
            $this->raw_meta($post_id, '_breakdance_data', BreakdanceTreeTest::fixture_meta());
            $this->raw_meta($post_id, '_breakdance_css_file_paths_cache', sprintf(self::CSS_CACHE, $post_id, $post_id));
            $this->raw_meta($post_id, '_breakdance_dependency_cache', self::DEPENDENCY_CACHE);

            return $post_id;
        }

        private function update(int $post_id, array $args)
        {
            return (new Update_Builder_Content())->handle(['post_id' => $post_id, 'builder' => 'breakdance'] + $args);
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

        /** The stored document, decoded the way Breakdance reads it. */
        private function stored_tree(int $post_id): array
        {
            $outer = json_decode((string) $this->raw($post_id, '_breakdance_data'), true);
            return json_decode($outer['tree_json_string'], true);
        }

        private function detect(int $post_id): string
        {
            return (new Detect_Builder())->handle(['post_id' => $post_id])['builder'];
        }

        // ------------------------------------------------------------ detection

        public function test_detects_breakdance_from_a_valid_stored_tree(): void
        {
            $page      = $this->breakdance_page();
            $broken    = $this->page('<p>Plain</p>');
            $this->raw_meta($broken, '_breakdance_data', '{"tree_json_string":"not a tree"}');
            $elementor = $this->breakdance_page();
            update_post_meta($elementor, '_elementor_edit_mode', 'builder');

            $this->assertSame('breakdance', $this->detect($page));
            $this->assertSame('classic', $this->detect($broken));
            $this->assertSame('elementor', $this->detect($elementor));
        }

        public function test_detection_is_the_same_whether_or_not_the_plugin_is_active(): void
        {
            $post_id = $this->breakdance_page();
            $plain   = $this->page('<p>Plain</p>');

            foreach ([false, true] as $active) {
                add_filter('wpmcp_breakdance_active', $active ? '__return_true' : '__return_false');
                $this->assertSame($active, Breakdance_Cache::plugin_active());
                $this->assertSame('breakdance', $this->detect($post_id));
                $this->assertSame('classic', $this->detect($plain));
                remove_all_filters('wpmcp_breakdance_active');
            }
        }

        public function test_plugin_is_reported_inactive_in_this_environment(): void
        {
            $this->assertFalse(Breakdance_Cache::plugin_active());
        }

        // ----------------------------------------------------------------- read

        public function test_read_returns_the_node_tree_and_state(): void
        {
            add_filter('wpmcp_breakdance_active', '__return_true');
            $out = $this->read($this->breakdance_page());

            $this->assertSame('breakdance', $out['builder']);
            $this->assertTrue($out['plugin_active']);
            $this->assertSame(112, $out['next_node_id']);
            $this->assertSame(100, $out['tree'][0]->id);
            $this->assertSame('EssentialElements\\Heading', $out['tree'][0]->children[0]->data->type);
            $this->assertSame('h1', $out['tree'][0]->children[0]->data->properties->content->content->tags);
        }

        // ---------------------------------------------------------- round trips

        public function test_update_writes_the_tree_and_rolls_back_every_row_exactly(): void
        {
            $post_id = $this->breakdance_page();
            $before  = $this->state($post_id);

            $out = $this->update($post_id, ['operation' => 'update', 'path' => '102', 'attrs' => ['content' => ['content' => ['text' => 'Now in D:\\new "quoted"']]]]);

            $this->assertSame('breakdance', $out['builder']);
            $this->assertSame('102', $out['path']);
            $tree = $this->stored_tree($post_id);
            $this->assertSame('Now in D:\\new "quoted"', $tree['root']['children'][0]['children'][1]['data']['properties']['content']['content']['text']);
            $this->assertSame(112, $tree['_nextNodeId']);
            // The stale generated cache rows are dropped so Breakdance rebuilds them.
            $this->assertNull($this->raw($post_id, '_breakdance_css_file_paths_cache'));
            $this->assertNull($this->raw($post_id, '_breakdance_dependency_cache'));
            // post_content is Breakdance's to manage; wpmcp leaves it alone.
            $this->assertSame($before[0], get_post($post_id)->post_content);

            Rollback_Service::restore_operation($out['operation_id']);

            $this->assertSame($before, $this->state($post_id));
            $this->assertSame(BreakdanceTreeTest::fixture_meta(), $this->raw($post_id, '_breakdance_data'));
            $this->assertSame(self::DEPENDENCY_CACHE, $this->raw($post_id, '_breakdance_dependency_cache'));
        }

        public function test_add_remove_and_move_each_roll_back_exactly(): void
        {
            $post_id = $this->breakdance_page();
            $before  = $this->state($post_id);

            $add = $this->update($post_id, ['operation' => 'add', 'to' => '107', 'index' => 0, 'element' => [
                'type'       => 'EssentialElements\\Heading',
                'properties' => ['content' => ['content' => ['text' => 'First', 'tags' => 'h3']]],
            ]]);
            $this->assertSame('112', $add['path']);
            $column = $this->read($post_id)['tree'][1]->children[0]->children[1];
            $this->assertSame([112, 108], [$column->children[0]->id, $column->children[1]->id]);
            $this->assertSame(113, $this->read($post_id)['next_node_id']);
            Rollback_Service::restore_operation($add['operation_id']);
            $this->assertSame($before, $this->state($post_id));

            $remove = $this->update($post_id, ['operation' => 'remove', 'path' => '103']);
            $this->assertArrayNotHasKey('path', $remove);
            $this->assertSame([100, 111], array_map(static fn ($n) => $n->id, $this->read($post_id)['tree']));
            Rollback_Service::restore_operation($remove['operation_id']);
            $this->assertSame($before, $this->state($post_id));

            $move = $this->update($post_id, ['operation' => 'move', 'path' => '106', 'to' => '107', 'index' => 0]);
            $this->assertSame(106, $this->read($post_id)['tree'][1]->children[0]->children[1]->children[0]->id);
            Rollback_Service::restore_operation($move['operation_id']);
            $this->assertSame($before, $this->state($post_id));
        }

        public function test_whole_tree_write_of_an_unchanged_read_is_byte_identical(): void
        {
            $post_id = $this->breakdance_page();
            $before  = $this->state($post_id);

            $out = $this->update($post_id, ['content' => (string) wp_json_encode($this->read($post_id)['tree'])]);

            $this->assertSame('breakdance', $out['builder']);
            $this->assertSame($before, $this->state($post_id));
        }

        public function test_whole_tree_replace_rolls_back_exactly(): void
        {
            $post_id = $this->breakdance_page();
            $before  = $this->state($post_id);

            $out = $this->update($post_id, ['content' => '[{"type":"EssentialElements\\\\Section","children":[{"type":"EssentialElements\\\\Text","properties":{"content":{"content":{"text":"<b>a\\\\b</b>"}}}}]}]']);

            $tree = $this->read($post_id)['tree'];
            $this->assertCount(1, $tree);
            $this->assertSame(112, $tree[0]->id);
            $this->assertSame('<b>a\\b</b>', $tree[0]->children[0]->data->properties->content->content->text);

            Rollback_Service::restore_operation($out['operation_id']);
            $this->assertSame($before, $this->state($post_id));
        }

        public function test_first_write_to_a_blank_page_creates_the_tree_and_rollback_removes_it(): void
        {
            $post_id = $this->page();
            $before  = $this->state($post_id);

            $out = $this->update($post_id, ['operation' => 'add', 'to' => '', 'element' => [
                'type'     => 'EssentialElements\\Section',
                'children' => [['type' => 'EssentialElements\\Heading', 'properties' => ['content' => ['content' => ['text' => 'Hello', 'tags' => 'h1']]]]],
            ]]);

            $this->assertSame('100', $out['path']);
            $this->assertSame('breakdance', $this->detect($post_id));
            $tree = $this->stored_tree($post_id);
            $this->assertSame(1, $tree['root']['id']);
            $this->assertSame(102, $tree['_nextNodeId']);

            Rollback_Service::restore_operation($out['operation_id']);

            $this->assertSame($before, $this->state($post_id));
            $this->assertSame('classic', $this->detect($post_id));
        }

        // ---------------------------------------------------------------- cache

        public function test_cache_is_regenerated_through_breakdance_only_when_active(): void
        {
            $post_id = $this->breakdance_page();

            $out = $this->update($post_id, ['operation' => 'update', 'path' => '101', 'attrs' => ['content' => ['content' => ['text' => 'A']]]]);
            Rollback_Service::restore_operation($out['operation_id']);
            $this->assertSame([], $GLOBALS['wpmcp_breakdance_cache_calls']);

            add_filter('wpmcp_breakdance_active', '__return_true');
            $before = $this->state($post_id);
            $out    = $this->update($post_id, ['operation' => 'update', 'path' => '101', 'attrs' => ['content' => ['content' => ['text' => 'B']]]]);
            $this->assertSame([$post_id], $GLOBALS['wpmcp_breakdance_cache_calls']);

            $GLOBALS['wpmcp_breakdance_cache_calls'] = [];
            Rollback_Service::restore_operation($out['operation_id']);
            $this->assertSame([$post_id], $GLOBALS['wpmcp_breakdance_cache_calls']);
            $this->assertSame($before, $this->state($post_id));
        }

        // --------------------------------------------------------------- errors

        public function test_rejected_requests_write_nothing(): void
        {
            $post_id   = $this->breakdance_page();
            $before    = $this->state($post_id);
            $elementor = $this->page();
            update_post_meta($elementor, '_elementor_edit_mode', 'builder');
            $classic   = $this->page('<p>Existing classic content</p>');

            $cases = [
                [$post_id, [], 'invalid_breakdance_request'],
                [$post_id, ['content' => '{"not":"a list"}'], 'invalid_breakdance_request'],
                [$post_id, ['content' => 'not json'], 'invalid_breakdance_request'],
                [$post_id, ['content' => '[{"type":"EssentialElements\\\\Column"}]'], 'invalid_breakdance_request'],
                [$post_id, ['operation' => 'explode'], 'invalid_breakdance_request'],
                [$post_id, ['operation' => 'update', 'path' => '101', 'attrs' => 'x'], 'invalid_breakdance_request'],
                [$post_id, ['operation' => 'add', 'to' => '105'], 'invalid_breakdance_request'],
                [$post_id, ['operation' => 'remove', 'path' => '999'], 'invalid_breakdance_request'],
                [$post_id, ['operation' => 'move', 'path' => '103', 'to' => '105'], 'invalid_breakdance_request'],
                [$elementor, ['operation' => 'remove', 'path' => '101'], 'unsupported_builder'],
                [$classic, ['content' => '[]'], 'unsupported_builder'],
            ];
            foreach ($cases as [$id, $args, $code]) {
                $out = $this->update($id, $args);
                $this->assertInstanceOf(\WP_Error::class, $out, (string) wp_json_encode($args));
                $this->assertSame($code, $out->get_error_code(), (string) wp_json_encode($args));
            }

            $this->assertSame($before, $this->state($post_id));
            $this->assertFalse(metadata_exists('post', $classic, '_breakdance_data'));
        }

        // ------------------------------------------ Breakdance 1.x (#457)

        /**
         * A page saved by Breakdance 1.x: its set_meta() passes the key
         * straight to update_post_meta(), so the rows carry no leading
         * underscore (`breakdance_data` and the two generated cache rows).
         */
        private function legacy_page(): int
        {
            $post_id = $this->page('<p>Written before Breakdance took over</p>');
            $this->raw_meta($post_id, 'breakdance_data', BreakdanceTreeTest::fixture_meta());
            $this->raw_meta($post_id, 'breakdance_css_file_paths_cache', sprintf(self::CSS_CACHE, $post_id, $post_id));
            $this->raw_meta($post_id, 'breakdance_dependency_cache', self::DEPENDENCY_CACHE);

            return $post_id;
        }

        public function test_a_breakdance_1x_page_is_detected_and_read(): void
        {
            $post_id = $this->legacy_page();
            $broken  = $this->page('<p>Plain</p>');
            $this->raw_meta($broken, 'breakdance_data', '{"tree_json_string":"not a tree"}');

            $this->assertSame('breakdance', $this->detect($post_id));
            $this->assertSame('classic', $this->detect($broken));

            $out = $this->read($post_id);
            $this->assertSame('breakdance', $out['builder']);
            $this->assertSame(112, $out['next_node_id']);
            $this->assertSame('About the studio', $out['tree'][0]->children[0]->data->properties->content->content->text);
            $this->assertSame(BreakdanceTreeTest::fixture_json(), Breakdance_Tree::encode(Breakdance_Content::get_document($post_id)));
        }

        public function test_a_breakdance_1x_page_is_edited_in_its_own_key_and_rolls_back_exactly(): void
        {
            $post_id = $this->legacy_page();
            $before  = $this->state($post_id);

            $out = $this->update($post_id, ['operation' => 'update', 'path' => '101', 'attrs' => ['content' => ['content' => ['text' => 'Edited "here"']]]]);

            $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
            $outer = json_decode((string) $this->raw($post_id, 'breakdance_data'), true);
            $tree  = json_decode($outer['tree_json_string'], true);
            $this->assertSame('Edited "here"', $tree['root']['children'][0]['children'][0]['data']['properties']['content']['content']['text']);
            // Never both keys: the underscored row is not created.
            $this->assertFalse(metadata_exists('post', $post_id, '_breakdance_data'));
            // Its own stale cache rows are the ones dropped.
            $this->assertNull($this->raw($post_id, 'breakdance_css_file_paths_cache'));
            $this->assertNull($this->raw($post_id, 'breakdance_dependency_cache'));
            $this->assertSame('breakdance', $this->detect($post_id));

            Rollback_Service::restore_operation($out['operation_id']);

            $this->assertSame($before, $this->state($post_id));
            $this->assertSame(BreakdanceTreeTest::fixture_meta(), $this->raw($post_id, 'breakdance_data'));
            $this->assertFalse(metadata_exists('post', $post_id, '_breakdance_data'));
        }

        public function test_an_unchanged_whole_tree_write_to_a_1x_page_stores_nothing(): void
        {
            $post_id = $this->legacy_page();
            $before  = $this->state($post_id);

            $out = $this->update($post_id, ['content' => (string) wp_json_encode($this->read($post_id)['tree'])]);

            $this->assertSame('breakdance', $out['builder']);
            $this->assertSame($before, $this->state($post_id));
        }

        public function test_rollback_of_a_1x_page_regenerates_its_cache_when_active(): void
        {
            add_filter('wpmcp_breakdance_active', '__return_true');
            $post_id = $this->legacy_page();
            $before  = $this->state($post_id);

            $out = $this->update($post_id, ['operation' => 'remove', 'path' => '103']);
            $this->assertSame([$post_id], $GLOBALS['wpmcp_breakdance_cache_calls']);

            $GLOBALS['wpmcp_breakdance_cache_calls'] = [];
            Rollback_Service::restore_operation($out['operation_id']);

            $this->assertSame([$post_id], $GLOBALS['wpmcp_breakdance_cache_calls']);
            $this->assertSame($before, $this->state($post_id));
        }

        public function test_a_first_write_uses_the_key_the_loaded_breakdance_stores(): void
        {
            // Breakdance 1.x loaded: a new page gets the unprefixed row.
            add_filter('wpmcp_breakdance_meta_prefix', static fn () => 'breakdance_');
            $post_id = $this->page();
            $before  = $this->state($post_id);

            $out = $this->update($post_id, ['operation' => 'add', 'to' => '', 'element' => ['type' => 'EssentialElements\\Section']]);

            $this->assertTrue(metadata_exists('post', $post_id, 'breakdance_data'));
            $this->assertFalse(metadata_exists('post', $post_id, '_breakdance_data'));
            $this->assertSame('breakdance', $this->detect($post_id));
            Rollback_Service::restore_operation($out['operation_id']);
            $this->assertSame($before, $this->state($post_id));

            // Without that, the underscored row, as before.
            remove_all_filters('wpmcp_breakdance_meta_prefix');
            $this->update($post_id, ['operation' => 'add', 'to' => '', 'element' => ['type' => 'EssentialElements\\Section']]);
            $this->assertTrue(metadata_exists('post', $post_id, '_breakdance_data'));
            $this->assertFalse(metadata_exists('post', $post_id, 'breakdance_data'));
        }

        public function test_an_existing_page_keeps_its_own_key_whatever_breakdance_is_loaded(): void
        {
            $legacy  = $this->legacy_page();
            $current = $this->breakdance_page();

            add_filter('wpmcp_breakdance_meta_prefix', static fn () => '_breakdance_');
            $this->update($legacy, ['operation' => 'remove', 'path' => '103']);
            $this->assertFalse(metadata_exists('post', $legacy, '_breakdance_data'));
            $this->assertSame([100, 111], array_map(static fn ($n) => $n->id, $this->read($legacy)['tree']));

            remove_all_filters('wpmcp_breakdance_meta_prefix');
            add_filter('wpmcp_breakdance_meta_prefix', static fn () => 'breakdance_');
            $this->update($current, ['operation' => 'remove', 'path' => '103']);
            $this->assertFalse(metadata_exists('post', $current, 'breakdance_data'));
            $this->assertSame([100, 111], array_map(static fn ($n) => $n->id, $this->read($current)['tree']));
        }

        public function test_the_content_class_reads_what_breakdance_stores(): void
        {
            $post_id = $this->breakdance_page();

            $doc = Breakdance_Content::get_document($post_id);
            $this->assertNotNull($doc);
            $this->assertSame(BreakdanceTreeTest::fixture_json(), Breakdance_Tree::encode($doc));
            $this->assertNull(Breakdance_Content::get_document($this->page()));
        }
    }
}
