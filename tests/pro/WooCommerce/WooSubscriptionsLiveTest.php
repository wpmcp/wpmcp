<?php

namespace WPMCP\Tests\Pro\WooCommerce;

use WPMCP\Governance\Governance;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Safety\Wc_Subscription_Snapshot;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Ops;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Read;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Write;

/**
 * Issue #658: the subscriptions.* ops against the real WooCommerce
 * Subscriptions.
 *
 * It runs only in the local gate's live Subscriptions leg
 * (bin/test-local.sh --live-wc-subscriptions), which sets
 * WPMCP_LIVE_WC_SUBSCRIPTIONS=1, copies the plugin from
 * WPMCP_WC_SUBSCRIPTIONS_SRC onto a separate WordPress install beside
 * WooCommerce (Subscriptions is a commercial plugin, not on wordpress.org)
 * and loads it in tests/bootstrap.php. Everywhere else it is skipped; the
 * leg runs with --fail-on-skipped so it cannot pass vacuously.
 *
 * Subscriptions are made with the plugin's own wcs_create_subscription(),
 * every write goes through its own API, and every rollback must put the
 * subscription back as Subscriptions reads it, its scheduled renewal action
 * included.
 *
 * @group wc-subscriptions-live
 */
class WooSubscriptionsLiveTest extends \WP_UnitTestCase
{
    private const RENEWAL_HOOK = 'woocommerce_scheduled_subscription_payment';

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists('WC_Subscriptions') || ! function_exists('wcs_create_subscription')) {
            $this->markTestSkipped('Needs the real WooCommerce Subscriptions (bin/test-local.sh --live-wc-subscriptions, WPMCP_WC_SUBSCRIPTIONS_SRC).');
        }
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        Governance::reset_for_tests();
        \WC_Install::create_roles();
        $GLOBALS['wp_roles'] = null;
        wp_roles();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        update_option('woocommerce_calc_taxes', 'no');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_woo_op_enabled');
        Governance::reset_for_tests();
        wp_set_current_user(0);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function product(string $price, string $name): int
    {
        $product = new \WC_Product_Simple();
        $product->set_name($name);
        $product->set_regular_price($price);
        return (int) $product->save();
    }

    /** An active monthly subscription made by Subscriptions itself, manual renewals. */
    private function subscription(): \WC_Subscription
    {
        $customer = self::factory()->user->create(['role' => 'customer']);
        $sub      = wcs_create_subscription([
            'customer_id'      => $customer,
            'billing_period'   => 'month',
            'billing_interval' => 1,
            'start_date'       => gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS),
        ]);
        $this->assertInstanceOf(\WC_Subscription::class, $sub, is_wp_error($sub) ? $sub->get_error_message() : '');
        $sub->add_product(wc_get_product($this->product('10.00', 'Coffee box')), 2);
        $sub->set_address(['first_name' => 'Ada', 'city' => 'London', 'email' => 'ada@example.com'], 'billing');
        $sub->set_requires_manual_renewal(true);
        $sub->calculate_totals();
        $sub->save();
        $sub->update_dates(['next_payment' => gmdate('Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS)]);
        $sub->update_status('active');

        return wcs_get_subscription($sub->get_id());
    }

    private function write(string $op, array $params, array $extra = []): array
    {
        return (new Woo_Write())->handle(['op' => $op, 'params' => $params] + $extra);
    }

    /** The subscription as Subscriptions reads it, and its scheduled renewal. */
    private function state(int $id): array
    {
        wp_cache_flush();
        $sub   = wcs_get_subscription($id);
        $items = [];
        foreach ($sub->get_items() as $item_id => $item) {
            $items[ $item_id ] = [$item->get_name(), $item->get_quantity(), (string) $item->get_total()];
        }
        ksort($items);
        $dates = [];
        foreach (['start', 'trial_end', 'next_payment', 'cancelled', 'end'] as $type) {
            $dates[ $type ] = $sub->get_date($type);
        }
        $next = as_next_scheduled_action(self::RENEWAL_HOOK, ['subscription_id' => $id]);

        return [
            'status'     => $sub->get_status(),
            'dates'      => $dates,
            'suspension' => (int) $sub->get_suspension_count(),
            'items'      => $items,
            'total'      => $sub->get_total(),
            'renewal_at' => is_int($next) ? gmdate('Y-m-d H:i:s', $next) : $next,
        ];
    }

    private function rollback(array $out): void
    {
        $this->assertArrayHasKey('operation_id', $out, wp_json_encode($out));
        $rolled = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled['restored'], wp_json_encode($rolled));
    }

    public function test_the_plugin_is_detected_and_reads_see_real_subscriptions(): void
    {
        $this->assertTrue(Wc_Subscription_Snapshot::is_active());
        $listed = (new Woo_Ops())->handle(['domain' => 'subscriptions']);
        $this->assertCount(8, $listed['domains']['subscriptions']);

        // Each row's route template names a route the plugin registers.
        $routes = rest_get_server()->get_routes();
        foreach (['/wc/v3/subscriptions', '/wc/v3/subscriptions/(?P<id>[\d]+)', '/wc/v3/subscriptions/(?P<id>[\d]+)/orders'] as $route) {
            $this->assertArrayHasKey($route, $routes, $route);
        }

        $sub     = $this->subscription();
        $renewal = wcs_create_renewal_order($sub);
        $this->assertInstanceOf(\WC_Order::class, $renewal);

        $plan = new \WC_Product_Subscription();
        $plan->set_name('Monthly beans');
        $plan->set_regular_price('12');
        $plan->update_meta_data('_subscription_price', '12');
        $plan->update_meta_data('_subscription_period', 'month');
        $plan->update_meta_data('_subscription_period_interval', '1');
        $plan_id = $plan->save();

        $read = new Woo_Read();
        $list = $read->handle(['op' => 'subscriptions.list', 'params' => ['status' => 'active']]);
        $this->assertContains($sub->get_id(), array_column($list['body']['subscriptions'], 'id'), wp_json_encode($list));

        $one = $read->handle(['op' => 'subscriptions.get', 'params' => ['id' => $sub->get_id()]]);
        $this->assertSame('active', $one['body']['status']);
        $this->assertSame('month', $one['body']['billing_period']);
        $this->assertSame(1, $one['body']['related_orders']['renewal']);
        $this->assertArrayNotHasKey('meta_data', $one['body']);

        $orders = $read->handle(['op' => 'subscriptions.renewal-orders', 'params' => ['id' => $sub->get_id()]]);
        $this->assertSame([$renewal->get_id()], array_column($orders['body']['orders'], 'id'));

        $products = $read->handle(['op' => 'subscriptions.products']);
        $row      = array_values(array_filter($products['body']['products'], static fn ($p) => $p['id'] === $plan_id))[0] ?? null;
        $this->assertNotNull($row, wp_json_encode($products));
        $this->assertSame('subscription', $row['type']);
        $this->assertSame('month', $row['period']);
    }

    public function test_a_status_change_goes_through_the_plugin_and_rolls_back_with_its_renewal(): void
    {
        $sub    = $this->subscription();
        $id     = $sub->get_id();
        $before = $this->state($id);
        $this->assertNotFalse($before['renewal_at'], 'An active subscription has its renewal scheduled');

        $out = $this->write('subscriptions.update-status', ['id' => $id, 'status' => 'on-hold'], ['confirm' => true]);
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $held = $this->state($id);
        $this->assertSame('on-hold', $held['status']);
        $this->assertSame(1, $held['suspension']);
        $this->assertFalse($held['renewal_at'], 'Subscriptions unschedules the renewal of a held subscription');

        $this->rollback($out);
        $this->assertSame($before, $this->state($id));
    }

    public function test_moving_the_next_payment_reschedules_and_rolls_back(): void
    {
        $sub    = $this->subscription();
        $id     = $sub->get_id();
        $before = $this->state($id);
        $when   = gmdate('Y-m-d H:i:s', time() + 20 * DAY_IN_SECONDS);

        $out = $this->write('subscriptions.update-next-payment', ['id' => $id, 'next_payment' => $when]);
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $this->assertSame($when, $this->state($id)['renewal_at']);

        $this->rollback($out);
        $this->assertSame($before, $this->state($id));
    }

    public function test_editing_items_rolls_back_exactly(): void
    {
        $sub    = $this->subscription();
        $id     = $sub->get_id();
        $before = $this->state($id);
        $line   = array_values($sub->get_items())[0];

        $out = $this->write('subscriptions.update-items', ['id' => $id, 'line_items' => [['id' => $line->get_id(), 'quantity' => 5]]]);
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $this->assertEqualsWithDelta(50.0, (float) $this->state($id)['total'], 0.001);

        $this->rollback($out);
        $this->assertSame($before, $this->state($id));
    }

    public function test_cancel_at_end_of_term_and_now_roll_back_to_an_active_scheduled_subscription(): void
    {
        add_filter('wpmcp_woo_op_enabled', static fn ($on, $op) => 'subscriptions.cancel' === $op ? true : $on, 10, 2);

        foreach (['end of term' => false, 'now' => true] as $label => $now) {
            $sub    = $this->subscription();
            $id     = $sub->get_id();
            $before = $this->state($id);

            $out = $this->write('subscriptions.cancel', ['id' => $id, 'immediately' => $now], ['confirm' => true]);
            $this->assertSame(200, $out['status'], $label . ' ' . wp_json_encode($out));
            $this->assertFalse($out['recoverable']);
            $after = $this->state($id);
            $this->assertSame($now ? 'cancelled' : 'pending-cancel', $after['status'], $label);
            $this->assertFalse($after['renewal_at'], $label);

            $this->rollback($out);
            $this->assertSame($before, $this->state($id), $label);
        }
    }
}
