<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Paid Memberships Pro (PMPro) read integration (wpmcp/pmpro-read pair).
 *
 * PMPro stores membership levels in its own custom table
 * {prefix}pmpro_membership_levels (id, name, description, initial_payment,
 * billing_amount, cycle_number, cycle_period, allow_signups, ...) and member
 * assignments in {prefix}pmpro_memberships_users (verified against PMPro
 * source). This integration reads those tables directly, the same way the
 * Gravity Tables integration reads its custom table, so an agent can inventory
 * a site's membership levels, their pricing, and their active member counts.
 *
 * Read-only: levels and memberships are managed through PMPro's admin and are
 * not a Safe_Mutation snapshot target, so writes are deferred.
 */
class Paid_Memberships_Pro_Integration extends Integration_Dispatcher
{
    private static function levels_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'pmpro_membership_levels';
    }

    private static function members_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'pmpro_memberships_users';
    }

    public function integration(): string
    {
        return 'pmpro';
    }

    public function is_available(): bool
    {
        global $wpdb;
        $table    = self::levels_table();
        $suppress = $wpdb->suppress_errors(true);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- probes whether PMPro's own custom table exists; the answer must reflect the live schema, not a cache.
        $wpdb->get_var($wpdb->prepare('SELECT 1 FROM %i LIMIT 1', $table));
        $exists = '' === $wpdb->last_error;
        $wpdb->suppress_errors($suppress);
        return $exists;
    }

    protected function summary(): string
    {
        return 'Paid Memberships Pro (membership levels, pricing, and member counts)';
    }

    private static function active_members(int $level_id): int
    {
        global $wpdb;
        $members = self::members_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- pmpro_memberships_users is PMPro's own custom table with no WP API; live member counts must not be stale.
        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM %i WHERE membership_id = %d AND status = 'active'", $members, $level_id)
        );
    }

    protected function operations(): array
    {
        return [
            'list-levels' => [
                'mode'         => 'read',
                'description'  => 'List PMPro membership levels with id, name, initial and recurring price, billing cycle, whether signups are allowed, and active member count',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => function (): array {
                    global $wpdb;
                    $table = self::levels_table();
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- pmpro_membership_levels is PMPro's own custom table with no WP API; the level inventory must reflect the live rows.
                    $rows = $wpdb->get_results(
                        $wpdb->prepare(
                            'SELECT id, name, initial_payment, billing_amount, cycle_number, cycle_period, allow_signups FROM %i ORDER BY id ASC',
                            $table
                        ),
                        ARRAY_A
                    );
                    $levels = [];
                    foreach ((array) $rows as $r) {
                        $levels[] = [
                            'id'              => (int) $r['id'],
                            'name'            => (string) $r['name'],
                            'initial_payment' => $r['initial_payment'],
                            'billing_amount'  => $r['billing_amount'],
                            'cycle'           => trim((string) ($r['cycle_number'] ?? '') . ' ' . (string) ($r['cycle_period'] ?? '')),
                            'allow_signups'   => ! empty($r['allow_signups']),
                            'active_members'  => self::active_members((int) $r['id']),
                        ];
                    }
                    return [ 'levels' => $levels, 'total' => count($levels) ];
                },
            ],
            'get-level' => [
                'mode'         => 'read',
                'description'  => 'Read one membership level\'s full configuration and its active member count',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'level_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
                    'required'   => [ 'level_id' ],
                ],
                'handler'      => function (array $args): array {
                    global $wpdb;
                    $table = self::levels_table();
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- pmpro_membership_levels is PMPro's own custom table with no WP API; the level read must reflect the live row.
                    $row = $wpdb->get_row(
                        $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $table, (int) $args['level_id']),
                        ARRAY_A
                    );
                    if (null === $row) {
                        return [ 'level' => null ];
                    }
                    $row['id']             = (int) $row['id'];
                    $row['active_members'] = self::active_members((int) $row['id']);
                    return [ 'level' => $row ];
                },
            ],
        ];
    }
}
