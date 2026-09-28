<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Duplicator status (issue #300): the backup packages list, newest first,
 * and the last completed build, read from the plugin's own table:
 * {base_prefix}duplicator_backups since Duplicator 5, or the older
 * {prefix}duplicator_packages on a site that has not migrated.
 *
 * Only the id, name, status, creation time and flags columns are read. The
 * package column (the serialized or JSON model, with paths and the archive
 * hash that forms its download names) and the hash and archive_name columns
 * are never selected, so nothing is unserialized and no download name leaks.
 */
final class Duplicator_Status
{
    /** Duplicator's AbstractPackage::STATUS_COMPLETE. */
    private const COMPLETE = 100;

    /** Duplicator's negative AbstractPackage status codes. */
    private const FAILURES = [
        -6 => 'requirements_failed',
        -5 => 'storage_failed',
        -4 => 'storage_cancelled',
        -3 => 'pending_cancel',
        -2 => 'build_cancelled',
        -1 => 'error',
    ];

    private const DEFAULT_LIMIT = 10;
    private const MAX_LIMIT     = 50;

    /** @return array<string,array<string,mixed>> */
    public static function operations(): array
    {
        return [
            'get-duplicator-status' => Ops_Status_Packs::read_op(
                'Duplicator status: backup packages newest first (id, name, status, created, flags; limit, default 10) and the last completed build',
                static fn () => self::requirement(),
                static fn (array $args): array => self::status((int) ($args['limit'] ?? self::DEFAULT_LIMIT)),
                [ 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT ] ]
            ),
        ];
    }

    /** Whether Duplicator is loaded, filterable through wpmcp_duplicator_active. */
    public static function is_active(): bool
    {
        return (bool) apply_filters('wpmcp_duplicator_active', defined('DUPLICATOR_VERSION'));
    }

    /** @return true|array{code:string,message:string} */
    private static function requirement()
    {
        if (! self::is_active()) {
            return Ops_Status_Packs::presence(false, 'duplicator_inactive', 'Duplicator');
        }
        if (null === self::table()) {
            return [
                'code'    => 'duplicator_not_installed',
                'message' => 'Duplicator is active but its backups table is missing; open Duplicator once to finish its setup.',
            ];
        }
        return true;
    }

    /** @return array{0:string,1:bool}|null the packages table and whether it is the current (flags-carrying) one. */
    private static function table(): ?array
    {
        global $wpdb;

        if (Ops_Status_Packs::table_exists($wpdb->base_prefix . 'duplicator_backups')) {
            return [ $wpdb->base_prefix . 'duplicator_backups', true ];
        }
        if (Ops_Status_Packs::table_exists($wpdb->prefix . 'duplicator_packages')) {
            return [ $wpdb->prefix . 'duplicator_packages', false ];
        }
        return null;
    }

    /** @return array<string,mixed> */
    private static function status(int $limit): array
    {
        global $wpdb;

        $found = self::table();
        if (null === $found) {
            return [ 'packages' => [], 'total' => 0, 'last_build' => null ];
        }
        [ $table, $current ] = $found;
        $columns             = $current ? 'id, name, status, created, flags' : "id, name, status, created, '' AS flags";

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Duplicator's own table has no WP API; the column list is a literal.
        $rows  = $wpdb->get_results($wpdb->prepare("SELECT {$columns} FROM %i ORDER BY created DESC, id DESC LIMIT %d", $table, $limit), ARRAY_A);
        $last  = $wpdb->get_row($wpdb->prepare("SELECT {$columns} FROM %i WHERE status = %d ORDER BY created DESC, id DESC LIMIT 1", $table, self::COMPLETE), ARRAY_A);
        $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $table));
        // phpcs:enable

        return [
            'packages'   => array_map([ self::class, 'view' ], (array) $rows),
            'total'      => $total,
            'last_build' => is_array($last) ? self::view($last) : null,
        ];
    }

    /** @return array<string,mixed> */
    private static function view(array $row): array
    {
        $code  = (int) $row['status'];
        $flags = array_values(array_filter(
            explode(',', (string) $row['flags']),
            static fn (string $flag): bool => 1 === preg_match('/^[A-Z][A-Z_]*$/', $flag)
        ));

        return [
            'id'          => (int) $row['id'],
            'name'        => sanitize_text_field((string) $row['name']),
            'status'      => self::label($code),
            'status_code' => $code,
            'created'     => Ops_Status_Packs::iso_datetime($row['created']),
            'flags'       => $flags,
        ];
    }

    private static function label(int $code): string
    {
        if (self::COMPLETE === $code) {
            return 'complete';
        }
        if ($code < 0) {
            return self::FAILURES[ $code ] ?? 'error';
        }
        return 'building';
    }
}
