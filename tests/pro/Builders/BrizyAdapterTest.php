<?php

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Pro\Gate;
use WPMCP\Safety\{Rollback_Service, Snapshot_Store};
use WPMCP\Tools\Builders\{Brizy_Content, Brizy_Tree, Detect_Builder, Get_Builder_Content, Update_Builder_Content};

/**
 * Issue #645: Brizy through the builder dispatcher pair.
 *
 * Brizy is not installed here, so a page is stored the way Brizy 2.8.26's
 * own save stores it (Brizy_Editor_Post::saveStorage() and the compiler):
 * `brizy_enabled` 1, the `brizy` row whose `brizy-post` entry holds base64
 * of the page JSON (blocks {blockId, type, value}, elements {type, value}
 * with children in value.items and 36 letter `_id`s), the compiled sections,
 * the compiler version and the editor's data version. Its presence is
 * toggled through wpmcp_brizy_active.
 */
class BrizyAdapterTest extends \WP_UnitTestCase
{
    private const SECTION = 'sectaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const ITEM    = 'itemaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const WRAP    = 'wrapaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const TEXT    = 'textaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const WRAP2   = 'wrapbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const BUTTON  = 'buttaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @return array<string,mixed> one section: a text wrapper and a button wrapper */
    public static function page_data(): array
    {
        return ['items' => [[
            'type'    => 'Section',
            'blockId' => 'Kit2Blank000Light',
            'value'   => [
                '_styles' => ['section'],
                '_id'     => self::SECTION,
                'items'   => [[
                    'type'  => 'SectionItem',
                    'value' => [
                        '_styles' => ['section-item'],
                        '_id'     => self::ITEM,
                        'items'   => [
                            ['type' => 'Wrapper', 'value' => ['_styles' => ['wrapper', 'wrapper--richText'], '_id' => self::WRAP, 'items' => [
                                ['type' => 'RichText', 'value' => ['_styles' => ['richText'], '_version' => 3, '_id' => self::TEXT, 'text' => '<p class="brz-tp-paragraph">Hello "Brizy" \\ world</p>']],
                            ]]],
                            ['type' => 'Cloneable', 'value' => ['_styles' => ['wrapper-clone'], '_id' => self::WRAP2, 'items' => [
                                ['type' => 'Button', 'value' => ['_styles' => ['button'], '_id' => self::BUTTON, 'text' => 'Buy now']],
                            ]]],
                        ],
                    ],
                ]],
            ],
        ]]];
    }

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        $this->admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->admin);
        add_filter('wpmcp_brizy_active', '__return_true');
        foreach (['brizy-saved-block', 'brizy-layout', 'brizy-global-block', 'brizy-project'] as $type) {
            if (! post_type_exists($type)) {
                register_post_type($type, ['public' => false]);
            }
        }
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        remove_all_filters('wpmcp_brizy_active');
        parent::tearDown();
    }

    /** Store data the way Brizy's saveStorage() and compiler leave a page. */
    private static function store(int $id, array $data): void
    {
        update_post_meta($id, 'brizy', [
            'brizy-post' => [
                'editor_version'     => '383-wp',
                'compiler_version'   => '383-wp',
                'plugin_version'     => '2.8.26',
                'pro_plugin_version' => null,
                'editor_data'        => base64_encode((string) wp_json_encode($data)),
                'lastUserEdited'     => 1,
                'compiler'           => 'browser',
                'brizy-use-brizy'    => true,
            ],
        ]);
    }

    private function page(?array $data = null, array $args = []): int
    {
        $id = self::factory()->post->create($args + ['post_type' => 'page', 'post_content' => '<div class="brz-root__container"></div><!-- version:1700000000 -->']);
        self::store($id, $data ?? self::page_data());
        update_post_meta($id, 'brizy_enabled', 1);
        update_post_meta($id, 'brizy-post-compiler-version', '383-wp');
        update_post_meta($id, 'brizy-need-compile', '');
        update_post_meta($id, 'brizy-compiled-sections', base64_encode('{"sections":[]}'));
        update_post_meta($id, 'brizy_data_version', 3);

        return $id;
    }

    private function read(int $id): array
    {
        $out = (new Get_Builder_Content())->handle(['post_id' => $id]);
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');

        return $out;
    }

    private function write(int $id, array $args)
    {
        return (new Update_Builder_Content())->handle($args + ['post_id' => $id, 'builder' => 'brizy', 'expected_hash' => $this->read($id)['hash']]);
    }

    private function ok($out): array
    {
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_code() . ': ' . $out->get_error_message() : '');

        return $out;
    }

    public function test_detects_a_brizy_page_by_its_enabled_flag(): void
    {
        $id = $this->page();
        $this->assertSame('brizy', (new Detect_Builder())->handle(['post_id' => $id])['builder']);
        update_post_meta($id, 'brizy_enabled', 0);
        $this->assertNotSame('brizy', (new Detect_Builder())->handle(['post_id' => $id])['builder']);
    }

    public function test_read_returns_the_data_an_outline_by_id_and_the_compile_state(): void
    {
        $read = $this->read($this->page());
        $this->assertSame('brizy', $read['builder']);
        $this->assertTrue($read['plugin_active']);
        $this->assertTrue($read['enabled']);
        $this->assertSame(3, $read['data_version']);
        $this->assertFalse($read['compile_pending']);
        $this->assertSame(self::page_data(), $read['data']);
        $this->assertSame(['id' => self::SECTION, 'type' => 'Section', 'block_id' => 'Kit2Blank000Light'], array_diff_key($read['tree'][0], ['children' => 1]));
        $text = $read['tree'][0]['children'][0]['children'][0]['children'][0];
        $this->assertSame(['id' => self::TEXT, 'type' => 'RichText', 'label' => 'Hello "Brizy" \\ world'], $text);
    }

    public function test_read_refuses_a_draft_the_caller_cannot_read(): void
    {
        $other = self::factory()->user->create(['role' => 'administrator']);
        $id    = $this->page(null, ['post_status' => 'draft', 'post_author' => $other]);
        wp_set_current_user(self::factory()->user->create(['role' => 'contributor']));
        $out = (new Get_Builder_Content())->handle(['post_id' => $id]);
        $this->assertWPError($out);
        $this->assertSame('forbidden', $out->get_error_code());
    }

    public function test_update_merges_fields_marks_a_recompile_and_rolls_back_exactly(): void
    {
        $id      = $this->page();
        $before  = get_post_meta($id);
        $session = wp_generate_uuid4();

        $out = $this->ok($this->write($id, [
            'operation'  => 'update',
            'path'       => self::TEXT,
            'attrs'      => ['text' => '<p class="brz-tp-paragraph">Edited by wpmcp</p>', '_version' => null],
            'session_id' => $session,
        ]));
        $this->assertSame(self::TEXT, $out['path']);
        $this->assertTrue($out['compile_pending']);
        $this->assertSame($this->read($id)['hash'], $out['hash']);

        $value = Brizy_Content::decode($id)['items'][0]['value']['items'][0]['value']['items'][0]['value']['items'][0]['value'];
        $this->assertSame('<p class="brz-tp-paragraph">Edited by wpmcp</p>', $value['text']);
        $this->assertArrayNotHasKey('_version', $value);
        $this->assertSame('0.0.0', get_post_meta($id, 'brizy-post-compiler-version', true));
        $this->assertSame('1', get_post_meta($id, 'brizy-need-compile', true));
        $this->assertSame('4', get_post_meta($id, 'brizy_data_version', true));
        $this->assertSame('2.8.26', get_post_meta($id, 'brizy', true)['brizy-post']['plugin_version']);
        // Stored as the editor's JSON.stringify() writes it: slashes unescaped.
        $this->assertStringContainsString('</p>', base64_decode(get_post_meta($id, 'brizy', true)['brizy-post']['editor_data']));

        Rollback_Service::restore_session($session);
        $this->assertSame(self::page_data(), Brizy_Content::decode($id));
        $this->assertSame($before, get_post_meta($id));
    }

    public function test_update_refuses_ids_children_and_global_blocks(): void
    {
        $id = $this->page();
        $this->assertSame('invalid_brizy_request', $this->write($id, ['operation' => 'update', 'path' => self::TEXT, 'attrs' => ['_id' => 'x']])->get_error_code());
        $this->assertSame('invalid_brizy_request', $this->write($id, ['operation' => 'update', 'path' => self::WRAP, 'attrs' => ['items' => []]])->get_error_code());
        $this->assertSame('invalid_brizy_request', $this->write($id, ['operation' => 'update', 'path' => 'nope', 'attrs' => ['text' => 'x']])->get_error_code());
        $this->assertSame(self::page_data(), Brizy_Content::decode($id));
    }

    public function test_add_elements_and_blocks_with_fresh_ids_where_they_belong(): void
    {
        $id = $this->page();
        $w  = $this->ok($this->write($id, ['operation' => 'add', 'to' => self::ITEM, 'index' => 0, 'element' => [
            'type'  => 'Wrapper',
            'value' => ['_styles' => ['wrapper'], '_id' => self::TEXT, 'items' => [['type' => 'RichText', 'value' => ['text' => '<p>New</p>']]]],
        ]]));
        $s  = $this->ok($this->write($id, ['operation' => 'add', 'to' => '', 'element' => ['type' => 'Section', 'value' => ['items' => []]]]));

        $data = Brizy_Content::decode($id);
        $new  = $data['items'][0]['value']['items'][0]['value']['items'][0];
        $this->assertSame($w['path'], $new['value']['_id']);
        $this->assertNotSame(self::TEXT, $new['value']['_id']);
        $this->assertMatchesRegularExpression('/^[a-z]{36}$/', $new['value']['items'][0]['value']['_id']);
        $this->assertCount(3, $data['items'][0]['value']['items'][0]['value']['items']);
        $this->assertSame($s['path'], $data['items'][1]['value']['_id']);
        $this->assertSame('Kit2Blank000Light', $data['items'][1]['blockId']);

        $this->assertSame('invalid_brizy_request', $this->write($id, ['operation' => 'add', 'to' => '', 'element' => ['type' => 'RichText']])->get_error_code());
        $this->assertSame('invalid_brizy_request', $this->write($id, ['operation' => 'add', 'to' => self::ITEM, 'element' => ['type' => 'Section']])->get_error_code());
        $this->assertSame('invalid_brizy_request', $this->write($id, ['operation' => 'add', 'to' => self::TEXT, 'element' => ['type' => 'Button']])->get_error_code());
    }

    public function test_remove_and_move_by_id(): void
    {
        $id = $this->page();
        $this->ok($this->write($id, ['operation' => 'move', 'path' => self::WRAP2, 'to' => self::ITEM, 'index' => 0]));
        $items = Brizy_Content::decode($id)['items'][0]['value']['items'][0]['value']['items'];
        $this->assertSame([self::WRAP2, self::WRAP], array_column(array_column($items, 'value'), '_id'));
        $this->assertSame('invalid_brizy_request', $this->write($id, ['operation' => 'move', 'path' => self::ITEM, 'to' => self::WRAP])->get_error_code());

        $this->ok($this->write($id, ['operation' => 'remove', 'path' => self::WRAP2]));
        $items = Brizy_Content::decode($id)['items'][0]['value']['items'][0]['value']['items'];
        $this->assertSame([self::WRAP], array_column(array_column($items, 'value'), '_id'));
    }

    public function test_whole_content_write_validates_and_fills_missing_ids(): void
    {
        $id  = $this->page();
        $out = $this->ok($this->write($id, ['content' => wp_json_encode([['type' => 'Section', 'value' => ['items' => [['type' => 'SectionItem', 'value' => ['items' => []]]]]]])]));
        $this->assertSame('brizy', $out['builder']);
        $data = Brizy_Content::decode($id);
        $this->assertMatchesRegularExpression('/^[a-z]{36}$/', $data['items'][0]['value']['_id']);
        $this->assertMatchesRegularExpression('/^[a-z]{36}$/', $data['items'][0]['value']['items'][0]['value']['_id']);

        $dupe = ['items' => [['type' => 'Section', 'value' => ['_id' => 'x', 'items' => [['type' => 'SectionItem', 'value' => ['_id' => 'x']]]]]]];
        $this->assertSame('invalid_brizy_request', $this->write($id, ['content' => wp_json_encode($dupe)])->get_error_code());
        $this->assertSame('invalid_brizy_request', $this->write($id, ['content' => '{"nope":1}'])->get_error_code());
        $this->assertSame('invalid_brizy_request', $this->write($id, ['content' => 'not json'])->get_error_code());
    }

    public function test_writes_need_the_hash_the_plugin_and_a_brizy_page(): void
    {
        $id  = $this->page();
        $out = (new Update_Builder_Content())->handle(['post_id' => $id, 'builder' => 'brizy', 'operation' => 'remove', 'path' => self::WRAP2]);
        $this->assertSame('missing_expected_hash', $out->get_error_code());
        $out = (new Update_Builder_Content())->handle(['post_id' => $id, 'builder' => 'brizy', 'operation' => 'remove', 'path' => self::WRAP2, 'expected_hash' => str_repeat('0', 64)]);
        $this->assertSame('stale_expected_hash', $out->get_error_code());

        remove_all_filters('wpmcp_brizy_active');
        add_filter('wpmcp_brizy_active', '__return_false');
        $out = $this->write($id, ['operation' => 'remove', 'path' => self::WRAP2]);
        $this->assertSame('plugin_missing', $out->get_error_code());
        $this->assertStringContainsString('Install and activate Brizy', $out->get_error_message());

        remove_all_filters('wpmcp_brizy_active');
        add_filter('wpmcp_brizy_active', '__return_true');
        $gutenberg = self::factory()->post->create(['post_type' => 'page', 'post_content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->']);
        $out       = (new Update_Builder_Content())->handle(['post_id' => $gutenberg, 'builder' => 'brizy', 'content' => '[]']);
        $this->assertSame('unsupported_builder', $out->get_error_code());

        wp_set_current_user(self::factory()->user->create(['role' => 'contributor']));
        $this->assertSame('forbidden', $this->write($id, ['operation' => 'remove', 'path' => self::WRAP2])->get_error_code());
        $this->assertSame(self::page_data(), Brizy_Content::decode($id));
    }

    public function test_a_blank_page_becomes_a_brizy_page_and_rolls_back_to_none(): void
    {
        $id      = self::factory()->post->create(['post_type' => 'page', 'post_content' => '']);
        $session = wp_generate_uuid4();
        $out     = $this->ok((new Update_Builder_Content())->handle(['post_id' => $id, 'builder' => 'brizy', 'operation' => 'add', 'to' => '', 'element' => ['type' => 'Section', 'value' => ['items' => []]], 'session_id' => $session]));
        $this->assertNotEmpty($out['operation_id']);
        $this->assertSame('brizy', (new Detect_Builder())->handle(['post_id' => $id])['builder']);
        $this->assertSame('1', get_post_meta($id, 'brizy_data_version', true));
        $this->assertSame('server', get_post_meta($id, 'brizy', true)['brizy-post']['compiler']);

        Rollback_Service::restore_session($session);
        $this->assertFalse(metadata_exists('post', $id, 'brizy'));
        $this->assertFalse(metadata_exists('post', $id, 'brizy_enabled'));
    }

    public function test_publish_sets_the_page_live_and_rolls_back(): void
    {
        $id      = $this->page(null, ['post_status' => 'draft']);
        $session = wp_generate_uuid4();
        $this->ok($this->write($id, ['operation' => 'publish', 'session_id' => $session]));
        $this->assertSame('publish', get_post_status($id));
        $this->assertSame('already_published', $this->write($id, ['operation' => 'publish'])->get_error_code());

        Rollback_Service::restore_session($session);
        $this->assertSame('draft', get_post_status($id));
    }

    public function test_add_a_saved_block_layout_or_global_block_template(): void
    {
        $data  = self::page_data();
        $saved = self::factory()->post->create(['post_type' => 'brizy-saved-block', 'post_status' => 'publish', 'post_title' => 'Hero']);
        self::store($saved, $data['items'][0]);
        $layout = self::factory()->post->create(['post_type' => 'brizy-layout', 'post_status' => 'publish', 'post_title' => 'Landing']);
        self::store($layout, $data);
        $global = self::factory()->post->create(['post_type' => 'brizy-global-block', 'post_status' => 'publish', 'post_title' => 'Footer']);
        self::store($global, ['type' => 'Section', 'blockId' => 'Footer01', 'value' => ['_id' => 'gx']]);
        update_post_meta($global, 'brizy_post_uid', 'globaluidaaaaaaaaaaaaaaaaaaaaaaaaaaa');

        $id = $this->page();
        $a  = $this->ok($this->write($id, ['operation' => 'add', 'to' => '', 'element' => ['template' => $saved]]));
        $b  = $this->ok($this->write($id, ['operation' => 'add', 'to' => '', 'element' => ['template' => (string) $layout]]));
        $c  = $this->ok($this->write($id, ['operation' => 'add', 'to' => '', 'index' => 0, 'element' => ['template' => $global]]));

        $items = Brizy_Content::decode($id)['items'];
        $this->assertCount(4, $items);
        $this->assertSame(['blockId' => 'Footer01', 'type' => 'GlobalBlock', 'value' => ['_id' => 'globaluidaaaaaaaaaaaaaaaaaaaaaaaaaaa']], $items[0]);
        $this->assertSame($c['path'], 'globaluidaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $this->assertSame($a['path'], $items[2]['value']['_id']);
        $this->assertNotSame(self::SECTION, $a['path']);
        $this->assertSame($b['path'], $items[3]['value']['_id']);
        $this->assertSame('Buy now', $items[3]['value']['items'][0]['value']['items'][1]['value']['items'][0]['value']['text']);
        $this->assertCount(19, Brizy_Tree::ids(['items' => $items]), 'every copied element got its own id');

        $this->assertSame('invalid_brizy_request', $this->write($id, ['operation' => 'add', 'to' => '', 'element' => ['template' => $global]])->get_error_code());
        $this->assertSame('invalid_brizy_request', $this->write($id, ['operation' => 'update', 'path' => 'globaluidaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'attrs' => ['x' => 1]])->get_error_code());
        $other = self::factory()->post->create(['post_type' => 'page']);
        $this->assertSame('invalid_brizy_request', $this->write($id, ['operation' => 'add', 'to' => '', 'element' => ['template' => $other]])->get_error_code());
    }

    public function test_templates_scope_lists_readable_templates(): void
    {
        $mine = self::factory()->post->create(['post_type' => 'brizy-saved-block', 'post_status' => 'publish', 'post_title' => 'Hero']);
        self::store($mine, self::page_data()['items'][0]);
        $other = self::factory()->user->create(['role' => 'author']);
        self::factory()->post->create(['post_type' => 'brizy-layout', 'post_status' => 'draft', 'post_author' => $other, 'post_title' => 'Hidden']);

        wp_set_current_user(self::factory()->user->create(['role' => 'contributor']));
        $out = (new Get_Builder_Content())->handle(['scope' => 'templates', 'builder' => 'brizy']);
        $this->assertSame([['id' => $mine, 'title' => 'Hero', 'status' => 'publish', 'kind' => 'saved_block', 'blocks' => 1]], $out['templates']);
    }

    public function test_design_system_scope_reads_project_styles_without_credentials(): void
    {
        $project = self::factory()->post->create(['post_type' => 'brizy-project', 'post_status' => 'publish']);
        update_post_meta($project, 'brizy-project', [
            'brizy-license-key'  => 'SECRET-LICENSE',
            'brizy-cloud-token'  => 'SECRET-TOKEN',
            'data'               => base64_encode((string) wp_json_encode([
                'selectedStyle'   => 'stylea',
                'styles'          => [['id' => 'stylea', 'title' => 'Overpass', 'colorPalette' => [['id' => 'color1', 'hex' => '#A170D9']], 'fontStyles' => [['id' => 'paragraph', 'fontSize' => 16]]]],
                'extraFontStyles' => [],
                'font'            => 'lato',
            ])),
        ]);

        $out = (new Get_Builder_Content())->handle(['scope' => 'design_system', 'builder' => 'brizy']);
        $this->assertTrue($out['project']);
        $this->assertSame('stylea', $out['selected_style']);
        $this->assertSame('#A170D9', $out['styles'][0]['colorPalette'][0]['hex']);
        $this->assertSame('lato', $out['font']);
        $this->assertStringNotContainsString('SECRET', (string) wp_json_encode($out));
    }

    public function test_catalog_lists_element_types_and_one_by_name(): void
    {
        $out = (new Get_Builder_Content())->handle(['scope' => 'catalog', 'builder' => 'brizy']);
        $this->assertContains('RichText', array_column($out['elements'], 'type'));
        $one = (new Get_Builder_Content())->handle(['scope' => 'catalog', 'builder' => 'brizy', 'element' => 'Section']);
        $this->assertTrue($one['block']);
        $this->assertSame('element_not_found', (new Get_Builder_Content())->handle(['scope' => 'catalog', 'builder' => 'brizy', 'element' => 'Nope'])->get_error_code());
    }
}

