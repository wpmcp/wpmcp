<?php

namespace Breakdance\Render {
    if (! function_exists('Breakdance\\Render\\generateCacheForPost')) {
        /**
         * Stand-in for the cache generator Oxygen 6 shares with Breakdance
         * (same function, same namespace), recording the calls wpmcp makes.
         * Only reached when the wpmcp_oxygen_active filter says the plugin
         * is loaded.
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
     * Oxygen 6 through the builder dispatcher pair. Oxygen 6 runs on the
     * Breakdance engine: the same document (root, _nextNodeId, status) sits
     * in the same {"tree_json_string": ...} row, written the same way, and
     * the same \Breakdance\Render\generateCacheForPost() rebuilds its CSS.
     * What differs is the meta prefix (`_oxygen_` instead of `_breakdance_`),
     * the element classes (OxygenElements\Container, \Text, \TextLink, ...)
     * and the root id, which Oxygen documents carry as 0.
     *
     * The fixture follows Oxygen 6.1's page tree contract (a root of id 0
     * and type root with `properties` {}, Container, Text and TextLink nodes
     * with settings.advanced and meta.classes, `exportedLookupTable` {} and
     * status exported), extended with an element class wpmcp does not know,
     * carrying extra keys, to prove unknown elements survive byte for byte.
     * Oxygen is not installed here, so its rows are stored raw and its
     * presence is toggled through the wpmcp_oxygen_active filter.
     */
    class OxygenAdapterTest extends \WP_UnitTestCase
    {
        private const CSS_CACHE = '{"postDefaultsCssFilePath":"post-%d-defaults.css?v=0c4a1f","postCssFilePath":"post-%d.css?v=9be2d7"}';
        private const DEPENDENCY_CACHE = '{"scripts":[],"inlineScripts":[],"styles":[],"inlineStyles":[],"googleFonts":["Inter"]}';

        /** The unknown element exactly as stored; its bytes must never change. */
        private const UNKNOWN_NODE = '{"id":5,"data":{"type":"Acme\\\\Elements\\\\PricingTable","properties":{"content":{"plans":[{"name":"Pro","price":"€9"}],"svg":"<svg viewBox=\"0 0 24 24\"><path d=\"M5 12h14\"/></svg>"},"futureKey":{}},"ssrCache":[]},"children":[],"_parentId":4,"_customFlag":true}';

        /** The document exactly as the Oxygen 6 editor serializes it. */
        public static function fixture_json(): string
        {
            $json = <<<'JSON'
{"root":{"id":0,"data":{"type":"root","properties":{}},"children":[
{"id":1,"data":{"type":"OxygenElements\\Container","properties":{"settings":{"advanced":{"tag":"section","id":"hero","classes":["imported-hero"]}},"meta":{"classes":["selector-hero"],"classes_conditions":{}}}},"children":[
{"id":2,"data":{"type":"OxygenElements\\Text","properties":{"content":{"content":{"text":"Files live in C:\\Sites\\oxy and say \"hi\". Café & crème"}},"settings":{"advanced":{"tag":"h1"}},"meta":{"classes":["selector-heading"]}}},"children":[],"_parentId":1},
{"id":3,"data":{"type":"OxygenElements\\TextLink","properties":{"content":{"content":{"text":"Get started","url":"/contact","open_in_new_tab":false}},"settings":{"advanced":{"classes":["button-like-link"]}}}},"children":[],"_parentId":1}
],"_parentId":0},
{"id":4,"data":{"type":"OxygenElements\\Container","properties":{"design":{"layout":{"gap":{"breakpoint_base":{"number":20,"unit":"px","style":"20px"},"breakpoint_phone_portrait":{"number":8,"unit":"px","style":"8px"}}}}}},"children":[
UNKNOWN,
{"id":6,"data":{"type":"OxygenElements\\CssCode","properties":{"content":{"content":{"css_code":".hero a { color: red; }\n.hero b {}"}}}},"children":[],"_parentId":4}
],"_parentId":0},
{"id":7,"data":{"type":"OxygenElements\\Component","properties":{"content":{"content":{"block":{"componentId":2051,"targets":[],"properties":{}}}}}},"children":[],"_parentId":0}
]},"_nextNodeId":8,"exportedLookupTable":{},"status":"exported"}
JSON;

            return str_replace(["\n", 'UNKNOWN'], ['', self::UNKNOWN_NODE], $json);
        }

        /** The `_oxygen_data` meta value, encoded the way the engine's set_meta() does. */
        public static function fixture_meta(): string
        {
            return (string) wp_json_encode(['tree_json_string' => self::fixture_json()]);
        }

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
            remove_all_filters('wpmcp_oxygen_active');
            remove_all_filters('wpmcp_breakdance_active');
            parent::tearDown();
        }

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

        /** An Oxygen 6 page with its generated cache rows in place. */
        private function oxygen_page(): int
        {
            $post_id = $this->page('<!-- wp:paragraph --><p>Written before Oxygen took over</p><!-- /wp:paragraph -->');
            $this->raw_meta($post_id, '_oxygen_data', self::fixture_meta());
            $this->raw_meta($post_id, '_oxygen_css_file_paths_cache', sprintf(self::CSS_CACHE, $post_id, $post_id));
            $this->raw_meta($post_id, '_oxygen_dependency_cache', self::DEPENDENCY_CACHE);

            return $post_id;
        }

        private function update(int $post_id, array $args, string $builder = 'oxygen')
        {
            return (new Update_Builder_Content())->handle(['post_id' => $post_id, 'builder' => $builder] + $args);
        }

        private function read(int $post_id)
        {
            return (new Get_Builder_Content())->handle(['post_id' => $post_id]);
        }

        private function state(int $post_id): array
        {
            return [get_post($post_id)->post_content, get_post_meta($post_id)];
        }

        private function raw(int $post_id, string $key): ?string
        {
            global $wpdb;
            return $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $post_id, $key));
        }

        /** The stored tree_json_string, exactly as it sits in the row. */
        private function stored_json(int $post_id): string
        {
            $outer = json_decode((string) $this->raw($post_id, '_oxygen_data'), true);
            return (string) $outer['tree_json_string'];
        }

        private function detect(int $post_id): string
        {
            return (new Detect_Builder())->handle(['post_id' => $post_id])['builder'];
        }

        // ------------------------------------------------------------ detection

        public function test_detects_oxygen_from_a_valid_stored_tree(): void
        {
            $page      = $this->oxygen_page();
            $broken    = $this->page('<p>Plain</p>');
            $this->raw_meta($broken, '_oxygen_data', '{"tree_json_string":"not a tree"}');
            $elementor = $this->oxygen_page();
            update_post_meta($elementor, '_elementor_edit_mode', 'builder');
            $breakdance = $this->page();
            $this->raw_meta($breakdance, '_breakdance_data', BreakdanceTreeTest::fixture_meta());

            $this->assertSame('oxygen', $this->detect($page));
            $this->assertSame('classic', $this->detect($broken));
            $this->assertSame('elementor', $this->detect($elementor));
            $this->assertSame('breakdance', $this->detect($breakdance));
        }

        public function test_detection_is_the_same_whether_or_not_the_plugin_is_active(): void
        {
            $post_id = $this->oxygen_page();

            foreach ([false, true] as $active) {
                add_filter('wpmcp_oxygen_active', $active ? '__return_true' : '__return_false');
                $this->assertSame($active, Breakdance_Cache::plugin_active('oxygen'));
                $this->assertSame('oxygen', $this->detect($post_id));
                remove_all_filters('wpmcp_oxygen_active');
            }
        }

        public function test_neither_engine_is_reported_active_in_this_environment(): void
        {
            $this->assertFalse(Breakdance_Cache::plugin_active('oxygen'));
            $this->assertFalse(Breakdance_Cache::plugin_active('breakdance'));
        }

        // ----------------------------------------------------------------- read

        public function test_read_returns_the_node_tree_and_state(): void
        {
            add_filter('wpmcp_oxygen_active', '__return_true');
            $out = $this->read($this->oxygen_page());

            $this->assertIsArray($out);
            $this->assertSame('oxygen', $out['builder']);
            $this->assertTrue($out['plugin_active']);
            $this->assertSame(8, $out['next_node_id']);
            $this->assertSame([1, 4, 7], array_map(static fn ($n) => $n->id, $out['tree']));
            $this->assertSame('OxygenElements\\Text', $out['tree'][0]->children[0]->data->type);
            $this->assertSame('Acme\\Elements\\PricingTable', $out['tree'][1]->children[0]->data->type);
        }

        public function test_an_unchanged_oxygen_document_round_trips_byte_for_byte(): void
        {
            $post_id = $this->oxygen_page();

            $doc = Breakdance_Content::get_document($post_id, 'oxygen');
            $this->assertNotNull($doc);
            $this->assertSame(self::fixture_json(), Breakdance_Tree::encode($doc));
            $this->assertNull(Breakdance_Content::get_document($post_id));
        }

        // ---------------------------------------------------------- round trips

        public function test_update_changes_one_node_keeps_unknown_elements_and_rolls_back_exactly(): void
        {
            $post_id = $this->oxygen_page();
            $before  = $this->state($post_id);

            $out = $this->update($post_id, ['operation' => 'update', 'path' => '2', 'attrs' => ['content' => ['content' => ['text' => 'Now in D:\\new "quoted"']]]]);

            $this->assertIsArray($out);
            $this->assertSame('oxygen', $out['builder']);
            $this->assertSame('2', $out['path']);
            $json = $this->stored_json($post_id);
            $this->assertSame(
                str_replace('Files live in C:\\\\Sites\\\\oxy and say \\"hi\\". Café & crème', 'Now in D:\\\\new \\"quoted\\"', self::fixture_json()),
                $json
            );
            $this->assertStringContainsString(self::UNKNOWN_NODE, $json);
            // The stale generated cache rows are dropped so Oxygen rebuilds them.
            $this->assertNull($this->raw($post_id, '_oxygen_css_file_paths_cache'));
            $this->assertNull($this->raw($post_id, '_oxygen_dependency_cache'));
            // Nothing is written under the Breakdance prefix, and post_content is left alone.
            $this->assertNull($this->raw($post_id, '_breakdance_data'));
            $this->assertSame($before[0], get_post($post_id)->post_content);

            Rollback_Service::restore_operation($out['operation_id']);

            $this->assertSame($before, $this->state($post_id));
            $this->assertSame(self::fixture_meta(), $this->raw($post_id, '_oxygen_data'));
            $this->assertSame(self::DEPENDENCY_CACHE, $this->raw($post_id, '_oxygen_dependency_cache'));
        }

        public function test_update_of_the_unknown_element_itself_touches_only_its_properties(): void
        {
            $post_id = $this->oxygen_page();

            $out = $this->update($post_id, ['operation' => 'update', 'path' => '5', 'attrs' => ['content' => ['heading' => 'Plans']]]);

            $this->assertIsArray($out);
            $node = $this->read($post_id)['tree'][1]->children[0];
            $this->assertSame('Plans', $node->data->properties->content->heading);
            $this->assertSame([], $node->data->ssrCache);
            $this->assertTrue($node->_customFlag);
            $this->assertEquals((object) [], $node->data->properties->futureKey);
        }

        public function test_add_remove_and_move_each_roll_back_exactly(): void
        {
            $post_id = $this->oxygen_page();
            $before  = $this->state($post_id);

            $add = $this->update($post_id, ['operation' => 'add', 'to' => '4', 'index' => 0, 'element' => [
                'type'       => 'OxygenElements\\Text',
                'properties' => ['content' => ['content' => ['text' => 'First']], 'settings' => ['advanced' => ['tag' => 'h3']]],
            ]]);
            $this->assertIsArray($add);
            $this->assertSame('8', $add['path']);
            $container = $this->read($post_id)['tree'][1];
            $this->assertSame([8, 5, 6], array_map(static fn ($n) => $n->id, $container->children));
            $this->assertSame(4, $container->children[0]->_parentId);
            $this->assertSame(9, $this->read($post_id)['next_node_id']);
            $this->assertStringContainsString(self::UNKNOWN_NODE, $this->stored_json($post_id));
            Rollback_Service::restore_operation($add['operation_id']);
            $this->assertSame($before, $this->state($post_id));

            $top = $this->update($post_id, ['operation' => 'add', 'to' => '', 'element' => ['type' => 'OxygenElements\\Container']]);
            $this->assertIsArray($top);
            $this->assertSame(0, $this->read($post_id)['tree'][3]->_parentId);
            Rollback_Service::restore_operation($top['operation_id']);
            $this->assertSame($before, $this->state($post_id));

            $remove = $this->update($post_id, ['operation' => 'remove', 'path' => '1']);
            $this->assertIsArray($remove);
            $this->assertSame([4, 7], array_map(static fn ($n) => $n->id, $this->read($post_id)['tree']));
            Rollback_Service::restore_operation($remove['operation_id']);
            $this->assertSame($before, $this->state($post_id));

            $move = $this->update($post_id, ['operation' => 'move', 'path' => '5', 'to' => '1', 'index' => 0]);
            $this->assertIsArray($move);
            $moved = $this->read($post_id)['tree'][0]->children[0];
            $this->assertSame(5, $moved->id);
            $this->assertSame(1, $moved->_parentId);
            Rollback_Service::restore_operation($move['operation_id']);
            $this->assertSame($before, $this->state($post_id));
        }

        public function test_whole_tree_write_of_an_unchanged_read_is_byte_identical(): void
        {
            $post_id = $this->oxygen_page();
            $before  = $this->state($post_id);

            $out = $this->update($post_id, ['content' => (string) wp_json_encode($this->read($post_id)['tree'])]);

            $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
            $this->assertSame('oxygen', $out['builder']);
            $this->assertSame($before, $this->state($post_id));
        }

        public function test_whole_tree_replace_rolls_back_exactly(): void
        {
            $post_id = $this->oxygen_page();
            $before  = $this->state($post_id);

            $out = $this->update($post_id, ['content' => '[{"type":"OxygenElements\\\\Container","children":[{"type":"OxygenElements\\\\Text","properties":{"content":{"content":{"text":"<b>a\\\\b</b>"}}}}]}]']);

            $this->assertIsArray($out);
            $tree = $this->read($post_id)['tree'];
            $this->assertCount(1, $tree);
            $this->assertSame(8, $tree[0]->id);
            $this->assertSame('<b>a\\b</b>', $tree[0]->children[0]->data->properties->content->content->text);

            Rollback_Service::restore_operation($out['operation_id']);
            $this->assertSame($before, $this->state($post_id));
        }

        public function test_first_write_to_a_blank_page_creates_an_oxygen_tree_and_rollback_removes_it(): void
        {
            $post_id = $this->page();
            $before  = $this->state($post_id);

            $out = $this->update($post_id, ['operation' => 'add', 'to' => '', 'element' => [
                'type'     => 'OxygenElements\\Container',
                'children' => [['type' => 'OxygenElements\\Text', 'properties' => ['content' => ['content' => ['text' => 'Hello']]]]],
            ]]);

            $this->assertIsArray($out);
            $this->assertSame('oxygen', $this->detect($post_id));
            $this->assertNotNull($this->raw($post_id, '_oxygen_data'));
            $this->assertNull($this->raw($post_id, '_breakdance_data'));

            Rollback_Service::restore_operation($out['operation_id']);

            $this->assertSame($before, $this->state($post_id));
            $this->assertSame('classic', $this->detect($post_id));
        }

        // ---------------------------------------------------------------- cache

        public function test_cache_is_regenerated_only_when_oxygen_is_active(): void
        {
            $post_id = $this->oxygen_page();

            // Breakdance being active says nothing about an Oxygen page.
            add_filter('wpmcp_breakdance_active', '__return_true');
            $out = $this->update($post_id, ['operation' => 'update', 'path' => '2', 'attrs' => ['content' => ['content' => ['text' => 'A']]]]);
            Rollback_Service::restore_operation($out['operation_id']);
            $this->assertSame([], $GLOBALS['wpmcp_breakdance_cache_calls']);
            remove_all_filters('wpmcp_breakdance_active');

            add_filter('wpmcp_oxygen_active', '__return_true');
            $before = $this->state($post_id);
            $out    = $this->update($post_id, ['operation' => 'update', 'path' => '2', 'attrs' => ['content' => ['content' => ['text' => 'B']]]]);
            $this->assertSame([$post_id], $GLOBALS['wpmcp_breakdance_cache_calls']);

            $GLOBALS['wpmcp_breakdance_cache_calls'] = [];
            Rollback_Service::restore_operation($out['operation_id']);
            $this->assertSame([$post_id], $GLOBALS['wpmcp_breakdance_cache_calls']);
            $this->assertSame($before, $this->state($post_id));
        }

        // --------------------------------------------------------------- errors

        public function test_rejected_requests_write_nothing(): void
        {
            $post_id    = $this->oxygen_page();
            $before     = $this->state($post_id);
            $breakdance = $this->page();
            $this->raw_meta($breakdance, '_breakdance_data', BreakdanceTreeTest::fixture_meta());
            $bd_before  = $this->state($breakdance);
            $classic    = $this->page('<p>Existing classic content</p>');

            $cases = [
                [$post_id, [], 'invalid_oxygen_request'],
                [$post_id, ['content' => '{"not":"a list"}'], 'invalid_oxygen_request'],
                [$post_id, ['content' => '[{"id":0,"data":{"type":"OxygenElements\\\\Text","properties":null}}]'], 'invalid_oxygen_request'],
                [$post_id, ['operation' => 'update', 'path' => '0', 'attrs' => ['a' => 1]], 'invalid_oxygen_request'],
                [$post_id, ['operation' => 'remove', 'path' => '99'], 'invalid_oxygen_request'],
                [$post_id, ['operation' => 'move', 'path' => '1', 'to' => '2'], 'invalid_oxygen_request'],
                [$post_id, ['operation' => 'add', 'to' => '1', 'element' => ['type' => 'not-a-class']], 'invalid_oxygen_request'],
                [$breakdance, ['operation' => 'remove', 'path' => '101'], 'unsupported_builder'],
                [$classic, ['content' => '[]'], 'unsupported_builder'],
            ];
            foreach ($cases as [$id, $args, $code]) {
                $out = $this->update($id, $args);
                $this->assertInstanceOf(\WP_Error::class, $out, (string) wp_json_encode($args));
                $this->assertSame($code, $out->get_error_code(), (string) wp_json_encode($args));
            }

            // Nor does a Breakdance write aimed at an Oxygen page.
            $out = $this->update($post_id, ['operation' => 'remove', 'path' => '1'], 'breakdance');
            $this->assertSame('unsupported_builder', $out->get_error_code());

            $this->assertSame($before, $this->state($post_id));
            $this->assertSame($bd_before, $this->state($breakdance));
            $this->assertFalse(metadata_exists('post', $classic, '_oxygen_data'));
        }
    }
}
