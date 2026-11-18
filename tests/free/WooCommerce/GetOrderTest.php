<?php

namespace WPMCP\Tests\Free\WooCommerce;

use WPMCP\Tools\WooCommerce\Get_Order;

class GetOrderTest extends \WP_UnitTestCase
{
    private array $created = [];

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            $order = wc_get_order($id);
            if ($order) {
                $order->delete(true);
            }
        }
        $this->created = [];
        parent::tearDown();
    }

    private function order(): int
    {
        $order = wc_create_order();
        $order->set_status('processing');
        $order->set_billing_email('shopper@example.com');
        $id = $order->save();
        $this->created[] = $id;
        return $id;
    }

    public function test_returns_order_detail(): void
    {
        if (! wpmcp_woocommerce_active()) {
            $this->markTestSkipped('WooCommerce not active');
        }

        $id  = $this->order();
        $out = (new Get_Order())->handle(['id' => $id]);

        $this->assertSame($id, $out['id']);
        $this->assertSame('processing', $out['status']);
        $this->assertSame('shopper@example.com', $out['billing_email']);
        $this->assertArrayHasKey('items', $out);
    }

    public function test_missing_order_throws(): void
    {
        if (! wpmcp_woocommerce_active()) {
            $this->markTestSkipped('WooCommerce not active');
        }

        $this->expectException(\RuntimeException::class);
        (new Get_Order())->handle(['id' => 999999]);
    }

    public function test_requires_id(): void
    {
        if (! wpmcp_woocommerce_active()) {
            $this->markTestSkipped('WooCommerce not active');
        }

        $this->expectException(\InvalidArgumentException::class);
        (new Get_Order())->handle([]);
    }

    /** #942: a refund id is not an order id; refuse it instead of a TypeError. */
    public function test_refund_id_is_not_an_order(): void
    {
        if (! wpmcp_woocommerce_active()) {
            $this->markTestSkipped('WooCommerce not active');
        }

        $order = wc_create_order();
        $order->set_status('completed');
        $order->set_total('10.00');
        $order_id        = $order->save();
        $this->created[] = $order_id;
        $refund          = wc_create_refund([ 'order_id' => $order_id, 'amount' => '4.00' ]);
        $this->assertInstanceOf(\WC_Order_Refund::class, $refund);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Order not found.');
        (new Get_Order())->handle(['id' => $refund->get_id()]);
    }
}
