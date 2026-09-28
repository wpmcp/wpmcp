<?php

namespace {
    /*
     * Stand-ins for the classic Oxygen functions wpmcp calls when Oxygen is
     * loaded, recording each call. They are only reached when the
     * wpmcp_oxygen_classic_active filter says Oxygen is loaded.
     *
     * The shortcode serializer returns a marker followed by the JSON it was
     * given, so a test can tell exactly which tree it was asked to write;
     * the real one writes nested ct_* shortcodes, each signed with the
     * site's key. The CSS cache writer records the page it was asked for and
     * the post id global Oxygen's own save sets for it, and stores a state
     * entry naming the tree it read, as the real one stores the file it wrote.
     */
    if (! function_exists('components_json_to_shortcodes')) {
        function components_json_to_shortcodes($json, $reusable = false) // phpcs:ignore
        {
            $GLOBALS['wpmcp_oxygen_classic_calls'][] = ['shortcodes', $json];
            return '[oxygen_stub]' . $json;
        }
    }
    if (! function_exists('ct_base64_encode_decode_tree')) {
        function ct_base64_encode_decode_tree($children, $decode = false) // phpcs:ignore
        {
            $GLOBALS['wpmcp_oxygen_classic_calls'][] = ['base64', $decode];
            return $children;
        }
    }
    if (! function_exists('ct_sign_oxy_dynamic_shortcode')) {
        function ct_sign_oxy_dynamic_shortcode($results) // phpcs:ignore
        {
            return substr($results[0], 0, -1) . " ct_sign_sha256='stub']";
        }
    }
    if (! function_exists('oxygen_vsb_cache_page_css')) {
        function oxygen_vsb_cache_page_css($post_id, $content = false) // phpcs:ignore
        {
            $GLOBALS['wpmcp_oxygen_classic_calls'][] = ['css', (int) $post_id, $GLOBALS['oxy_ajax_post_id'] ?? null];
            $state = get_option('oxygen_vsb_css_files_state', []);
            $state[ $post_id ] = ['success' => true, 'from' => md5((string) get_post_meta($post_id, '_ct_builder_json', true))];
            update_option('oxygen_vsb_css_files_state', $state);
            return true;
        }
    }
    if (! class_exists('Oxygen_Revisions')) {
        class Oxygen_Revisions // phpcs:ignore
        {
            public static function create_revision($post_id)
            {
                $GLOBALS['wpmcp_oxygen_classic_calls'][] = ['revision', (int) $post_id];
                add_post_meta($post_id, '_ct_builder_shortcodes_revisions', addslashes((string) get_post_meta($post_id, '_ct_builder_json', true)));
                add_post_meta($post_id, '_ct_builder_shortcodes_revisions_dates', 1790000000);
                return true;
            }
        }
    }
}

namespace WPMCP\Tests\Pro\Builders {

    use WPMCP\Pro\Gate;
    use WPMCP\Safety\{Rollback_Service, Snapshot_Store};
    use WPMCP\Tools\Builders\{Detect_Builder, Get_Builder_Content, Oxygen_Classic_Content, Oxygen_Classic_Json, Update_Builder_Content};

    /**
     * Classic Oxygen (4.x and earlier) through the builder dispatcher pair.
     *
     * Oxygen 4 saves a page twice: the JSON tree in ct_builder_json, which it
     * renders from whenever the tree has elements, and a copy as nested ct_*
     * shortcodes in ct_builder_shortcodes, each signed with the site's key,
     * which it falls back to when there is no tree. Pages from before 4.0
     * that were never saved again hold only the shortcodes. Since 4.8.3 the
     * rows are stored under `_ct_` keys; earlier versions use `ct_` keys. Its
     * save first copies the old tree into the _ct_builder_shortcodes_revisions
     * rows, and the page's generated CSS lives in uploads/oxygen/css/<id>.css,
     * recorded in the oxygen_vsb_css_files_state option.
     *
     * Oxygen is commercial and not installed here, so its rows are written as
     * its save stores them (the fixtures follow public page exports) and its
     * presence is toggled through the wpmcp_oxygen_classic_active filter.
     */
    class OxygenClassicAdapterTest extends \WP_UnitTestCase
    {
        /** A signed shortcode copy in the format Oxygen 4 writes it. */
        private const SHORTCODES = "[ct_section ct_sign_sha256='0b4073cd78571fd7b2fc50998e7d2e889ea434bac720445c5fb2cce764d5a82f' ct_options='{\"ct_id\":1,\"ct_parent\":0,\"selector\":\"section-1-77\",\"original\":{\"background-color\":\"#f2f4f7\",\"padding-top\":\"80\"},\"nicename\":\"Hero\",\"activeselector\":false,\"ct_depth\":1}'][/ct_section]";

        /** A page saved before Oxygen 4, from a public export: shortcodes only. */
        private const LEGACY = "[ct_section ct_sign_sha256='0b4073cd78571fd7b2fc50998e7d2e889ea434bac720445c5fb2cce764d5a82f' ct_options='{\"ct_id\":1,\"ct_parent\":0,\"selector\":\"section-1-102\",\"original\":{\"flex-direction\":\"column\",\"display\":\"flex\"},\"activeselector\":false}']"
            . "[ct_div_block_2 ct_sign_sha256='7b3487593c6efe2e555a1eca2fc10681ae133218250d36c3255d482bf5d58eb8' ct_options='{\"ct_id\":2,\"ct_parent\":1,\"selector\":\"div_block-17-102\",\"original\":{\"padding-bottom\":\"50\"},\"activeselector\":false,\"nicename\":\"Header\"}']"
            . "[ct_headline ct_sign_sha256='a69525b5630ad24bbc7b3c1ab94136e4ba3cfc0294c0841f589763d344ce4155' ct_options='{\"ct_id\":3,\"ct_parent\":2,\"selector\":\"headline-10-102\",\"original\":{\"font-size\":\"40\"},\"activeselector\":false,\"nicename\":\"Title\"}']Choose the best plan for you[/ct_headline]"
            . "[ct_text_block ct_sign_sha256='c72893fef3e69d35caf8b0d2c046dc972da6ea2cf42396a75f4468ce99b74d0e' ct_options='{\"ct_id\":4,\"ct_parent\":2,\"selector\":\"text_block-13-102\",\"activeselector\":false,\"nicename\":\"Description\"}']Flexible options to suit any business[/ct_text_block]"
            . '[/ct_div_block_2][/ct_section]';

        protected function setUp(): void
        {
            parent::setUp();
            Gate::set_pro_for_tests(true);
            Snapshot_Store::install();
            $GLOBALS['wpmcp_oxygen_classic_calls'] = [];
        }

        protected function tearDown(): void
        {
            Gate::set_pro_for_tests(null);
            remove_all_filters('wpmcp_oxygen_classic_active');
            unset($GLOBALS['wpmcp_oxygen_classic_calls'], $GLOBALS['oxy_ajax_post_id']);
            delete_option('oxygen_vsb_css_files_state');
            parent::tearDown();
        }

        private function active(): void
        {
            add_filter('wpmcp_oxygen_classic_active', '__return_true');
        }

        /** A page with rows stored raw, the way Oxygen's save leaves them. */
        private function page(array $meta, string $content = ''): int
        {
            $post_id = self::factory()->post->create(['post_type' => 'page', 'post_content' => wp_slash($content)]);
            foreach ($meta as $key => $value) {
                update_post_meta($post_id, $key, is_string($value) ? wp_slash($value) : $value);
            }

            return $post_id;
        }

        /** An Oxygen 4.8.3+ page: prefixed rows, JSON plus its shortcode copy. */
        private function oxygen_page(bool $with_shortcodes = true): int
        {
            $meta = ['_ct_builder_json' => OxygenClassicJsonTest::FIXTURE, '_ct_page_settings' => ['max-width' => '1120']];
            if ($with_shortcodes) {
                $meta['_ct_builder_shortcodes'] = self::SHORTCODES;
            }

            return $this->page($meta);
        }

        /** A generated CSS file and its state entry, beside another page's entry. */
        private function css_cache(int $post_id): string
        {
            $dir = wp_upload_dir()['basedir'] . '/oxygen/css';
            wp_mkdir_p($dir);
            $file = $dir . '/' . $post_id . '.css';
            file_put_contents($file, '#headline-3-77{font-size:40px}');
            update_option('oxygen_vsb_css_files_state', [
                $post_id => ['success' => true, 'url' => '//example.org/wp-content/uploads/oxygen/css/' . $post_id . '.css', 'path' => $file, 'last_save_time' => 1790000000],
                999999   => ['empty' => true],
            ]);

            return $file;
        }

        private function update(int $post_id, array $args)
        {
            return (new Update_Builder_Content())->handle(['post_id' => $post_id, 'builder' => 'oxygen-classic'] + $args);
        }

        private function meta(int $post_id): array
        {
            clean_post_cache($post_id);
            return get_post_meta($post_id);
        }

        private function json(int $post_id, string $key = '_ct_builder_json'): string
        {
            return (string) get_post_meta($post_id, $key, true);
        }

        /** The shortcode row Oxygen's save would store for a tree (its value is not slashed first). */
        private static function regenerated(string $json): string
        {
            return wp_unslash('[oxygen_stub]' . wp_json_encode(json_decode($json, true)));
        }

        private function calls(string $kind): array
        {
            return array_values(array_filter($GLOBALS['wpmcp_oxygen_classic_calls'], static fn ($call) => $kind === $call[0]));
        }

        // -------------------------------------------------------- detection

        public function test_detects_json_pages_under_either_key_layout_and_legacy_pages(): void
        {
            $pages = [
                $this->oxygen_page(),
                $this->page(['ct_builder_json' => OxygenClassicJsonTest::FIXTURE, 'ct_builder_shortcodes' => self::SHORTCODES]),
                $this->page(['_ct_builder_shortcodes' => self::LEGACY]),
                $this->page(['ct_builder_shortcodes' => self::LEGACY]),
                $this->page(['_ct_builder_json' => OxygenClassicJsonTest::FIXTURE], "<!-- wp:paragraph -->\n<p>Left over</p>\n<!-- /wp:paragraph -->"),
            ];
            foreach ($pages as $post_id) {
                $this->assertSame('oxygen-classic', (new Detect_Builder())->handle(['post_id' => $post_id])['builder']);
            }
        }

        public function test_an_empty_tree_is_not_an_oxygen_page_and_other_builders_win(): void
        {
            $empty     = $this->page(['_ct_builder_json' => '{"id":0,"name":"root","depth":0,"children":[]}', '_ct_builder_shortcodes' => ''], '<p>Theme renders this</p>');
            $elementor = $this->page(['_elementor_edit_mode' => 'builder', '_ct_builder_json' => OxygenClassicJsonTest::FIXTURE]);
            $oxygen6   = $this->page([
                '_oxygen_data'     => '{"tree_json_string":"{\"root\":{\"id\":0,\"data\":{\"type\":\"root\",\"properties\":{}},\"children\":[]},\"_nextNodeId\":100,\"status\":\"exported\"}"}',
                '_ct_builder_json' => OxygenClassicJsonTest::FIXTURE,
            ]);

            $this->assertSame('classic', (new Detect_Builder())->handle(['post_id' => $empty])['builder']);
            $this->assertSame('elementor', (new Detect_Builder())->handle(['post_id' => $elementor])['builder']);
            $this->assertSame('oxygen', (new Detect_Builder())->handle(['post_id' => $oxygen6])['builder']);
        }

        public function test_plugin_state_follows_the_filter_and_is_off_here(): void
        {
            $this->assertFalse(Oxygen_Classic_Content::plugin_active());
            $this->active();
            $this->assertTrue(Oxygen_Classic_Content::plugin_active());
        }

        // ------------------------------------------------------------- read

        public function test_read_returns_the_json_tree_and_its_state(): void
        {
            $post_id = $this->oxygen_page();

            $out = (new Get_Builder_Content())->handle(['post_id' => $post_id]);

            $this->assertSame('oxygen-classic', $out['builder']);
            $this->assertSame($post_id, $out['post_id']);
            $this->assertSame('json', $out['format']);
            $this->assertFalse($out['plugin_active']);
            $this->assertFalse($out['writable'], 'The signed shortcode copy needs Oxygen to be rewritten');
            $this->assertSame(OxygenClassicJsonTest::FIXTURE, $out['content']);
            $this->assertSame(Oxygen_Classic_Json::tree(OxygenClassicJsonTest::FIXTURE), $out['tree']);

            $this->active();
            $this->assertTrue((new Get_Builder_Content())->handle(['post_id' => $post_id])['writable']);
        }

        public function test_read_of_a_legacy_page_parses_its_shortcodes(): void
        {
            $post_id = $this->page(['ct_builder_shortcodes' => self::LEGACY]);

            $out = (new Get_Builder_Content())->handle(['post_id' => $post_id]);

            $this->assertSame('shortcodes', $out['format']);
            $this->assertFalse($out['writable']);
            $this->assertSame(self::LEGACY, $out['content']);

            $section = $out['tree'][0];
            $this->assertSame(['0', 1, 'ct_section'], [$section['path'], $section['id'], $section['name']]);
            $div = $section['children'][0];
            $this->assertSame(['0.0', 2, 'ct_div_block', 'Header'], [$div['path'], $div['id'], $div['name'], $div['options']['nicename']]);
            $this->assertSame('Choose the best plan for you', $div['children'][0]['text']);
            $this->assertSame(['font-size' => '40'], $div['children'][0]['options']['original']);
            $this->assertSame('ct_text_block', $div['children'][1]['name']);
        }

        // ------------------------------------------------- writes, inactive

        public function test_a_json_only_page_is_written_without_oxygen_and_its_css_invalidated(): void
        {
            $post_id = $this->oxygen_page(false);
            $file    = $this->css_cache($post_id);
            $before  = $this->meta($post_id);

            $out = $this->update($post_id, ['operation' => 'update', 'path' => '0.0.0', 'text' => 'New title']);

            $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
            $this->assertSame(['post_id' => $post_id, 'builder' => 'oxygen-classic', 'path' => '0.0.0'], array_diff_key($out, ['operation_id' => 1]));
            $this->assertSame(Oxygen_Classic_Json::update(OxygenClassicJsonTest::FIXTURE, '0.0.0', null, 'New title'), $this->json($post_id));
            $this->assertFalse(metadata_exists('post', $post_id, '_ct_builder_shortcodes'), 'No shortcode copy is invented');
            $this->assertFalse(metadata_exists('post', $post_id, 'ct_builder_json'), 'The other key layout is not touched');

            $this->assertFileDoesNotExist($file);
            $this->assertSame([999999 => ['empty' => true]], get_option('oxygen_vsb_css_files_state'), 'Only this page\'s cache entry is dropped');
            $this->assertSame([], $GLOBALS['wpmcp_oxygen_classic_calls'], 'Nothing calls into Oxygen when it is not loaded');

            $this->css_cache($post_id);
            $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
            $this->assertSame($before, $this->meta($post_id));
            $this->assertFileDoesNotExist($file, 'Rollback drops the CSS generated for the undone tree too');
            $this->assertArrayNotHasKey($post_id, get_option('oxygen_vsb_css_files_state'));
        }

        public function test_a_page_with_a_shortcode_copy_needs_oxygen_loaded(): void
        {
            $post_id = $this->oxygen_page();
            $before  = $this->meta($post_id);

            $out = $this->update($post_id, ['operation' => 'update', 'path' => '0.0.0', 'text' => 'New title']);

            $this->assertWPError($out);
            $this->assertSame('oxygen_classic_inactive', $out->get_error_code());
            $this->assertSame($before, $this->meta($post_id));
        }

        public function test_a_legacy_page_is_refused_with_a_way_forward(): void
        {
            $this->active();
            $post_id = $this->page(['ct_builder_shortcodes' => self::LEGACY]);
            $before  = $this->meta($post_id);

            $out = $this->update($post_id, ['operation' => 'update', 'path' => '0.0.0', 'text' => 'x']);

            $this->assertWPError($out);
            $this->assertSame('oxygen_classic_legacy_format', $out->get_error_code());
            $this->assertStringContainsString('Oxygen', $out->get_error_message());
            $this->assertSame($before, $this->meta($post_id));
        }

        public function test_other_pages_and_bad_requests_are_refused(): void
        {
            $this->active();
            $plain   = $this->page([], '<p>Plain</p>');
            $oxygen6 = $this->page(['_oxygen_data' => '{"tree_json_string":"{\"root\":{\"id\":0,\"data\":{\"type\":\"root\",\"properties\":{}},\"children\":[]},\"_nextNodeId\":100,\"status\":\"exported\"}"}']);
            $post_id = $this->oxygen_page();

            $this->assertSame('unsupported_builder', $this->update($plain, ['operation' => 'remove', 'path' => '0'])->get_error_code());
            $this->assertSame('unsupported_builder', $this->update($oxygen6, ['operation' => 'remove', 'path' => '0'])->get_error_code());
            $this->assertSame('unsupported_builder', (new Update_Builder_Content())->handle(['post_id' => $post_id, 'builder' => 'oxygen', 'operation' => 'remove', 'path' => '1'])->get_error_code());

            $before = $this->meta($post_id);
            foreach (
                [
                ['operation' => 'update', 'path' => '9', 'text' => 'x'],
                ['operation' => 'update', 'path' => '0.0.0', 'attrs' => 'x'],
                ['operation' => 'add', 'to' => '0', 'element' => 'x'],
                ['operation' => 'remove', 'path' => ''],
                ['operation' => 'move', 'path' => '0', 'to' => '0.0'],
                ['operation' => 'explode', 'path' => '0'],
                ['content' => '{"id":0,"name":"root","depth":0,"children":[{"id":1}]}'],
                ['content' => 'not json'],
                [],
                ] as $args
            ) {
                $out = $this->update($post_id, $args);
                $this->assertWPError($out, wp_json_encode($args));
                $this->assertSame('invalid_oxygen_classic_request', $out->get_error_code());
            }
            $this->assertSame($before, $this->meta($post_id));
        }

        public function test_dynamic_data_needs_oxygen_to_sign_it(): void
        {
            $post_id = $this->oxygen_page(false);

            $out = $this->update($post_id, ['operation' => 'update', 'path' => '0.0.0', 'text' => "[oxygen data='title']"]);

            $this->assertWPError($out);
            $this->assertSame('invalid_oxygen_classic_request', $out->get_error_code());
        }

        // --------------------------------------------------- writes, active

        public function test_an_update_writes_both_stores_a_revision_and_the_css_like_oxygens_save(): void
        {
            $this->active();
            $post_id = $this->oxygen_page();
            $this->css_cache($post_id);
            $before = $this->meta($post_id);

            $out = $this->update($post_id, ['operation' => 'update', 'path' => '0.2', 'attrs' => ['original' => ['oxy-acme-countdown_label' => 'Soon']]]);

            $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
            $json = Oxygen_Classic_Json::update(OxygenClassicJsonTest::FIXTURE, '0.2', ['original' => ['oxy-acme-countdown_label' => 'Soon']], null);
            $this->assertSame($json, $this->json($post_id));
            $this->assertSame(self::regenerated($json), get_post_meta($post_id, '_ct_builder_shortcodes', true));
            $this->assertSame([['base64', false]], $this->calls('base64'), 'Code fields are encoded the way the save encodes them');

            $this->assertSame([['revision', $post_id]], $this->calls('revision'));
            $this->assertSame([OxygenClassicJsonTest::FIXTURE], get_post_meta($post_id, '_ct_builder_shortcodes_revisions'), 'The revision holds the tree before the write');

            $this->assertSame([['css', $post_id, $post_id]], $this->calls('css'));
            $this->assertSame(md5($json), get_option('oxygen_vsb_css_files_state')[ $post_id ]['from']);

            $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
            $this->assertSame($before, $this->meta($post_id), 'Both stores, the revision rows and the page settings come back exactly');
            $this->assertCount(2, $this->calls('css'), 'Rollback regenerates the CSS');
            $this->assertSame(md5(OxygenClassicJsonTest::FIXTURE), get_option('oxygen_vsb_css_files_state')[ $post_id ]['from']);
        }

        public function test_add_remove_and_move_each_roll_back_exactly(): void
        {
            $this->active();
            $post_id = $this->oxygen_page();
            $before  = $this->meta($post_id);

            $cases = [
                [['operation' => 'add', 'to' => '0.1.1', 'element' => ['name' => 'ct_headline', 'text' => 'New']], Oxygen_Classic_Json::add(OxygenClassicJsonTest::FIXTURE, '0.1.1', null, ['name' => 'ct_headline', 'text' => 'New'], $post_id)[0], '0.1.1.0'],
                [['operation' => 'remove', 'path' => '0.0.1.0'], Oxygen_Classic_Json::remove(OxygenClassicJsonTest::FIXTURE, '0.0.1.0'), null],
                [['operation' => 'move', 'path' => '1.0', 'to' => '0.1.1', 'index' => 0], Oxygen_Classic_Json::move(OxygenClassicJsonTest::FIXTURE, '1.0', '0.1.1', 0), null],
            ];
            foreach ($cases as [$args, $json, $path]) {
                $out = $this->update($post_id, $args);
                $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
                $this->assertSame($path, $out['path'] ?? null);
                $this->assertSame($json, $this->json($post_id));
                $this->assertSame(self::regenerated($json), get_post_meta($post_id, '_ct_builder_shortcodes', true));

                $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
                $this->assertSame($before, $this->meta($post_id));
            }
        }

        public function test_a_whole_tree_write_is_stored_as_given(): void
        {
            $this->active();
            $post_id = $this->oxygen_page();
            $before  = $this->meta($post_id);
            $tree    = '{"id":0,"name":"root","depth":0,"children":[{"id":1,"name":"ct_section","options":{"ct_id":1,"ct_parent":0,"selector":"section-1-' . $post_id . '","original":{}},"depth":1,"children":[{"id":2,"name":"ct_headline","options":{"ct_id":2,"ct_parent":1,"selector":"headline-2-' . $post_id . '","original":{},"ct_content":"C:\\\\Sites \u00e9"},"depth":2}]}]}';

            $out = $this->update($post_id, ['content' => $tree]);

            $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
            $this->assertArrayNotHasKey('path', $out);
            $this->assertSame($tree, $this->json($post_id), 'Backslashes and escapes land byte for byte');
            $this->assertSame(self::regenerated($tree), get_post_meta($post_id, '_ct_builder_shortcodes', true));

            $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
            $this->assertSame($before, $this->meta($post_id));
        }

        public function test_a_page_in_the_older_key_layout_is_written_under_its_own_keys(): void
        {
            $this->active();
            $post_id = $this->page(['ct_builder_json' => OxygenClassicJsonTest::FIXTURE, 'ct_builder_shortcodes' => self::SHORTCODES]);
            $before  = $this->meta($post_id);

            $out = $this->update($post_id, ['operation' => 'remove', 'path' => '1']);

            $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
            $json = Oxygen_Classic_Json::remove(OxygenClassicJsonTest::FIXTURE, '1');
            $this->assertSame($json, $this->json($post_id, 'ct_builder_json'));
            $this->assertSame(self::regenerated($json), get_post_meta($post_id, 'ct_builder_shortcodes', true));
            $this->assertFalse(metadata_exists('post', $post_id, '_ct_builder_json'));
            $this->assertFalse(metadata_exists('post', $post_id, '_ct_builder_shortcodes'));

            $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
            $this->assertSame($before, $this->meta($post_id));
        }

        public function test_new_dynamic_data_is_signed_through_oxygen(): void
        {
            $this->active();
            $post_id = $this->oxygen_page();

            $out = $this->update($post_id, ['operation' => 'update', 'path' => '0.0.0', 'text' => "[oxygen data='title']"]);

            $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
            $this->assertStringContainsString('"ct_content":"[oxygen data=\'title\' ct_sign_sha256=\'stub\']"', $this->json($post_id));
            $this->assertStringContainsString(OxygenClassicJsonTest::H12, $this->json($post_id), 'Existing signed data elsewhere keeps its bytes');
        }

        public function test_a_write_that_changes_nothing_touches_nothing(): void
        {
            $this->active();
            $post_id = $this->oxygen_page();
            $before  = $this->meta($post_id);

            $out = $this->update($post_id, ['operation' => 'update', 'path' => '0.0.0', 'attrs' => ['nicename' => 'Title']]);

            $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
            $this->assertSame($before, $this->meta($post_id));
            $this->assertSame([], $GLOBALS['wpmcp_oxygen_classic_calls']);
        }
    }
}
