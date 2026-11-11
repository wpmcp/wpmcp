<?php

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Pro\Gate;
use WPMCP\Safety\{Rollback_Service, Snapshot_Store};
use WPMCP\Tools\Builders\{BeTheme_Cache, Detect_Builder, Get_Builder_Content, Update_Builder_Content};

/**
 * Issue #643: BeTheme's BeBuilder through the builder dispatcher pair.
 *
 * BeTheme is a commercial theme and not installed here, so a page is stored
 * the way BeTheme 29.0.5's own save stores it (Mfn_Builder_Admin::save()
 * and mfnvb_updateVbView()): the `mfn-page-items` row holding sections,
 * wraps and items with 9 character hex uids, as the array (the default
 * "Serialized" storage) or base64(serialize(array)) under the theme option
 * builder-storage 'encode', with the visual builder's `mfn-builder-preview`
 * copy and `mfn-page-object`, and style fields stored as {selector, style,
 * val}. The theme's presence is toggled through wpmcp_betheme_active.
 */
class BeThemeAdapterTest extends \WP_UnitTestCase
{
    /** A layout as BeTheme's save stores it: one section, one wrap, a heading and a column. */
    public const LAYOUT = [
        [
            'uid'   => 'a1b2c3d4e',
            'attr'  => [
                'title'          => 'Intro',
                'css_section_bg' => ['selector' => '.mcb-section-mfnuidelement', 'style' => 'background-color', 'val' => '#ffffff'],
            ],
            'wraps' => [
                [
                    'uid'         => 'b2c3d4e5f',
                    'size'        => '1/1',
                    'tablet_size' => '1/1',
                    'mobile_size' => '1/1',
                    'attr'        => [],
                    'items'       => [
                        [
                            'type'        => 'heading',
                            'uid'         => 'c3d4e5f60',
                            'size'        => '1/1',
                            'tablet_size' => '1/1',
                            'mobile_size' => '1/1',
                            'used_fonts'  => '',
                            'attr'        => [
                                'title'                  => 'Welcome to BeTheme',
                                'header_tag'             => 'h2',
                                'css_heading_color'      => ['selector' => '.mcb-section .mcb-wrap .mcb-item-mfnuidelement .title', 'style' => 'color', 'val' => '#161922'],
                            ],
                        ],
                        [
                            'type'        => 'column',
                            'uid'         => 'd4e5f6071',
                            'size'        => '1/2',
                            'tablet_size' => '1/2',
                            'mobile_size' => '1/1',
                            'used_fonts'  => '',
                            'attr'        => ['content' => '<p>Body text</p>'],
                        ],
                    ],
                ],
            ],
        ],
    ];

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        $this->admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->admin);
        add_filter('wpmcp_betheme_active', '__return_true');
        if (! post_type_exists('template')) {
            register_post_type('template', ['public' => false]);
        }
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        remove_all_filters('wpmcp_betheme_active');
        delete_option('betheme');
        parent::tearDown();
    }

    /** @param array<int,array<string,mixed>>|string $items */
    private function page($items = self::LAYOUT, array $args = []): int
    {
        $id = self::factory()->post->create($args + ['post_type' => 'page', 'post_content' => '']);
        update_post_meta($id, 'mfn-page-items', is_array($items) ? wp_slash($items) : $items);

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
        return (new Update_Builder_Content())->handle($args + ['post_id' => $id, 'builder' => 'betheme', 'expected_hash' => $this->read($id)['hash']]);
    }

    private function ok($out): array
    {
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_code() . ': ' . $out->get_error_message() : '');

        return $out;
    }

    public function test_detects_a_page_in_either_storage_format(): void
    {
        $this->assertSame('betheme', (new Detect_Builder())->handle(['post_id' => $this->page()])['builder']);
        $encoded = base64_encode(serialize(self::LAYOUT));
        $this->assertSame('betheme', (new Detect_Builder())->handle(['post_id' => $this->page($encoded)])['builder']);
        $this->assertSame('classic', (new Detect_Builder())->handle(['post_id' => $this->page('')])['builder']);
    }

    public function test_read_returns_sections_an_outline_by_uid_and_the_storage(): void
    {
        $read = $this->read($this->page());
        $this->assertSame('betheme', $read['builder']);
        $this->assertSame('serialized', $read['storage']);
        $this->assertTrue($read['plugin_active']);
        $this->assertSame(self::LAYOUT, $read['sections']);
        $this->assertSame('section', $read['tree'][0]['kind']);
        $this->assertSame('b2c3d4e5f', $read['tree'][0]['children'][0]['uid']);
        $heading = $read['tree'][0]['children'][0]['children'][0];
        $this->assertSame(['uid' => 'c3d4e5f60', 'kind' => 'item', 'type' => 'heading', 'size' => '1/1', 'label' => 'Welcome to BeTheme'], $heading);

        $encoded = $this->read($this->page(base64_encode(serialize(self::LAYOUT))));
        $this->assertSame('encoded', $encoded['storage']);
        $this->assertSame(self::LAYOUT, $encoded['sections']);
    }

    public function test_an_encoded_row_holding_an_object_is_not_instantiated(): void
    {
        $evil = base64_encode('a:1:{i:0;O:8:"stdClass":0:{}}');
        $read = $this->read($this->page($evil));
        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, $read['sections'][0]);
    }

    public function test_read_refuses_a_draft_the_caller_cannot_read(): void
    {
        $other = self::factory()->user->create(['role' => 'administrator']);
        $id    = $this->page(self::LAYOUT, ['post_status' => 'draft', 'post_author' => $other]);
        wp_set_current_user(self::factory()->user->create(['role' => 'contributor']));
        $out = (new Get_Builder_Content())->handle(['post_id' => $id]);
        $this->assertWPError($out);
        $this->assertSame('forbidden', $out->get_error_code());
    }

    public function test_update_merges_attrs_keeps_style_selectors_and_rolls_back_exactly(): void
    {
        $id      = $this->page();
        update_post_meta($id, 'mfn-page-object', '[]');
        $before  = get_post_meta($id);
        $session = wp_generate_uuid4();

        $out = $this->ok($this->write($id, [
            'operation'  => 'update',
            'path'       => 'c3d4e5f60',
            'attrs'      => ['title' => 'Edited by wpmcp', 'css_heading_color' => '#ff0000', 'header_tag' => null],
            'session_id' => $session,
        ]));
        $this->assertSame('c3d4e5f60', $out['path']);
        $this->assertSame($this->read($id)['hash'], $out['hash']);

        $attr = BeTheme_Cache::decode($id)[0]['wraps'][0]['items'][0]['attr'];
        $this->assertSame('Edited by wpmcp', $attr['title']);
        $this->assertArrayNotHasKey('header_tag', $attr);
        $this->assertSame(['selector' => '.mcb-section .mcb-wrap .mcb-item-mfnuidelement .title', 'style' => 'color', 'val' => '#ff0000'], $attr['css_heading_color']);
        $this->assertFalse(metadata_exists('post', $id, 'mfn-page-object'));

        Rollback_Service::restore_session($session);
        $this->assertSame(self::LAYOUT, BeTheme_Cache::decode($id));
        $this->assertSame($before, get_post_meta($id));
    }

    public function test_update_sets_size_fields_and_refuses_a_new_style_field_it_cannot_define(): void
    {
        $id = $this->page();
        $this->ok($this->write($id, ['operation' => 'update', 'path' => 'd4e5f6071', 'element' => ['size' => '1/3']]));
        $this->assertSame('1/3', BeTheme_Cache::decode($id)[0]['wraps'][0]['items'][1]['size']);

        $out = $this->write($id, ['operation' => 'update', 'path' => 'd4e5f6071', 'attrs' => ['css_unknown' => '4px']]);
        $this->assertWPError($out);
        $this->assertSame('invalid_betheme_request', $out->get_error_code());

        $this->ok($this->write($id, ['operation' => 'update', 'path' => 'd4e5f6071', 'attrs' => ['css_pad' => ['selector' => '.x', 'style' => 'padding', 'val' => '4px']]]));
        $this->assertSame('padding', BeTheme_Cache::decode($id)[0]['wraps'][0]['items'][1]['attr']['css_pad']['style']);
    }

    public function test_add_a_section_wrap_item_and_nested_wrap_with_new_uids(): void
    {
        $id = $this->page();
        $s  = $this->ok($this->write($id, ['operation' => 'add', 'to' => '', 'element' => ['attr' => ['title' => 'Second'], 'wraps' => [['size' => '1/2', 'items' => [['type' => 'heading', 'attr' => ['title' => 'New']]]]]]]));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{9}$/', $s['path']);

        $w = $this->ok($this->write($id, ['operation' => 'add', 'to' => $s['path'], 'index' => 0, 'element' => ['size' => '1/4']]));
        $i = $this->ok($this->write($id, ['operation' => 'add', 'to' => $w['path'], 'element' => ['type' => 'button', 'attr' => ['title' => 'Go']]]));
        $n = $this->ok($this->write($id, ['operation' => 'add', 'to' => 'b2c3d4e5f', 'element' => ['item_is_wrap' => 1, 'size' => '1/2', 'items' => [['type' => 'image']]]]));

        $layout = BeTheme_Cache::decode($id);
        $this->assertCount(2, $layout);
        $this->assertSame('Second', $layout[1]['attr']['title']);
        $this->assertSame($w['path'], $layout[1]['wraps'][0]['uid']);
        $this->assertSame(['type' => 'button', 'uid' => $i['path'], 'size' => '1/1', 'tablet_size' => '1/1', 'mobile_size' => '1/1', 'used_fonts' => '', 'attr' => ['title' => 'Go']], $layout[1]['wraps'][0]['items'][0]);
        $this->assertSame(1, $layout[0]['wraps'][0]['items'][2]['item_is_wrap']);
        $this->assertSame($n['path'], $layout[0]['wraps'][0]['items'][2]['uid']);
        $this->assertSame('image', $layout[0]['wraps'][0]['items'][2]['items'][0]['type']);
        $uids = [];
        array_walk_recursive($layout, static function ($v, $k) use (&$uids) {
            if ('uid' === $k) {
                $uids[] = $v;
            }
        });
        $this->assertSame($uids, array_unique($uids));
    }

    public function test_add_refuses_an_element_into_the_wrong_container(): void
    {
        $id = $this->page();
        foreach ([
            ['to' => 'c3d4e5f60', 'element' => ['type' => 'heading']],
            ['to' => '', 'element' => ['item_is_wrap' => 1]],
            ['to' => 'a1b2c3d4e', 'element' => ['type' => 'heading']],
            ['to' => 'nope', 'element' => ['size' => '1/1']],
            ['to' => 'b2c3d4e5f', 'element' => ['type' => '<script>']],
        ] as $case) {
            $out = $this->write($id, ['operation' => 'add'] + $case);
            $this->assertWPError($out, wp_json_encode($case));
            $this->assertSame('invalid_betheme_request', $out->get_error_code());
        }
        $this->assertSame(self::LAYOUT, BeTheme_Cache::decode($id));
    }

    public function test_remove_and_move_by_uid(): void
    {
        $id = $this->page();
        $this->ok($this->write($id, ['operation' => 'move', 'path' => 'd4e5f6071', 'to' => 'b2c3d4e5f', 'index' => 0]));
        $items = BeTheme_Cache::decode($id)[0]['wraps'][0]['items'];
        $this->assertSame(['d4e5f6071', 'c3d4e5f60'], array_column($items, 'uid'));

        $out = $this->write($id, ['operation' => 'move', 'path' => 'b2c3d4e5f', 'to' => '']);
        $this->assertWPError($out);

        $this->ok($this->write($id, ['operation' => 'remove', 'path' => 'c3d4e5f60']));
        $this->assertSame(['d4e5f6071'], array_column(BeTheme_Cache::decode($id)[0]['wraps'][0]['items'], 'uid'));
    }

    public function test_whole_layout_write_validates_and_fills_missing_uids(): void
    {
        $id     = $this->page();
        $layout = [['attr' => [], 'wraps' => [['size' => '1/1', 'items' => [['type' => 'heading', 'attr' => ['title' => 'Whole']]]]]]];
        $this->ok($this->write($id, ['content' => wp_json_encode($layout)]));
        $stored = BeTheme_Cache::decode($id);
        $this->assertSame('Whole', $stored[0]['wraps'][0]['items'][0]['attr']['title']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{9}$/', $stored[0]['wraps'][0]['items'][0]['uid']);

        $dupe = [['uid' => 'x', 'wraps' => [['uid' => 'x', 'items' => []]]]];
        $this->assertSame('invalid_betheme_request', $this->write($id, ['content' => wp_json_encode($dupe)])->get_error_code());
        $this->assertSame('invalid_betheme_request', $this->write($id, ['content' => '{"a":1}'])->get_error_code());
        $this->assertSame('invalid_betheme_request', $this->write($id, ['content' => wp_json_encode([['wraps' => [['items' => [['attr' => []]]]]]])])->get_error_code());
    }

    public function test_writes_follow_the_builder_storage_option_and_keep_the_preview_in_step(): void
    {
        update_option('betheme', ['builder-storage' => 'encode']);
        $id = $this->page();
        update_post_meta($id, 'mfn-builder-preview', wp_slash(self::LAYOUT));
        $this->ok($this->write($id, ['operation' => 'update', 'path' => 'c3d4e5f60', 'attrs' => ['title' => 'Encoded']]));

        $raw = get_post_meta($id, 'mfn-page-items', true);
        $this->assertIsString($raw);
        $this->assertSame('Encoded', unserialize(base64_decode($raw))[0]['wraps'][0]['items'][0]['attr']['title']);
        $this->assertSame($raw, get_post_meta($id, 'mfn-builder-preview', true));
        $this->assertSame('encoded', $this->read($id)['storage']);
    }

    public function test_a_slashed_value_round_trips(): void
    {
        $id = $this->page();
        $this->ok($this->write($id, ['operation' => 'update', 'path' => 'd4e5f6071', 'attrs' => ['content' => 'C:\\Sites "quoted"']]));
        $this->assertSame('C:\\Sites "quoted"', BeTheme_Cache::decode($id)[0]['wraps'][0]['items'][1]['attr']['content']);
    }

    public function test_writes_need_the_hash_the_theme_and_a_betheme_page(): void
    {
        $id  = $this->page();
        $out = (new Update_Builder_Content())->handle(['post_id' => $id, 'builder' => 'betheme', 'operation' => 'remove', 'path' => 'c3d4e5f60']);
        $this->assertSame('missing_expected_hash', $out->get_error_code());
        $out = (new Update_Builder_Content())->handle(['post_id' => $id, 'builder' => 'betheme', 'operation' => 'remove', 'path' => 'c3d4e5f60', 'expected_hash' => str_repeat('0', 64)]);
        $this->assertSame('stale_expected_hash', $out->get_error_code());

        remove_all_filters('wpmcp_betheme_active');
        add_filter('wpmcp_betheme_active', '__return_false');
        $out = $this->write($id, ['operation' => 'remove', 'path' => 'c3d4e5f60']);
        $this->assertSame('plugin_missing', $out->get_error_code());
        $this->assertStringContainsString('BeTheme', $out->get_error_message());

        remove_all_filters('wpmcp_betheme_active');
        add_filter('wpmcp_betheme_active', '__return_true');
        $gutenberg = self::factory()->post->create(['post_type' => 'page', 'post_content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->']);
        $out       = (new Update_Builder_Content())->handle(['post_id' => $gutenberg, 'builder' => 'betheme', 'content' => '[]']);
        $this->assertSame('unsupported_builder', $out->get_error_code());
        $this->assertSame(self::LAYOUT, BeTheme_Cache::decode($id));
    }

    public function test_a_blank_page_takes_a_first_layout_without_a_hash_and_rolls_back_to_none(): void
    {
        $id      = self::factory()->post->create(['post_type' => 'page', 'post_content' => '']);
        $session = wp_generate_uuid4();
        $out     = $this->ok((new Update_Builder_Content())->handle(['post_id' => $id, 'builder' => 'betheme', 'operation' => 'add', 'to' => '', 'element' => ['wraps' => [['items' => [['type' => 'heading']]]]], 'session_id' => $session]));
        $this->assertSame('betheme', (new Detect_Builder())->handle(['post_id' => $id])['builder']);
        $this->assertNotEmpty($out['operation_id']);

        Rollback_Service::restore_session($session);
        $this->assertFalse(metadata_exists('post', $id, 'mfn-page-items'));
    }

    public function test_elements_of_a_global_section_or_wrap_are_refused(): void
    {
        $layout                                     = self::LAYOUT;
        $layout[0]['mfn_global_section_id']         = 99;
        $id                                         = $this->page($layout);
        $out                                        = $this->write($id, ['operation' => 'update', 'path' => 'c3d4e5f60', 'attrs' => ['title' => 'x']]);
        $this->assertSame('invalid_betheme_request', $out->get_error_code());
        $this->assertStringContainsString('global', $out->get_error_message());
        $this->assertSame(99, $this->read($id)['tree'][0]['global']);

        $layout                                       = self::LAYOUT;
        $layout[0]['wraps'][0]['attr']['global_wraps_select'] = '77';
        $id2                                          = $this->page($layout);
        $out                                          = $this->write($id2, ['operation' => 'add', 'to' => 'b2c3d4e5f', 'element' => ['type' => 'heading']]);
        $this->assertSame('invalid_betheme_request', $out->get_error_code());
    }

    public function test_a_pre_wraps_layout_is_read_but_not_written(): void
    {
        $id   = $this->page([['uid' => 'aaaaaaaaa', 'items' => [['type' => 'heading', 'uid' => 'bbbbbbbbb', 'attr' => []]]]]);
        $read = $this->read($id);
        $this->assertTrue($read['tree'][0]['legacy']);
        $out = $this->write($id, ['operation' => 'remove', 'path' => 'bbbbbbbbb']);
        $this->assertSame('invalid_betheme_request', $out->get_error_code());
    }

    public function test_publish_sets_the_page_live_and_rolls_back(): void
    {
        $id      = $this->page(self::LAYOUT, ['post_status' => 'draft']);
        $session = wp_generate_uuid4();
        $this->ok($this->write($id, ['operation' => 'publish', 'session_id' => $session]));
        $this->assertSame('publish', get_post_status($id));
        $this->assertSame('already_published', $this->write($id, ['operation' => 'publish'])->get_error_code());

        Rollback_Service::restore_session($session);
        $this->assertSame('draft', get_post_status($id));
    }

    public function test_add_a_template_copies_its_sections_wraps_or_items_with_new_uids(): void
    {
        $template = self::factory()->post->create(['post_type' => 'template', 'post_status' => 'publish', 'post_title' => 'Hero']);
        update_post_meta($template, 'mfn_template_type', 'section');
        update_post_meta($template, 'mfn-page-items', base64_encode(serialize(self::LAYOUT)));
        $id = $this->page();

        $s = $this->ok($this->write($id, ['operation' => 'add', 'to' => '', 'element' => ['template' => $template]]));
        $w = $this->ok($this->write($id, ['operation' => 'add', 'to' => 'a1b2c3d4e', 'element' => ['template' => (string) $template]]));
        $i = $this->ok($this->write($id, ['operation' => 'add', 'to' => 'b2c3d4e5f', 'index' => 0, 'element' => ['template' => $template]]));

        $layout = BeTheme_Cache::decode($id);
        $this->assertCount(2, $layout);
        $this->assertSame($s['path'], $layout[1]['uid']);
        $this->assertSame('Welcome to BeTheme', $layout[1]['wraps'][0]['items'][0]['attr']['title']);
        $this->assertNotSame('c3d4e5f60', $layout[1]['wraps'][0]['items'][0]['uid']);
        $this->assertCount(2, $layout[0]['wraps']);
        $this->assertSame($w['path'], $layout[0]['wraps'][1]['uid']);
        $this->assertCount(4, $layout[0]['wraps'][0]['items']);
        $this->assertSame($i['path'], $layout[0]['wraps'][0]['items'][0]['uid']);
        $this->assertSame('heading', $layout[0]['wraps'][0]['items'][0]['type']);

        $other = self::factory()->post->create(['post_type' => 'page']);
        $this->assertSame('invalid_betheme_request', $this->write($id, ['operation' => 'add', 'to' => '', 'element' => ['template' => $other]])->get_error_code());
    }

    public function test_templates_scope_lists_readable_templates(): void
    {
        $mine = self::factory()->post->create(['post_type' => 'template', 'post_status' => 'publish', 'post_title' => 'Hero']);
        update_post_meta($mine, 'mfn_template_type', 'section');
        update_post_meta($mine, 'mfn-page-items', wp_slash(self::LAYOUT));
        $other = self::factory()->user->create(['role' => 'author']);
        self::factory()->post->create(['post_type' => 'template', 'post_status' => 'draft', 'post_author' => $other, 'post_title' => 'Hidden']);

        wp_set_current_user(self::factory()->user->create(['role' => 'contributor']));
        $out = (new Get_Builder_Content())->handle(['scope' => 'templates', 'builder' => 'betheme']);
        $this->assertSame([['id' => $mine, 'title' => 'Hero', 'status' => 'publish', 'type' => 'section', 'sections' => 1]], $out['templates']);
    }

    public function test_design_system_scope_reads_theme_options_without_secrets(): void
    {
        update_option('betheme', [
            'color-theme'         => '#0089F7',
            'background-body'     => '#FCFCFC',
            'font-content'        => 'Roboto',
            'grid-width'          => '1240',
            'builder-storage'     => 'encode',
            'google-maps-api-key' => 'AIzaSECRET',
            'recaptcha-secret'    => 'SECRET',
            'recaptcha-key'       => 'SECRET',
        ]);
        $out  = (new Get_Builder_Content())->handle(['scope' => 'design_system', 'builder' => 'betheme']);
        $json = wp_json_encode($out);
        $this->assertSame('#0089F7', $out['colors']['color-theme']);
        $this->assertSame('#FCFCFC', $out['colors']['background-body']);
        $this->assertSame('Roboto', $out['typography']['font-content']);
        $this->assertSame('1240', $out['layout']['grid-width']);
        $this->assertSame('encoded', $out['storage']);
        $this->assertStringNotContainsString('SECRET', $json);

        $one = (new Get_Builder_Content())->handle(['scope' => 'design_system', 'builder' => 'betheme', 'element' => 'grid-width']);
        $this->assertSame('1240', $one['value']);
        $this->assertSame('forbidden_option', (new Get_Builder_Content())->handle(['scope' => 'design_system', 'builder' => 'betheme', 'element' => 'google-maps-api-key'])->get_error_code());
    }

    public function test_catalog_needs_the_theme(): void
    {
        $out = (new Get_Builder_Content())->handle(['scope' => 'catalog', 'builder' => 'betheme']);
        $this->assertWPError($out);
        $this->assertSame('builder_not_loaded', $out->get_error_code());
        $this->assertStringContainsString('BeTheme', $out->get_error_message());
    }
}
