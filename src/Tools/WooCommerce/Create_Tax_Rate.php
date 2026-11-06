<?php

namespace WPMCP\Tools\WooCommerce;

use WPMCP\Safety\Wc_Tax_Rate_Creation_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Create a WooCommerce tax rate through WC_Tax's own insert helpers.
 *
 * Creation has no prior state to capture, and the auto-increment id is not
 * known before the insert, so the call records a creation row once the rate
 * exists (issue #761, Wc_Tax_Rate_Creation_Snapshot) and returns its
 * operation_id: rollback-operation and rollback-session delete the rate
 * again, but only while it is unchanged since. The rate is validated first
 * (Tax_Rate_Input), so nothing is written for a call that would be refused.
 */
class Create_Tax_Rate
{
    public function handle(array $args): array
    {
        $input  = Tax_Rate_Input::parse($args, true);
        $fields = array_merge(
            [
                'tax_rate_country'  => '',
                'tax_rate_state'    => '',
                'tax_rate_name'     => 'Tax',
                'tax_rate_priority' => 1,
                'tax_rate_compound' => 0,
                'tax_rate_shipping' => 1,
                'tax_rate_order'    => 0,
                'tax_rate_class'    => '',
            ],
            $input['fields']
        );

        $id = (int) \WC_Tax::_insert_tax_rate($fields);
        if ($id <= 0) {
            throw new \RuntimeException('Could not create the tax rate.');
        }
        if (null !== $input['postcodes']) {
            \WC_Tax::_update_tax_rate_postcodes($id, $input['postcodes']);
        }
        if (null !== $input['cities']) {
            \WC_Tax::_update_tax_rate_cities($id, $input['cities']);
        }

        $operation_id = Wc_Tax_Rate_Creation_Snapshot::record('create-tax-rate', $id, $args, (string) ($args['session_id'] ?? 'default'));

        return ['operation_id' => $operation_id] + (array) Tax_Rate_View::find($id);
    }
}
