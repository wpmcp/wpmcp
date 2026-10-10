<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\FunnelKit_Writes;
use WPMCP\Integrations\Theme_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\FunnelKit_Funnel_Snapshot;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/funnelkit-tables.php';
require_once __DIR__ . '/../../support/funnelkit-rest-doubles.php';

/**
 * Issue #500: FunnelKit funnel, step and checkout product writes on the
 * theme dispatcher pair, through FunnelKit's own REST routes.
 *
 * FunnelKit is not installed in the shared test core, so its routes are
 * stood in for (tests/support/funnelkit-rest-doubles.php) at the same paths,
 * with the same parameters, permission rule and storage. The live FunnelKit
 * group (FunnelKitLiveTest) runs the same writes through the real plugin.
 */
class FunnelKitWritesTest extends \WP_UnitTestCase
{
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
        wpmcp_test_funnelkit_rest_doubles();
    }

    protected function tearDown(): void
    {
        wpmcp_test_funnelkit_rest_reset();
        remove_all_filters('wpmcp_funnelkit_active');
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function write(string $op, array $args, array $extra = []): array
    {
        return (new Theme_Integration())->handle_write([ 'operation' => $op, 'args' => $args ] + $extra);
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

    private function funnel(string $title = 'Spring'): int
    {
        return (int) $this->ok('create-funnelkit-funnel', [ 'title' => $title ])['result']['funnel']['id'];
    }

    private function step(int $funnel_id, string $type, string $title): int
    {
        return (int) $this->ok('add-funnelkit-step', [ 'funnel_id' => $funnel_id, 'type' => $type, 'title' => $title ])['result']['step_id'];
    }

    private function product(string $regular, string $sale = '', string $name = 'Kit'): int
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

    public function test_the_writes_are_listed_on_the_theme_pair_at_manage_woocommerce_and_answer_funnelkit_inactive_without_funnelkit(): void
    {
        $ops = array_column((new Theme_Integration())->catalog()['operations'], null, 'name');
        foreach ([ 'create-funnelkit-funnel', 'update-funnelkit-funnel', 'delete-funnelkit-funnel', 'add-funnelkit-step', 'reorder-funnelkit-steps', 'set-funnelkit-step-status', 'delete-funnelkit-step', 'publish-funnelkit-steps', 'set-funnelkit-checkout-products', 'remove-funnelkit-checkout-products', 'get-funnelkit-checkout-products' ] as $op) {
            $this->assertArrayHasKey($op, $ops);
        }
        $this->assertSame('destructive', $ops['delete-funnelkit-funnel']['mode']);
        $this->assertSame('destructive', $ops['delete-funnelkit-step']['mode']);
        $this->assertSame('destructive', $ops['remove-funnelkit-checkout-products']['mode']);

        remove_all_filters('wpmcp_funnelkit_active');
        add_filter('wpmcp_funnelkit_active', '__return_false');
        $this->assertSame('funnelkit_inactive', $this->code($this->write('create-funnelkit-funnel', [ 'title' => 'X' ])));

        remove_all_filters('wpmcp_funnelkit_active');
        add_filter('wpmcp_funnelkit_active', '__return_true');
        $editor = self::factory()->user->create([ 'role' => 'editor' ]);
        wp_set_current_user($editor);
        $this->assertSame('operation_denied', $this->code($this->write('create-funnelkit-funnel', [ 'title' => 'X' ])));
    }

    public function test_create_goes_through_funnelkit_previews_with_dry_run_and_rolls_back_by_deleting_the_funnel(): void
    {
        global $wpdb;
        $before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bwf_funnels");

        $dry = $this->ok('create-funnelkit-funnel', [ 'title' => 'Spring', 'dry_run' => true ]);
        $this->assertTrue($dry['result']['dry_run']);
        $this->assertSame([], $dry['operation_ids']);
        $this->assertSame($before, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bwf_funnels"));

        $out = $this->ok('create-funnelkit-funnel', [ 'title' => 'Spring' ]);
        $id  = (int) $out['result']['funnel']['id'];
        $this->assertSame('Spring', $out['result']['funnel']['title']);
        $this->assertTrue($out['recoverable']);
        $this->assertNotNull(FunnelKit_Funnel_Snapshot::row($id));

        $this->step($id, 'landing', 'Welcome');
        Rollback_Service::restore_operation($out['operation_ids'][0]);
        $this->assertNull(FunnelKit_Funnel_Snapshot::row($id));
    }

    public function test_rename_keeps_the_description_and_rolls_back_exactly(): void
    {
        global $wpdb;
        $id = $this->funnel();
        $wpdb->update($wpdb->prefix . 'bwf_funnels', [ 'desc' => 'Spring launch' ], [ 'id' => $id ]);
        $before = $this->state($id);

        $dry = $this->ok('update-funnelkit-funnel', [ 'id' => $id, 'title' => 'Summer', 'dry_run' => true ]);
        $this->assertSame([ 'Spring', 'Summer' ], $dry['result']['changes']['title']);
        $this->assertFalse($dry['recoverable']);
        $this->assertSame($before, $this->state($id));

        $out = $this->ok('update-funnelkit-funnel', [ 'id' => $id, 'title' => 'Summer' ]);
        $this->assertSame('Summer', $out['result']['funnel']['title']);
        $this->assertSame('Spring launch', $out['result']['funnel']['description']);

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($id));

        $this->assertSame('funnel_not_found', $this->code($this->write('update-funnelkit-funnel', [ 'id' => 999999, 'title' => 'X' ])));
    }

    public function test_steps_are_added_reordered_switched_published_and_deleted_and_each_rolls_back_exactly(): void
    {
        $id       = $this->funnel();
        $landing  = $this->step($id, 'landing', 'Welcome');
        $checkout = $this->step($id, 'wc_checkout', 'Checkout');

        $before = $this->state($id);
        $dry    = $this->ok('add-funnelkit-step', [ 'funnel_id' => $id, 'type' => 'wc_thankyou', 'title' => 'Thanks', 'dry_run' => true ]);
        $this->assertSame(3, $dry['result']['would_add']['position']);
        $this->assertSame($before, $this->state($id));
        $out = $this->ok('add-funnelkit-step', [ 'funnel_id' => $id, 'type' => 'wc_thankyou', 'title' => 'Thanks' ]);
        $thanks = (int) $out['result']['step_id'];
        $this->assertSame('wffn_ty', get_post_type($thanks));
        $this->assertSame([ $landing, $checkout, $thanks ], array_column($out['result']['funnel']['steps'], 'id'));
        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($id));
        $this->assertNull(get_post($thanks));

        $this->assertSame('step_ids_mismatch', $this->code($this->write('reorder-funnelkit-steps', [ 'funnel_id' => $id, 'step_ids' => [ $checkout ] ])));
        $out = $this->ok('reorder-funnelkit-steps', [ 'funnel_id' => $id, 'step_ids' => [ $checkout, $landing ] ]);
        $this->assertSame([ $checkout, $landing ], array_column($out['result']['funnel']['steps'], 'id'));
        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($id));

        $this->assertSame('step_not_found', $this->code($this->write('set-funnelkit-step-status', [ 'funnel_id' => $id, 'step_id' => 999999, 'enabled' => false ])));
        $out = $this->ok('set-funnelkit-step-status', [ 'funnel_id' => $id, 'step_id' => $landing, 'enabled' => false ]);
        $this->assertSame('inactive', $out['result']['status']);
        $dry = $this->ok('publish-funnelkit-steps', [ 'funnel_id' => $id, 'dry_run' => true ]);
        $this->assertSame([ $landing ], $dry['result']['would_publish']);
        $published = $this->ok('publish-funnelkit-steps', [ 'funnel_id' => $id ]);
        $this->assertSame('publish', get_post_status($landing));
        Rollback_Service::restore_operation($published['operation_id']);
        $this->assertSame('draft', get_post_status($landing));
        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($id));

        $this->assertSame('confirmation_required', $this->code($this->write('delete-funnelkit-step', [ 'funnel_id' => $id, 'step_id' => $checkout ])));
        $out = $this->ok('delete-funnelkit-step', [ 'funnel_id' => $id, 'step_id' => $checkout ], [ 'confirm' => true ]);
        $this->assertNull(get_post($checkout));
        $this->assertSame([ $landing ], array_column($out['result']['funnel']['steps'], 'id'));
        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($id));
        $this->assertSame('wfacp_checkout', get_post_type($checkout));
    }

    public function test_deleting_a_funnel_needs_confirm_and_rollback_brings_back_the_funnel_its_meta_and_its_steps(): void
    {
        $id       = $this->funnel();
        $checkout = $this->step($id, 'wc_checkout', 'Checkout');
        $this->ok('set-funnelkit-checkout-products', [ 'step_id' => $checkout, 'products' => [ [ 'product_id' => $this->product('50') ] ] ]);
        $before = $this->state($id);
        $this->assertNotEmpty($before['meta']);

        $this->assertSame('confirmation_required', $this->code($this->write('delete-funnelkit-funnel', [ 'id' => $id ])));
        $out = $this->ok('delete-funnelkit-funnel', [ 'id' => $id ], [ 'confirm' => true ]);
        $this->assertSame([ $checkout ], $out['result']['deleted_step_ids']);
        $this->assertNull(FunnelKit_Funnel_Snapshot::row($id));
        $this->assertNull(get_post($checkout));

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($id));
        $this->assertSame('Checkout', get_the_title($checkout));
    }

    public function test_checkout_products_take_quantity_and_discount_report_effective_prices_and_roll_back_exactly(): void
    {
        $id       = $this->funnel();
        $checkout = $this->step($id, 'wc_checkout', 'Checkout');
        $kit      = $this->product('100', '80', 'Starter kit');
        $case     = $this->product('30', '', 'Travel case');
        $before   = $this->state($id);

        $dry = $this->ok('set-funnelkit-checkout-products', [
            'step_id'  => $checkout,
            'products' => [
                [ 'product_id' => $kit, 'quantity' => 2, 'discount_type' => 'percent_discount_reg', 'discount_amount' => 20 ],
                [ 'product_id' => $case ],
            ],
            'dry_run'  => true,
        ]);
        $this->assertFalse($dry['recoverable']);
        $this->assertSame($before, $this->state($id));
        $lines = array_column($dry['result']['products'], null, 'product_id');
        $this->assertEqualsWithDelta(80.0, $lines[ $kit ]['unit_price'], 0.001);
        $this->assertEqualsWithDelta(160.0, $lines[ $kit ]['line_total'], 0.001);
        $this->assertEqualsWithDelta(30.0, $lines[ $case ]['unit_price'], 0.001);

        $out   = $this->ok('set-funnelkit-checkout-products', [
            'step_id'  => $checkout,
            'products' => [
                [ 'product_id' => $kit, 'quantity' => 2, 'discount_type' => 'percent_discount_reg', 'discount_amount' => 20 ],
                [ 'product_id' => $case ],
            ],
        ]);
        $lines = array_column($out['result']['products'], null, 'product_id');
        $this->assertEqualsWithDelta(160.0, $lines[ $kit ]['line_total'], 0.001);
        $stored = array_column(get_post_meta($checkout, '_wfacp_selected_products', true), null, 'id');
        $this->assertSame(2, (int) $stored[ $kit ]['quantity']);
        $this->assertSame('percent_discount_reg', $stored[ $kit ]['discount_type']);

        // Changing a product already on the checkout keeps its key.
        $key   = $lines[ $kit ]['key'];
        $again = $this->ok('set-funnelkit-checkout-products', [ 'step_id' => $checkout, 'products' => [ [ 'product_id' => $kit, 'discount_type' => 'fixed_discount_sale', 'discount_amount' => 15 ] ] ]);
        $lines = array_column($again['result']['products'], null, 'product_id');
        $this->assertSame($key, $lines[ $kit ]['key']);
        $this->assertEqualsWithDelta(65.0, $lines[ $kit ]['unit_price'], 0.001);
        $this->assertEqualsWithDelta(130.0, $lines[ $kit ]['line_total'], 0.001);
        $this->assertSame([], $again['result']['added']);

        $read = (new Theme_Integration())->handle_read([ 'operation' => 'get-funnelkit-checkout-products', 'args' => [ 'step_id' => $checkout ] ]);
        $this->assertSame($id, $read['result']['funnel_id']);
        $this->assertCount(2, $read['result']['products']);

        $this->assertSame('confirmation_required', $this->code($this->write('remove-funnelkit-checkout-products', [ 'step_id' => $checkout, 'product_ids' => [ $case ] ])));
        $this->assertSame('product_not_on_checkout', $this->code($this->write('remove-funnelkit-checkout-products', [ 'step_id' => $checkout, 'product_ids' => [ 999999 ] ], [ 'confirm' => true ])));
        $removed = $this->ok('remove-funnelkit-checkout-products', [ 'step_id' => $checkout, 'product_ids' => [ $case ] ], [ 'confirm' => true ]);
        $this->assertSame([ $kit ], array_column($removed['result']['products'], 'product_id'));

        Rollback_Service::restore_operation($removed['operation_id']);
        Rollback_Service::restore_operation($again['operation_id']);
        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($id));
    }

    public function test_checkout_product_refusals_write_nothing(): void
    {
        $id       = $this->funnel();
        $checkout = $this->step($id, 'wc_checkout', 'Checkout');
        $kit      = $this->product('100');
        $loose    = self::factory()->post->create([ 'post_type' => 'wfacp_checkout' ]);
        $before   = $this->state($id);

        $this->assertSame('product_not_found', $this->code($this->write('set-funnelkit-checkout-products', [ 'step_id' => $checkout, 'products' => [ [ 'product_id' => 999999 ] ] ])));
        $this->assertSame('duplicate_product', $this->code($this->write('set-funnelkit-checkout-products', [ 'step_id' => $checkout, 'products' => [ [ 'product_id' => $kit ], [ 'product_id' => $kit ] ] ])));
        $this->assertSame('invalid_discount', $this->code($this->write('set-funnelkit-checkout-products', [ 'step_id' => $checkout, 'products' => [ [ 'product_id' => $kit, 'discount_type' => 'percent_discount_sale', 'discount_amount' => 120 ] ] ])));
        $this->assertSame('invalid_args', $this->code($this->write('set-funnelkit-checkout-products', [ 'step_id' => $checkout, 'products' => [ [ 'product_id' => $kit, 'discount_type' => 'flat_price' ] ] ])));
        $this->assertSame('checkout_not_found', $this->code($this->write('set-funnelkit-checkout-products', [ 'step_id' => $kit, 'products' => [ [ 'product_id' => $kit ] ] ])));
        $this->assertSame('checkout_not_in_funnel', $this->code($this->write('set-funnelkit-checkout-products', [ 'step_id' => $loose, 'products' => [ [ 'product_id' => $kit ] ] ])));
        $this->assertSame($before, $this->state($id));
    }

    public function test_a_variable_product_has_no_single_price(): void
    {
        $id       = $this->funnel();
        $checkout = $this->step($id, 'wc_checkout', 'Checkout');
        $variable = new \WC_Product_Variable();
        $variable->set_name('Shirt');
        $variable->set_status('publish');
        $variable_id = $variable->save();

        $out  = $this->ok('set-funnelkit-checkout-products', [ 'step_id' => $checkout, 'products' => [ [ 'product_id' => $variable_id ] ], 'dry_run' => true ]);
        $line = $out['result']['products'][0];
        $this->assertNull($line['unit_price']);
        $this->assertNull($line['line_total']);
        $this->assertArrayHasKey('unit_price_unavailable', $line);
    }

    public function test_funnelkits_own_access_check_is_honoured_and_its_refusal_leaves_no_undo_point(): void
    {
        $id      = $this->funnel();
        $manager = self::factory()->user->create([ 'role' => 'subscriber' ]);
        get_userdata($manager)->add_cap('manage_woocommerce');
        wp_set_current_user($manager);
        $before = count(Snapshot_Store::list_by_session('default'));

        $out = $this->write('update-funnelkit-funnel', [ 'id' => $id, 'title' => 'Hijack' ]);
        $this->assertSame('funnelkit_access_denied', $this->code($out));
        $this->assertSame('Spring', FunnelKit_Funnel_Snapshot::row($id)['title']);
        $this->assertCount($before, Snapshot_Store::list_by_session('default'));
        $this->assertSame('funnelkit_access_denied', $this->code($this->write('create-funnelkit-funnel', [ 'title' => 'X' ])));
    }

    public function test_a_site_without_funnelkits_routes_answers_funnelkit_api_unavailable(): void
    {
        $id = $this->funnel();
        wpmcp_test_funnelkit_rest_without_routes();
        $this->assertSame('funnelkit_api_unavailable', $this->code($this->write('update-funnelkit-funnel', [ 'id' => $id, 'title' => 'X' ])));
    }

    public function test_discounts_follow_funnelkits_formula(): void
    {
        $this->assertSame(80.0, FunnelKit_Writes::discounted(100.0, 80.0, 'percent_discount_sale', 0.0));
        $this->assertEqualsWithDelta(64.0, FunnelKit_Writes::discounted(100.0, 80.0, 'percent_discount_sale', 20.0), 0.001);
        $this->assertEqualsWithDelta(80.0, FunnelKit_Writes::discounted(100.0, 80.0, 'percent_discount_reg', 20.0), 0.001);
        $this->assertEqualsWithDelta(70.0, FunnelKit_Writes::discounted(100.0, 80.0, 'fixed_discount_sale', 10.0), 0.001);
        $this->assertEqualsWithDelta(90.0, FunnelKit_Writes::discounted(100.0, 80.0, 'fixed_discount_reg', 10.0), 0.001);
        $this->assertSame(0.0, FunnelKit_Writes::discounted(100.0, 80.0, 'fixed_discount_sale', 500.0));
    }

    public function test_funnel_snapshots_are_restorable_types_and_restoring_one_takes_manage_woocommerce(): void
    {
        $this->assertContains(FunnelKit_Funnel_Snapshot::TYPE, Rollback_Service::restorable_object_types());
        $this->assertContains(FunnelKit_Funnel_Snapshot::CREATE_TYPE, Rollback_Service::restorable_object_types());

        $id  = $this->funnel();
        $out = $this->ok('update-funnelkit-funnel', [ 'id' => $id, 'title' => 'Summer' ]);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $this->assertFalse(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame('Summer', FunnelKit_Funnel_Snapshot::row($id)['title']);
        wp_set_current_user($this->admin);
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame('Spring', FunnelKit_Funnel_Snapshot::row($id)['title']);
    }
}
