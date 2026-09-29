<?php

namespace WPMCP\Tools\WooCommerce;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Delete a WooCommerce tax rate.
 *
 * Destructive and disabled by default, like every other delete tool: a site
 * opts in with add_filter('wpmcp_enable_delete_tax_rate', '__return_true'),
 * and the caller must pass confirm:true. The delete runs through
 * Safe_Mutation with the 'wc_tax_rate' snapshot type, so rollback-operation
 * re-inserts the rate at its ORIGINAL id with its postcodes and cities; past
 * orders reference the rate by that id.
 */
class Delete_Tax_Rate
{
    public static function is_enabled(): bool
    {
        return (bool) apply_filters('wpmcp_enable_delete_tax_rate', false);
    }

    public function handle(array $args): array
    {
        if (! self::is_enabled()) {
            throw new \RuntimeException('The delete-tax-rate tool is disabled. Enable it with the wpmcp_enable_delete_tax_rate filter.');
        }

        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0 || null === Tax_Rate_View::find($id)) {
            throw new \InvalidArgumentException('Tax rate not found.');
        }
        if (true !== ($args['confirm'] ?? null)) {
            throw new \WPMCP\MCP\Confirmation_Required('Deleting a tax rate changes what every checkout charges. Pass confirm:true to proceed.');
        }

        $out = Safe_Mutation::run(
            [
                'object_type' => 'wc_tax_rate',
                'object_id'   => $id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'delete-tax-rate',
                'args'        => $args,
            ],
            static function () use ($id): void {
                \WC_Tax::_delete_tax_rate($id);
            }
        );

        return [
            'operation_id' => $out['operation_id'],
            'id'           => $id,
            'deleted'      => true,
            'recoverable'  => true,
        ];
    }
}
