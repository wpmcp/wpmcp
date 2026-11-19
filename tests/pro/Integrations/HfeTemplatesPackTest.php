<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #651: Ultimate Addons for Elementor (header-footer-elementor)
 * header, before-footer, footer and custom block templates with their
 * display rules, as the theme layout ops (Theme_Layouts_Pack) of the
 * ultimate-addons family. Exercised against the plugin's own storage (the
 * elementor-hf post type and the ehf_* post meta its save routine writes,
 * read from header-footer-elementor 2.9.8), with the plugin's presence
 * filtered on and its post type registered the way the plugin registers it.
 */
class HfeTemplatesPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'list-ultimate-addons-layouts',
        'get-ultimate-addons-layout',
        'list-ultimate-addons-hooks',
        'create-ultimate-addons-layout',
        'update-ultimate-addons-layout',
        'set-ultimate-addons-layout-display-rules',
        'delete-ultimate-addons-layout',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/support/hfe-doubles.php';
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_enable_theme_write', '__return_true');
        add_filter('wpmcp_ultimate_addons_active', '__return_true');
        register_post_type('elementor-hf', [ 'public' => true, 'show_ui' => true, 'supports' => [ 'title', 'thumbnail', 'elementor' ] ]);
    }

    protected function tearDown(): void
    {
        remove_filter('wpmcp_enable_theme_write', '__return_true');
        remove_filter('wpmcp_ultimate_addons_active', '__return_true');
        remove_filter('wpmcp_ultimate_addons_active', '__return_false');
        unregister_post_type('elementor-hf');
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        return (new Theme_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args): array
    {
        return (new Theme_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    /** @return array<string,array<string,mixed>> */
    private function catalog(): array
    {
        return array_column((new Theme_Integration())->catalog()['operations'], null, 'name');
    }

    private function snapshot_count(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Snapshot_Store::table_name());
    }

    private function create(array $args): int
    {
        return (int) $this->ok($this->write('create-ultimate-addons-layout', $args))['result']['layout']['id'];
    }

    // Registration and availability.

    public function test_ops_register_on_the_theme_pair_whatever_the_theme(): void
    {
        $ops = $this->catalog();
        foreach (self::OPS as $op) {
            $this->assertArrayHasKey($op, $ops, $op);
            $this->assertTrue($ops[ $op ]['dependency_met'], $op);
        }
        $this->assertSame('write', $ops['delete-ultimate-addons-layout']['mode'], 'Trash is undoable, so it is a write, not a destructive op');
        $this->assertArrayNotHasKey('delete-astra-layout', $ops);
    }

    public function test_ops_are_pro_tier(): void
    {
        Gate::set_pro_for_tests(false);
        $ops = $this->catalog();
        foreach (self::OPS as $op) {
            $this->assertArrayNotHasKey($op, $ops, $op);
        }
    }

    public function test_missing_plugin_is_refused_with_the_install_hint(): void
    {
        remove_filter('wpmcp_ultimate_addons_active', '__return_true');
        add_filter('wpmcp_ultimate_addons_active', '__return_false');

        $this->assertFalse($this->catalog()['list-ultimate-addons-layouts']['dependency_met']);
        $out = $this->read('list-ultimate-addons-layouts');
        $this->assertSame('plugin_missing', $out['error']['code']);
        $this->assertStringContainsString('Install and activate Ultimate Addons for Elementor (Header Footer Elementor)', $out['error']['message']);
        $this->assertStringContainsString(admin_url('plugins.php'), $out['error']['message']);
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        remove_filter('wpmcp_enable_theme_write', '__return_true');
        $out = $this->write('create-ultimate-addons-layout', [ 'title' => 'Head', 'type' => 'type_header' ]);
        $this->assertSame('opt_in_required', $out['error']['code']);
        $this->assertStringContainsString('wpmcp_enable_theme_write', $out['error']['message']);
    }

    public function test_writes_need_manage_options_as_the_plugin_does(): void
    {
        $editor = self::factory()->user->create([ 'role' => 'editor' ]);
        get_user_by('id', $editor)->add_cap('edit_theme_options');
        wp_set_current_user($editor);
        $out = $this->write('create-ultimate-addons-layout', [ 'title' => 'Head', 'type' => 'type_header' ]);
        $this->assertArrayHasKey('error', $out);
        $this->assertSame([], get_posts([ 'post_type' => 'elementor-hf', 'post_status' => 'any' ]));
    }

    // Writes, as the plugin stores them.

    public function test_create_stores_what_the_plugin_reads_and_rollback_trashes_it(): void
    {
        $out = $this->ok($this->write('create-ultimate-addons-layout', [
            'title'  => 'Main header',
            'type'   => 'type_header',
            'status' => 'publish',
            'rules'  => [
                'location'  => [ 'rule' => [ 'basic-global' ] ],
                'exclusion' => [ 'rule' => [ 'special-404' ] ],
                'users'     => [ 'all' ],
                'canvas'    => true,
            ],
        ]));
        $this->assertTrue($out['recoverable']);
        $id = (int) $out['result']['layout']['id'];

        $this->assertSame('elementor-hf', get_post_type($id));
        $this->assertSame('publish', get_post_status($id));
        $this->assertSame('type_header', get_post_meta($id, 'ehf_template_type', true));
        $this->assertSame([ 'rule' => [ 'basic-global' ], 'specific' => [] ], get_post_meta($id, 'ehf_target_include_locations', true));
        $this->assertSame([ 'rule' => [ 'special-404' ], 'specific' => [] ], get_post_meta($id, 'ehf_target_exclude_locations', true));
        $this->assertSame([ 'all' ], get_post_meta($id, 'ehf_target_user_roles', true));
        $this->assertSame('1', get_post_meta($id, 'display-on-canvas-template', true));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_ids'][0]));
        $this->assertSame('trash', get_post_status($id));
    }

    public function test_custom_block_reports_its_shortcode_and_defaults_to_draft(): void
    {
        $id   = $this->create([ 'title' => 'Promo', 'type' => 'custom' ]);
        $view = $this->ok($this->read('get-ultimate-addons-layout', [ 'layout_id' => $id ]))['result']['layout'];
        $this->assertSame('draft', $view['status']);
        $this->assertSame('custom', $view['type']);
        $this->assertSame("[hfe_template id='{$id}']", $view['shortcode']);
        $this->assertFalse($view['rules']['canvas']);
        $this->assertSame([ 'rule' => [], 'specific' => [] ], $view['rules']['location']);
        $this->assertSame('', $view['content']);
        $this->assertStringContainsString('Elementor', $view['content_note'], 'The content is pointed at the Elementor tools');
    }

    public function test_update_and_rules_roll_back_exactly(): void
    {
        $id          = $this->create([ 'title' => 'Foot', 'type' => 'type_footer', 'rules' => [ 'location' => [ 'rule' => [ 'basic-global' ] ] ] ]);
        $meta_before = get_post_meta($id);

        $up = $this->ok($this->write('update-ultimate-addons-layout', [ 'layout_id' => $id, 'title' => 'Foot 2', 'status' => 'publish' ]));
        $this->assertSame('Foot 2', get_the_title($id));
        $this->assertSame('publish', get_post_status($id));

        $page  = self::factory()->post->create([ 'post_type' => 'page' ]);
        $rules = $this->ok($this->write('set-ultimate-addons-layout-display-rules', [
            'layout_id' => $id,
            'rules'     => [ 'location' => [ 'rule' => [ 'page|all' ], 'specific' => [ 'post-' . $page ] ], 'users' => [ 'administrator' ], 'canvas' => true ],
        ]));
        $this->assertSame([ 'rule' => [ 'page|all', 'specifics' ], 'specific' => [ 'post-' . $page ] ], get_post_meta($id, 'ehf_target_include_locations', true));
        $this->assertSame([ 'administrator' ], get_post_meta($id, 'ehf_target_user_roles', true));
        $this->assertSame('1', get_post_meta($id, 'display-on-canvas-template', true));
        $this->assertSame('type_footer', get_post_meta($id, 'ehf_template_type', true), 'Rules leave the type alone');

        $off = $this->ok($this->write('set-ultimate-addons-layout-display-rules', [ 'layout_id' => $id, 'rules' => [ 'canvas' => false ] ]));
        $this->assertSame('', get_post_meta($id, 'display-on-canvas-template', true), 'Off deletes the key, as the plugin does');
        $this->assertSame([ 'administrator' ], get_post_meta($id, 'ehf_target_user_roles', true), 'Keys not passed are kept');

        $this->assertTrue(Rollback_Service::restore_operation((string) $off['operation_id']));
        $this->assertSame('1', get_post_meta($id, 'display-on-canvas-template', true));
        $this->assertTrue(Rollback_Service::restore_operation((string) $rules['operation_id']));
        $this->assertSame([ 'rule' => [ 'basic-global' ], 'specific' => [] ], get_post_meta($id, 'ehf_target_include_locations', true));
        $this->assertTrue(Rollback_Service::restore_operation((string) $up['operation_id']));
        $this->assertSame('Foot', get_the_title($id));
        $this->assertSame('draft', get_post_status($id));
        $this->assertEquals($meta_before, get_post_meta($id));
    }

    public function test_delete_trashes_and_rollback_brings_it_back(): void
    {
        $id = $this->create([ 'title' => 'Old header', 'type' => 'type_header', 'status' => 'publish', 'rules' => [ 'location' => [ 'rule' => [ 'basic-global' ] ] ] ]);

        $out = $this->ok($this->write('delete-ultimate-addons-layout', [ 'layout_id' => $id ]));
        $this->assertTrue($out['recoverable']);
        $this->assertSame('trash', get_post_status($id));
        $this->assertNotNull(get_post($id), 'Moved to the trash, never deleted for good');
        $this->assertSame('layout_not_found', $this->read('get-ultimate-addons-layout', [ 'layout_id' => $id ])['error']['code']);
        $this->assertSame('layout_not_found', $this->write('delete-ultimate-addons-layout', [ 'layout_id' => $id ])['error']['code']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame('publish', get_post_status($id));
        $this->assertSame([ 'rule' => [ 'basic-global' ], 'specific' => [] ], get_post_meta($id, 'ehf_target_include_locations', true));
    }

    public function test_content_is_left_to_the_elementor_tools(): void
    {
        $id     = $this->create([ 'title' => 'Head', 'type' => 'type_header' ]);
        $before = $this->snapshot_count();
        $out    = $this->write('update-ultimate-addons-layout', [ 'layout_id' => $id, 'content' => '<p>x</p>' ]);
        $this->assertSame('elementor_content', $out['error']['code']);
        $this->assertSame('elementor_content', $this->write('create-ultimate-addons-layout', [ 'title' => 'H', 'type' => 'type_header', 'content' => '<p>x</p>' ])['error']['code']);
        $this->assertSame($before, $this->snapshot_count());
    }

    public function test_refusals_take_no_snapshot(): void
    {
        $before = $this->snapshot_count();
        $cases  = [
            'invalid_args'          => [ 'title' => 'A', 'type' => 'type_sidebar' ],
            'hook_not_allowed'      => [ 'title' => 'A', 'type' => 'type_header', 'hook' => 'wp_head' ],
            'invalid_display_rules' => [ 'title' => 'A', 'type' => 'type_header', 'rules' => [ 'location' => [ 'rule' => [ 'nowhere|all' ] ] ] ],
        ];
        foreach ($cases as $code => $args) {
            $out = $this->write('create-ultimate-addons-layout', $args);
            $this->assertSame($code, $out['error']['code'] ?? null, $code . ': ' . wp_json_encode($out));
        }
        $this->assertSame('invalid_display_rules', $this->write('create-ultimate-addons-layout', [ 'title' => 'A', 'type' => 'custom', 'rules' => [ 'canvas' => 'yes' ] ])['error']['code']);
        $this->assertSame('invalid_display_rules', $this->write('create-ultimate-addons-layout', [ 'title' => 'A', 'type' => 'custom', 'rules' => [ 'users' => [ 'no-such-role' ] ] ])['error']['code']);
        $this->assertSame($before, $this->snapshot_count());
        $this->assertSame([], get_posts([ 'post_type' => 'elementor-hf', 'post_status' => 'any' ]));
    }

    public function test_ops_refuse_a_post_of_another_type(): void
    {
        $post = self::factory()->post->create();
        $this->assertSame('layout_not_found', $this->read('get-ultimate-addons-layout', [ 'layout_id' => $post ])['error']['code']);
        $this->assertSame('layout_not_found', $this->write('delete-ultimate-addons-layout', [ 'layout_id' => $post ])['error']['code']);
        $this->assertSame('publish', get_post_status($post));
    }

    // Reads.

    public function test_list_and_rule_vocabulary(): void
    {
        $this->create([ 'title' => 'One', 'type' => 'type_footer', 'status' => 'publish' ]);
        $this->create([ 'title' => 'Two', 'type' => 'type_header' ]);
        $other = self::factory()->post->create();

        $all = $this->ok($this->read('list-ultimate-addons-layouts'))['result'];
        $this->assertSame(2, $all['total']);
        $this->assertNotContains($other, array_column($all['layouts'], 'id'));
        $this->assertArrayNotHasKey('content', $all['layouts'][0], 'The list leaves content to get');

        $footers = $this->ok($this->read('list-ultimate-addons-layouts', [ 'type' => 'type_footer' ]))['result'];
        $this->assertSame([ 'One' ], array_column($footers['layouts'], 'title'));

        $hooks = $this->ok($this->read('list-ultimate-addons-hooks'))['result'];
        $this->assertSame([ 'type_header', 'type_before_footer', 'type_footer', 'custom' ], $hooks['writable_types']);
        $this->assertSame([], $hooks['hooks']);
        $this->assertArrayHasKey('basic-global', $hooks['rules']['location_options']['basic']['value']);
        $this->assertArrayHasKey('administrator', $hooks['rules']['user_options']['advanced']['value']);
        $this->assertStringContainsString('canvas', $hooks['rules']['format']);
    }
}
