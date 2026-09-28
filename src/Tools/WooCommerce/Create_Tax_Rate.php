<?php

namespace WPMCP\Tools\WooCommerce;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Create a WooCommerce tax rate through WC_Tax's own insert helpers.
 *
 * Creation is exempt from Safe_Mutation for the same reason create-product
 * is: there is no prior state to capture, and the auto-increment id is not
 * known before the insert. The reply names delete-tax-rate as the undo. The
 * rate is validated first (Tax_Rate_Input), so nothing is written for a call
 * that would be refused.
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

        return array_merge(
            (array) Tax_Rate_View::find($id),
            ['recoverable' => false, 'undo' => 'delete-tax-rate']
        );
    }
}
