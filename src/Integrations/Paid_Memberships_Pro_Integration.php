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
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- existence probe against a third-party table name built from $wpdb->prefix; no input reaches it.
        $wpdb->get_var("SELECT 1 FROM `{$table}` LIMIT 1");
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
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- third-party table name built from $wpdb->prefix; values, when present, are bound by prepare().
        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM `{$members}` WHERE membership_id = %d AND status = 'active'", $level_id)
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
                    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- third-party table name built from $wpdb->prefix; values, when present, are bound by prepare().
                    $rows  = $wpdb->get_results(
                        "SELECT id, name, initial_payment, billing_amount, cycle_number, cycle_period, allow_signups FROM `{$table}` ORDER BY id ASC",
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
                    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- third-party table name built from $wpdb->prefix; values, when present, are bound by prepare().
                    $row   = $wpdb->get_row(
                        $wpdb->prepare("SELECT * FROM `{$table}` WHERE id = %d", (int) $args['level_id']),
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
