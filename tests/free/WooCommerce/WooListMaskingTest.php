<?php

namespace WPMCP\Tests\Free\WooCommerce;

use WPMCP\Tools\WooCommerce\Catalog\List_Masking;

/**
 * The WooCommerce raw list reads (orders.list, orders.notes, refunds.list,
 * customers.list) hide personal data on the server before the body reaches the
 * model. Single-record reads stay full. Ids, statuses, totals, dates and
 * order numbers stay readable.
 */
class WooListMaskingTest extends \WP_UnitTestCase
{
    public function test_the_masked_ops_are_exactly_the_four_raw_lists(): void
    {
        $this->assertSame(['orders.list', 'orders.notes', 'refunds.list', 'customers.list'], List_Masking::OPS);
        $this->assertFalse(List_Masking::applies('orders.get'));
        $this->assertFalse(List_Masking::applies('customers.get'));
        $this->assertFalse(List_Masking::applies('refunds.get'));
        $this->assertFalse(List_Masking::applies('products.list'));
    }

    public function test_an_order_list_masks_addresses_contact_and_free_text(): void
    {
        $body = [[
            'id'                 => 41,
            'number'             => '41',
            'status'             => 'processing',
            'total'              => '25.00',
            'currency'           => 'USD',
            'date_created'       => '2026-11-01T10:00:00',
            'customer_id'        => 7,
            'customer_note'      => 'Leave at the back door',
            'customer_ip_address' => '203.0.113.9',
            'customer_user_agent' => 'Mozilla/5.0',
            'billing'            => ['first_name' => 'Jane', 'last_name' => 'Doe', 'address_1' => '1 Main St', 'city' => 'Leeds', 'postcode' => 'LS1 1AA', 'country' => 'GB', 'email' => 'jane@example.com', 'phone' => '555-0100'],
            'shipping'           => ['first_name' => 'Jane', 'last_name' => 'Doe', 'address_1' => '2 Side St', 'country' => 'GB'],
            'meta_data'          => [['id' => 1, 'key' => 'gift_message', 'value' => 'Love, Bob']],
            'line_items'         => [['id' => 3, 'name' => 'Mug', 'quantity' => 2, 'total' => '25.00']],
        ]];

        $out = List_Masking::apply('orders.list', $body);
        $row = $out[0];

        $this->assertSame(41, $row['id']);
        $this->assertSame('41', $row['number']);
        $this->assertSame('processing', $row['status']);
        $this->assertSame('25.00', $row['total']);
        $this->assertSame('2026-11-01T10:00:00', $row['date_created']);
        $this->assertSame(7, $row['customer_id']);
        $this->assertSame('Mug', $row['line_items'][0]['name']);
        $this->assertSame('j***@example.com', $row['billing']['email']);
        $this->assertSame('J***', $row['billing']['first_name']);
        $this->assertSame('D***', $row['billing']['last_name']);
        $this->assertSame('1***', $row['billing']['address_1']);
        $this->assertSame('5***', $row['billing']['phone']);
        $this->assertSame('GB', $row['billing']['country']);
        $this->assertSame('2***', $row['shipping']['address_1']);
        $this->assertSame('L***', $row['customer_note']);
        $this->assertSame('2***', $row['customer_ip_address']);
        $this->assertSame('M***', $row['customer_user_agent']);
        $this->assertSame('gift_message', $row['meta_data'][0]['key']);
        $this->assertSame('L***', $row['meta_data'][0]['value']);
        $json = wp_json_encode($out);
        foreach (['jane@example.com', '555-0100', 'Leave at the back', '203.0.113.9', 'Love, Bob', '1 Main St'] as $raw) {
            $this->assertStringNotContainsString($raw, $json);
        }
    }

    public function test_order_notes_and_refunds_mask_free_text_but_keep_ids_and_dates(): void
    {
        $notes = List_Masking::apply('orders.notes', [
            ['id' => 5, 'author' => 'jane@example.com', 'date_created' => '2026-11-01T10:00:00', 'note' => 'Emailed jane@example.com', 'customer_note' => true],
        ]);
        $this->assertSame(5, $notes[0]['id']);
        $this->assertSame('2026-11-01T10:00:00', $notes[0]['date_created']);
        $this->assertTrue($notes[0]['customer_note']);
        $this->assertSame('E***', $notes[0]['note']);
        $this->assertSame('j***@example.com', $notes[0]['author']);

        $refunds = List_Masking::apply('refunds.list', [
            ['id' => 9, 'date_created' => '2026-11-02T09:00:00', 'total' => '-5.00', 'reason' => 'Jane said it broke', 'refunded_by' => 1, 'meta_data' => [['id' => 2, 'key' => 'k', 'value' => 'Secret']]],
        ]);
        $this->assertSame(9, $refunds[0]['id']);
        $this->assertSame('-5.00', $refunds[0]['total']);
        $this->assertSame(1, $refunds[0]['refunded_by']);
        $this->assertSame('J***', $refunds[0]['reason']);
        $this->assertSame('S***', $refunds[0]['meta_data'][0]['value']);
    }

    public function test_a_customer_list_masks_identity_and_the_gravatar_hash(): void
    {
        $out = List_Masking::apply('customers.list', [[
            'id'            => 7,
            'email'         => 'jane@example.com',
            'first_name'    => 'Jane',
            'last_name'     => 'Doe',
            'username'      => 'jane',
            'role'          => 'customer',
            'avatar_url'    => 'https://secure.gravatar.com/avatar/abcdef0123456789',
            'is_paying_customer' => true,
            'orders_count'  => 3,
            'total_spent'   => '75.00',
            'date_created'  => '2026-01-01T00:00:00',
            'billing'       => ['first_name' => 'Jane', 'email' => 'jane@example.com', 'country' => 'GB'],
            'shipping'      => ['first_name' => 'Jane', 'country' => 'GB'],
            'meta_data'     => [],
        ]]);
        $row = $out[0];

        $this->assertSame(7, $row['id']);
        $this->assertSame('customer', $row['role']);
        $this->assertSame(3, $row['orders_count']);
        $this->assertSame('75.00', $row['total_spent']);
        $this->assertTrue($row['is_paying_customer']);
        $this->assertSame('j***@example.com', $row['email']);
        $this->assertSame('J***', $row['first_name']);
        $this->assertSame('D***', $row['last_name']);
        $this->assertSame('j***', $row['username']);
        $this->assertSame('h***', $row['avatar_url']);
        $this->assertSame('j***@example.com', $row['billing']['email']);
        $this->assertSame('GB', $row['billing']['country']);
        $this->assertSame([], $row['meta_data']);
    }

    public function test_empty_values_and_non_list_bodies_pass_through(): void
    {
        $this->assertSame([], List_Masking::apply('orders.list', []));
        $row = List_Masking::apply('customers.list', [['id' => 1, 'first_name' => '', 'email' => '', 'billing' => []]]);
        $this->assertSame('', $row[0]['first_name']);
        $this->assertSame('', $row[0]['email']);
        $this->assertSame('not-a-list', List_Masking::apply('orders.list', 'not-a-list'));
    }
}
