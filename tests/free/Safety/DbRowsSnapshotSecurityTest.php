<?php

namespace WPMCP\Tests\Free\Safety;

use WPMCP\Safety\Mutation_Failed;
use WPMCP\Safety\Rollback_Service;

/**
 * Adversarial coverage for the db_rows restore path (issue #82).
 *
 * The rollback path itself performs DB writes, so a forged or stale snapshot
 * must never become a write primitive against tables or columns the write
 * tools themselves would refuse. Every apply re-validates the snapshot
 * against the LIVE schema: the table must exist, must not be protected, the
 * primary key must be declared, and every captured column must still be a
 * real column of the table.
 */
class DbRowsSnapshotSecurityTest extends \WP_UnitTestCase
{
    private function forged(array $overrides): array
    {
        global $wpdb;
        return [
            'object_type' => 'db_rows',
            'object_id'   => 0,
            'data'        => array_merge([
                'table'       => $wpdb->options,
                'operation'   => 'update',
                'primary_key' => ['option_id'],
                'where'       => [],
                'set'         => [],
                'rows'        => [],
            ], $overrides),
        ];
    }

    public function test_refuses_restore_into_protected_table(): void
    {
        global $wpdb;

        $this->expectException(Mutation_Failed::class);
        Rollback_Service::apply_snapshot($this->forged([
            'table'       => $wpdb->users,
            'primary_key' => ['ID'],
            'rows'        => [['ID' => '1', 'user_pass' => 'owned']],
        ]));
    }

    public function test_refuses_restore_into_unknown_table(): void
    {
        $this->expectException(Mutation_Failed::class);
        Rollback_Service::apply_snapshot($this->forged([
            'table' => 'wp_totally_missing_table',
            'rows'  => [['option_id' => '1']],
        ]));
    }

    public function test_refuses_rows_with_columns_not_in_live_schema(): void
    {
        $this->expectException(Mutation_Failed::class);
        Rollback_Service::apply_snapshot($this->forged([
            'rows' => [['option_id' => '1', 'evil`col` = 1 -- ' => 'x']],
        ]));
    }

    public function test_refuses_snapshot_without_primary_key(): void
    {
        $this->expectException(Mutation_Failed::class);
        Rollback_Service::apply_snapshot($this->forged([
            'primary_key' => [],
            'rows'        => [['option_id' => '1', 'option_name' => 'x']],
        ]));
    }

    public function test_refuses_row_missing_a_primary_key_value(): void
    {
        $this->expectException(Mutation_Failed::class);
        Rollback_Service::apply_snapshot($this->forged([
            'rows' => [['option_name' => 'x', 'option_value' => 'y']],
        ]));
    }

    public function test_empty_rows_snapshot_is_a_safe_no_op(): void
    {
        Rollback_Service::apply_snapshot($this->forged(['rows' => []]));
        $this->assertSame([], Rollback_Service::take_warnings());
    }
}
