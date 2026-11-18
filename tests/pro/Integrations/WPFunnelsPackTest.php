<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Integrations\WPFunnels_Pack;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #834: WPFunnels funnels, steps and checkout products, paid-tier ops
 * on the theme dispatcher pair beside FunnelKit, so they add no top-level
 * tools.
 *
 * WPFunnels is not loaded in the shared test core. Presence is driven
 * through wpmcp_wpfunnels_active, and its two post types are registered here
 * the way WPFunnels 2.8.4 does in Wpfnl_Register_Funnel: a funnel is a
 * `wpfunnels` post whose _steps_order meta is the ordered step list
 * ([{id, step_type, name}, ...]), each step a `wpfunnel_steps` post with
 * _funnel_id and _step_type, and a checkout step's products the
 * _wpfnl_checkout_products list ([{id, quantity, ...}, ...]).
 *
 * The writes are off until the site opts in (wpmcp_enable_wpfunnels_write)
 * and each is undone in a test.
 */
class WPFunnelsPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'list-wpfunnels-funnels'          => 'read',
        'get-wpfunnels-funnel'            => 'read',
        'get-wpfunnels-step'              => 'read',
        'get-wpfunnels-checkout-products' => 'read',
        'create-wpfunnels-funnel'         => 'write',
        'update-wpfunnels-funnel'         => 'write',
        'set-wpfunnels-checkout-products' => 'write',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        register_post_type('wpfunnels', [ 'public' => true, 'show_ui' => false, 'capability_type' => 'post', 'supports' => [ 'thumbnail' ] ]);
        register_post_type('wpfunnel_steps', [ 'public' => true, 'show_ui' => true, 'capability_type' => 'post', 'map_meta_cap' => true, 'supports' => [ 'title', 'editor', 'custom-fields' ] ]);
        if (! post_type_exists('product')) {
            register_post_type('product', [ 'public' => true ]);
        }
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        if (is_multisite()) {
            grant_super_admin(get_current_user_id());
        }
        // WPFunnels offers its step templates on init (PageTemplates\Manager).
        add_filter('theme_wpfunnel_steps_templates', [ $this, 'step_templates' ]);
        add_filter('wpmcp_wpfunnels_active', '__return_true');
        add_filter('wpmcp_enable_wpfunnels_write', '__return_true');
    }

    /** @param array<string,string> $templates */
    public function step_templates(array $templates): array
    {
        return $templates + [ 'wpfunnels_default' => 'Default for WPFunnels', 'wpfunnels_checkout' => 'Checkout' ];
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_wpfunnels_active');
        remove_all_filters('wpmcp_enable_wpfunnels_write');
        remove_filter('theme_wpfunnel_steps_templates', [ $this, 'step_templates' ]);
        unregister_post_type('wpfunnel_steps');
        unregister_post_type('wpfunnels');
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

    private function code(array $out): string
    {
        return (string) ($out['error']['code'] ?? '');
    }

    /** A step as Wpfnl_Steps_Store_Data::create_step() stores it. */
    private function step(int $funnel, string $type, string $title, string $status = 'publish'): int
    {
        $id = self::factory()->post->create([ 'post_type' => 'wpfunnel_steps', 'post_title' => $title, 'post_status' => $status ]);
        update_post_meta($id, '_step_type', $type);
        update_post_meta($id, '_funnel_id', $funnel);
        update_post_meta($id, '_wp_page_template', 'wpfunnels_default');
        return $id;
    }

    /**
     * A funnel with a landing, a checkout and a thank you step, stored the
     * way the canvas save (save_funnel_data) leaves it.
     *
     * @return array{funnel:int, landing:int, checkout:int, thankyou:int}
     */
    private function funnel(string $title = 'Spring sale', string $status = 'publish'): array
    {
        $funnel   = self::factory()->post->create([ 'post_type' => 'wpfunnels', 'post_title' => $title, 'post_status' => $status ]);
        $landing  = $this->step($funnel, 'landing', 'Landing');
        $checkout = $this->step($funnel, 'checkout', 'Checkout');
        $thankyou = $this->step($funnel, 'thankyou', 'Thank you');
        $order    = [
            [ 'id' => $landing, 'step_type' => 'landing', 'name' => 'Landing' ],
            [ 'id' => $checkout, 'step_type' => 'checkout', 'name' => 'Checkout' ],
            [ 'id' => $thankyou, 'step_type' => 'thankyou', 'name' => 'Thank you' ],
        ];
        update_post_meta($funnel, '_steps_order', $order);
        update_post_meta($funnel, '_steps', $order);
        update_post_meta($funnel, '_wpfnl_funnel_type', 'wc');
        return [ 'funnel' => $funnel, 'landing' => $landing, 'checkout' => $checkout, 'thankyou' => $thankyou ];
    }

    private function product(string $title = 'Course kit'): int
    {
        return self::factory()->post->create([ 'post_type' => 'product', 'post_title' => $title, 'post_status' => 'publish' ]);
    }

    // ---------------------------------------------------------------
    // Catalog and availability
    // ---------------------------------------------------------------

    public function test_the_catalog_lists_every_op_on_the_theme_pair_at_the_pro_tier(): void
    {
        $ops = array_column((new Theme_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
        }

        Gate::set_pro_for_tests(false);
        $ops = array_column((new Theme_Integration())->catalog()['operations'], null, 'name');
        $this->assertArrayNotHasKey('list-wpfunnels-funnels', $ops, 'a paid-tier op leaves the free catalog');
    }

    public function test_while_wpfunnels_is_missing_a_call_answers_plugin_missing_with_the_plugins_link(): void
    {
        remove_all_filters('wpmcp_wpfunnels_active');
        add_filter('wpmcp_wpfunnels_active', '__return_false');
        $out = $this->read('list-wpfunnels-funnels');
        $this->assertSame('plugin_missing', $this->code($out), (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate WPFunnels', $out['error']['message']);
        $this->assertSame(admin_url('plugins.php'), $out['error']['data']['plugins_url'] ?? null);
        $this->assertSame('plugin_missing', $this->code($this->write('create-wpfunnels-funnel', [ 'title' => 'X' ])));
    }

    public function test_presence_defaults_to_the_plugin_constant(): void
    {
        remove_all_filters('wpmcp_wpfunnels_active');
        $this->assertSame(defined('WPFNL_VERSION'), WPFunnels_Pack::active());
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        $f = $this->funnel();
        remove_all_filters('wpmcp_enable_wpfunnels_write');
        foreach ([
            [ 'create-wpfunnels-funnel', [ 'title' => 'x' ] ],
            [ 'update-wpfunnels-funnel', [ 'id' => $f['funnel'], 'title' => 'x' ] ],
            [ 'set-wpfunnels-checkout-products', [ 'step_id' => $f['checkout'], 'products' => [ [ 'product_id' => $this->product(), 'quantity' => 1 ] ] ] ],
        ] as [ $op, $args ]) {
            $out = $this->write($op, $args);
            $this->assertSame('opt_in_required', $this->code($out), $op);
            $this->assertStringContainsString('wpmcp_enable_wpfunnels_write', $out['error']['message']);
        }
        $this->assertSame('Spring sale', get_post($f['funnel'])->post_title);
        $this->assertSame('', get_post_meta($f['checkout'], '_wpfnl_checkout_products', true));
    }

    public function test_only_users_who_may_manage_options_reach_the_ops(): void
    {
        $f = $this->funnel();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $this->assertNotSame('', $this->code($this->read('get-wpfunnels-funnel', [ 'id' => $f['funnel'] ])));
        $this->assertNotSame('', $this->code($this->write('update-wpfunnels-funnel', [ 'id' => $f['funnel'], 'title' => 'Nope' ])));
        $this->assertSame('Spring sale', get_post($f['funnel'])->post_title);
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_list_funnels_returns_each_funnel_with_a_step_summary_and_filters(): void
    {
        $this->funnel('Spring sale');
        $this->funnel('Webinar', 'draft');

        $out = $this->ok($this->read('list-wpfunnels-funnels'))['result'];
        $this->assertSame(2, $out['total']);
        $byTitle = array_column($out['funnels'], null, 'title');
        $this->assertSame(3, $byTitle['Spring sale']['step_count']);
        $this->assertSame([ 'landing', 'checkout', 'thankyou' ], $byTitle['Spring sale']['step_types']);
        $this->assertSame('publish', $byTitle['Spring sale']['status']);
        $this->assertSame('wc', $byTitle['Spring sale']['funnel_type']);

        $only = $this->ok($this->read('list-wpfunnels-funnels', [ 'search' => 'Webin' ]))['result'];
        $this->assertSame([ 'Webinar' ], array_column($only['funnels'], 'title'));
        $drafts = $this->ok($this->read('list-wpfunnels-funnels', [ 'status' => 'draft' ]))['result'];
        $this->assertSame([ 'Webinar' ], array_column($drafts['funnels'], 'title'));
    }

    public function test_get_funnel_returns_the_steps_in_canvas_order_with_checkout_products(): void
    {
        $f       = $this->funnel();
        $product = $this->product();
        update_post_meta($f['checkout'], '_wpfnl_checkout_products', [ [ 'id' => $product, 'quantity' => '2', 'discount_type' => '', 'discount_value' => '' ] ]);

        $out = $this->ok($this->read('get-wpfunnels-funnel', [ 'id' => $f['funnel'] ]))['result'];
        $this->assertSame($f['funnel'], $out['id']);
        $this->assertSame(3, $out['step_count']);
        $this->assertSame([ 1, 2, 3 ], array_column($out['steps'], 'position'));
        $this->assertSame([ $f['landing'], $f['checkout'], $f['thankyou'] ], array_column($out['steps'], 'post_id'));
        $this->assertSame([ 'landing', 'checkout', 'thankyou' ], array_column($out['steps'], 'type'));
        $this->assertSame('Checkout', $out['steps'][1]['title']);
        $this->assertSame('publish', $out['steps'][1]['status']);
        $this->assertSame([ $product ], $out['steps'][1]['product_ids']);
        $this->assertSame([], $out['steps'][0]['product_ids']);
    }

    public function test_get_funnel_falls_back_to_the_steps_list_and_skips_deleted_steps(): void
    {
        $f = $this->funnel();
        delete_post_meta($f['funnel'], '_steps_order');
        wp_delete_post($f['thankyou'], true);

        $out = $this->ok($this->read('get-wpfunnels-funnel', [ 'id' => $f['funnel'] ]))['result'];
        $this->assertSame([ $f['landing'], $f['checkout'] ], array_column($out['steps'], 'post_id'));
    }

    public function test_get_funnel_refuses_an_id_that_is_not_a_funnel(): void
    {
        $f = $this->funnel();
        $this->assertSame('funnel_not_found', $this->code($this->read('get-wpfunnels-funnel', [ 'id' => $f['landing'] ])));
    }

    public function test_get_step_returns_the_step_its_funnel_and_its_place(): void
    {
        $f   = $this->funnel();
        $out = $this->ok($this->read('get-wpfunnels-step', [ 'id' => $f['checkout'] ]))['result'];
        $this->assertSame($f['checkout'], $out['post_id']);
        $this->assertSame('checkout', $out['type']);
        $this->assertSame($f['funnel'], $out['funnel_id']);
        $this->assertSame(2, $out['position']);
        $this->assertSame('Checkout', $out['title']);
        $this->assertNotEmpty($out['url']);

        $this->assertSame('step_not_found', $this->code($this->read('get-wpfunnels-step', [ 'id' => $f['funnel'] ])));
    }

    public function test_get_checkout_products_lists_each_product_with_its_quantity(): void
    {
        $f       = $this->funnel();
        $product = $this->product('Starter kit');
        update_post_meta($f['checkout'], '_wpfnl_checkout_products', [ [ 'id' => $product, 'quantity' => '3' ] ]);

        $out = $this->ok($this->read('get-wpfunnels-checkout-products', [ 'step_id' => $f['checkout'] ]))['result'];
        $this->assertSame($f['checkout'], $out['step_id']);
        $this->assertSame([ [ 'product_id' => $product, 'title' => 'Starter kit', 'quantity' => 3 ] ], array_map(
            static fn (array $p): array => array_intersect_key($p, array_flip([ 'product_id', 'title', 'quantity' ])),
            $out['products']
        ));

        $this->assertSame('not_a_checkout_step', $this->code($this->read('get-wpfunnels-checkout-products', [ 'step_id' => $f['landing'] ])));
    }

    // ---------------------------------------------------------------
    // Writes, each undone
    // ---------------------------------------------------------------

    public function test_create_funnel_stores_an_empty_funnel_and_rollback_trashes_it(): void
    {
        $out    = $this->ok($this->write('create-wpfunnels-funnel', [ 'title' => 'Black Friday' ]));
        $funnel = $out['result']['funnel'];
        $post   = get_post($funnel['id']);
        $this->assertSame('wpfunnels', $post->post_type);
        $this->assertSame('Black Friday', $post->post_title);
        $this->assertSame(0, $funnel['step_count']);

        $this->assertNotEmpty($out['operation_ids'] ?? []);
        foreach ((array) $out['operation_ids'] as $operation) {
            $this->assertTrue(Rollback_Service::restore_operation((string) $operation));
        }
        $this->assertSame('trash', get_post_status($post->ID));
    }

    public function test_update_funnel_renames_it_and_rolls_back(): void
    {
        $f   = $this->funnel();
        $out = $this->ok($this->write('update-wpfunnels-funnel', [ 'id' => $f['funnel'], 'title' => 'Summer sale' ]));
        $this->assertSame('Summer sale', get_post($f['funnel'])->post_title);
        $this->assertSame('Summer sale', $out['result']['funnel']['title']);

        $this->assertGreaterThan(0, Rollback_Service::restore_session((string) $out['session_id']));
        $this->assertSame('Spring sale', get_post($f['funnel'])->post_title);
    }

    public function test_update_funnel_status_moves_the_funnel_and_every_step_like_wpfunnels_and_rolls_back(): void
    {
        $f   = $this->funnel();
        $out = $this->ok($this->write('update-wpfunnels-funnel', [ 'id' => $f['funnel'], 'status' => 'draft' ]));
        foreach ([ 'funnel', 'landing', 'checkout', 'thankyou' ] as $key) {
            $this->assertSame('draft', get_post_status($f[ $key ]), $key);
        }
        $this->assertCount(4, $out['operation_ids']);

        Rollback_Service::restore_session((string) $out['session_id']);
        foreach ([ 'funnel', 'landing', 'checkout', 'thankyou' ] as $key) {
            $this->assertSame('publish', get_post_status($f[ $key ]), $key);
        }
    }

    public function test_update_funnel_refuses_a_non_funnel(): void
    {
        $f = $this->funnel();
        $this->assertSame('funnel_not_found', $this->code($this->write('update-wpfunnels-funnel', [ 'id' => $f['landing'], 'title' => 'X' ])));
        $this->assertSame('Landing', get_post($f['landing'])->post_title);
    }

    public function test_set_checkout_products_replaces_the_list_keeps_other_settings_and_rolls_back(): void
    {
        $f      = $this->funnel();
        $kept   = $this->product('Kept');
        $added  = $this->product('Added');
        $before = [ [ 'id' => $kept, 'quantity' => '1', 'discount_type' => 'discount-percentage', 'discount_value' => '10' ] ];
        update_post_meta($f['checkout'], '_wpfnl_checkout_products', $before);

        $out = $this->ok($this->write('set-wpfunnels-checkout-products', [
            'step_id'  => $f['checkout'],
            'products' => [ [ 'product_id' => $kept, 'quantity' => 2 ], [ 'product_id' => $added, 'quantity' => 1 ] ],
        ]));
        $stored = get_post_meta($f['checkout'], '_wpfnl_checkout_products', true);
        $this->assertSame([ $kept, $added ], array_map('intval', array_column($stored, 'id')));
        $this->assertSame(2, (int) $stored[0]['quantity']);
        $this->assertSame('10', $stored[0]['discount_value'], 'a product already on the checkout keeps its other settings');
        $this->assertSame([ $kept, $added ], array_column($out['result']['products'], 'product_id'));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, get_post_meta($f['checkout'], '_wpfnl_checkout_products', true));
    }

    public function test_set_checkout_products_refuses_a_non_checkout_step_and_a_non_product(): void
    {
        $f = $this->funnel();
        $this->assertSame('not_a_checkout_step', $this->code($this->write('set-wpfunnels-checkout-products', [ 'step_id' => $f['landing'], 'products' => [ [ 'product_id' => $this->product(), 'quantity' => 1 ] ] ])));
        $this->assertSame('product_not_found', $this->code($this->write('set-wpfunnels-checkout-products', [ 'step_id' => $f['checkout'], 'products' => [ [ 'product_id' => $f['landing'], 'quantity' => 1 ] ] ])));
        $this->assertSame('', get_post_meta($f['checkout'], '_wpfnl_checkout_products', true));
    }
}
