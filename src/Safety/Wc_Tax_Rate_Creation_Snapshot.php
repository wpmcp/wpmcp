<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- ABSPATH guard is an intentional side effect.
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional (matches the rest of WPMCP\Safety).
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional (matches the rest of WPMCP\Safety).

namespace WPMCP\Safety;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The ledger row for a WooCommerce tax rate create-tax-rate CREATED
 * (issue #761).
 *
 * Like Menu_Creation_Snapshot, there is no prior state to capture, so the
 * row records what the call made: the rate id and a fingerprint of the rate
 * row, its postcodes and its cities as the call left them (read through
 * Snapshot::capture(), the same capture update-tax-rate and delete-tax-rate
 * snapshot). rollback-operation and rollback-session undo it by deleting the
 * rate, but only while it is unchanged since: a rate someone has edited is
 * kept, with the reason, and nothing is deleted. Undoing takes
 * manage_woocommerce, the capability the tax rate tools take.
 *
 * The row is written AFTER the rate exists (its id is the table's
 * auto-increment). If it cannot be written, the rate this call just created
 * is deleted again and the error is rethrown: a creation is never left
 * behind without its undo point.
 */
class Wc_Tax_Rate_Creation_Snapshot
{
    public const OBJECT_TYPE = 'wc_tax_rate_create';

    /**
     * @return string The operation id of the written row.
     * @throws Mutation_Failed When the row could not be written.
     */
    public static function record(string $tool_name, int $tax_rate_id, array $args, string $session_id): string
    {
        $fingerprint = self::fingerprint($tax_rate_id);
        if (null === $fingerprint) {
            throw new \InvalidArgumentException('A creation row needs the created tax rate.');
        }

        $operation_id = wp_generate_uuid4();
        try {
            Snapshot_Store::save(
                $operation_id,
                '' === $session_id ? 'default' : $session_id,
                [
                    'object_type' => self::OBJECT_TYPE,
                    'object_id'   => $tax_rate_id,
                    'data'        => [ 'fingerprint' => $fingerprint ],
                ],
                $tool_name,
                hash('sha256', (string) wp_json_encode($args))
            );
        } catch (\Throwable $e) {
            // No undo point, no creation. Created by this same call a moment
            // ago, so no order can have been charged under it yet.
            \WC_Tax::_delete_tax_rate($tax_rate_id);
            throw $e;
        }
        Operation_Context::note($operation_id);
        Snapshot_Store::prune();

        return $operation_id;
    }

    /**
     * Why undoing this creation would delete more than the call made, or
     * null when it may go ahead (also null for a rate that is already gone:
     * there is nothing left to undo).
     */
    public static function refusal(array $snapshot): ?string
    {
        if (self::OBJECT_TYPE !== ($snapshot['object_type'] ?? '')) {
            return null;
        }
        if (! current_user_can('manage_woocommerce')) {
            return 'undoing a tax rate creation requires the "manage_woocommerce" capability.';
        }
        $tax_rate_id = (int) ($snapshot['object_id'] ?? 0);
        $current     = self::fingerprint($tax_rate_id);
        if (null === $current) {
            return null;
        }
        if (! hash_equals((string) ($snapshot['data']['fingerprint'] ?? ''), $current)) {
            return sprintf('tax rate %d changed since create-tax-rate made it (its rate, location, class or settings), so it was kept.', $tax_rate_id);
        }
        return null;
    }

    /**
     * Delete the created rate, unless refusal() objects (then warn and keep
     * it). A rate that is already gone needs nothing.
     *
     * @return string|null A warning, or null.
     */
    public static function undo_creation(array $snapshot): ?string
    {
        $tax_rate_id = (int) ($snapshot['object_id'] ?? 0);
        if (null === self::fingerprint($tax_rate_id)) {
            return null;
        }
        $refused = self::refusal($snapshot);
        if (null !== $refused) {
            return 'Undo of create-tax-rate skipped: ' . $refused;
        }
        \WC_Tax::_delete_tax_rate($tax_rate_id);
        if (null !== self::fingerprint($tax_rate_id)) {
            return sprintf('Tax rate %d created by this operation could not be deleted; it was left in place.', $tax_rate_id);
        }
        return null;
    }

    /**
     * The rate row with its postcodes and cities as one hash, or null when
     * no tax rate has this id (or WooCommerce is not active).
     */
    private static function fingerprint(int $tax_rate_id): ?string
    {
        if ($tax_rate_id <= 0 || ! class_exists('WC_Tax')) {
            return null;
        }
        $data = (array) (Snapshot::capture('wc_tax_rate', $tax_rate_id)['data'] ?? []);
        if (! is_array($data['rate'] ?? null)) {
            return null;
        }
        $rate = array_map('strval', $data['rate']);
        ksort($rate);
        $postcodes = array_map('strval', (array) ($data['postcodes'] ?? []));
        $cities    = array_map('strval', (array) ($data['cities'] ?? []));
        sort($postcodes);
        sort($cities);
        return hash('sha256', (string) wp_json_encode([
            'rate'      => $rate,
            'postcodes' => $postcodes,
            'cities'    => $cities,
        ]));
    }
}
