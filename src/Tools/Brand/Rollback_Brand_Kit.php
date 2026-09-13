<?php

namespace WPMCP\Tools\Brand;

use WPMCP\Safety\Snapshot_Store;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Tools\Elementor\Elementor_Kit_Data;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Undo a brand-kit apply, restoring the whole kit (palette, swatches,
 * typography, logo) from the single snapshot that apply-brand-kit took.
 *
 * With no arguments this targets the most recent apply that has not already
 * been rolled back, read from the apply log, so an agent that has lost the
 * operation_id (or a different agent entirely) can still undo the rebrand.
 * Naming an operation_id targets that apply specifically, even if it was
 * already rolled back once.
 */
class Rollback_Brand_Kit
{
    public function handle(array $args)
    {
        $operation_id = trim((string) ($args['operation_id'] ?? ''));
        $record       = Brand_Kit_Store::find_apply('' !== $operation_id ? $operation_id : null);

        if (null === $record) {
            if ('' !== $operation_id) {
                return new \WP_Error(
                    'brand_kit_apply_not_found',
                    sprintf('No brand-kit apply is recorded for operation "%s".', $operation_id)
                );
            }
            return new \WP_Error(
                'no_brand_kit_apply',
                [] === Brand_Kit_Store::applies()
                    ? 'No brand kit has been applied on this site, so there is nothing to roll back.'
                    : 'Every recorded brand-kit apply has already been rolled back. Pass an operation_id to restore one again.'
            );
        }

        $target = (string) $record['operation_id'];

        if (! Rollback_Service::restore_operation($target)) {
            return new \WP_Error(
                'snapshot_unavailable',
                sprintf(
                    'The snapshot for operation "%s" is no longer stored, so the apply cannot be undone (history keeps %d operations).',
                    $target,
                    Snapshot_Store::history_limit()
                )
            );
        }

        $warnings = Rollback_Service::take_warnings();
        Brand_Kit_Store::mark_rolled_back($target);

        $kit_id = (int) ($record['kit_id'] ?? Elementor_Kit_Data::active_kit_id());

        return [
            'restored'      => true,
            'operation_id'  => $target,
            'slug'          => (string) ($record['slug'] ?? ''),
            'title'         => (string) ($record['title'] ?? ''),
            'kit_id'        => $kit_id,
            'applied_at'    => $record['applied_at'] ?? null,
            'settings_hash' => $kit_id > 0 ? Elementor_Kit_Data::settings_hash($kit_id) : '',
            'warnings'      => $warnings,
        ];
    }
}
