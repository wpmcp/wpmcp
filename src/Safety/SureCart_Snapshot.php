<?php

// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional (matches the rest of WPMCP\Safety).
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional (matches the rest of WPMCP\Safety).

namespace WPMCP\Safety;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Snapshots of a SureCart write (issue #665). SureCart keeps its store in
 * its own cloud, so there is no local row to copy: the before-image is read
 * from SureCart's API and the undo is another API call. The key is
 * "<kind>:<id>":
 *
 *  - product:<product id>, for a product edit. capture() reads the fields
 *    the edit may change (FIELDS) and the write records what it left there
 *    (record_after()). The undo puts back each field the edit changed that
 *    still holds what the edit left, and leaves alone, with a warning, one
 *    changed since in the SureCart dashboard.
 *  - coupon:new-<token>, for a coupon the write creates. The write records
 *    the new coupon's id (record_created()). The undo deletes it, or, when
 *    an order has redeemed it since, archives it instead, so no order loses
 *    the discount it was given.
 *
 * Restoring takes manage_options, the capability the SureCart writes take.
 */
final class SureCart_Snapshot
{
    public const TYPE = 'surecart_object';

    public const KINDS = [ 'product', 'coupon' ];

    /** The product fields an edit may change, and so the ones the image holds. */
    public const FIELDS = [ 'name', 'description', 'slug', 'status', 'archived', 'sku' ];

    public static function key(string $kind, string $id): string
    {
        return $kind . ':' . $id;
    }

    /** @return array{kind: string, id: string} */
    public static function parse(string $key): array
    {
        [$kind, $id] = array_pad(explode(':', $key, 2), 2, '');
        return [ 'kind' => in_array($kind, self::KINDS, true) ? $kind : '', 'id' => $id ];
    }

    /**
     * @throws Mutation_Failed When the product cannot be read, so no edit
     *                         runs without its before-image.
     */
    public static function capture(string $key): array
    {
        $parsed = self::parse($key);
        $data   = [ 'kind' => $parsed['kind'], 'id' => $parsed['id'] ];
        if ('product' === $parsed['kind']) {
            $product = SureCart_Api::call('products', 'find', [ 'id' => $parsed['id'] ]);
            if (is_wp_error($product)) {
                throw new Mutation_Failed(esc_html('Could not read SureCart product ' . $parsed['id'] . ' before the edit: ' . $product->get_error_message()));
            }
            $data['before'] = self::fields($product);
        }
        return [ 'object_type' => self::TYPE, 'object_id' => $key, 'data' => $data ];
    }

    /**
     * The product fields an edit can move.
     *
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    public static function fields(array $product): array
    {
        $out = [];
        foreach (self::FIELDS as $field) {
            $out[ $field ] = $product[ $field ] ?? null;
        }
        return $out;
    }

    /** Complete a product snapshot with what the edit left there. */
    public static function record_after(string $operation_id, array $product): void
    {
        self::amend($operation_id, [ 'after' => self::fields($product) ]);
    }

    /** Complete a coupon snapshot with the id of the coupon the write created. */
    public static function record_created(string $operation_id, string $coupon_id): void
    {
        self::amend($operation_id, [ 'created' => $coupon_id ]);
    }

    private static function amend(string $operation_id, array $data): void
    {
        $row = '' === $operation_id ? null : Snapshot_Store::get_by_operation($operation_id);
        if (null === $row || self::TYPE !== ($row['snapshot']['object_type'] ?? '')) {
            return;
        }
        $snapshot         = (array) $row['snapshot'];
        $snapshot['data'] = array_merge((array) $snapshot['data'], $data);
        Snapshot_Store::update_snapshot($operation_id, $snapshot);
    }

    /**
     * Undo the recorded write. Returns a warning naming what was left as it
     * is, or null.
     *
     * @throws Mutation_Failed When SureCart refuses the undo.
     */
    public static function restore(array $snapshot): ?string
    {
        $data = (array) ($snapshot['data'] ?? []);
        $kind = (string) ($data['kind'] ?? '');
        if ('product' === $kind && isset($data['before'], $data['after'])) {
            return self::restore_product((string) $data['id'], (array) $data['before'], (array) $data['after']);
        }
        if ('coupon' === $kind && '' !== (string) ($data['created'] ?? '')) {
            return self::remove_coupon((string) $data['created']);
        }
        return null;
    }

    private static function restore_product(string $id, array $before, array $after): ?string
    {
        $now = SureCart_Api::call('products', 'find', [ 'id' => $id ]);
        if (is_wp_error($now)) {
            if ('not_found' === $now->get_error_code()) {
                return sprintf('SureCart product %s is gone; its fields were not restored.', $id);
            }
            throw new Mutation_Failed(esc_html('SureCart refused the undo: ' . $now->get_error_message()));
        }
        $current  = self::fields($now);
        $set      = [];
        $warnings = [];
        foreach ($before as $field => $value) {
            if (! array_key_exists($field, $after) || $after[ $field ] === $value) {
                continue;
            }
            if (($current[ $field ] ?? null) !== $after[ $field ]) {
                $warnings[] = sprintf('SureCart product %s: %s was changed since, so it was left as it is.', $id, $field);
                continue;
            }
            $set[ $field ] = $value;
        }
        if ([] !== $set) {
            $done = SureCart_Api::call('products', 'update', [ 'id' => $id, 'attributes' => $set ]);
            if (is_wp_error($done)) {
                throw new Mutation_Failed(esc_html('SureCart refused the undo: ' . $done->get_error_message()));
            }
        }
        return [] === $warnings ? null : implode(' ', $warnings);
    }

    private static function remove_coupon(string $id): ?string
    {
        $coupon = SureCart_Api::call('coupons', 'find', [ 'id' => $id ]);
        if (is_wp_error($coupon)) {
            if ('not_found' === $coupon->get_error_code()) {
                return null;
            }
            throw new Mutation_Failed(esc_html('SureCart refused the undo: ' . $coupon->get_error_message()));
        }
        if ((int) ($coupon['times_redeemed'] ?? 0) > 0) {
            $done = SureCart_Api::call('coupons', 'update', [ 'id' => $id, 'attributes' => [ 'archived' => true ] ]);
            if (is_wp_error($done)) {
                throw new Mutation_Failed(esc_html('SureCart refused the undo: ' . $done->get_error_message()));
            }
            return sprintf('SureCart coupon %s was redeemed since it was created, so it was archived rather than deleted.', $id);
        }
        $done = SureCart_Api::call('coupons', 'delete', [ 'id' => $id ]);
        if (is_wp_error($done) && 'not_found' !== $done->get_error_code()) {
            throw new Mutation_Failed(esc_html('SureCart refused the undo: ' . $done->get_error_message()));
        }
        return null;
    }
}
