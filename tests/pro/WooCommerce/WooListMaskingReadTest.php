<?php

namespace WPMCP\Tests\Pro\WooCommerce;

use WPMCP\Governance\Governance;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Read;

/**
 * Through the real Woo_Read dispatcher: the raw order and customer lists come
 * back masked with masked:true, and the single-record reads stay full for an
 * administrator (an agent gets one order's full address from orders.get).
 */
class WooListMaskingReadTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists('WooCommerce')) {
            $this->markTestSkipped('WooCommerce is not active in this environment.');
        }
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        Governance::reset_for_tests();
        \WC_Install::create_roles();
        $GLOBALS['wp_roles'] = null;
        wp_roles();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        global $wp_rest_server;
        $wp_rest_server = null;
        rest_get_server();
    }

    protected function tearDown(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;
        Governance::reset_for_tests();
        wp_set_current_user(0);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function order(): \WC_Order
    {
        $order = wc_create_order();
        $order->set_billing_first_name('Jane');
        $order->set_billing_last_name('Doe');
        $order->set_billing_email('jane@example.com');
        $order->set_billing_address_1('1 Main St');
        $order->set_shipping_address_1('2 Side St');
        $order->set_customer_note('Leave at the back door');
        $order->add_order_note('Emailed jane@example.com');
        $order->save();
        return $order;
    }

    private function read(string $op, array $params = []): array
    {
        return (new Woo_Read())->handle(['op' => $op, 'params' => $params]);
    }

    public function test_orders_list_is_masked_and_orders_get_is_full(): void
    {
        $order = $this->order();

        $list = $this->read('orders.list');
        $this->assertSame(200, $list['status'], wp_json_encode($list));
        $this->assertTrue($list['masked']);
        $row = array_column($list['body'], null, 'id')[ $order->get_id() ];
        $this->assertSame('j***@example.com', $row['billing']['email']);
        $this->assertSame('J***', $row['billing']['first_name']);
        $this->assertSame('1***', $row['billing']['address_1']);
        $this->assertSame('L***', $row['customer_note']);
        $this->assertSame((string) $order->get_id(), (string) $row['number']);
        $this->assertStringNotContainsString('jane@example.com', wp_json_encode($list));

        $one = $this->read('orders.get', ['id' => $order->get_id()]);
        $this->assertArrayNotHasKey('masked', $one);
        $this->assertSame('jane@example.com', $one['body']['billing']['email']);
        $this->assertSame('2 Side St', $one['body']['shipping']['address_1']);
    }

    /** Issue #943: a refund's reason inside orders.list is masked like refunds.list; the single reads stay full. */
    public function test_orders_list_masks_refund_reasons_and_the_single_reads_stay_full(): void
    {
        $reason = 'Customer phoned from +1 555 0177, mug chipped';
        $order  = $this->order();
        $order->set_total('20.00');
        $order->save();
        $refund = wc_create_refund(['order_id' => $order->get_id(), 'amount' => '5.00', 'reason' => $reason]);
        $this->assertInstanceOf(\WC_Order_Refund::class, $refund);

        $list = $this->read('orders.list', ['per_page' => 5]);
        $this->assertSame(200, $list['status'], wp_json_encode($list));
        $this->assertTrue($list['masked']);
        $row = array_column($list['body'], null, 'id')[ $order->get_id() ];
        $this->assertCount(1, $row['refunds']);
        $this->assertSame($refund->get_id(), $row['refunds'][0]['id']);
        $this->assertSame('-5.00', (string) $row['refunds'][0]['total']);
        $this->assertSame('C***', $row['refunds'][0]['reason']);
        $this->assertStringNotContainsString('555 0177', wp_json_encode($list));

        $one = $this->read('orders.get', ['id' => $order->get_id()]);
        $this->assertArrayNotHasKey('masked', $one);
        $this->assertSame($reason, $one['body']['refunds'][0]['reason']);

        $single = $this->read('refunds.get', ['order_id' => $order->get_id(), 'id' => $refund->get_id()]);
        $this->assertSame(200, $single['status'], wp_json_encode($single));
        $this->assertArrayNotHasKey('masked', $single);
        $this->assertSame($reason, $single['body']['reason']);
    }

    public function test_order_notes_are_masked(): void
    {
        $order = $this->order();
        $out   = $this->read('orders.notes', ['order_id' => $order->get_id()]);
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $this->assertTrue($out['masked']);
        $this->assertStringNotContainsString('jane@example.com', wp_json_encode($out));
        $this->assertNotEmpty($out['body'][0]['id']);
    }

    public function test_customers_list_is_masked_and_customers_get_is_full(): void
    {
        $id = self::factory()->user->create(['role' => 'customer', 'user_email' => 'jane@example.com', 'user_login' => 'janedoe']);
        update_user_meta($id, 'first_name', 'Jane');

        $list = $this->read('customers.list', ['role' => 'customer']);
        $this->assertSame(200, $list['status'], wp_json_encode($list));
        $this->assertTrue($list['masked']);
        $row = array_column($list['body'], null, 'id')[ $id ];
        $this->assertSame('j***@example.com', $row['email']);
        $this->assertSame('J***', $row['first_name']);
        $this->assertStringNotContainsString('jane@example.com', wp_json_encode($list));

        $one = $this->read('customers.get', ['id' => $id]);
        $this->assertSame('jane@example.com', $one['body']['email']);
        $this->assertSame('Jane', $one['body']['first_name']);
    }
}
