<?php

namespace WPMCP\Tools\WooCommerce;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Add a note to a WooCommerce order.
 *
 * Additive only: a note is appended, never overwriting or removing existing
 * data, so there is nothing to roll back and this does not go through
 * Safe_Mutation (mirroring how additive tools like sideload-image and
 * install-plugin are exempt). A customer note (customer_note:true) is emailed
 * to the customer; the default is a private/internal note.
 */
class Add_Order_Note
{
    public function handle(array $args): array
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            throw new \InvalidArgumentException('An order id is required.');
        }

        $note = trim((string) ($args['note'] ?? ''));
        if ('' === $note) {
            throw new \InvalidArgumentException('Note text is required.');
        }

        $order = wc_get_order($id);
        // A refund id resolves to a WC_Order_Refund, which is not an order (#942).
        if (! $order instanceof \WC_Order) {
            throw new \RuntimeException('Order not found.');
        }

        $is_customer_note = ! empty($args['customer_note']);
        $note_id          = $order->add_order_note($note, $is_customer_note ? 1 : 0, false);

        return [
            'id'            => $id,
            'note_id'       => (int) $note_id,
            'customer_note' => $is_customer_note,
        ];
    }
}
