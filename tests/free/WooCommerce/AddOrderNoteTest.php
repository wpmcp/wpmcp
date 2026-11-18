<?php

namespace WPMCP\Tests\Free\WooCommerce;

use WPMCP\Tools\WooCommerce\Add_Order_Note;

class AddOrderNoteTest extends \WP_UnitTestCase
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
        $id = $order->save();
        $this->created[] = $id;
        return $id;
    }

    public function test_adds_a_note(): void
    {
        if (! wpmcp_woocommerce_active()) {
            $this->markTestSkipped('WooCommerce not active');
        }

        $id  = $this->order();
        $out = (new Add_Order_Note())->handle(['id' => $id, 'note' => 'Packed and ready.']);

        $this->assertArrayHasKey('note_id', $out);
        $this->assertGreaterThan(0, $out['note_id']);

        $notes    = wc_get_order_notes(['order_id' => $id]);
        $contents = array_map(static fn($n) => $n->content, $notes);
        $this->assertContains('Packed and ready.', $contents);
    }

    public function test_requires_note_text(): void
    {
        if (! wpmcp_woocommerce_active()) {
            $this->markTestSkipped('WooCommerce not active');
        }

        $id = $this->order();
        $this->expectException(\InvalidArgumentException::class);
        (new Add_Order_Note())->handle(['id' => $id, 'note' => '']);
    }

    public function test_missing_order_throws(): void
    {
        if (! wpmcp_woocommerce_active()) {
            $this->markTestSkipped('WooCommerce not active');
        }

        $this->expectException(\RuntimeException::class);
        (new Add_Order_Note())->handle(['id' => 999999, 'note' => 'hi']);
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
        (new Add_Order_Note())->handle(['id' => $refund->get_id(), 'note' => 'Hello']);
    }
}
