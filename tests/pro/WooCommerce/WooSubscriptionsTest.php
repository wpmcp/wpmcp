<?php

namespace WPMCP\Tests\Pro\WooCommerce;

use WPMCP\Governance\Governance;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Safety\Wc_Subscription_Snapshot;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\WooCommerce\Catalog\Op_Catalog;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Ops;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Read;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Write;

require_once __DIR__ . '/../../support/wc-subscriptions-double.php';

/**
 * WooCommerce Subscriptions (issue #658) as ops on the existing woo-read and
 * woo-write dispatchers, against the stand-in in
 * tests/support/wc-subscriptions-double.php (the live leg,
 * WooSubscriptionsLiveTest, runs them on the real plugin).
 *
 * Reads return a curated view; every write snapshots the whole subscription
 * and is undone here; a status change needs confirm:true; cancel is
 * destructive (off by default, confirm:true) and reported not recoverable.
 * Without the plugin the ops are left out of woo-ops and refused with
 * plugin_missing.
 */
class WooSubscriptionsTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists('WooCommerce')) {
            $this->markTestSkipped('WooCommerce is not active in this environment.');
        }
        if (class_exists('WC_Subscriptions')) {
            $this->markTestSkipped('The real WooCommerce Subscriptions is loaded; WooSubscriptionsLiveTest covers it.');
        }

        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        Governance::reset_for_tests();

        \WC_Install::create_roles();
        $GLOBALS['wp_roles'] = null;
        wp_roles();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        update_option('woocommerce_calc_taxes', 'no');
        wpmcp_test_wc_subscriptions_double();
    }

    protected function tearDown(): void
    {
        wpmcp_test_wc_subscriptions_reset();
        remove_all_filters('wpmcp_woo_op_enabled');
        Governance::reset_for_tests();
        wp_set_current_user(0);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    // ------------------------------------------------------------ fixtures

    private function product(string $price = '10.00', string $name = 'Coffee box'): int
    {
        $product = new \WC_Product_Simple();
        $product->set_name($name);
        $product->set_regular_price($price);
        return (int) $product->save();
    }

    /** A subscription product as Subscriptions stores one: product_type subscription and its _subscription_* meta. */
    private function subscription_product(): int
    {
        $id = $this->product('12.00', 'Monthly beans');
        wp_set_object_terms($id, 'subscription', 'product_type');
        foreach (['_subscription_price' => '12', '_subscription_period' => 'month', '_subscription_period_interval' => '1', '_subscription_length' => '0', '_subscription_trial_length' => '7', '_subscription_trial_period' => 'day', '_subscription_sign_up_fee' => '5'] as $key => $value) {
            update_post_meta($id, $key, $value);
        }
        return $id;
    }

    private function subscription(array $args = []): \WC_Order
    {
        return wpmcp_test_wc_subscription($args + ['product_id' => $this->product(), 'quantity' => 2]);
    }

    private function read(string $op, array $params = []): array
    {
        return (new Woo_Read())->handle(['op' => $op, 'params' => $params]);
    }

    private function write(string $op, array $params, array $extra = []): array
    {
        return (new Woo_Write())->handle(['op' => $op, 'params' => $params] + $extra);
    }

    /** Everything a subscription write can change, read back fresh. */
    private function state(int $id): array
    {
        $sub = Wc_Subscription_Snapshot::subscription($id);
        $this->assertNotNull($sub);

        $items = [];
        foreach ($sub->get_items(['line_item', 'shipping', 'fee']) as $item_id => $item) {
            $items[ $item_id ] = [$item->get_type(), $item->get_name(), $item->get_quantity(), (string) $item->get_total()];
        }
        ksort($items);
        $dates = [];
        foreach (['start', 'trial_end', 'next_payment', 'cancelled', 'end'] as $type) {
            $dates[ $type ] = Wc_Subscription_Snapshot::date($sub, $type);
        }

        return [
            'status'     => $sub->get_status(),
            'dates'      => $dates,
            'suspension' => $sub->get_suspension_count(),
            'pre_cancel' => [$sub->get_meta('end_date_pre_cancellation'), $sub->get_meta('trial_end_pre_cancellation')],
            'items'      => $items,
            'total'      => $sub->get_total(),
        ];
    }

    private function snapshot_count(): int
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- counts ledger rows to prove a refusal wrote nothing.
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpmcp_snapshots");
    }

    private function rollback(array $out): void
    {
        $this->assertArrayHasKey('operation_id', $out, wp_json_encode($out));
        $rolled = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled['restored'], wp_json_encode($rolled));
    }

    // ------------------------------------------------------------- catalog

    public function test_the_catalog_carries_subscription_ops_on_the_existing_pair(): void
    {
        $ops = Op_Catalog::ops();

        foreach (['subscriptions.list', 'subscriptions.get', 'subscriptions.products', 'subscriptions.renewal-orders'] as $op) {
            $this->assertSame('read', $ops[ $op ]['mode'], $op);
            $this->assertSame('woocommerce-subscriptions', $ops[ $op ]['plugin'], $op);
        }
        foreach (['subscriptions.update-status', 'subscriptions.update-next-payment', 'subscriptions.update-items'] as $op) {
            $this->assertSame('write', $ops[ $op ]['mode'], $op);
            $this->assertSame('wc_subscription', $ops[ $op ]['snapshot']['type'], $op);
            $this->assertTrue($ops[ $op ]['recoverable'], $op);
            $this->assertSame('manage_woocommerce', $ops[ $op ]['capability'], $op);
        }
        $this->assertSame('destructive', $ops['subscriptions.cancel']['mode']);
        $this->assertSame('wc_subscription', $ops['subscriptions.cancel']['snapshot']['type']);
        $this->assertFalse($ops['subscriptions.cancel']['recoverable']);
        $this->assertSame('edit_shop_orders', $ops['subscriptions.get']['capability']);
        $this->assertContains(Wc_Subscription_Snapshot::TYPE, Rollback_Service::restorable_object_types());
    }

    public function test_without_the_plugin_the_ops_are_hidden_and_refused_with_plugin_missing(): void
    {
        $listed = (new Woo_Ops())->handle([]);
        $this->assertArrayHasKey('subscriptions', $listed['domains']);
        $this->assertSame('WooCommerce Subscriptions', $listed['domains']['subscriptions'][0]['requires']);

        wpmcp_test_wc_subscriptions_reset();

        $listed = (new Woo_Ops())->handle([]);
        $this->assertArrayNotHasKey('subscriptions', $listed['domains']);
        $this->assertArrayHasKey('orders', $listed['domains']);

        $before = $this->snapshot_count();
        foreach ([[ 'read', 'subscriptions.list', [] ], [ 'write', 'subscriptions.update-next-payment', ['id' => 5, 'next_payment' => '2099-01-01 00:00:00'] ]] as [$kind, $op, $params]) {
            $out = 'read' === $kind ? $this->read($op, $params) : $this->write($op, $params);
            $this->assertSame('plugin_missing', $out['error']['code'] ?? null, $op);
            $this->assertStringContainsString('Install and activate WooCommerce Subscriptions', $out['error']['message']);
            $this->assertSame(admin_url('plugins.php'), $out['error']['data']['plugins_url']);
        }
        $this->assertSame($before, $this->snapshot_count());
    }

    // --------------------------------------------------------------- reads

    public function test_list_get_and_renewal_orders_return_a_curated_view(): void
    {
        $customer = self::factory()->user->create(['role' => 'customer']);
        $sub      = $this->subscription(['customer_id' => $customer]);
        $this->subscription(['status' => 'on-hold']);

        $renewal = wc_create_order();
        $renewal->set_total('20.00');
        $renewal->save();
        $sub->update_meta_data('_test_related_renewal', [$renewal->get_id()]);
        $sub->save();

        $all = $this->read('subscriptions.list');
        $this->assertSame(200, $all['status'], wp_json_encode($all));
        $this->assertCount(2, $all['body']['subscriptions']);

        $active = $this->read('subscriptions.list', ['status' => 'active', 'customer_id' => $customer]);
        $this->assertSame([$sub->get_id()], array_column($active['body']['subscriptions'], 'id'));
        $row = $active['body']['subscriptions'][0];
        $this->assertSame('month', $row['billing_period']);
        $this->assertSame(1, $row['billing_interval']);
        $this->assertSame(2, $row['line_items'][0]['quantity']);

        $bad = $this->read('subscriptions.list', ['status' => 'shipped']);
        $this->assertSame('invalid_params', $bad['error']['code'] ?? null);

        $one = $this->read('subscriptions.get', ['id' => $sub->get_id()]);
        $this->assertSame(200, $one['status'], wp_json_encode($one));
        $this->assertSame('active', $one['body']['status']);
        $this->assertSame(1, $one['body']['related_orders']['renewal']);
        $this->assertNotSame(0, $one['body']['dates']['next_payment']);
        $this->assertArrayNotHasKey('meta_data', $one['body']);

        $orders = $this->read('subscriptions.renewal-orders', ['id' => $sub->get_id()]);
        $this->assertSame([$renewal->get_id()], array_column($orders['body']['orders'], 'id'));
        $this->assertSame('renewal', $orders['body']['orders'][0]['relation']);

        $missing = $this->read('subscriptions.get', ['id' => $renewal->get_id()]);
        $this->assertSame('unknown_subscription', $missing['error']['code'] ?? null, 'A plain order is not a subscription');
    }

    public function test_products_lists_subscription_products_with_their_terms(): void
    {
        $plan = $this->subscription_product();
        $this->product('3.00', 'Plain mug');

        $out = $this->read('subscriptions.products');
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $this->assertSame([$plan], array_column($out['body']['products'], 'id'));
        $row = $out['body']['products'][0];
        $this->assertSame('subscription', $row['type']);
        $this->assertSame('12', $row['price']);
        $this->assertSame('month', $row['period']);
        $this->assertSame(7, $row['trial_length']);
        $this->assertSame('5', $row['sign_up_fee']);
    }

    // ------------------------------------------------------------- status

    public function test_a_status_change_needs_confirm_and_rolls_back_exactly(): void
    {
        $sub    = $this->subscription();
        $id     = $sub->get_id();
        $before = $this->state($id);

        $unconfirmed = $this->write('subscriptions.update-status', ['id' => $id, 'status' => 'on-hold']);
        $this->assertSame('confirmation_required', $unconfirmed['error']['code'] ?? null);
        $this->assertStringContainsString('email', $unconfirmed['error']['message']);
        $this->assertSame($before, $this->state($id));

        $out = $this->write('subscriptions.update-status', ['id' => $id, 'status' => 'on-hold', 'note' => 'Paused on request'], ['confirm' => true]);
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        $this->assertArrayHasKey('side_effects', $out);
        $this->assertSame('on-hold', $out['body']['status']);
        $this->assertSame(1, $this->state($id)['suspension']);

        $this->rollback($out);
        $this->assertSame($before, $this->state($id));
    }

    public function test_status_changes_follow_the_plugins_rules_and_refusals_write_nothing(): void
    {
        $sub       = $this->subscription();
        $id        = $sub->get_id();
        $before    = $this->state($id);
        $snapshots = $this->snapshot_count();

        foreach ([
            'cancel is its own op' => [['id' => $id, 'status' => 'cancelled'], 'invalid_params'],
            'already active'       => [['id' => $id, 'status' => 'active'], 'invalid_params'],
            'unknown field'        => [['id' => $id, 'status' => 'on-hold', 'customer_id' => 1], 'invalid_params'],
            'no subscription'      => [['id' => 999999, 'status' => 'on-hold'], 'unknown_subscription'],
        ] as $label => [$params, $code]) {
            $out = $this->write('subscriptions.update-status', $params, ['confirm' => true]);
            $this->assertSame($code, $out['error']['code'] ?? null, $label);
        }

        \WPMCP_Test_Subscription::$supports['subscription_suspension'] = false;
        $out = $this->write('subscriptions.update-status', ['id' => $id, 'status' => 'on-hold'], ['confirm' => true]);
        $this->assertSame('status_not_allowed', $out['error']['code'] ?? null);

        $this->assertSame($before, $this->state($id));
        $this->assertSame($snapshots, $this->snapshot_count());
    }

    // ------------------------------------------------------- next payment

    public function test_moving_the_next_payment_date_rolls_back_exactly(): void
    {
        $sub    = $this->subscription();
        $id     = $sub->get_id();
        $before = $this->state($id);
        $when   = gmdate('Y-m-d H:i:s', time() + 20 * DAY_IN_SECONDS);

        $out = $this->write('subscriptions.update-next-payment', ['id' => $id, 'next_payment' => $when]);
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $this->assertSame($when, $out['body']['dates']['next_payment']);
        $this->assertSame($before['dates']['next_payment'], $out['previous_next_payment']);

        $this->rollback($out);
        $this->assertSame($before, $this->state($id));
    }

    public function test_next_payment_refuses_bad_past_and_unchangeable_dates(): void
    {
        $sub       = $this->subscription(['dates' => ['end' => gmdate('Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS)]]);
        $id        = $sub->get_id();
        $before    = $this->state($id);
        $snapshots = $this->snapshot_count();

        foreach ([
            'not a date'      => ['next week', 'invalid_params'],
            'in the past'     => [gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS), 'invalid_params'],
            'after the end'   => [gmdate('Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS), 'invalid_params'],
        ] as $label => [$value, $code]) {
            $out = $this->write('subscriptions.update-next-payment', ['id' => $id, 'next_payment' => $value]);
            $this->assertSame($code, $out['error']['code'] ?? null, $label);
        }

        \WPMCP_Test_Subscription::$supports['subscription_date_changes'] = false;
        $out = $this->write('subscriptions.update-next-payment', ['id' => $id, 'next_payment' => gmdate('Y-m-d H:i:s', time() + 2 * DAY_IN_SECONDS)]);
        $this->assertSame('date_not_updatable', $out['error']['code'] ?? null);

        $this->assertSame($before, $this->state($id));
        $this->assertSame($snapshots, $this->snapshot_count());
    }

    // -------------------------------------------------------------- items

    public function test_editing_items_recalculates_and_rolls_back_exactly(): void
    {
        $sub    = $this->subscription();
        $id     = $sub->get_id();
        $before = $this->state($id);
        $line   = array_values($sub->get_items())[0];

        $out = $this->write('subscriptions.update-items', [
            'id'         => $id,
            'line_items' => [
                ['id' => $line->get_id(), 'quantity' => 5],
                ['product_id' => $this->product('3.00', 'Filter papers'), 'quantity' => 1],
            ],
        ]);
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        $this->assertEqualsWithDelta(53.0, (float) $out['body']['totals']['total'], 0.001);
        $this->assertCount(2, $out['body']['line_items']);

        $this->rollback($out);
        $this->assertSame($before, $this->state($id));
    }

    public function test_items_are_refused_when_the_gateway_cannot_change_amounts_or_the_subscription_ended(): void
    {
        $sub  = $this->subscription();
        $line = array_values($sub->get_items())[0];

        \WPMCP_Test_Subscription::$supports['subscription_amount_changes'] = false;
        $out = $this->write('subscriptions.update-items', ['id' => $sub->get_id(), 'line_items' => [['id' => $line->get_id(), 'quantity' => 3]]]);
        $this->assertSame('amount_changes_unsupported', $out['error']['code'] ?? null);

        \WPMCP_Test_Subscription::$supports = [];
        $ended = $this->subscription(['status' => 'cancelled']);
        $out   = $this->write('subscriptions.update-items', ['id' => $ended->get_id(), 'line_items' => [['id' => array_values($ended->get_items())[0]->get_id(), 'quantity' => 3]]]);
        $this->assertSame('subscription_ended', $out['error']['code'] ?? null);

        $out = $this->write('subscriptions.update-items', ['id' => $sub->get_id(), 'billing' => ['city' => 'Rome']]);
        $this->assertSame('invalid_params', $out['error']['code'] ?? null);
    }

    // ------------------------------------------------------------- cancel

    public function test_cancel_is_off_by_default_and_needs_confirm(): void
    {
        $sub    = $this->subscription();
        $before = $this->state($sub->get_id());

        $off = $this->write('subscriptions.cancel', ['id' => $sub->get_id()], ['confirm' => true]);
        $this->assertSame('operation_disabled', $off['error']['code'] ?? null);

        add_filter('wpmcp_woo_op_enabled', static fn ($on, $op) => 'subscriptions.cancel' === $op ? true : $on, 10, 2);
        $unconfirmed = $this->write('subscriptions.cancel', ['id' => $sub->get_id()]);
        $this->assertSame('confirmation_required', $unconfirmed['error']['code'] ?? null);

        $this->assertSame($before, $this->state($sub->get_id()));
    }

    public function test_cancel_at_end_of_term_or_now_is_not_recoverable_but_rolls_back_locally(): void
    {
        add_filter('wpmcp_woo_op_enabled', static fn ($on, $op) => 'subscriptions.cancel' === $op ? true : $on, 10, 2);

        foreach ([false, true] as $now) {
            $sub    = $this->subscription();
            $id     = $sub->get_id();
            $before = $this->state($id);

            $out = $this->write('subscriptions.cancel', ['id' => $id, 'immediately' => $now], ['confirm' => true]);
            $this->assertSame(200, $out['status'], wp_json_encode($out));
            $this->assertFalse($out['recoverable']);
            $this->assertStringContainsString('gateway', $out['side_effects']);
            $after = $this->state($id);
            $this->assertSame($now ? 'cancelled' : 'pending-cancel', $after['status']);
            $this->assertSame(0, $after['dates']['next_payment']);

            $this->rollback($out);
            $this->assertSame($before, $this->state($id), $now ? 'immediately' : 'end of term');
        }
    }

    // ------------------------------------------------------------- access

    public function test_reads_take_the_order_capability_and_writes_the_store_one(): void
    {
        $sub  = $this->subscription();
        $role = add_role('order_clerk', 'Order clerk', ['read' => true, 'edit_shop_orders' => true, 'edit_others_shop_orders' => true]);
        $this->assertNotNull($role);
        wp_set_current_user(self::factory()->user->create(['role' => 'order_clerk']));

        $read  = $this->read('subscriptions.get', ['id' => $sub->get_id()]);
        $write = $this->write('subscriptions.update-next-payment', ['id' => $sub->get_id(), 'next_payment' => gmdate('Y-m-d H:i:s', time() + 9 * DAY_IN_SECONDS)]);
        remove_role('order_clerk');

        $this->assertSame(200, $read['status'] ?? null, wp_json_encode($read));
        $this->assertSame('operation_denied', $write['error']['code'] ?? null);
        $this->assertSame('capability', $write['error']['data']['reason'] ?? null);
    }

    public function test_a_subscription_snapshot_is_restorable_only_by_a_store_manager(): void
    {
        $sub    = $this->subscription();
        $before = $this->state($sub->get_id());
        $out    = $this->write('subscriptions.update-next-payment', ['id' => $sub->get_id(), 'next_payment' => gmdate('Y-m-d H:i:s', time() + 12 * DAY_IN_SECONDS)]);
        $this->assertSame(200, $out['status']);

        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $refused = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertFalse($refused['restored']);

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->rollback($out);
        $this->assertSame($before, $this->state($sub->get_id()));
    }
}
