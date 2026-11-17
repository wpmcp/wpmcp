<?php

namespace WPMCP\Tests\Free\WooCommerce;

use WPMCP\Tools\WooCommerce\Update_Order_Status;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Safety\Snapshot_Store;

class UpdateOrderStatusTest extends \WP_UnitTestCase
{
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
    }

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

    private function order(string $status = 'pending'): int
    {
        $order = wc_create_order();
        $order->set_status($status);
        $id = $order->save();
        $this->created[] = $id;
        return $id;
    }

    public function test_changes_order_status(): void
    {
        if (! wpmcp_woocommerce_active()) {
            $this->markTestSkipped('WooCommerce not active');
        }

        $id  = $this->order('pending');
        $out = (new Update_Order_Status())->handle(['id' => $id, 'status' => 'processing']);

        $this->assertSame('processing', $out['status']);
        $this->assertSame('processing', wc_get_order($id)->get_status());
    }

    public function test_status_change_is_recoverable(): void
    {
        if (! wpmcp_woocommerce_active()) {
            $this->markTestSkipped('WooCommerce not active');
        }

        $id  = $this->order('pending');
        $out = (new Update_Order_Status())->handle(['id' => $id, 'status' => 'completed']);

        $this->assertArrayHasKey('operation_id', $out);
        $this->assertTrue($out['recoverable']);
        $this->assertNotNull(Snapshot_Store::get_by_operation($out['operation_id']));
        $this->assertSame('completed', wc_get_order($id)->get_status());

        $rolled_back = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled_back['restored']);

        $this->assertSame('pending', wc_get_order($id)->get_status());
    }

    public function test_missing_order_throws(): void
    {
        if (! wpmcp_woocommerce_active()) {
            $this->markTestSkipped('WooCommerce not active');
        }

        $this->expectException(\RuntimeException::class);
        (new Update_Order_Status())->handle(['id' => 999999, 'status' => 'processing']);
    }

    public function test_unknown_status_throws(): void
    {
        if (! wpmcp_woocommerce_active()) {
            $this->markTestSkipped('WooCommerce not active');
        }

        $id = $this->order('pending');
        $this->expectException(\InvalidArgumentException::class);
        (new Update_Order_Status())->handle(['id' => $id, 'status' => 'not-a-real-status']);
    }

    public function test_requires_id_and_status(): void
    {
        if (! wpmcp_woocommerce_active()) {
            $this->markTestSkipped('WooCommerce not active');
        }

        $this->expectException(\InvalidArgumentException::class);
        (new Update_Order_Status())->handle(['id' => $this->order()]);
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
        (new Update_Order_Status())->handle(['id' => $refund->get_id(), 'status' => 'cancelled']);
    }
}
