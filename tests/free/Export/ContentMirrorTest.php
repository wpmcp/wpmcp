<?php

namespace WPMCP\Tests\Free\Export;

use WPMCP\Safety\{Rollback_Service, Snapshot_Store};
use WPMCP\Tools\Export\{Content_Mirror, Export_Content, Import_Content};

/**
 * The git-friendly content mirror (issue #298): export-content with
 * mirror:true writes one pretty-printed JSON file per builder page under
 * uploads/wpmcp-mirror, and import-content with mirror:true restores a page
 * from its file as a snapshotted write.
 *
 * Every builder adapter is exercised through the same round trip: export,
 * edit the page, restore, re-export (byte-identical to the first export), then
 * roll the restore back and compare every stored row with the edited state.
 */
class ContentMirrorTest extends \WP_UnitTestCase
{
    /** @var string[] */
    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->wipe_mirror();
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            if (is_link($path) || is_file($path)) {
                unlink($path);
            }
        }
        $this->cleanup = [];
        $this->wipe_mirror();
        // Rolling back an Elementor page lets the real Elementor, when it is
        // loaded, boot the REST server mid-test. Drop it so the next REST
        // test builds a server with every route registered.
        $GLOBALS['wp_rest_server'] = null;
        parent::tearDown();
    }

    private function wipe_mirror(): void
    {
        $dir = Content_Mirror::dir();
        if (! is_dir($dir)) {
            return;
        }
        foreach (array_diff((array) scandir($dir), ['.', '..']) as $entry) {
            unlink($dir . '/' . $entry);
        }
        rmdir($dir);
    }

    private function export(array $args = []): array
    {
        return (new Export_Content())->handle(['mirror' => true] + $args);
    }

    private function restore(int $post_id, array $extra = []): array
    {
        return (new Import_Content())->handle(['mirror' => true, 'post_id' => $post_id] + $extra);
    }

    private function bytes(int $post_id): string
    {
        return (string) file_get_contents(Content_Mirror::file_for($post_id));
    }

    /** Every stored row that makes up the page: content plus all meta. */
    private function state(int $post_id): array
    {
        clean_post_cache($post_id);
        return [get_post($post_id)->post_content, get_post_meta($post_id)];
    }

    private static function without_modified(string $bytes): string
    {
        return (string) preg_replace('/^    "modified": "[^"]*",\n/m', '', $bytes, 1);
    }

    private function page(string $content = ''): int
    {
        return self::factory()->post->create(['post_type' => 'page', 'post_content' => $content]);
    }

    private function raw_meta(int $post_id, string $key, string $value): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->postmeta, ['post_id' => $post_id, 'meta_key' => $key, 'meta_value' => $value]);
        wp_cache_delete($post_id, 'post_meta');
    }

    private static function elementor_json(string $title): string
    {
        return (string) wp_json_encode([
            [
                'id'       => 'a1b2c3d',
                'elType'   => 'container',
                'settings' => new \stdClass(),
                'elements' => [
                    [
                        'id'         => 'e4f5a6b',
                        'elType'     => 'widget',
                        'widgetType' => 'heading',
                        'settings'   => ['title' => $title, 'path' => 'C:\\Sites\\el "quoted"'],
                        'elements'   => [],
                    ],
                ],
            ],
        ]);
    }

    private static function breakdance_meta(string $text): string
    {
        $tree = [
            'root'        => [
                'id'       => 1,
                'data'     => ['type' => 'root', 'properties' => []],
                'children' => [
                    [
                        'id'        => 100,
                        'data'      => ['type' => 'EssentialElements\\Text', 'properties' => ['content' => ['content' => ['text' => $text]]]],
                        'children'  => [],
                        '_parentId' => 1,
                    ],
                ],
            ],
            '_nextNodeId' => 101,
            'status'      => 'exported',
        ];

        return (string) wp_json_encode(['tree_json_string' => wp_json_encode($tree, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
    }

    private static function beaver_nodes(string $heading): string
    {
        $node = static function (string $id, string $type, ?string $parent, int $position, array $settings): object {
            return (object) [
                'node'     => $id,
                'type'     => $type,
                'parent'   => $parent,
                'position' => $position,
                'settings' => json_decode((string) wp_json_encode((object) $settings)),
            ];
        };

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
        return serialize([
            'r1' => $node('r1', 'row', null, 0, ['width' => 'fixed']),
            'g1' => $node('g1', 'column-group', 'r1', 0, []),
            'c1' => $node('c1', 'column', 'g1', 0, ['size' => '100']),
            'm1' => $node('m1', 'module', 'c1', 0, ['type' => 'heading', 'heading' => $heading, 'tag' => 'h2']),
        ]);
    }

    /**
     * One page per builder, plus the edit that the restore has to undo.
     *
     * @return array<string,array{0:callable(self):int,1:callable(self,int):void}>
     */
    public static function builders(): array
    {
        return [
            'elementor'      => [
                static function (self $t): int {
                    $id = $t->page();
                    update_post_meta($id, '_elementor_edit_mode', 'builder');
                    update_post_meta($id, '_elementor_data', wp_slash(self::elementor_json('Welcome')));
                    return $id;
                },
                static function (self $t, int $id): void {
                    update_post_meta($id, '_elementor_data', wp_slash(self::elementor_json('Edited')));
                },
            ],
            'gutenberg'      => [
                static function (self $t): int {
                    return $t->page("<!-- wp:heading -->\n<h2>Welcome \\ \"home\"</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Café</p>\n<!-- /wp:paragraph -->");
                },
                static function (self $t, int $id): void {
                    wp_update_post(['ID' => $id, 'post_content' => "<!-- wp:paragraph -->\n<p>Edited</p>\n<!-- /wp:paragraph -->"]);
                },
            ],
            'bricks'         => [
                static function (self $t): int {
                    $id = $t->page();
                    update_post_meta($id, '_bricks_page_content_2', [
                        ['id' => 'abc123', 'name' => 'section', 'parent' => 0, 'children' => ['def456'], 'settings' => []],
                        ['id' => 'def456', 'name' => 'heading', 'parent' => 'abc123', 'children' => [], 'settings' => ['text' => 'Welcome', 'tag' => 'h2']],
                    ]);
                    return $id;
                },
                static function (self $t, int $id): void {
                    update_post_meta($id, '_bricks_page_content_2', [
                        ['id' => 'zzz999', 'name' => 'text', 'parent' => 0, 'children' => [], 'settings' => ['text' => 'Edited']],
                    ]);
                },
            ],
            'divi'           => [
                static function (self $t): int {
                    $id = $t->page("[et_pb_section][et_pb_row][et_pb_column type=\"4_4\"]\n[et_pb_text]<p>Welcome to C:\\Sites\\divi</p>[/et_pb_text]\n[/et_pb_column][/et_pb_row][/et_pb_section]");
                    update_post_meta($id, '_et_pb_use_builder', 'on');
                    return $id;
                },
                static function (self $t, int $id): void {
                    wp_update_post(['ID' => $id, 'post_content' => '[et_pb_section][et_pb_text]Edited[/et_pb_text][/et_pb_section]']);
                },
            ],
            'wpbakery'       => [
                static function (self $t): int {
                    $id = $t->page("[vc_row][vc_column]\n[vc_column_text]<p>Welcome</p>[/vc_column_text]\n[/vc_column][/vc_row]");
                    update_post_meta($id, '_wpb_vc_js_status', 'true');
                    return $id;
                },
                static function (self $t, int $id): void {
                    wp_update_post(['ID' => $id, 'post_content' => '[vc_row][vc_column][vc_column_text]Edited[/vc_column_text][/vc_column][/vc_row]']);
                },
            ],
            'beaver-builder' => [
                static function (self $t): int {
                    $id = $t->page('<div class="fl-builder-content"><h2>Welcome</h2></div>');
                    $t->raw_meta($id, '_fl_builder_enabled', '1');
                    $t->raw_meta($id, '_fl_builder_data', self::beaver_nodes('Welcome'));
                    return $id;
                },
                static function (self $t, int $id): void {
                    global $wpdb;
                    $wpdb->update($wpdb->postmeta, ['meta_value' => self::beaver_nodes('Edited')], ['post_id' => $id, 'meta_key' => '_fl_builder_data']);
                    wp_cache_delete($id, 'post_meta');
                },
            ],
            'breakdance'     => [
                static function (self $t): int {
                    $id = $t->page();
                    $t->raw_meta($id, '_breakdance_data', self::breakdance_meta('Welcome to C:\\Sites\\bd, "hi"'));
                    return $id;
                },
                static function (self $t, int $id): void {
                    update_post_meta($id, '_breakdance_data', wp_slash(self::breakdance_meta('Edited')));
                },
            ],
            'avada'          => [
                static function (self $t): int {
                    $id = $t->page("[fusion_builder_container type=\"flex\"][fusion_builder_row][fusion_builder_column type=\"1_1\"]\n[fusion_text]<p>Welcome to C:\\Sites\\avada</p>[/fusion_text]\n[fusion_separator style_type=\"default\" /][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]");
                    update_post_meta($id, 'fusion_builder_status', 'active');
                    return $id;
                },
                static function (self $t, int $id): void {
                    wp_update_post(['ID' => $id, 'post_content' => '[fusion_builder_container][fusion_text]Edited[/fusion_text][/fusion_builder_container]']);
                },
            ],
            'oxygen'         => [
                static function (self $t): int {
                    $id = $t->page();
                    $t->raw_meta($id, '_oxygen_data', self::breakdance_meta('Welcome to C:\\Sites\\oxygen, "hi"'));
                    return $id;
                },
                static function (self $t, int $id): void {
                    update_post_meta($id, '_oxygen_data', wp_slash(self::breakdance_meta('Edited')));
                },
            ],
            'oxygen-classic' => [
                static function (self $t): int {
                    $id = $t->page('<p>Left over</p>');
                    $t->raw_meta($id, '_ct_builder_json', '{"id":0,"name":"root","depth":0,"children":[{"id":1,"name":"ct_section","options":{"ct_id":1,"ct_parent":0,"selector":"section-1-9","original":{"padding-top":"80"},"nicename":"Hero","activeselector":false},"depth":1,"children":[{"id":2,"name":"ct_headline","options":{"ct_id":2,"ct_parent":1,"selector":"headline-2-9","original":{"tag":"h1"},"ct_content":"Welcome to C:\\\\Sites\\\\oxygen, \\"hi\\" <\\/b> Café"},"depth":2}]}]}');
                    return $id;
                },
                static function (self $t, int $id): void {
                    update_post_meta($id, '_ct_builder_json', wp_slash('{"id":0,"name":"root","depth":0,"children":[{"id":1,"name":"ct_section","options":{"ct_id":1,"ct_parent":0,"selector":"section-1-9","original":{}},"depth":1}]}'));
                },
            ],
            'thrive'         => [
                static function (self $t): int {
                    $id = $t->page('<h1>Welcome</h1>');
                    update_post_meta($id, 'tcb2_ready', '1');
                    update_post_meta($id, 'tcb_editor_enabled', '1');
                    update_post_meta($id, 'tve_updated_post', wp_slash("<div class=\"thrv_wrapper thrv_text_element\" data-css=\"tve-u-1\">\n<h1>Welcome to C:\\Sites\\thrive &amp; \"friends\"</h1>\n</div>\n"));
                    return $id;
                },
                static function (self $t, int $id): void {
                    update_post_meta($id, 'tve_updated_post', '<div class="thrv_wrapper thrv_text_element"><p>Edited</p></div>');
                },
            ],
        ];
    }

    /** @dataProvider builders */
    public function test_export_is_byte_stable_and_carries_a_small_header(callable $make): void
    {
        $id = $make($this);

        $first = $this->export(['post_id' => $id]);
        $bytes = $this->bytes($id);
        $again = $this->export(['post_id' => $id]);

        $this->assertSame($bytes, $this->bytes($id), 'Same content must give a byte-identical file');
        $this->assertTrue($first['files'][0]['changed']);
        $this->assertFalse($again['files'][0]['changed'], 'An unchanged page must not be reported as changed');

        $this->assertStringEndsWith("\n", $bytes);
        $this->assertStringContainsString("\n    \"post_id\": {$id},\n", $bytes, 'Pretty-printed, one key per line');

        $doc = json_decode($bytes, true);
        $this->assertSame($id, $doc['post_id']);
        $this->assertSame('page', $doc['post_type']);
        $this->assertSame(get_post($id)->post_modified_gmt, $doc['modified']);
        $this->assertSame($first['files'][0]['builder'], $doc['builder']);
        $this->assertArrayHasKey('data', $doc);
    }

    /** @dataProvider builders */
    public function test_restore_round_trips_every_builder_and_rolls_back_exactly(callable $make, callable $edit): void
    {
        $id = $make($this);
        $this->export(['post_id' => $id]);
        $exported = $this->bytes($id);

        $edit($this, $id);
        $edited = $this->state($id);
        $this->assertNotSame($exported, (function () use ($id) {
            $this->export(['post_id' => $id]);
            return $this->bytes($id);
        })(), 'The edit must change what the mirror sees');

        // The edit above re-exported the file; put the original back so the
        // restore reads what the first export wrote.
        file_put_contents(Content_Mirror::file_for($id), $exported);

        $out = $this->restore($id);
        $this->assertNotEmpty($out['operation_id']);
        $this->assertSame($id, $out['post_id']);

        // Restoring post_content bumps the modified stamp, which the header
        // reports on purpose; everything else must come back byte for byte.
        $this->export(['post_id' => $id]);
        $this->assertSame(self::without_modified($exported), self::without_modified($this->bytes($id)), 'Restore then export must reproduce the mirror file exactly');

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($edited, $this->state($id), 'Rolling the restore back must return every row to the edited state');
    }

    public function test_directory_is_protected_and_holds_only_json_mirror_files(): void
    {
        $id  = self::factory()->post->create(['post_type' => 'page', 'post_content' => "<!-- wp:paragraph -->\n<p>x</p>\n<!-- /wp:paragraph -->"]);
        $out = $this->export(['post_id' => $id]);
        $dir = Content_Mirror::dir();

        $this->assertSame($dir, $out['dir']);
        $this->assertStringStartsWith(trailingslashit(wp_upload_dir()['basedir']), $dir);
        $this->assertSame('wpmcp-mirror', basename($dir));
        $this->assertStringContainsString('Require all denied', (string) file_get_contents($dir . '/.htaccess'));
        $this->assertFileExists($dir . '/index.php');

        foreach (array_diff((array) scandir($dir), ['.', '..', '.htaccess', 'index.php']) as $entry) {
            $this->assertMatchesRegularExpression('/^post-\d+\.json$/', $entry, 'Only JSON mirror files, never executable PHP');
        }
    }

    public function test_post_ids_that_look_like_paths_are_refused(): void
    {
        foreach (['../../wp-config', '1/../../x', '..', '-3', 0] as $bad) {
            try {
                $this->export(['post_id' => $bad]);
                $this->fail('Expected refusal for ' . var_export($bad, true));
            } catch (\InvalidArgumentException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
        $this->assertFalse(is_file(ABSPATH . 'wp-config.json'));
    }

    public function test_restore_never_reads_a_caller_supplied_path(): void
    {
        $id = $this->page("<!-- wp:paragraph -->\n<p>Mirror</p>\n<!-- /wp:paragraph -->");
        $this->export(['post_id' => $id]);
        wp_update_post(['ID' => $id, 'post_content' => 'Edited']);

        $decoy = wp_tempnam('wpmcp-mirror-decoy') . '.json';
        $this->cleanup[] = $decoy;
        file_put_contents($decoy, (string) wp_json_encode(['post_id' => $id, 'builder' => 'gutenberg', 'data' => ['<p>Decoy</p>']]));

        $out = $this->restore($id, ['file' => $decoy, 'path' => $decoy]);

        $this->assertSame(Content_Mirror::file_for($id), $out['file']);
        $this->assertSame("<!-- wp:paragraph -->\n<p>Mirror</p>\n<!-- /wp:paragraph -->", get_post($id)->post_content);
    }

    public function test_restore_refuses_a_mirror_file_that_is_a_symlink_out_of_the_directory(): void
    {
        $id = $this->page("<!-- wp:paragraph -->\n<p>Mirror</p>\n<!-- /wp:paragraph -->");
        $this->export(['post_id' => $id]);
        wp_update_post(['ID' => $id, 'post_content' => 'Edited']);

        $outside = wp_tempnam('wpmcp-mirror-outside') . '.json';
        $this->cleanup[] = $outside;
        file_put_contents($outside, $this->bytes($id));
        $file = Content_Mirror::file_for($id);
        unlink($file);
        symlink($outside, $file);

        try {
            $this->restore($id);
            $this->fail('A symlink leaving the mirror directory must be refused');
        } catch (\RuntimeException $e) {
            $this->assertSame('Edited', get_post($id)->post_content);
        }
    }

    public function test_restore_refuses_a_file_whose_header_names_another_post(): void
    {
        $a = $this->page("<!-- wp:paragraph -->\n<p>A</p>\n<!-- /wp:paragraph -->");
        $b = $this->page("<!-- wp:paragraph -->\n<p>B</p>\n<!-- /wp:paragraph -->");
        $this->export(['post_id' => $b]);
        copy(Content_Mirror::file_for($b), Content_Mirror::file_for($a));

        $this->expectException(\RuntimeException::class);
        $this->restore($a);
    }

    public function test_restore_without_a_mirror_file_is_refused(): void
    {
        $id = $this->page("<!-- wp:paragraph -->\n<p>x</p>\n<!-- /wp:paragraph -->");

        $this->expectException(\RuntimeException::class);
        $this->restore($id);
    }

    public function test_restore_does_not_need_the_wxr_import_opt_in(): void
    {
        $this->assertFalse(Import_Content::is_enabled());
        $id = $this->page("<!-- wp:paragraph -->\n<p>x</p>\n<!-- /wp:paragraph -->");
        $this->export(['post_id' => $id]);

        $this->assertNotEmpty($this->restore($id)['operation_id']);
    }

    public function test_export_all_pages_through_builder_pages_and_skips_classic(): void
    {
        $gutenberg = $this->page("<!-- wp:paragraph -->\n<p>x</p>\n<!-- /wp:paragraph -->");
        $classic   = $this->page('<p>Plain classic content</p>');
        $divi      = $this->page('[et_pb_section][/et_pb_section]');
        update_post_meta($divi, '_et_pb_use_builder', 'on');

        $out = $this->export(['page' => 1]);

        $ids = array_column($out['files'], 'post_id');
        $this->assertContains($gutenberg, $ids);
        $this->assertContains($divi, $ids);
        $this->assertNotContains($classic, $ids);
        $this->assertSame(1, $out['page']);
        $this->assertGreaterThanOrEqual(1, $out['pages']);
        $this->assertFileDoesNotExist(Content_Mirror::file_for($classic));
    }

    public function test_single_export_of_a_classic_page_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->export(['post_id' => $this->page('<p>Plain</p>')]);
    }

    public function test_mirror_requires_manage_options(): void
    {
        $id = $this->page("<!-- wp:paragraph -->\n<p>x</p>\n<!-- /wp:paragraph -->");
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        try {
            $this->export(['post_id' => $id]);
            $this->fail('An editor must not write the mirror');
        } catch (\RuntimeException $e) {
            $this->assertFileDoesNotExist(Content_Mirror::file_for($id));
        }

        $this->expectException(\RuntimeException::class);
        $this->restore($id);
    }

    public function test_the_tools_advertise_the_mirror_parameters(): void
    {
        $abilities = wp_get_abilities();

        $export = $abilities['wpmcp/export-content']->get_input_schema();
        foreach (['mirror', 'post_id', 'page'] as $key) {
            $this->assertArrayHasKey($key, $export['properties']);
        }

        $import = $abilities['wpmcp/import-content']->get_input_schema();
        $this->assertArrayHasKey('mirror', $import['properties']);
        $this->assertArrayHasKey('post_id', $import['properties']);
        $this->assertNotContains('file', $import['required'] ?? [], 'A mirror restore takes a post id, not a file');
    }
}
