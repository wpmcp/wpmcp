<?php

namespace WPMCP\Tools\WooCommerce\Catalog;

use WPMCP\Safety\Wc_Tax_Rate_Creation_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The taxes.create op of woo-write (issue #657). A new tax rate has no prior
 * state to capture and its id is the table's auto-increment, so the rate is
 * created through the wc/v3 taxes route (its own schema and permission
 * check) and, once it exists, a creation row is recorded with the same
 * ledger type create-tax-rate uses: rollback deletes the rate while it is
 * unchanged since. taxes.update and taxes.delete need no handler; they
 * dispatch their route under a wc_tax_rate snapshot.
 */
final class Tax_Ops
{
    /**
     * Nothing to normalize beyond the route schema: the params are the body.
     *
     * @param array<string, mixed> $params
     * @return array{body: array<string, mixed>}
     */
    public static function prepare(string $handler, array $params, bool $confirm = false): array
    {
        unset($handler, $confirm);
        return [ 'body' => $params ];
    }

    /**
     * Create the rate and record its creation row.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function create(array $body, string $session_id, string $op): array
    {
        $out = (new Wc_Rest_Write_Dispatch())->send('POST', '/wc/v3/taxes', $body);
        $id  = is_array($out['body']) ? (int) ($out['body']['id'] ?? 0) : 0;
        if ($out['status'] < 200 || $out['status'] >= 300 || $id <= 0) {
            $message = is_array($out['body']) && is_string($out['body']['message'] ?? null)
                ? $out['body']['message']
                : 'WooCommerce did not create the tax rate.';
            return Op_Guard::error('tax_create_failed', $message, [ 'status' => $out['status'] ]);
        }

        $operation_id = Wc_Tax_Rate_Creation_Snapshot::record('woo-write', $id, [ 'op' => $op, 'params' => $body ], $session_id);

        return $out + [ 'operation_id' => $operation_id ];
    }
}
