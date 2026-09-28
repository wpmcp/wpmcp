<?php

// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional (matches the rest of WPMCP\Safety).
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional (matches the rest of WPMCP\Safety).

namespace WPMCP\Safety;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce webhook snapshots and webhook creation rows (issue #292).
 *
 * 'wc_webhook' captures the webhook's whole wc_webhooks row, signing secret
 * and dates included, so rollback restores it exactly: an edit is written
 * back in place, a deleted webhook returns at its own id. The secret has to
 * be in the snapshot for that, and it stays there: no tool response carries
 * it, the rollback tools report only whether a restore happened, restoring
 * takes manage_woocommerce (Rollback_Service), and the change-set export
 * excludes these rows by design.
 *
 * The row is written back raw rather than through WC_Webhook::save(), which
 * would stamp a new date_modified and, for an active webhook, send a
 * delivery ping; a restore must do neither.
 *
 * 'wc_webhook_create' is the creation row for a webhook a tool created.
 * Webhooks are store configuration, not content, so undoing a create deletes
 * the webhook through WooCommerce's own webhook data store. date_created is
 * fixed at creation, so a mismatch means a different webhook holds the id,
 * and it is left alone.
 */
final class Wc_Webhook_Snapshot
{
    public const TYPE        = 'wc_webhook';
    public const CREATE_TYPE = 'wc_webhook_create';

    private const COLUMNS = [
        'webhook_id'        => '%d',
        'status'            => '%s',
        'name'              => '%s',
        'user_id'           => '%d',
        'delivery_url'      => '%s',
        'secret'            => '%s',
        'topic'             => '%s',
        'date_created'      => '%s',
        'date_created_gmt'  => '%s',
        'date_modified'     => '%s',
        'date_modified_gmt' => '%s',
        'api_version'       => '%d',
        'failure_count'     => '%d',
        'pending_delivery'  => '%d',
    ];

    public static function capture(int $webhook_id): array
    {
        $row = self::row($webhook_id);
        return [
            'object_type' => self::TYPE,
            'object_id'   => $webhook_id,
            'data'        => null === $row ? [ 'exists' => false ] : [ 'exists' => true, 'row' => $row ],
        ];
    }

    /** Put a webhook back to a 'wc_webhook' snapshot. Returns a warning, or null. */
    public static function restore(array $snapshot): ?string
    {
        global $wpdb;

        $data = (array) ($snapshot['data'] ?? []);
        $id   = (int) ($snapshot['object_id'] ?? 0);
        if (empty($data['exists']) || $id <= 0 || ! is_array($data['row'] ?? null)) {
            return null;
        }

        $values  = [];
        $formats = [];
        foreach (self::COLUMNS as $column => $format) {
            if (array_key_exists($column, $data['row'])) {
                $values[ $column ] = $data['row'][ $column ];
                $formats[]         = $format;
            }
        }
        $values['webhook_id'] = $id;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- restoring the raw row at its own id; WooCommerce's caches are flushed below.
        $written = $wpdb->replace($wpdb->prefix . 'wc_webhooks', $values, $formats);
        self::flush_caches($id);

        return false === $written ? "Webhook {$id} could not be restored." : null;
    }

    /**
     * Write the creation row for a webhook a tool just created. If the row
     * cannot be written the webhook is deleted again and the error rethrown.
     */
    public static function record_creation(int $webhook_id, string $tool_name, array $args, string $session_id): string
    {
        $row          = self::row($webhook_id);
        $operation_id = wp_generate_uuid4();
        try {
            Snapshot_Store::save(
                $operation_id,
                '' === $session_id ? 'default' : $session_id,
                [
                    'object_type' => self::CREATE_TYPE,
                    'object_id'   => $webhook_id,
                    'data'        => [ 'date_created_gmt' => (string) ($row['date_created_gmt'] ?? '') ],
                ],
                $tool_name,
                hash('sha256', (string) wp_json_encode($args))
            );
        } catch (\Throwable $e) {
            self::delete($webhook_id);
            throw $e;
        }
        Operation_Context::note($operation_id);
        Snapshot_Store::prune();

        return $operation_id;
    }

    /** Undo a 'wc_webhook_create' row: delete the webhook. Returns a warning, or null. */
    public static function undo_creation(array $snapshot): ?string
    {
        $id  = (int) ($snapshot['object_id'] ?? 0);
        $row = self::row($id);
        if (null === $row) {
            return null; // Already gone: nothing to undo.
        }
        if ((string) ($snapshot['data']['date_created_gmt'] ?? '') !== (string) $row['date_created_gmt']) {
            return "Webhook {$id} is not the webhook this operation created (the id was reclaimed); it was left untouched.";
        }
        self::delete($id);
        return null === self::row($id) ? null : "Webhook {$id} created by this operation could not be deleted; it was left in place.";
    }

    /** The raw webhook row, secret included, or null. Never returned to a caller outside the safety layer. */
    public static function row(int $webhook_id): ?array
    {
        global $wpdb;
        if ($webhook_id <= 0) {
            return null;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- snapshot capture must read the live row, not a cache.
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}wc_webhooks WHERE webhook_id = %d", $webhook_id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** Delete a webhook through WooCommerce's data store, falling back to the raw row. */
    private static function delete(int $webhook_id): void
    {
        global $wpdb;
        $webhook = function_exists('wc_get_webhook') ? wc_get_webhook($webhook_id) : null;
        if ($webhook instanceof \WC_Webhook) {
            $webhook->delete(true);
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WooCommerce is unavailable; remove the row directly.
            $wpdb->delete($wpdb->prefix . 'wc_webhooks', [ 'webhook_id' => $webhook_id ], [ '%d' ]);
        }
        self::flush_caches($webhook_id);
    }

    /** The caches WooCommerce's webhook data store keeps: the row cache, the group prefix and the id-list transients. */
    private static function flush_caches(int $webhook_id): void
    {
        wp_cache_delete($webhook_id, 'webhooks');
        if (class_exists('WC_Cache_Helper')) {
            \WC_Cache_Helper::invalidate_cache_group('webhooks');
        }
        delete_transient('woocommerce_webhook_ids');
        $statuses = function_exists('wc_get_webhook_statuses') ? array_keys(wc_get_webhook_statuses()) : ['active', 'paused', 'disabled'];
        foreach ($statuses as $status) {
            delete_transient('woocommerce_webhook_ids_status_' . $status);
        }
    }
}
