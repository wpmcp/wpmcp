<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/cartflows-stubs.php';

/**
 * CartFlows (issue #662): paid-tier flow, step and checkout product ops on
 * the theme dispatcher pair, beside FunnelKit, so they add no top-level
 * tools.
 *
 * CartFlows is not loaded in the shared test core. Presence is driven
 * through wpmcp_cartflows_active, and the storage is seeded the way
 * CartFlows 3.3.1 keeps it (tests/support/cartflows-stubs.php).
 *
 * Every write is off until the site opts in (wpmcp_enable_cartflows_write)
 * and is undone here: a created flow or step goes to the trash, and a
 * changed step list, step switch or checkout product list is a post
 * snapshot (meta included).
 */
class CartFlowsPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'list-cartflows-flows'            => 'read',
        'get-cartflows-flow'              => 'read',
        'list-cartflows-steps'            => 'read',
        'create-cartflows-flow'           => 'write',
        'add-cartflows-step'              => 'write',
        'reorder-cartflows-steps'         => 'write',
        'set-cartflows-step-status'       => 'write',
        'set-cartflows-checkout-products' => 'write',
    ];

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wpmcp_test_register_cartflows_types();
        $this->admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        wp_set_current_user($this->admin);
        add_filter('wpmcp_cartflows_active', '__return_true');
        add_filter('wpmcp_enable_cartflows_write', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_cartflows_active');
        remove_all_filters('wpmcp_enable_cartflows_write');
        wpmcp_test_unregister_cartflows_types();
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

    /** @return int[] the step ids of a flow's wcf-steps list, in order */
    private function order(int $flow): array
    {
        return array_map(static fn (array $s): int => (int) $s['id'], (array) get_post_meta($flow, 'wcf-steps', true));
    }

    private function seed(): array
    {
        return wpmcp_test_seed_cartflows_flow('Spring sale', [ [ 'landing', 'Sales Landing' ], [ 'checkout', 'Checkout' ], [ 'thankyou', 'Thank You' ] ]);
    }

    public function test_the_ops_are_on_the_theme_pair_paid_tier_and_writes_are_off_until_the_site_opts_in(): void
    {
        $ops = array_column((new Theme_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $op => $mode) {
            $this->assertArrayHasKey($op, $ops);
            $this->assertSame($mode, $ops[ $op ]['mode'], $op);
        }

        remove_all_filters('wpmcp_enable_cartflows_write');
        $out = $this->write('create-cartflows-flow', [ 'title' => 'X' ]);
        $this->assertSame('opt_in_required', $this->code($out));
        $this->assertStringContainsString('wpmcp_enable_cartflows_write', (string) wp_json_encode($out));

        Gate::set_pro_for_tests(false);
        $free = array_column((new Theme_Integration())->catalog()['operations'], null, 'name');
        foreach (array_keys(self::OPS) as $op) {
            $this->assertArrayNotHasKey($op, $free);
        }
    }

    public function test_without_cartflows_each_op_names_the_plugin(): void
    {
        remove_all_filters('wpmcp_cartflows_active');
        add_filter('wpmcp_cartflows_active', '__return_false');
        $out = $this->read('list-cartflows-flows');
        $this->assertSame('cartflows_inactive', $this->code($out));
        $this->assertSame('CartFlows', $out['error']['data']['plugin_name'] ?? $out['error']['data']['plugin'] ?? '');
        $this->assertSame('cartflows_inactive', $this->code($this->write('create-cartflows-flow', [ 'title' => 'X' ])));
    }

    public function test_a_user_without_cartflows_access_is_refused(): void
    {
        $seed   = $this->seed();
        $editor = self::factory()->user->create([ 'role' => 'editor' ]);
        get_userdata($editor)->add_cap('edit_theme_options');
        wp_set_current_user($editor);
        $this->assertSame('operation_denied', $this->code($this->read('get-cartflows-flow', [ 'id' => $seed['flow'] ])));

        wp_get_current_user()->add_cap('cartflows_manage_flows_steps');
        $this->ok($this->read('get-cartflows-flow', [ 'id' => $seed['flow'] ]));
    }

    public function test_reads_list_flows_and_their_steps_in_order(): void
    {
        $seed = $this->seed();
        wpmcp_test_seed_cartflows_flow('Draft flow', [ [ 'optin', 'Optin' ] ], 'draft');
        $plain = self::factory()->post->create();

        $list = $this->ok($this->read('list-cartflows-flows'))['result'];
        $this->assertSame(2, $list['total']);
        $byTitle = array_column($list['flows'], null, 'title');
        $this->assertSame(3, $byTitle['Spring sale']['step_count']);
        $this->assertSame([ 'landing', 'checkout', 'thankyou' ], $byTitle['Spring sale']['step_types']);
        $this->assertSame(1, $this->ok($this->read('list-cartflows-flows', [ 'status' => 'draft' ]))['result']['total']);
        $this->assertSame(1, $this->ok($this->read('list-cartflows-flows', [ 'search' => 'Spring' ]))['result']['total']);

        $flow = $this->ok($this->read('get-cartflows-flow', [ 'id' => $seed['flow'] ]))['result']['flow'];
        $this->assertSame('Spring sale', $flow['title']);
        $this->assertSame($seed['steps'], array_column($flow['steps'], 'id'));
        $this->assertSame([ 1, 2, 3 ], array_column($flow['steps'], 'position'));
        $this->assertTrue($flow['steps'][1]['enabled']);

        update_post_meta($seed['steps'][1], 'wcf-checkout-products', [ [ 'product' => '77', 'quantity' => '2', 'discount_type' => 'discount_percent', 'discount_value' => '10', 'unique_id' => 'abcd1234', 'add_to_cart' => 'yes' ] ]);
        $steps = $this->ok($this->read('list-cartflows-steps', [ 'flow_id' => $seed['flow'], 'type' => 'checkout' ]))['result']['steps'];
        $this->assertCount(1, $steps);
        $this->assertSame(77, $steps[0]['checkout_products'][0]['product_id']);
        $this->assertSame(2, $steps[0]['checkout_products'][0]['quantity']);
        $this->assertSame('discount_percent', $steps[0]['checkout_products'][0]['discount_type']);
        $this->assertTrue($steps[0]['checkout_products'][0]['add_to_cart']);

        $this->assertSame('flow_not_found', $this->code($this->read('get-cartflows-flow', [ 'id' => $plain ])));
        $this->assertSame('flow_not_found', $this->code($this->read('list-cartflows-steps', [ 'flow_id' => $seed['steps'][0] ])));
    }

    public function test_create_flow_makes_a_draft_flow_with_its_steps_and_rollback_trashes_them(): void
    {
        $out  = $this->ok($this->write('create-cartflows-flow', [ 'title' => 'Webinar', 'steps' => [ [ 'type' => 'optin', 'title' => 'Sign up' ], [ 'type' => 'thankyou', 'title' => 'Done' ] ] ]));
        $flow = (int) $out['result']['flow']['id'];
        $this->assertTrue($out['recoverable']);
        $this->assertSame('draft', get_post_status($flow));
        $this->assertSame('cartflows_flow', get_post_type($flow));

        $steps = $this->order($flow);
        $this->assertCount(2, $steps);
        $this->assertSame('optin', get_post_meta($steps[0], 'wcf-step-type', true));
        $this->assertSame($flow, (int) get_post_meta($steps[0], 'wcf-flow-id', true));
        $this->assertSame('draft', get_post_status($steps[0]), 'a draft flow\'s steps are not live either');
        $this->assertSame([ 'flow-' . $flow ], wp_get_object_terms($steps[0], 'cartflows_step_flow', [ 'fields' => 'names' ]));
        $this->assertSame([ 'optin' ], wp_get_object_terms($steps[0], 'cartflows_step_type', [ 'fields' => 'names' ]));

        $this->assertSame('invalid_args', $this->code($this->write('create-cartflows-flow', [ 'title' => 'X', 'steps' => [ [ 'type' => 'upsell', 'title' => 'Offer' ] ] ])));

        Rollback_Service::restore_session((string) $out['session_id']);
        $this->assertSame('trash', get_post_status($flow));
        $this->assertSame('trash', get_post_status($steps[0]));
        $this->assertSame('trash', get_post_status($steps[1]));
    }

    public function test_add_step_inserts_at_a_position_and_rollback_takes_it_out_again(): void
    {
        $seed = $this->seed();
        $out  = $this->ok($this->write('add-cartflows-step', [ 'flow_id' => $seed['flow'], 'type' => 'optin', 'title' => 'Lead', 'position' => 2 ]));
        $step = (int) $out['result']['step']['id'];
        $this->assertSame([ $seed['steps'][0], $step, $seed['steps'][1], $seed['steps'][2] ], $this->order($seed['flow']));
        $this->assertSame('publish', get_post_status($step), 'a live flow gets a live step, as CartFlows does');
        $this->assertSame('optin', get_post_meta($step, 'wcf-step-type', true));

        $appended = $this->ok($this->write('add-cartflows-step', [ 'flow_id' => $seed['flow'], 'type' => 'landing', 'title' => 'Last' ]));
        $this->assertSame((int) $appended['result']['step']['id'], $this->order($seed['flow'])[4]);

        Rollback_Service::restore_session((string) $appended['session_id']);
        Rollback_Service::restore_session((string) $out['session_id']);
        $this->assertSame($seed['steps'], $this->order($seed['flow']));
        $this->assertSame('trash', get_post_status($step));

        $this->assertSame('flow_not_found', $this->code($this->write('add-cartflows-step', [ 'flow_id' => $seed['steps'][0], 'type' => 'landing', 'title' => 'X' ])));
    }

    public function test_reorder_needs_every_step_once_and_rollback_puts_the_order_back(): void
    {
        $seed = $this->seed();
        [ $a, $b, $c ] = $seed['steps'];

        $this->assertSame('invalid_step_order', $this->code($this->write('reorder-cartflows-steps', [ 'flow_id' => $seed['flow'], 'step_ids' => [ $b, $a ] ])));
        $this->assertSame('invalid_step_order', $this->code($this->write('reorder-cartflows-steps', [ 'flow_id' => $seed['flow'], 'step_ids' => [ $b, $a, $a ] ])));
        $this->assertSame('no_change', $this->code($this->write('reorder-cartflows-steps', [ 'flow_id' => $seed['flow'], 'step_ids' => [ $a, $b, $c ] ])));

        $out = $this->ok($this->write('reorder-cartflows-steps', [ 'flow_id' => $seed['flow'], 'step_ids' => [ $b, $a, $c ] ]));
        $this->assertSame([ $b, $a, $c ], $this->order($seed['flow']));
        $this->assertSame('checkout', get_post_meta($seed['flow'], 'wcf-steps', true)[0]['type']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame([ $a, $b, $c ], $this->order($seed['flow']));
    }

    public function test_set_step_status_switches_a_step_off_and_rollback_switches_it_back(): void
    {
        $seed = $this->seed();
        $step = $seed['steps'][0];

        $this->assertSame('no_change', $this->code($this->write('set-cartflows-step-status', [ 'step_id' => $step, 'enabled' => true ])));
        $out = $this->ok($this->write('set-cartflows-step-status', [ 'step_id' => $step, 'enabled' => false ]));
        $this->assertSame('yes', get_post_meta($step, 'wcf-disable-step', true));
        $this->assertFalse($out['result']['step']['enabled']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertFalse(metadata_exists('post', $step, 'wcf-disable-step'));

        $this->assertSame('step_not_found', $this->code($this->write('set-cartflows-step-status', [ 'step_id' => $seed['flow'], 'enabled' => false ])));
    }

    public function test_set_checkout_products_stores_cartflows_rows_and_rollback_restores_the_old_list(): void
    {
        if (! function_exists('wc_get_product')) {
            $this->markTestSkipped('Needs WooCommerce.');
        }
        $seed     = $this->seed();
        $checkout = $seed['steps'][1];
        $product  = new \WC_Product_Simple();
        $product->set_name('Kit');
        $product->set_regular_price('20');
        $product->set_status('publish');
        $pid = $product->save();

        $this->assertSame('not_a_checkout_step', $this->code($this->write('set-cartflows-checkout-products', [ 'step_id' => $seed['steps'][0], 'products' => [ [ 'product_id' => $pid ] ] ])));
        $this->assertSame('product_not_found', $this->code($this->write('set-cartflows-checkout-products', [ 'step_id' => $checkout, 'products' => [ [ 'product_id' => $seed['flow'] ] ] ])));

        $out  = $this->ok($this->write('set-cartflows-checkout-products', [ 'step_id' => $checkout, 'products' => [ [ 'product_id' => $pid, 'quantity' => 2, 'discount_type' => 'discount_percent', 'discount_value' => 15 ] ] ]));
        $rows = get_post_meta($checkout, 'wcf-checkout-products', true);
        $this->assertSame((string) $pid, $rows[0]['product']);
        $this->assertSame('2', $rows[0]['quantity']);
        $this->assertSame('discount_percent', $rows[0]['discount_type']);
        $this->assertSame('15', $rows[0]['discount_value']);
        $this->assertSame('yes', $rows[0]['add_to_cart']);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{8}$/', $rows[0]['unique_id']);
        $this->assertSame('Kit', $out['result']['step']['checkout_products'][0]['name']);

        $again = $this->ok($this->write('set-cartflows-checkout-products', [ 'step_id' => $checkout, 'products' => [ [ 'product_id' => $pid, 'quantity' => 3 ] ] ]));
        $this->assertSame($rows[0]['unique_id'], get_post_meta($checkout, 'wcf-checkout-products', true)[0]['unique_id'], 'a product kept on the checkout keeps its row id');

        $cleared = $this->ok($this->write('set-cartflows-checkout-products', [ 'step_id' => $checkout, 'products' => [] ]));
        $this->assertFalse(metadata_exists('post', $checkout, 'wcf-checkout-products'));

        $this->assertTrue(Rollback_Service::restore_operation((string) $cleared['operation_id']));
        $this->assertTrue(Rollback_Service::restore_operation((string) $again['operation_id']));
        $this->assertSame('2', get_post_meta($checkout, 'wcf-checkout-products', true)[0]['quantity']);
        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertFalse(metadata_exists('post', $checkout, 'wcf-checkout-products'));
    }
}
