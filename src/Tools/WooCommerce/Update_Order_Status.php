<?php

namespace WPMCP\Tools\WooCommerce;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Change a WooCommerce order's status.
 *
 * Routed through Safe_Mutation with the additive object_type 'wc_order', which
 * snapshots the order's prior status before the change and restores exactly
 * that status on rollback-operation. This is honestly recoverable: the tool
 * only ever changes the status, and the snapshot restores it through the
 * WC_Order CRUD setter, which writes correctly whether the store uses HPOS or
 * the legacy CPT.
 *
 * The status is validated against the store's registered order statuses so an
 * unknown value is rejected before any snapshot or write happens.
 */
class Update_Order_Status
{
    public function handle(array $args): array
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            throw new \InvalidArgumentException('An order id is required.');
        }

        $status = (string) ($args['status'] ?? '');
        if ('' === $status) {
            throw new \InvalidArgumentException('A target status is required.');
        }

        $order = wc_get_order($id);
        if (! $order) {
            throw new \RuntimeException('Order not found.');
        }

        // wc_get_order_statuses() keys are prefixed with 'wc-'; the CRUD API
        // accepts the unprefixed slug. Accept either form from the caller and
        // reduce to the bare slug for validation and for set_status().
        $slug  = (0 === strpos($status, 'wc-')) ? substr($status, 3) : $status;
        $valid = array_map(
            static fn(string $key): string => substr($key, 3),
            array_keys(wc_get_order_statuses())
        );
        if (! in_array($slug, $valid, true)) {
            throw new \InvalidArgumentException('Unknown order status: ' . esc_html($status));
        }

        $out = Safe_Mutation::run(
            [
                'object_type' => 'wc_order',
                'object_id'   => $id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'update-order-status',
                'args'        => $args,
            ],
            function () use ($order, $slug): void {
                $order->set_status($slug);
                $order->save();
            }
        );

        return [
            'id'           => $id,
            'status'       => wc_get_order($id)->get_status(),
            'operation_id' => $out['operation_id'],
            'recoverable'  => true,
        ];
    }
}
