<?php

namespace WPMCP\Tools\WooCommerce;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Update a WooCommerce tax rate. A tax rate is a woocommerce_tax_rates row
 * plus location rows, not a post, so it snapshots through the dedicated
 * 'wc_tax_rate' object type (Snapshot::capture_wc_tax_rate), which captures
 * the full row and every postcode and city; rollback-operation puts all of
 * it back through WC_Tax's helpers.
 *
 * Only the fields present in the call change. postcodes and cities replace
 * the whole list when given (an empty array clears it), which is how the
 * tax settings screen treats them too.
 */
class Update_Tax_Rate
{
    public function handle(array $args): array
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            throw new \InvalidArgumentException('A tax rate id is required.');
        }
        if (null === Tax_Rate_View::find($id)) {
            throw new \RuntimeException('Tax rate ' . (int) $id . ' not found.');
        }

        $input = Tax_Rate_Input::parse($args, false);
        if ([] === $input['fields'] && null === $input['postcodes'] && null === $input['cities']) {
            throw new \InvalidArgumentException('Nothing to update: pass at least one field to change.');
        }

        $out = Safe_Mutation::run(
            [
                'object_type' => 'wc_tax_rate',
                'object_id'   => $id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'update-tax-rate',
                'args'        => $args,
            ],
            static function () use ($id, $input): void {
                if ([] !== $input['fields']) {
                    \WC_Tax::_update_tax_rate($id, $input['fields']);
                }
                if (null !== $input['postcodes']) {
                    \WC_Tax::_update_tax_rate_postcodes($id, $input['postcodes']);
                }
                if (null !== $input['cities']) {
                    \WC_Tax::_update_tax_rate_cities($id, $input['cities']);
                }
            }
        );

        return array_merge((array) Tax_Rate_View::find($id), ['operation_id' => $out['operation_id']]);
    }
}
