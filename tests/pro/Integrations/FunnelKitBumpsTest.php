<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\FunnelKit_Funnel_Snapshot;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/funnelkit-tables.php';
require_once __DIR__ . '/../../support/funnelkit-rest-doubles.php';
require_once __DIR__ . '/../../support/funnelkit-bump-rest-doubles.php';

/**
 * Issue #663: FunnelKit order bumps on the theme dispatcher pair, through
 * Funnel Builder Pro's own REST routes, stood in for here
 * (tests/support/funnelkit-bump-rest-doubles.php) at the same paths, with
 * the same parameters, permission rule and storage.
 */
class FunnelKitBumpsTest extends \WP_UnitTestCase
{
    private const OPS = [
        'list-funnelkit-order-bumps',
        'get-funnelkit-order-bump',
        'create-funnelkit-order-bump',
        'update-funnelkit-order-bump',
        'set-funnelkit-order-bump-products',
        'remove-funnelkit-order-bump-products',
        'delete-funnelkit-order-bump',
    ];

    private int $admin;

    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_funnelkit_tables();
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_funnelkit_tables();
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('wc_get_product')) {
            $this->markTestSkipped('Needs WooCommerce.');
        }
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        $this->admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        get_userdata($this->admin)->add_cap('manage_woocommerce');
        wp_set_current_user($this->admin);
        add_filter('wpmcp_funnelkit_active', '__return_true');
        add_filter('wpmcp_funnelkit_bumps_active', '__return_true');
        wpmcp_test_funnelkit_bump_rest_doubles();
        wpmcp_test_funnelkit_rest_doubles();
    }

    protected function tearDown(): void
    {
        wpmcp_test_funnelkit_bump_rest_reset();
        wpmcp_test_funnelkit_rest_reset();
        remove_all_filters('wpmcp_funnelkit_active');
        remove_all_filters('wpmcp_funnelkit_bumps_active');
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function write(string $op, array $args, array $extra = []): array
    {
        return (new Theme_Integration())->handle_write([ 'operation' => $op, 'args' => $args ] + $extra);
    }

    private function read(string $op, array $args): array
    {
        return (new Theme_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function ok(string $op, array $args, array $extra = []): array
    {
        $out = $this->write($op, $args, $extra);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    private function code(array $out): string
    {
        return (string) ($out['error']['code'] ?? '');
    }

    /** @return array{0:int,1:int} funnel id and checkout step id. */
    private function checkout(): array
    {
        $funnel = (int) $this->ok('create-funnelkit-funnel', [ 'title' => 'Spring' ])['result']['funnel']['id'];
        $step   = (int) $this->ok('add-funnelkit-step', [ 'funnel_id' => $funnel, 'type' => 'wc_checkout', 'title' => 'Checkout' ])['result']['step_id'];
        return [ $funnel, $step ];
    }

    private function bump(int $checkout, string $title = 'Gift wrap'): int
    {
        return (int) $this->ok('create-funnelkit-order-bump', [ 'step_id' => $checkout, 'title' => $title ])['result']['bump']['bump_id'];
    }

    private function product(string $regular, string $sale = '', string $name = 'Wrap'): int
    {
        $product = new \WC_Product_Simple();
        $product->set_name($name);
        $product->set_regular_price($regular);
        if ('' !== $sale) {
            $product->set_sale_price($sale);
        }
        $product->set_status('publish');
        return $product->save();
    }

    private function state(int $funnel_id): array
    {
        return FunnelKit_Funnel_Snapshot::capture($funnel_id)['data'];
    }

    public function test_the_ops_sit_on_the_theme_pair_and_need_funnelkit_and_its_order_bump_add_on(): void
    {
        $ops = array_column((new Theme_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $op) {
            $this->assertArrayHasKey($op, $ops);
        }
        $this->assertSame('read', $ops['list-funnelkit-order-bumps']['mode']);
        $this->assertSame('read', $ops['get-funnelkit-order-bump']['mode']);
        $this->assertSame('destructive', $ops['delete-funnelkit-order-bump']['mode']);
        $this->assertSame('destructive', $ops['remove-funnelkit-order-bump-products']['mode']);

        [ , $checkout ] = $this->checkout();

        remove_all_filters('wpmcp_funnelkit_bumps_active');
        add_filter('wpmcp_funnelkit_bumps_active', '__return_false');
        $out = $this->write('create-funnelkit-order-bump', [ 'step_id' => $checkout, 'title' => 'X' ]);
        $this->assertSame('funnelkit_pro_inactive', $this->code($out));
        $this->assertStringContainsString('Funnel Builder Pro', (string) $out['error']['message']);
        $this->assertSame('funnelkit_pro_inactive', $this->code($this->read('list-funnelkit-order-bumps', [ 'step_id' => $checkout ])));

        remove_all_filters('wpmcp_funnelkit_active');
        add_filter('wpmcp_funnelkit_active', '__return_false');
        $this->assertSame('funnelkit_inactive', $this->code($this->read('list-funnelkit-order-bumps', [ 'step_id' => $checkout ])));
    }

    public function test_presence_of_the_add_on_defaults_to_its_own_class(): void
    {
        remove_all_filters('wpmcp_funnelkit_bumps_active');
        [ , $checkout ] = $this->checkout();
        $this->assertSame(class_exists('WFOB_Common') ? '' : 'funnelkit_pro_inactive', $this->code($this->read('list-funnelkit-order-bumps', [ 'step_id' => $checkout ])));
    }

    public function test_create_adds_a_bump_to_the_checkout_previews_with_dry_run_and_rolls_back(): void
    {
        [ $funnel, $checkout ] = $this->checkout();
        $before = $this->state($funnel);

        $dry = $this->ok('create-funnelkit-order-bump', [ 'step_id' => $checkout, 'title' => 'Gift wrap', 'dry_run' => true ]);
        $this->assertTrue($dry['result']['dry_run']);
        $this->assertFalse($dry['recoverable']);
        $this->assertSame($before, $this->state($funnel));

        $out  = $this->ok('create-funnelkit-order-bump', [ 'step_id' => $checkout, 'title' => 'Gift wrap' ]);
        $bump = (int) $out['result']['bump']['bump_id'];
        $this->assertTrue($out['recoverable']);
        $this->assertSame('wfob_bump', get_post_type($bump));
        $this->assertSame('Gift wrap', $out['result']['bump']['title']);
        $this->assertSame('active', $out['result']['bump']['status']);
        $this->assertSame($checkout, $out['result']['bump']['step_id']);
        $this->assertSame($funnel, $out['result']['bump']['funnel_id']);
        $this->assertContains($bump, FunnelKit_Funnel_Snapshot::step_ids((array) FunnelKit_Funnel_Snapshot::row($funnel)));

        $list = $this->read('list-funnelkit-order-bumps', [ 'step_id' => $checkout ]);
        $this->assertSame([ $bump ], array_column($list['result']['bumps'], 'bump_id'));

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($funnel));
        $this->assertNull(get_post($bump));
    }

    public function test_create_refuses_a_step_that_is_not_a_checkout_in_a_funnel(): void
    {
        [ $funnel ] = $this->checkout();
        $landing    = (int) $this->ok('add-funnelkit-step', [ 'funnel_id' => $funnel, 'type' => 'landing', 'title' => 'Welcome' ])['result']['step_id'];
        $loose      = self::factory()->post->create([ 'post_type' => 'wfacp_checkout' ]);

        $this->assertSame('checkout_not_found', $this->code($this->write('create-funnelkit-order-bump', [ 'step_id' => $landing, 'title' => 'X' ])));
        $this->assertSame('checkout_not_in_funnel', $this->code($this->write('create-funnelkit-order-bump', [ 'step_id' => $loose, 'title' => 'X' ])));
        $this->assertSame('checkout_not_found', $this->code($this->read('list-funnelkit-order-bumps', [ 'step_id' => $landing ])));
    }

    public function test_products_take_quantity_and_discount_report_the_price_and_roll_back_exactly(): void
    {
        [ $funnel, $checkout ] = $this->checkout();
        $bump   = $this->bump($checkout);
        $wrap   = $this->product('10', '', 'Wrap');
        $card   = $this->product('5', '4', 'Card');
        $before = $this->state($funnel);

        $lines = [
            [ 'product_id' => $wrap, 'quantity' => 2, 'discount_type' => 'percent_discount_reg', 'discount_amount' => 50 ],
            [ 'product_id' => $card ],
        ];
        $dry = $this->ok('set-funnelkit-order-bump-products', [ 'bump_id' => $bump, 'products' => $lines, 'dry_run' => true ]);
        $this->assertSame($before, $this->state($funnel));
        $by = array_column($dry['result']['products'], null, 'product_id');
        $this->assertEqualsWithDelta(5.0, $by[ $wrap ]['unit_price'], 0.001);
        $this->assertEqualsWithDelta(10.0, $by[ $wrap ]['line_total'], 0.001);
        $this->assertEqualsWithDelta(4.0, $by[ $card ]['unit_price'], 0.001);

        $out    = $this->ok('set-funnelkit-order-bump-products', [ 'bump_id' => $bump, 'products' => $lines ]);
        $stored = array_column(get_post_meta($bump, '_wfob_selected_products', true), null, 'id');
        $this->assertSame(2, (int) $stored[ $wrap ]['quantity']);
        $this->assertSame('percent_discount_reg', $stored[ $wrap ]['discount_type']);
        $this->assertSame('1', (string) get_post_meta($bump, '_wfob_product_settings', true)['bump_action_type'], 'The bump behaviour is kept.');

        $key   = array_column($out['result']['products'], null, 'product_id')[ $wrap ]['key'];
        $again = $this->ok('set-funnelkit-order-bump-products', [ 'bump_id' => $bump, 'products' => [ [ 'product_id' => $wrap, 'discount_type' => 'fixed_discount_reg', 'discount_amount' => 3 ] ] ]);
        $by    = array_column($again['result']['products'], null, 'product_id');
        $this->assertSame($key, $by[ $wrap ]['key']);
        $this->assertEqualsWithDelta(7.0, $by[ $wrap ]['unit_price'], 0.001);

        $got = $this->read('get-funnelkit-order-bump', [ 'bump_id' => $bump ]);
        $this->assertSame($bump, $got['result']['bump_id']);
        $this->assertCount(2, $got['result']['products']);
        $this->assertSame('add', $got['result']['behaviour']);

        $this->assertSame('confirmation_required', $this->code($this->write('remove-funnelkit-order-bump-products', [ 'bump_id' => $bump, 'product_ids' => [ $card ] ])));
        $this->assertSame('product_not_on_bump', $this->code($this->write('remove-funnelkit-order-bump-products', [ 'bump_id' => $bump, 'product_ids' => [ 999999 ] ], [ 'confirm' => true ])));
        $removed = $this->ok('remove-funnelkit-order-bump-products', [ 'bump_id' => $bump, 'product_ids' => [ $card ] ], [ 'confirm' => true ]);
        $this->assertSame([ $wrap ], array_column($removed['result']['products'], 'product_id'));

        Rollback_Service::restore_operation($removed['operation_id']);
        Rollback_Service::restore_operation($again['operation_id']);
        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($funnel));
    }

    public function test_product_refusals_write_nothing(): void
    {
        [ $funnel, $checkout ] = $this->checkout();
        $bump   = $this->bump($checkout);
        $wrap   = $this->product('10');
        $before = $this->state($funnel);

        $this->assertSame('product_not_found', $this->code($this->write('set-funnelkit-order-bump-products', [ 'bump_id' => $bump, 'products' => [ [ 'product_id' => 999999 ] ] ])));
        $this->assertSame('duplicate_product', $this->code($this->write('set-funnelkit-order-bump-products', [ 'bump_id' => $bump, 'products' => [ [ 'product_id' => $wrap ], [ 'product_id' => $wrap ] ] ])));
        $this->assertSame('invalid_discount', $this->code($this->write('set-funnelkit-order-bump-products', [ 'bump_id' => $bump, 'products' => [ [ 'product_id' => $wrap, 'discount_type' => 'percent_discount_sale', 'discount_amount' => 150 ] ] ])));
        $this->assertSame('bump_not_found', $this->code($this->write('set-funnelkit-order-bump-products', [ 'bump_id' => $wrap, 'products' => [ [ 'product_id' => $wrap ] ] ])));
        $this->assertSame('bump_not_found', $this->code($this->read('get-funnelkit-order-bump', [ 'bump_id' => $wrap ])));
        $this->assertSame($before, $this->state($funnel));
    }

    public function test_update_renames_and_switches_a_bump_and_rolls_back(): void
    {
        [ $funnel, $checkout ] = $this->checkout();
        $bump   = $this->bump($checkout);
        $before = $this->state($funnel);

        $dry = $this->ok('update-funnelkit-order-bump', [ 'bump_id' => $bump, 'title' => 'Express', 'enabled' => false, 'dry_run' => true ]);
        $this->assertSame([ 'Gift wrap', 'Express' ], $dry['result']['changes']['title']);
        $this->assertSame([ 'active', 'inactive' ], $dry['result']['changes']['status']);
        $this->assertSame($before, $this->state($funnel));

        $this->assertSame('nothing_to_update', $this->code($this->write('update-funnelkit-order-bump', [ 'bump_id' => $bump ])));

        $out = $this->ok('update-funnelkit-order-bump', [ 'bump_id' => $bump, 'title' => 'Express', 'enabled' => false ]);
        $this->assertSame('Express', $out['result']['bump']['title']);
        $this->assertSame('inactive', $out['result']['bump']['status']);
        $this->assertSame('draft', get_post_status($bump));

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($funnel));
        $this->assertSame('publish', get_post_status($bump));
    }

    public function test_delete_needs_confirm_and_rollback_brings_the_bump_back_in_place(): void
    {
        [ $funnel, $checkout ] = $this->checkout();
        $first  = $this->bump($checkout, 'Gift wrap');
        $second = $this->bump($checkout, 'Express');
        $this->ok('set-funnelkit-order-bump-products', [ 'bump_id' => $first, 'products' => [ [ 'product_id' => $this->product('10') ] ] ]);
        $before = $this->state($funnel);

        $this->assertSame('confirmation_required', $this->code($this->write('delete-funnelkit-order-bump', [ 'bump_id' => $first ])));
        $out = $this->ok('delete-funnelkit-order-bump', [ 'bump_id' => $first ], [ 'confirm' => true ]);
        $this->assertTrue($out['result']['deleted']);
        $this->assertNotSame('publish', get_post_status($first));
        $list = $this->read('list-funnelkit-order-bumps', [ 'step_id' => $checkout ]);
        $this->assertSame([ $second ], array_column($list['result']['bumps'], 'bump_id'));

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($funnel));
        $list = $this->read('list-funnelkit-order-bumps', [ 'step_id' => $checkout ]);
        $this->assertSame([ $first, $second ], array_column($list['result']['bumps'], 'bump_id'));
    }

    public function test_funnelkits_own_access_check_is_honoured(): void
    {
        [ , $checkout ] = $this->checkout();
        $bump    = $this->bump($checkout);
        $manager = self::factory()->user->create([ 'role' => 'subscriber' ]);
        get_userdata($manager)->add_cap('manage_woocommerce');
        get_userdata($manager)->add_cap('edit_theme_options');
        wp_set_current_user($manager);
        $before = count(Snapshot_Store::list_by_session('default'));

        $this->assertSame('funnelkit_access_denied', $this->code($this->write('update-funnelkit-order-bump', [ 'bump_id' => $bump, 'title' => 'Hijack' ])));
        $this->assertSame('Gift wrap', get_the_title($bump));
        $this->assertCount($before, Snapshot_Store::list_by_session('default'));
    }
}
