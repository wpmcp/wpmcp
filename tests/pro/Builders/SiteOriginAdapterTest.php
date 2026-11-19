<?php

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Pro\Gate;
use WPMCP\Safety\{Rollback_Service, Snapshot_Store};
use WPMCP\Tools\Builders\{Detect_Builder, Get_Builder_Content, SiteOrigin_Content, Update_Builder_Content};

/**
 * Issue #644: SiteOrigin Page Builder through the builder dispatcher pair.
 *
 * SiteOrigin Page Builder is not loaded here, so a page is stored the way
 * its 2.38.0 editor save stores it (SiteOrigin_Panels_Admin::save_post()
 * and the builder's getPanelsData()): the `panels_data` postmeta holding
 * `widgets` (each with a `panels_info` of class, grid, cell, id, widget_id
 * and style), `grids` (cells, style) and `grid_cells` (grid, index,
 * weight, style). The plugin's presence is toggled through
 * wpmcp_siteorigin_active.
 */
class SiteOriginAdapterTest extends \WP_UnitTestCase
{
    /** Two rows: a two column row with a heading and some text, then a one column row with a C:\ path in it. */
    public const LAYOUT = [
        'widgets'    => [
            [
                'title'       => 'Welcome',
                'text'        => '<p>Hello from SiteOrigin</p>',
                'filter'      => '1',
                'visual'      => '1',
                'panels_info' => ['class' => 'WP_Widget_Text', 'grid' => 0, 'cell' => 0, 'id' => 0, 'widget_id' => '1b8c7d2e-0000-4000-8000-000000000001', 'style' => ['background_display' => 'tile']],
            ],
            [
                'title'       => 'Side',
                'text'        => 'Second column',
                'filter'      => '',
                'visual'      => '',
                'panels_info' => ['class' => 'WP_Widget_Text', 'grid' => 0, 'cell' => 1, 'id' => 1, 'widget_id' => '1b8c7d2e-0000-4000-8000-000000000002', 'style' => []],
            ],
            [
                'title'       => 'Paths',
                'text'        => 'Saved at C:\\sites\\demo',
                'filter'      => '',
                'visual'      => '',
                'panels_info' => ['class' => 'WP_Widget_Text', 'grid' => 1, 'cell' => 0, 'id' => 2, 'widget_id' => '1b8c7d2e-0000-4000-8000-000000000003', 'style' => []],
            ],
        ],
        'grids'      => [
            ['cells' => 2, 'style' => ['bottom_margin' => '30px'], 'label' => 'Intro'],
            ['cells' => 1, 'style' => []],
        ],
        'grid_cells' => [
            ['grid' => 0, 'index' => 0, 'weight' => 0.5, 'style' => []],
            ['grid' => 0, 'index' => 1, 'weight' => 0.5, 'style' => []],
            ['grid' => 1, 'index' => 0, 'weight' => 1, 'style' => []],
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
        add_filter('wpmcp_siteorigin_active', '__return_true');
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        remove_all_filters('wpmcp_siteorigin_active');
        remove_all_filters('siteorigin_panels_prebuilt_layouts');
        parent::tearDown();
    }

    private function page(array $layout = self::LAYOUT, array $args = []): int
    {
        $id = self::factory()->post->create($args + ['post_type' => 'page', 'post_content' => '']);
        update_post_meta($id, 'panels_data', wp_slash($layout));

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
        return (new Update_Builder_Content())->handle($args + ['post_id' => $id, 'builder' => 'siteorigin', 'expected_hash' => $this->read($id)['hash']]);
    }

    private function ok($out): array
    {
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_code() . ': ' . $out->get_error_message() : '');

        return $out;
    }

    private function stored(int $id): array
    {
        return get_post_meta($id, 'panels_data', true);
    }

    public function test_detects_a_page_with_a_layout_and_not_an_empty_row(): void
    {
        $this->assertSame('siteorigin', (new Detect_Builder())->handle(['post_id' => $this->page()])['builder']);
        $this->assertSame('classic', (new Detect_Builder())->handle(['post_id' => $this->page(['widgets' => [], 'grids' => [], 'grid_cells' => []])])['builder']);
    }

    public function test_read_returns_the_layout_a_path_tree_and_the_hash(): void
    {
        $id   = $this->page();
        $read = $this->read($id);

        $this->assertSame('siteorigin', $read['builder']);
        $this->assertTrue($read['plugin_active']);
        $this->assertSame(self::LAYOUT, $read['panels_data']);
        $this->assertSame(SiteOrigin_Content::hash($id), $read['hash']);
        $this->assertSame(64, strlen($read['hash']));

        $this->assertSame(['path' => '0', 'kind' => 'row', 'cells' => 2, 'label' => 'Intro'], array_diff_key($read['tree'][0], ['children' => 1]));
        $cell = $read['tree'][0]['children'][1];
        $this->assertSame(['path' => '0.1', 'kind' => 'cell', 'weight' => 0.5], array_diff_key($cell, ['children' => 1]));
        $this->assertSame(
            ['path' => '0.1.0', 'kind' => 'widget', 'class' => 'WP_Widget_Text', 'widget_id' => '1b8c7d2e-0000-4000-8000-000000000002', 'label' => 'Side'],
            $cell['children'][0]
        );
        $this->assertSame('1.0.0', $read['tree'][1]['children'][0]['children'][0]['path']);
    }

    public function test_page_snapshot_says_the_layout_lives_outside_post_content(): void
    {
        $out = (new \WPMCP\Tools\Context\Get_Page_Snapshot())->handle(['post_id' => $this->page()]);

        $this->assertSame('siteorigin', $out['builder']);
        $this->assertFalse($out['content_coverage']['complete']);
        $this->assertStringContainsString('panels_data', $out['content_coverage']['note']);
    }

    public function test_read_refuses_a_post_the_caller_cannot_read(): void
    {
        $id = $this->page(self::LAYOUT, ['post_status' => 'private', 'post_author' => $this->admin]);
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $out = (new Get_Builder_Content())->handle(['post_id' => $id]);
        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('forbidden', $out->get_error_code());
    }

    public function test_update_merges_widget_fields_keeps_backslashes_and_rolls_back_exactly(): void
    {
        $id      = $this->page();
        $before  = get_post_meta($id);
        $session = wp_generate_uuid4();

        $out = $this->ok($this->write($id, [
            'operation'  => 'update',
            'path'       => '0.0.0',
            'attrs'      => ['title' => 'Edited by wpmcp', 'visual' => null],
            'element'    => ['style' => ['padding' => '10px']],
            'session_id' => $session,
        ]));
        $this->assertSame('0.0.0', $out['path']);
        $this->assertSame($this->read($id)['hash'], $out['hash']);

        $widget = $this->stored($id)['widgets'][0];
        $this->assertSame('Edited by wpmcp', $widget['title']);
        $this->assertArrayNotHasKey('visual', $widget);
        $this->assertSame(['background_display' => 'tile', 'padding' => '10px'], $widget['panels_info']['style']);
        $this->assertSame('Saved at C:\\sites\\demo', $this->stored($id)['widgets'][2]['text']);

        Rollback_Service::restore_session($session);
        $this->assertSame(self::LAYOUT, $this->stored($id));
        $this->assertSame($before, get_post_meta($id));
    }

    public function test_update_refuses_panels_info_in_attrs_and_a_missing_path(): void
    {
        $id = $this->page();

        $out = $this->write($id, ['operation' => 'update', 'path' => '0.0.0', 'attrs' => ['panels_info' => ['grid' => 5]]]);
        $this->assertSame('invalid_siteorigin_request', $out->get_error_code());

        $out = $this->write($id, ['operation' => 'update', 'path' => '4.0.0', 'attrs' => ['title' => 'x']]);
        $this->assertSame('invalid_siteorigin_request', $out->get_error_code());
        $this->assertSame(self::LAYOUT, $this->stored($id));
    }

    public function test_update_a_row_style_and_a_cell_weight_rescales_its_siblings(): void
    {
        $id = $this->page();

        $this->ok($this->write($id, ['operation' => 'update', 'path' => '0', 'attrs' => ['background' => '#eeeeee', 'bottom_margin' => null]]));
        $this->assertSame(['background' => '#eeeeee'], $this->stored($id)['grids'][0]['style']);

        $this->ok($this->write($id, ['operation' => 'update', 'path' => '0.0', 'element' => ['weight' => 0.7]]));
        $cells = $this->stored($id)['grid_cells'];
        $this->assertEqualsWithDelta(0.7, $cells[0]['weight'], 0.0001);
        $this->assertEqualsWithDelta(0.3, $cells[1]['weight'], 0.0001);
    }

    public function test_add_a_widget_to_a_cell_reindexes_and_rolls_back(): void
    {
        $id      = $this->page();
        $session = wp_generate_uuid4();

        $out = $this->ok($this->write($id, [
            'operation'  => 'add',
            'to'         => '0.0',
            'index'      => 0,
            'element'    => ['class' => 'WP_Widget_Text', 'attrs' => ['title' => 'New first', 'text' => 'Added']],
            'session_id' => $session,
        ]));
        $this->assertSame('0.0.0', $out['path']);

        $widgets = $this->stored($id)['widgets'];
        $this->assertCount(4, $widgets);
        $this->assertSame('New first', $widgets[0]['title']);
        $this->assertSame(['class' => 'WP_Widget_Text', 'grid' => 0, 'cell' => 0, 'id' => 0], array_intersect_key($widgets[0]['panels_info'], ['class' => 1, 'grid' => 1, 'cell' => 1, 'id' => 1]));
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $widgets[0]['panels_info']['widget_id']);
        $this->assertSame([0, 1, 2, 3], array_map(static fn ($w) => $w['panels_info']['id'], $widgets));
        $this->assertSame('Welcome', $widgets[1]['title']);

        Rollback_Service::restore_session($session);
        $this->assertSame(self::LAYOUT, $this->stored($id));
    }

    public function test_add_refuses_an_unregistered_widget_class(): void
    {
        $id  = $this->page();
        $out = $this->write($id, ['operation' => 'add', 'to' => '0.0', 'element' => ['class' => 'No_Such_Widget']]);

        $this->assertSame('invalid_siteorigin_request', $out->get_error_code());
        $this->assertStringContainsString('No_Such_Widget', $out->get_error_message());
    }

    public function test_add_a_row_with_cell_weights_and_a_cell_to_a_row(): void
    {
        $id = $this->page();

        $out = $this->ok($this->write($id, ['operation' => 'add', 'to' => '', 'index' => 1, 'element' => ['cells' => [0.25, 0.75], 'attrs' => ['background' => '#000000']]]));
        $this->assertSame('1', $out['path']);
        $data = $this->stored($id);
        $this->assertCount(3, $data['grids']);
        $this->assertSame(['cells' => 2, 'style' => ['background' => '#000000']], $data['grids'][1]);
        $this->assertSame(['grid' => 1, 'index' => 1, 'weight' => 0.75, 'style' => []], $data['grid_cells'][3]);
        // The C:\ widget moved down to row 2 with its row.
        $this->assertSame(2, $data['widgets'][2]['panels_info']['grid']);

        $out = $this->ok($this->write($id, ['operation' => 'add', 'to' => '2', 'element' => []]));
        $this->assertSame('2.1', $out['path']);
        $cells = array_values(array_filter($this->stored($id)['grid_cells'], static fn ($c) => 2 === $c['grid']));
        $this->assertCount(2, $cells);
        $this->assertEqualsWithDelta(0.5, $cells[0]['weight'], 0.0001);
        $this->assertEqualsWithDelta(0.5, $cells[1]['weight'], 0.0001);
        $this->assertSame(2, $this->stored($id)['grids'][2]['cells']);
    }

    public function test_remove_a_row_a_cell_and_a_widget(): void
    {
        $id = $this->page();

        $this->ok($this->write($id, ['operation' => 'remove', 'path' => '0']));
        $data = $this->stored($id);
        $this->assertCount(1, $data['grids']);
        $this->assertCount(1, $data['widgets']);
        $this->assertSame(['grid' => 0, 'cell' => 0, 'id' => 0], array_intersect_key($data['widgets'][0]['panels_info'], ['grid' => 1, 'cell' => 1, 'id' => 1]));

        $out = $this->write($id, ['operation' => 'remove', 'path' => '0.0']);
        $this->assertSame('invalid_siteorigin_request', $out->get_error_code());

        $this->ok($this->write($id, ['operation' => 'remove', 'path' => '0.0.0']));
        $this->assertSame([], $this->stored($id)['widgets']);

        $id2 = $this->page();
        $this->ok($this->write($id2, ['operation' => 'remove', 'path' => '0.1']));
        $data = $this->stored($id2);
        $this->assertSame(1, $data['grids'][0]['cells']);
        $this->assertEqualsWithDelta(1.0, $data['grid_cells'][0]['weight'], 0.0001);
        $this->assertCount(2, $data['widgets']);
    }

    public function test_move_a_widget_across_rows_and_a_row_to_the_top(): void
    {
        $id = $this->page();

        $out = $this->ok($this->write($id, ['operation' => 'move', 'path' => '1.0.0', 'to' => '0.1', 'index' => 0]));
        $this->assertSame('0.1.0', $out['path']);
        $data = $this->stored($id);
        $this->assertSame('Paths', $data['widgets'][1]['title']);
        $this->assertSame(['grid' => 0, 'cell' => 1, 'id' => 1], array_intersect_key($data['widgets'][1]['panels_info'], ['grid' => 1, 'cell' => 1, 'id' => 1]));

        $out = $this->ok($this->write($id, ['operation' => 'move', 'path' => '1', 'to' => '', 'index' => 0]));
        $this->assertSame('0', $out['path']);
        $data = $this->stored($id);
        $this->assertSame(1, $data['grids'][0]['cells']);
        $this->assertSame('Intro', $data['grids'][1]['label']);
    }

    public function test_writes_need_the_current_hash_and_write_nothing_otherwise(): void
    {
        $id = $this->page();

        $out = (new Update_Builder_Content())->handle(['post_id' => $id, 'builder' => 'siteorigin', 'operation' => 'remove', 'path' => '0']);
        $this->assertSame('missing_expected_hash', $out->get_error_code());

        $out = (new Update_Builder_Content())->handle(['post_id' => $id, 'builder' => 'siteorigin', 'operation' => 'remove', 'path' => '0', 'expected_hash' => str_repeat('0', 64)]);
        $this->assertSame('stale_expected_hash', $out->get_error_code());
        $this->assertSame(self::LAYOUT, $this->stored($id));
    }

    public function test_writes_refuse_while_the_plugin_is_inactive_and_name_it(): void
    {
        $id = $this->page();
        remove_all_filters('wpmcp_siteorigin_active');
        add_filter('wpmcp_siteorigin_active', '__return_false');

        $this->assertFalse($this->read($id)['plugin_active']);
        $out = $this->write($id, ['operation' => 'remove', 'path' => '0']);
        $this->assertSame('plugin_missing', $out->get_error_code());
        $this->assertStringContainsString('Install and activate SiteOrigin Page Builder', $out->get_error_message());
        $this->assertStringContainsString(admin_url('plugins.php'), $out->get_error_message());
        $this->assertSame(self::LAYOUT, $this->stored($id));
    }

    public function test_a_whole_layout_is_validated_and_a_blank_page_takes_one_without_a_hash(): void
    {
        $blank = self::factory()->post->create(['post_type' => 'page', 'post_content' => '']);
        $out   = (new Update_Builder_Content())->handle(['post_id' => $blank, 'builder' => 'siteorigin', 'content' => wp_json_encode(self::LAYOUT)]);
        $this->ok($out);
        $this->assertSame(self::LAYOUT, $this->stored($blank));
        $this->assertSame('siteorigin', (new Detect_Builder())->handle(['post_id' => $blank])['builder']);

        $bad              = self::LAYOUT;
        $bad['widgets'][0]['panels_info']['cell'] = 7;
        $out = $this->write($blank, ['content' => wp_json_encode($bad)]);
        $this->assertSame('invalid_siteorigin_request', $out->get_error_code());

        $out = $this->write($blank, ['content' => '[1,2]']);
        $this->assertSame('invalid_siteorigin_request', $out->get_error_code());
        $this->assertSame(self::LAYOUT, $this->stored($blank));

        $other = self::factory()->post->create(['post_type' => 'page', 'post_content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->']);
        $out   = (new Update_Builder_Content())->handle(['post_id' => $other, 'builder' => 'siteorigin', 'content' => wp_json_encode(self::LAYOUT)]);
        $this->assertSame('unsupported_builder', $out->get_error_code());
    }

    public function test_publish_sets_a_draft_live_and_rolls_back(): void
    {
        $id      = $this->page(self::LAYOUT, ['post_status' => 'draft']);
        $session = wp_generate_uuid4();

        $this->ok($this->write($id, ['operation' => 'publish', 'session_id' => $session]));
        $this->assertSame('publish', get_post_status($id));

        Rollback_Service::restore_session($session);
        $this->assertSame('draft', get_post_status($id));
    }

    public function test_add_a_prebuilt_layout_or_another_page_inserts_its_rows_with_new_widget_ids(): void
    {
        add_filter('siteorigin_panels_prebuilt_layouts', static function ($layouts) {
            $layouts['hero'] = [
                'name'        => 'Hero',
                'description' => 'One big row',
                'widgets'     => [['title' => 'Hero title', 'text' => '', 'panels_info' => ['class' => 'WP_Widget_Text', 'grid' => 0, 'cell' => 0, 'id' => 0, 'widget_id' => 'aaaaaaaa-0000-4000-8000-000000000000', 'style' => []]]],
                'grids'       => [['cells' => 1, 'style' => []]],
                'grid_cells'  => [['grid' => 0, 'index' => 0, 'weight' => 1, 'style' => []]],
            ];
            return $layouts;
        });

        $id      = $this->page();
        $session = wp_generate_uuid4();
        $out     = $this->ok($this->write($id, ['operation' => 'add', 'to' => '', 'index' => 1, 'element' => ['template' => 'hero'], 'session_id' => $session]));
        $this->assertSame('1', $out['path']);
        $data = $this->stored($id);
        $this->assertCount(3, $data['grids']);
        $this->assertSame('Hero title', $data['widgets'][2]['title']);
        $this->assertSame(1, $data['widgets'][2]['panels_info']['grid']);
        $this->assertNotSame('aaaaaaaa-0000-4000-8000-000000000000', $data['widgets'][2]['panels_info']['widget_id']);
        Rollback_Service::restore_session($session);
        $this->assertSame(self::LAYOUT, $this->stored($id));

        $source = $this->page();
        $target = $this->page();
        $this->ok($this->write($target, ['operation' => 'add', 'to' => '', 'element' => ['template' => (string) $source]]));
        $data = $this->stored($target);
        $this->assertCount(4, $data['grids']);
        $this->assertSame(3, $data['widgets'][5]['panels_info']['grid']);
        $this->assertNotSame(self::LAYOUT['widgets'][0]['panels_info']['widget_id'], $data['widgets'][3]['panels_info']['widget_id']);

        $out = $this->write($target, ['operation' => 'add', 'to' => '', 'element' => ['template' => 'missing']]);
        $this->assertSame('invalid_siteorigin_request', $out->get_error_code());
    }

    public function test_scope_reads_templates_and_catalog(): void
    {
        add_filter('siteorigin_panels_prebuilt_layouts', static fn ($l) => $l + ['hero' => ['name' => 'Hero', 'description' => 'Big', 'widgets' => [], 'grids' => [['cells' => 1]], 'grid_cells' => [['grid' => 0, 'index' => 0, 'weight' => 1]]]]);
        $this->page(self::LAYOUT, ['post_title' => 'Source page', 'post_status' => 'publish']);

        $templates = (new Get_Builder_Content())->handle(['builder' => 'siteorigin', 'scope' => 'templates']);
        $this->assertSame('siteorigin', $templates['builder']);
        $this->assertSame(['id' => 'hero', 'name' => 'Hero', 'description' => 'Big', 'rows' => 1, 'source' => 'prebuilt'], $templates['templates'][0]);
        $this->assertContains('Source page', array_column($templates['templates'], 'name'));

        $catalog = (new Get_Builder_Content())->handle(['builder' => 'siteorigin', 'scope' => 'catalog']);
        $classes = array_column($catalog['elements'], 'class');
        $this->assertContains('WP_Widget_Text', $classes);

        $one = (new Get_Builder_Content())->handle(['builder' => 'siteorigin', 'scope' => 'catalog', 'element' => 'WP_Widget_Text']);
        $this->assertSame('WP_Widget_Text', $one['element']['class']);
        $this->assertSame('text', $one['element']['id_base']);

        $missing = (new Get_Builder_Content())->handle(['builder' => 'siteorigin', 'scope' => 'catalog', 'element' => 'Nope']);
        $this->assertSame('element_not_found', $missing->get_error_code());

        $ds = (new Get_Builder_Content())->handle(['builder' => 'siteorigin', 'scope' => 'design_system']);
        $this->assertSame('unsupported_builder', $ds->get_error_code());

        remove_all_filters('wpmcp_siteorigin_active');
        add_filter('wpmcp_siteorigin_active', '__return_false');
        $out = (new Get_Builder_Content())->handle(['builder' => 'siteorigin', 'scope' => 'catalog']);
        $this->assertSame('builder_not_loaded', $out->get_error_code());
        $this->assertStringContainsString('SiteOrigin Page Builder', $out->get_error_message());
    }
}
