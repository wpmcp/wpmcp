<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #656: custom layouts, elements and hooks of Astra Pro, Kadence Pro
 * and GP Premium as ops on the theme pair, exercised against each add-on's
 * own storage (its post type and post meta, as the real plugins read them)
 * with the template filtered to that family, the add-on's presence filtered
 * on and its post type registered the way the add-on registers it. The
 * hook catalogs come from tests/support/theme-pro-doubles.php, recorded
 * from the real plugins.
 */
class ThemeLayoutsPackTest extends \WP_UnitTestCase
{
    private const POST_TYPES = [ 'astra' => 'astra-advanced-hook', 'kadence' => 'kadence_element', 'generatepress' => 'gp_elements' ];

    /** @var callable|null */
    private $template = null;

    private string $family = '';

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/support/theme-pro-doubles.php';
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_enable_theme_write', '__return_true');
        add_filter('wpmcp_theme_pro_active', '__return_true');
    }

    protected function tearDown(): void
    {
        if (null !== $this->template) {
            remove_filter('template', $this->template);
        }
        remove_filter('wpmcp_enable_theme_write', '__return_true');
        remove_filter('wpmcp_theme_pro_active', '__return_true');
        remove_filter('wpmcp_theme_pro_active', '__return_false');
        foreach (self::POST_TYPES as $type) {
            unregister_post_type($type);
        }
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function activate(string $family, bool $module = true): void
    {
        $this->family   = $family;
        $this->template = static fn () => $family;
        add_filter('template', $this->template);
        if ($module) {
            register_post_type(self::POST_TYPES[ $family ], [ 'public' => false, 'show_ui' => true ]);
        }
    }

    private function read(string $op, array $args = []): array
    {
        return (new Theme_Integration())->handle_read([ 'operation' => str_replace('{f}', $this->family, $op), 'args' => $args ]);
    }

    private function write(string $op, array $args): array
    {
        return (new Theme_Integration())->handle_write([ 'operation' => str_replace('{f}', $this->family, $op), 'args' => $args ]);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    private function names(): array
    {
        return array_column((new Theme_Integration())->catalog()['operations'], 'name');
    }

    private function snapshot_count(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Snapshot_Store::table_name());
    }

    // Registration and availability.

    public function test_ops_register_only_for_the_active_family(): void
    {
        $this->activate('kadence');
        $names = $this->names();
        foreach ([ 'list-kadence-layouts', 'get-kadence-layout', 'list-kadence-hooks', 'create-kadence-layout', 'update-kadence-layout', 'set-kadence-layout-display-rules' ] as $op) {
            $this->assertContains($op, $names);
        }
        $this->assertNotContains('list-astra-layouts', $names);
        $this->assertNotContains('list-generatepress-layouts', $names);
    }

    public function test_ops_are_pro_tier(): void
    {
        $this->activate('astra');
        Gate::set_pro_for_tests(false);
        $this->assertNotContains('list-astra-layouts', $this->names());
        $this->assertContains('get-astra-settings', $this->names(), 'The free Astra settings pack stays');
    }

    public function test_missing_add_on_is_refused_with_the_install_hint(): void
    {
        remove_filter('wpmcp_theme_pro_active', '__return_true');
        add_filter('wpmcp_theme_pro_active', '__return_false');
        $this->activate('generatepress');

        $ops = array_column((new Theme_Integration())->catalog()['operations'], null, 'name');
        $this->assertFalse($ops['list-generatepress-layouts']['dependency_met']);

        $out = $this->read('list-{f}-layouts');
        $this->assertSame('plugin_missing', $out['error']['code']);
        $this->assertStringContainsString('Install and activate GP Premium', $out['error']['message']);
        $this->assertStringContainsString(admin_url('plugins.php'), $out['error']['message']);
    }

    public function test_disabled_module_is_refused_naming_where_to_enable_it(): void
    {
        $this->activate('astra', false);
        $out = $this->read('list-{f}-layouts');
        $this->assertSame('module_disabled', $out['error']['code']);
        $this->assertStringContainsString('Switch on Site Builder under Astra > Dashboard > Astra Pro Modules.', $out['error']['message'], 'Astra Pro names the module as its dashboard does (#891)');
        $this->assertStringNotContainsString('Custom Layouts', $out['error']['message']);
    }

    public function test_kadence_disabled_module_names_the_hooked_elements_toggle(): void
    {
        $this->activate('kadence', false);
        $out = $this->read('list-{f}-layouts');
        $this->assertSame('module_disabled', $out['error']['code']);
        $this->assertStringContainsString('Switch on Hooked Elements under Appearance > Kadence > Pro Addons.', $out['error']['message'], 'The toggle and page as Kadence Pro names them (#891)');
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        remove_filter('wpmcp_enable_theme_write', '__return_true');
        $this->activate('kadence');
        $out = $this->write('create-{f}-layout', [ 'title' => 'X', 'type' => 'default', 'hook' => 'kadence_before_header' ]);
        $this->assertSame('opt_in_required', $out['error']['code']);
        $this->assertStringContainsString('wpmcp_enable_theme_write', $out['error']['message']);
    }

    // Astra Pro.

    public function test_astra_create_stores_what_astra_reads_and_rollback_trashes_it(): void
    {
        $this->activate('astra');
        $out = $this->ok($this->write('create-{f}-layout', [
            'title'    => 'Promo bar',
            'type'     => 'hooks',
            'hook'     => 'astra_header_before',
            'priority' => 5,
            'content'  => '<!-- wp:paragraph --><p>Sale</p><!-- /wp:paragraph -->',
            'status'   => 'publish',
            'rules'    => [
                'location'  => [ 'rule' => [ 'basic-global' ] ],
                'exclusion' => [ 'rule' => [ 'special-404' ] ],
                'users'     => [ 'logged-out' ],
            ],
        ]));

        $this->assertTrue($out['recoverable']);
        $id = (int) $out['result']['layout']['id'];
        $this->assertSame('astra-advanced-hook', get_post_type($id));
        $this->assertSame('publish', get_post_status($id));
        $this->assertSame('hooks', get_post_meta($id, 'ast-advanced-hook-layout', true));
        $this->assertSame([ [ 'action' => 'astra_header_before', 'custom' => '' ] ], get_post_meta($id, 'ast-advanced-hook-actions', true));
        $this->assertSame('astra_header_before', get_post_meta($id, 'ast-advanced-hook-action', true), 'The legacy single-hook key is kept in sync, as Astra does');
        $this->assertSame('5', get_post_meta($id, 'ast-advanced-hook-priority', true));
        $this->assertSame('wordpress_editor', get_post_meta($id, 'editor_type', true));
        $this->assertSame([ 'rule' => [ 'basic-global' ], 'specific' => [] ], get_post_meta($id, 'ast-advanced-hook-location', true));
        $this->assertSame([ 'rule' => [ 'special-404' ], 'specific' => [] ], get_post_meta($id, 'ast-advanced-hook-exclusion', true));
        $this->assertSame([ 'logged-out' ], get_post_meta($id, 'ast-advanced-hook-users', true));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_ids'][0]));
        $this->assertSame('trash', get_post_status($id));
    }

    public function test_astra_custom_hook_and_404_layout(): void
    {
        $this->activate('astra');
        $custom = $this->ok($this->write('create-{f}-layout', [ 'title' => 'C', 'type' => 'hooks', 'hook' => 'my_theme_hook', 'custom_hook' => true ]));
        $id     = (int) $custom['result']['layout']['id'];
        $this->assertSame([ [ 'action' => 'custom_hook', 'custom' => 'my_theme_hook' ] ], get_post_meta($id, 'ast-advanced-hook-actions', true));
        $this->assertSame('my_theme_hook', get_post_meta($id, 'ast-custom-hook', true));
        $this->assertSame('draft', get_post_status($id), 'A new layout is a draft unless publish is asked for');

        $view = $this->ok($this->read('get-{f}-layout', [ 'layout_id' => $id ]))['result']['layout'];
        $this->assertSame('my_theme_hook', $view['hook']);
        $this->assertTrue($view['custom_hook']);

        $page = $this->ok($this->write('create-{f}-layout', [ 'title' => '404', 'type' => '404-page', 'rules' => [ 'location' => [ 'rule' => [ 'basic-global' ] ] ] ]));
        $this->assertSame([ 'rule' => [ 'special-404' ], 'specific' => [] ], get_post_meta((int) $page['result']['layout']['id'], 'ast-advanced-hook-location', true), 'A 404 layout always targets the 404 page, as Astra forces');
    }

    public function test_astra_refusals_take_no_snapshot(): void
    {
        $this->activate('astra');
        $before = $this->snapshot_count();
        $cases  = [
            'unknown_hook'          => [ 'title' => 'A', 'type' => 'hooks', 'hook' => 'astra_typo' ],
            'hook_required'         => [ 'title' => 'A', 'type' => 'hooks' ],
            'hook_not_allowed'      => [ 'title' => 'A', 'type' => 'header', 'hook' => 'astra_body_top' ],
            'layout_type_read_only' => [ 'title' => 'A', 'type' => 'template' ],
            'invalid_args'          => [ 'title' => 'A', 'type' => 'nope' ],
            'invalid_display_rules' => [ 'title' => 'A', 'type' => 'header', 'rules' => [ 'location' => [ 'rule' => [ 'nowhere|all' ] ] ] ],
            'blocked_hook'          => [ 'title' => 'A', 'type' => 'hooks', 'hook' => 'init', 'custom_hook' => true ],
        ];
        foreach ($cases as $code => $args) {
            $out = $this->write('create-{f}-layout', $args);
            $this->assertSame($code, $out['error']['code'] ?? null, $code . ': ' . wp_json_encode($out));
        }
        $this->assertSame($before, $this->snapshot_count());
        $this->assertSame([], get_posts([ 'post_type' => 'astra-advanced-hook', 'post_status' => 'any' ]));
    }

    public function test_astra_update_and_rules_roll_back_exactly(): void
    {
        $this->activate('astra');
        $id = (int) $this->ok($this->write('create-{f}-layout', [ 'title' => 'Head', 'type' => 'header', 'content' => '<p>Old</p>', 'rules' => [ 'location' => [ 'rule' => [ 'basic-global' ] ] ] ]))['result']['layout']['id'];
        $meta_before = get_post_meta($id);

        $up = $this->ok($this->write('update-{f}-layout', [ 'layout_id' => $id, 'title' => 'Head 2', 'content' => '<p>New</p>', 'status' => 'publish' ]));
        $this->assertSame('Head 2', get_the_title($id));
        $this->assertSame('<p>New</p>', get_post_field('post_content', $id));

        $page  = self::factory()->post->create([ 'post_type' => 'page' ]);
        $rules = $this->ok($this->write('set-{f}-layout-display-rules', [ 'layout_id' => $id, 'rules' => [ 'location' => [ 'rule' => [ 'page|all', 'specifics' ], 'specific' => [ 'post-' . $page ] ], 'users' => [ 'administrator' ] ] ]));
        $this->assertSame([ 'rule' => [ 'page|all', 'specifics' ], 'specific' => [ 'post-' . $page ] ], get_post_meta($id, 'ast-advanced-hook-location', true));

        $this->assertTrue(Rollback_Service::restore_operation((string) $rules['operation_id']));
        $this->assertSame([ 'rule' => [ 'basic-global' ], 'specific' => [] ], get_post_meta($id, 'ast-advanced-hook-location', true));
        $this->assertTrue(Rollback_Service::restore_operation((string) $up['operation_id']));
        $this->assertSame('Head', get_the_title($id));
        $this->assertSame('<p>Old</p>', get_post_field('post_content', $id));
        $this->assertSame('draft', get_post_status($id));
        $this->assertEquals($meta_before, get_post_meta($id));
    }

    public function test_astra_code_layout_content_is_not_editable(): void
    {
        $this->activate('astra');
        $id = self::factory()->post->create([ 'post_type' => 'astra-advanced-hook', 'post_title' => 'Code' ]);
        update_post_meta($id, 'ast-advanced-hook-layout', 'hooks');
        update_post_meta($id, 'editor_type', 'code_editor');

        $out = $this->write('update-{f}-layout', [ 'layout_id' => $id, 'content' => '<p>x</p>' ]);
        $this->assertSame('code_layout', $out['error']['code']);
        $this->assertSame('', $this->ok($this->read('get-{f}-layout', [ 'layout_id' => $id ]))['result']['layout']['content']);
    }

    public function test_astra_list_and_hooks(): void
    {
        $this->activate('astra');
        $this->ok($this->write('create-{f}-layout', [ 'title' => 'One', 'type' => 'footer', 'status' => 'publish' ]));
        $this->ok($this->write('create-{f}-layout', [ 'title' => 'Two', 'type' => 'hooks', 'hook' => 'wp_head' ]));
        $other = self::factory()->post->create([ 'post_type' => 'post' ]);

        $all = $this->ok($this->read('list-{f}-layouts'))['result'];
        $this->assertSame(2, $all['total']);
        $this->assertNotContains($other, array_column($all['layouts'], 'id'));
        $this->assertArrayNotHasKey('content', $all['layouts'][0], 'The list leaves content to get');

        $footers = $this->ok($this->read('list-{f}-layouts', [ 'type' => 'footer' ]))['result'];
        $this->assertSame([ 'One' ], array_column($footers['layouts'], 'title'));

        $hooks = $this->ok($this->read('list-{f}-hooks'))['result'];
        $names = array_merge(...array_map(static fn ($g) => array_column($g['hooks'], 'name'), $hooks['hooks']));
        $this->assertContains('astra_header_before', $names);
        $this->assertNotContains('custom_hook', $names, 'Custom hooks are named with custom_hook:true instead');
        $this->assertContains('hooks', $hooks['writable_types']);
        $this->assertArrayHasKey('rules', $hooks);
    }

    public function test_astra_hooks_return_the_rule_options_outside_wp_admin(): void
    {
        // Astra Pro's location list calls get_current_screen(), which core
        // loads only in wp-admin; list-astra-hooks loads it as core does
        // (issue #894). The test bootstrap always has it loaded, so this
        // pins the answer; the adapter's loader is what a REST request needs.
        $this->activate('astra');
        $rules = $this->ok($this->read('list-{f}-hooks'))['result']['rules'];
        $this->assertArrayHasKey('basic-global', $rules['location_options']['basic']['value']);
        $this->assertArrayHasKey('logged-in', $rules['user_options']['basic']['value']);
        $this->assertTrue(function_exists('get_current_screen'));
        $this->assertTrue(class_exists('WP_Screen', false));
    }

    public function test_layout_ops_refuse_a_post_of_another_type(): void
    {
        $this->activate('astra');
        $post = self::factory()->post->create();
        $this->assertSame('layout_not_found', $this->read('get-{f}-layout', [ 'layout_id' => $post ])['error']['code']);
        $this->assertSame('layout_not_found', $this->write('update-{f}-layout', [ 'layout_id' => $post, 'title' => 'x' ])['error']['code']);
    }

    // Kadence Pro.

    public function test_kadence_element_writes_kadence_meta_and_rolls_back(): void
    {
        $this->activate('kadence');
        $post = self::factory()->post->create();
        $out  = $this->ok($this->write('create-{f}-layout', [
            'title'    => 'Banner',
            'type'     => 'default',
            'hook'     => 'kadence_before_header',
            'priority' => 20,
            'content'  => '<p>Hi</p>',
            'rules'    => [
                'show'     => [ [ 'rule' => 'general|site' ], [ 'rule' => 'singular|post', 'select' => 'ids', 'ids' => [ $post ] ] ],
                'all_show' => false,
                'hide'     => [ [ 'rule' => 'general|404' ] ],
                'user'     => [ 'logged_in' ],
                'device'   => [ 'desktop', 'mobile' ],
            ],
        ]));
        $id = (int) $out['result']['layout']['id'];

        $this->assertSame('kadence_element', get_post_type($id));
        $this->assertSame('default', get_post_meta($id, '_kad_element_type', true));
        $this->assertSame('kadence_before_header', get_post_meta($id, '_kad_element_hook', true));
        $this->assertSame('20', (string) get_post_meta($id, '_kad_element_hook_priority', true));
        $show = json_decode((string) get_post_meta($id, '_kad_element_show_conditionals', true), true);
        $this->assertSame([ 'rule' => 'general|site', 'select' => 'all', 'ids' => [], 'subRule' => '', 'subSelection' => [], 'mustMatch' => false ], $show[0]);
        $this->assertSame([ $post ], $show[1]['ids'], 'Ids are integers, which Kadence compares strictly');
        $this->assertSame([ [ 'role' => 'logged_in' ] ], json_decode((string) get_post_meta($id, '_kad_element_user_conditionals', true), true));
        $this->assertSame([ 'desktop', 'mobile' ], array_column(json_decode((string) get_post_meta($id, '_kad_element_device_conditionals', true), true), 'value'));

        $view = $this->ok($this->read('get-{f}-layout', [ 'layout_id' => $id ]))['result']['layout'];
        $this->assertSame([ 'logged_in' ], $view['rules']['user']);
        $this->assertSame([ 'desktop', 'mobile' ], $view['rules']['device']);
        $this->assertSame('<p>Hi</p>', $view['content']);

        $set = $this->ok($this->write('set-{f}-layout-display-rules', [ 'layout_id' => $id, 'rules' => [ 'show' => [ [ 'rule' => 'general|front_page' ] ], 'all_show' => true ] ]));
        $this->assertSame('1', (string) get_post_meta($id, '_kad_element_all_show', true));
        $this->assertSame('general|404', json_decode((string) get_post_meta($id, '_kad_element_hide_conditionals', true), true)[0]['rule'], 'Keys not passed are kept');
        $this->assertTrue(Rollback_Service::restore_operation((string) $set['operation_id']));
        $this->assertSame('general|site', json_decode((string) get_post_meta($id, '_kad_element_show_conditionals', true), true)[0]['rule']);
    }

    public function test_kadence_expiry_is_read_set_and_rolled_back_as_kadence_stores_it(): void
    {
        $this->activate('kadence');
        $id = (int) $this->ok($this->write('create-{f}-layout', [ 'title' => 'Sale', 'type' => 'default', 'hook' => 'kadence_before_header' ]))['result']['layout']['id'];

        $view = $this->ok($this->read('get-{f}-layout', [ 'layout_id' => $id ]))['result']['layout'];
        $this->assertSame([ 'enabled' => false, 'date' => null, 'expired' => false ], $view['rules']['expires'], 'No expiry by default');

        $set = $this->ok($this->write('set-{f}-layout-display-rules', [ 'layout_id' => $id, 'rules' => [ 'expires' => [ 'date' => '2099-11-30 23:59' ] ] ]));
        $this->assertSame('1', (string) get_post_meta($id, '_kad_element_enable_expires', true));
        $this->assertSame('2099-11-30T23:59:00', get_post_meta($id, '_kad_element_expires', true), 'Stored as the element editor date picker stores it');
        $listed = $this->ok($this->read('list-{f}-layouts'))['result']['layouts'][0];
        $this->assertSame([ 'enabled' => true, 'date' => '2099-11-30T23:59:00', 'expired' => false ], $listed['rules']['expires']);

        // Set in wp-admin to a past date: the read says it has expired.
        update_post_meta($id, '_kad_element_expires', '2001-01-01T00:00:00');
        $this->assertTrue($this->ok($this->read('get-{f}-layout', [ 'layout_id' => $id ]))['result']['layout']['rules']['expires']['expired']);

        $off = $this->ok($this->write('set-{f}-layout-display-rules', [ 'layout_id' => $id, 'rules' => [ 'expires' => false ] ]));
        $this->assertSame('', get_post_meta($id, '_kad_element_enable_expires', true));
        $this->assertSame('2001-01-01T00:00:00', get_post_meta($id, '_kad_element_expires', true), 'Switching off keeps the date, as the toggle does');

        $this->assertTrue(Rollback_Service::restore_operation((string) $off['operation_id']));
        $this->assertSame('1', (string) get_post_meta($id, '_kad_element_enable_expires', true));
        $this->assertTrue(Rollback_Service::restore_operation((string) $set['operation_id']));
        $this->assertSame('', get_post_meta($id, '_kad_element_enable_expires', true));
        $this->assertSame('', get_post_meta($id, '_kad_element_expires', true), 'Undo restores the element without an expiry');

        $this->assertSame('invalid_display_rules', $this->write('set-{f}-layout-display-rules', [ 'layout_id' => $id, 'rules' => [ 'expires' => [ 'date' => 'next friday-ish' ] ] ])['error']['code']);
        $this->assertSame('invalid_display_rules', $this->write('set-{f}-layout-display-rules', [ 'layout_id' => $id, 'rules' => [ 'expires' => [ 'enabled' => true ] ] ])['error']['code']);
        $this->assertStringContainsString('expires', $this->ok($this->read('list-{f}-hooks'))['result']['rules']['format']);
    }

    public function test_kadence_hooks_follow_the_element_type(): void
    {
        $this->activate('kadence');
        $this->assertSame('unknown_hook', $this->write('create-{f}-layout', [ 'title' => 'F', 'type' => 'fixed', 'hook' => 'kadence_before_header' ])['error']['code']);
        $this->ok($this->write('create-{f}-layout', [ 'title' => 'F', 'type' => 'fixed', 'hook' => 'fixed_below_footer' ]));
        $this->ok($this->write('create-{f}-layout', [ 'title' => 'T', 'type' => 'template', 'hook' => 'replace_404' ]));
        $custom = $this->ok($this->write('create-{f}-layout', [ 'title' => 'C', 'type' => 'default', 'hook' => 'my_hook', 'custom_hook' => true ]));
        $id     = (int) $custom['result']['layout']['id'];
        $this->assertSame('custom', get_post_meta($id, '_kad_element_hook', true));
        $this->assertSame('my_hook', get_post_meta($id, '_kad_element_hook_custom', true));
        $this->assertSame('layout_type_read_only', $this->write('create-{f}-layout', [ 'title' => 'S', 'type' => 'script', 'hook' => 'wp_head' ])['error']['code'], 'HTML code elements are not written');

        $fixed = $this->ok($this->read('list-{f}-hooks', [ 'type' => 'fixed' ]))['result']['hooks'];
        $this->assertSame([ 'fixed_above_header', 'fixed_below_footer' ], array_column($fixed[0]['hooks'], 'name'));
        $this->assertSame('invalid_display_rules', $this->write('create-{f}-layout', [ 'title' => 'R', 'type' => 'default', 'hook' => 'kadence_before_header', 'rules' => [ 'device' => [ 'watch' ] ] ])['error']['code']);
    }

    // GP Premium.

    public function test_generatepress_hook_element_stores_content_in_meta_and_rolls_back(): void
    {
        $this->activate('generatepress');
        $out = $this->ok($this->write('create-{f}-layout', [
            'title'    => 'Notice',
            'type'     => 'hook',
            'hook'     => 'generate_before_header',
            'priority' => 0,
            'content'  => '<p>Notice</p>',
            'status'   => 'publish',
            'rules'    => [ 'display' => [ [ 'rule' => 'general:site' ] ], 'exclude' => [ [ 'rule' => 'post:page', 'object' => 7 ] ], 'users' => [ 'general:logged_out' ] ],
        ]));
        $id = (int) $out['result']['layout']['id'];

        $this->assertSame('gp_elements', get_post_type($id));
        $this->assertSame('hook', get_post_meta($id, '_generate_element_type', true));
        $this->assertSame('<p>Notice</p>', get_post_meta($id, '_generate_element_content', true));
        $this->assertSame('generate_before_header', get_post_meta($id, '_generate_hook', true));
        $this->assertSame('0', get_post_meta($id, '_generate_hook_priority', true), 'Priority 0 is stored as GP stores it');
        $this->assertSame([ [ 'rule' => 'general:site', 'object' => '' ] ], get_post_meta($id, '_generate_element_display_conditions', true));
        $this->assertSame([ [ 'rule' => 'post:page', 'object' => '7' ] ], get_post_meta($id, '_generate_element_exclude_conditions', true));
        $this->assertSame([ 'general:logged_out' ], get_post_meta($id, '_generate_element_user_conditions', true));
        $this->assertSame('', get_post_meta($id, '_generate_hook_execute_php', true), 'PHP execution is never switched on');

        $up = $this->ok($this->write('update-{f}-layout', [ 'layout_id' => $id, 'content' => '<p>Changed</p>', 'hook' => 'generate_after_footer' ]));
        $this->assertSame('<p>Changed</p>', get_post_meta($id, '_generate_element_content', true));
        $this->assertTrue(Rollback_Service::restore_operation((string) $up['operation_id']));
        $this->assertSame('<p>Notice</p>', get_post_meta($id, '_generate_element_content', true));
        $this->assertSame('generate_before_header', get_post_meta($id, '_generate_hook', true));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_ids'][0]));
        $this->assertSame('trash', get_post_status($id));
    }

    public function test_generatepress_block_element_and_php_element_guard(): void
    {
        $this->activate('generatepress');
        $out = $this->ok($this->write('create-{f}-layout', [ 'title' => 'Footer', 'type' => 'block/site-footer', 'content' => '<!-- wp:paragraph --><p>F</p><!-- /wp:paragraph -->' ]));
        $id  = (int) $out['result']['layout']['id'];
        $this->assertSame('block', get_post_meta($id, '_generate_element_type', true));
        $this->assertSame('site-footer', get_post_meta($id, '_generate_block_type', true));
        $this->assertStringContainsString('<p>F</p>', get_post_field('post_content', $id));
        $this->assertSame('hook_not_allowed', $this->write('create-{f}-layout', [ 'title' => 'X', 'type' => 'block/site-header', 'hook' => 'wp_head' ])['error']['code']);
        $this->ok($this->write('create-{f}-layout', [ 'title' => 'BH', 'type' => 'block/hook', 'hook' => 'generate_after_header' ]));

        $php = self::factory()->post->create([ 'post_type' => 'gp_elements' ]);
        update_post_meta($php, '_generate_element_type', 'hook');
        update_post_meta($php, '_generate_hook_execute_php', 'true');
        $this->assertSame('code_layout', $this->write('update-{f}-layout', [ 'layout_id' => $php, 'content' => 'x' ])['error']['code']);
        $this->assertSame('invalid_display_rules', $this->write('set-{f}-layout-display-rules', [ 'layout_id' => $id, 'rules' => [ 'users' => [ 'nobody_role' ] ] ])['error']['code']);
    }

    // Issue #888: a draft layout reports a real modified time.

    public function test_draft_layout_reports_a_real_modified_time(): void
    {
        $this->activate('generatepress');
        $out = $this->ok($this->write('create-{f}-layout', [ 'title' => 'Draft hook', 'type' => 'hook', 'hook' => 'generate_before_footer', 'content' => '<p>F</p>' ]));
        $id  = (int) $out['result']['layout']['id'];
        $this->assertSame('draft', get_post_status($id));
        $this->assertSame('0000-00-00 00:00:00', get_post_field('post_modified_gmt', $id), 'WordPress keeps a new draft\'s GMT modified time at zero');

        $expected = get_gmt_from_date((string) get_post_field('post_modified', $id));
        $this->assertSame($expected, $out['result']['layout']['modified']);
        $this->assertSame($expected, $this->ok($this->read('get-{f}-layout', [ 'layout_id' => $id ]))['result']['layout']['modified']);
        $listed = array_column($this->ok($this->read('list-{f}-layouts'))['result']['layouts'], 'modified', 'id');
        $this->assertSame($expected, $listed[ $id ]);

        $published = self::factory()->post->create([ 'post_type' => 'gp_elements', 'post_status' => 'publish' ]);
        update_post_meta($published, '_generate_element_type', 'hook');
        $this->assertSame(
            get_post_field('post_modified_gmt', $published),
            $this->ok($this->read('get-{f}-layout', [ 'layout_id' => $published ]))['result']['layout']['modified'],
            'A published layout keeps its stored GMT time'
        );
    }

    // Issue #889: unknown arguments on the layout writes are refused.

    /** @return array<string,array{0:string}> */
    public static function families(): array
    {
        return [ 'astra' => [ 'astra' ], 'kadence' => [ 'kadence' ], 'generatepress' => [ 'generatepress' ] ];
    }

    /** @dataProvider families */
    public function test_unknown_arguments_on_layout_writes_are_refused_naming_them(string $family): void
    {
        $this->activate($family);
        $type   = [ 'astra' => 'hooks', 'kadence' => 'default', 'generatepress' => 'hook' ][ $family ];
        $hook   = [ 'astra' => 'astra_header_before', 'kadence' => 'kadence_before_header', 'generatepress' => 'generate_before_footer' ][ $family ];
        $before = $this->snapshot_count();
        $count  = static fn (): int => count(get_posts([ 'post_type' => self::POST_TYPES[ $family ], 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ]));
        $posts  = $count();

        $out = $this->write('create-{f}-layout', [ 'title' => 'X', 'type' => $type, 'hook' => $hook, 'colour' => 'red' ]);
        $this->assertSame('invalid_args', $out['error']['code'], (string) wp_json_encode($out));
        $this->assertStringContainsString('colour', $out['error']['message']);
        $this->assertSame([ 'colour' ], $out['error']['data']['unknown']);
        $this->assertSame($posts, $count(), 'Nothing is created');

        $id  = (int) $this->ok($this->write('create-{f}-layout', [ 'title' => 'Y', 'type' => $type, 'hook' => $hook ]))['result']['layout']['id'];
        $mid = $this->snapshot_count();
        $out = $this->write('update-{f}-layout', [ 'layout_id' => $id, 'title' => 'Z', 'colour' => 'red' ]);
        $this->assertSame('invalid_args', $out['error']['code']);
        $this->assertStringContainsString('colour', $out['error']['message']);
        $this->assertSame('Y', get_the_title($id), 'Nothing is written');

        $out = $this->write('set-{f}-layout-display-rules', [ 'layout_id' => $id, 'rules' => [ 'users' => [] ], 'colour' => 'red' ]);
        $this->assertSame('invalid_args', $out['error']['code']);
        $this->assertSame($mid, $this->snapshot_count(), 'No snapshot for a refused write');
        $this->assertGreaterThan($before, $mid);
    }

    public function test_execute_php_is_refused_pointing_at_wp_admin(): void
    {
        $this->activate('generatepress');
        $out = $this->write('create-{f}-layout', [ 'title' => 'PHP hook', 'type' => 'hook', 'hook' => 'generate_before_footer', 'content' => "<?php echo 'x'; ?>", 'execute_php' => true ]);
        $this->assertSame('invalid_args', $out['error']['code']);
        $this->assertStringContainsString('execute_php', $out['error']['message']);
        $this->assertStringContainsString('wp-admin', $out['error']['message']);
        $this->assertSame([], get_posts([ 'post_type' => 'gp_elements', 'post_status' => 'any', 'fields' => 'ids' ]), 'Nothing is created');

        $id  = (int) $this->ok($this->write('create-{f}-layout', [ 'title' => 'Hook', 'type' => 'hook', 'hook' => 'generate_before_footer' ]))['result']['layout']['id'];
        $out = $this->write('update-{f}-layout', [ 'layout_id' => $id, 'execute_php' => true ]);
        $this->assertSame('invalid_args', $out['error']['code']);
        $this->assertStringContainsString('execute_php', $out['error']['message']);
        $this->assertStringContainsString('wp-admin', $out['error']['message']);
        $this->assertSame('', get_post_meta($id, '_generate_hook_execute_php', true));
    }

    // Issue #890: a layout whose code runs is not switched on with code execution off.

    /** A code layout of each family: GP with Execute PHP, Astra's code editor, Kadence's HTML element. */
    private function code_layout(string $family): int
    {
        $id = self::factory()->post->create([ 'post_type' => self::POST_TYPES[ $family ], 'post_status' => 'draft', 'post_title' => 'Code' ]);
        if ('generatepress' === $family) {
            update_post_meta($id, '_generate_element_type', 'hook');
            update_post_meta($id, '_generate_hook', 'generate_before_footer');
            update_post_meta($id, '_generate_hook_execute_php', 'true');
        } elseif ('astra' === $family) {
            update_post_meta($id, 'ast-advanced-hook-layout', 'hooks');
            update_post_meta($id, 'editor_type', 'code_editor');
        } else {
            update_post_meta($id, '_kad_element_type', 'script');
            update_post_meta($id, '_kad_element_hook', 'kadence_before_footer');
        }
        return $id;
    }

    /** @return array<string,mixed> rules that put the layout on the whole site, in the family's shape. */
    private static function site_rules(string $family): array
    {
        return [
            'generatepress' => [ 'display' => [ [ 'rule' => 'general:site', 'object' => '' ] ] ],
            'astra'         => [ 'location' => [ 'rule' => [ 'basic-global' ] ] ],
            'kadence'       => [ 'show' => [ [ 'rule' => 'general|site', 'select' => 'all' ] ] ],
        ][ $family ];
    }

    /** @return array<string,mixed> rules that take every location away. */
    private static function no_rules(string $family): array
    {
        return [
            'generatepress' => [ 'display' => [] ],
            'astra'         => [ 'location' => [ 'rule' => [] ] ],
            'kadence'       => [ 'show' => [] ],
        ][ $family ];
    }

    /** @dataProvider families */
    public function test_code_layout_is_not_published_or_placed_with_code_execution_off(string $family): void
    {
        $this->activate($family);
        $id     = $this->code_layout($family);
        $meta   = get_post_meta($id);
        $before = $this->snapshot_count();

        foreach ([ 'publish', 'private', 'pending' ] as $status) {
            $out = $this->write('update-{f}-layout', [ 'layout_id' => $id, 'status' => $status ]);
            $this->assertSame('code_layout', $out['error']['code'], $status . ': ' . wp_json_encode($out));
            $this->assertStringContainsString('wp-admin', $out['error']['message']);
            $this->assertStringContainsString('generatepress' === $family || 'astra' === $family ? 'PHP execution' : 'injection', $out['error']['message']);
        }
        $out = $this->write('set-{f}-layout-display-rules', [ 'layout_id' => $id, 'rules' => self::site_rules($family) ]);
        $this->assertSame('code_layout', $out['error']['code'], (string) wp_json_encode($out));
        $this->assertStringContainsString('wp-admin', $out['error']['message']);

        $this->assertSame('draft', get_post_status($id), 'Nothing is written');
        $this->assertEquals($meta, get_post_meta($id));
        $this->assertSame($before, $this->snapshot_count(), 'No snapshot for a refused write');
    }

    /** @dataProvider families */
    public function test_code_layout_can_still_be_unpublished_and_unplaced(string $family): void
    {
        $this->activate($family);
        $id = $this->code_layout($family);
        wp_update_post([ 'ID' => $id, 'post_status' => 'publish' ]);
        $a = \WPMCP\Integrations\Theme_Layouts_Pack::adapter($family);
        $a->write_rules($id, $a->type_of($id), $a->normalize_rules(self::site_rules($family)));
        $stored = $a->read_rules($id);

        $off = $this->ok($this->write('update-{f}-layout', [ 'layout_id' => $id, 'status' => 'draft' ]));
        $this->assertSame('draft', get_post_status($id));
        $this->ok($this->write('update-{f}-layout', [ 'layout_id' => $id, 'title' => 'Renamed' ]));

        $rules = $this->ok($this->write('set-{f}-layout-display-rules', [ 'layout_id' => $id, 'rules' => self::no_rules($family) ]));
        $this->assertNotEquals($stored, $a->read_rules($id));

        $this->assertTrue(Rollback_Service::restore_operation((string) $rules['operation_id']));
        $this->assertEquals($stored, $a->read_rules($id));
        $this->assertTrue(Rollback_Service::restore_operation((string) $off['operation_id']));
        $this->assertSame('publish', get_post_status($id));
    }

    /** @dataProvider families */
    public function test_code_layout_is_switched_on_when_the_site_allows_code_execution(string $family): void
    {
        $this->activate($family);
        $id     = $this->code_layout($family);
        $filter = 'kadence' === $family ? 'wpmcp_allow_js_injection' : 'wpmcp_allow_php_exec';
        add_filter($filter, '__return_true');
        \WPMCP\Tools\Code\Php_Snippet_Guard::set_environment_override('local');
        if (is_multisite()) {
            // On a network the opt-in alone is not enough: code execution also
            // needs a network administrator (Code_Exec_Network_Gate, issue
            // #562), so act as one there (issue #963).
            grant_super_admin(get_current_user_id());
        }
        try {
            $this->ok($this->write('update-{f}-layout', [ 'layout_id' => $id, 'status' => 'publish' ]));
            $this->ok($this->write('set-{f}-layout-display-rules', [ 'layout_id' => $id, 'rules' => self::site_rules($family) ]));
            $this->assertSame('publish', get_post_status($id));
        } finally {
            remove_filter($filter, '__return_true');
            \WPMCP\Tools\Code\Php_Snippet_Guard::set_environment_override(null);
            if (is_multisite()) {
                revoke_super_admin(get_current_user_id());
            }
        }
    }

    public function test_plain_layout_is_published_and_placed_with_code_execution_off(): void
    {
        $this->activate('generatepress');
        $id = (int) $this->ok($this->write('create-{f}-layout', [ 'title' => 'Plain', 'type' => 'hook', 'hook' => 'generate_before_footer', 'content' => '<p>x</p>' ]))['result']['layout']['id'];
        $this->ok($this->write('update-{f}-layout', [ 'layout_id' => $id, 'status' => 'publish' ]));
        $this->ok($this->write('set-{f}-layout-display-rules', [ 'layout_id' => $id, 'rules' => self::site_rules('generatepress') ]));
    }
}
